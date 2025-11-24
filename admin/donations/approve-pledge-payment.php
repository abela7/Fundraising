<?php
// admin/donations/approve-pledge-payment.php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

try {
    require_admin();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $payment_id = isset($input['id']) ? (int)$input['id'] : 0;
    $user_id = (int)$_SESSION['user']['id'];
    
    if ($payment_id <= 0) {
        throw new Exception('Invalid payment ID');
    }
    
    $db = db();
    $db->begin_transaction();
    
    // 1. Fetch payment details
    // Check if payment_plan_id column exists
    $has_plan_col = $db->query("SHOW COLUMNS FROM pledge_payments LIKE 'payment_plan_id'")->num_rows > 0;
    
    $stmt = $db->prepare("
        SELECT pp.*, p.amount AS pledge_amount
        FROM pledge_payments pp
        LEFT JOIN pledges p ON pp.pledge_id = p.id
        WHERE pp.id = ?
    ");
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    
    // If column doesn't exist, set payment_plan_id to null
    if (!$has_plan_col) {
        $payment['payment_plan_id'] = null;
    }
    
    if (!$payment) {
        throw new Exception('Payment not found');
    }
    
    if ($payment['status'] !== 'pending') {
        throw new Exception('Payment is not pending. Current status: ' . $payment['status']);
    }
    
    // 2. Update payment status to 'confirmed'
    $stmt = $db->prepare("
        UPDATE pledge_payments 
        SET 
            status = 'confirmed',
            approved_by_user_id = ?,
            approved_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('ii', $user_id, $payment_id);
    $stmt->execute();
    
    // 3. Update donor totals using centralized FinancialCalculator
    // Use approve-specific logic to set status to 'paying' when balance > 0
    require_once __DIR__ . '/../../shared/FinancialCalculator.php';
    
    $donor_id = (int)$payment['donor_id'];
    $calculator = new FinancialCalculator();
    
    if (!$calculator->recalculateDonorTotalsAfterApprove($donor_id)) {
        throw new Exception('Failed to update donor totals');
    }
    
    // Update last payment date
    $stmt = $db->prepare("UPDATE donors SET last_payment_date = NOW() WHERE id = ?");
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    
    // 4. Update Payment Plan if linked OR if donor has active plan with matching amount
    $payment_plan_id = null;
    $plan = null;
    
    // First, check if payment has payment_plan_id set
    if (isset($payment['payment_plan_id']) && $payment['payment_plan_id'] > 0) {
        $payment_plan_id = (int)$payment['payment_plan_id'];
    }
    
    // If no payment_plan_id, check if donor has active plan and payment matches monthly amount
    if (!$payment_plan_id) {
        $donor_check = $db->prepare("
            SELECT active_payment_plan_id FROM donors WHERE id = ? LIMIT 1
        ");
        $donor_check->bind_param('i', $donor_id);
        $donor_check->execute();
        $donor_data = $donor_check->get_result()->fetch_assoc();
        $donor_check->close();
        
        if ($donor_data && $donor_data['active_payment_plan_id']) {
            $potential_plan_id = (int)$donor_data['active_payment_plan_id'];
            
            // Fetch plan to check if amount matches
            $plan_check = $db->prepare("
                SELECT * FROM donor_payment_plans 
                WHERE id = ? AND donor_id = ? AND status = 'active'
                LIMIT 1
            ");
            $plan_check->bind_param('ii', $potential_plan_id, $donor_id);
            $plan_check->execute();
            $potential_plan = $plan_check->get_result()->fetch_assoc();
            $plan_check->close();
            
            // If payment amount matches monthly amount (within 0.01 tolerance), link it
            if ($potential_plan) {
                $monthly_amount = (float)($potential_plan['monthly_amount'] ?? 0);
                $payment_amount = (float)$payment['amount'];
                
                // Check if amounts match (allow small tolerance for rounding)
                if (abs($payment_amount - $monthly_amount) < 0.01) {
                    $payment_plan_id = $potential_plan_id;
                    $plan = $potential_plan;
                    
                    // Update the payment record to link it to the plan (if column exists)
                    if ($has_plan_col) {
                        $link_stmt = $db->prepare("
                            UPDATE pledge_payments 
                            SET payment_plan_id = ? 
                            WHERE id = ?
                        ");
                        $link_stmt->bind_param('ii', $payment_plan_id, $payment_id);
                        $link_stmt->execute();
                        $link_stmt->close();
                    }
                }
            }
        }
    }
    
    // If we have payment_plan_id but haven't fetched plan yet, fetch it
    if ($payment_plan_id && !$plan) {
        $plan_stmt = $db->prepare("
            SELECT * FROM donor_payment_plans 
            WHERE id = ? AND donor_id = ? AND status = 'active'
            LIMIT 1
        ");
        $plan_stmt->bind_param('ii', $payment_plan_id, $donor_id);
        $plan_stmt->execute();
        $plan = $plan_stmt->get_result()->fetch_assoc();
        $plan_stmt->close();
    }
        
        if ($plan) {
            $payment_amount = (float)$payment['amount'];
            $current_payments_made = (int)($plan['payments_made'] ?? 0);
            $current_amount_paid = (float)($plan['amount_paid'] ?? 0);
            $total_payments = (int)($plan['total_payments'] ?? $plan['total_months'] ?? 1);
            $monthly_amount = (float)($plan['monthly_amount'] ?? 0);
            
            // Calculate new values
            $new_payments_made = $current_payments_made + 1;
            $new_amount_paid = $current_amount_paid + $payment_amount;
            
            // Calculate next payment due date
            $next_payment_due = null;
            $plan_status = 'active';
            
            if ($new_payments_made >= $total_payments) {
                // Plan is completed
                $plan_status = 'completed';
            } else {
                // Calculate next payment date based on plan frequency
                $frequency_unit = $plan['plan_frequency_unit'] ?? 'month';
                $frequency_number = (int)($plan['plan_frequency_number'] ?? 1);
                $payment_day = (int)($plan['payment_day'] ?? 1);
                
                // Start from current next_payment_due date (the one being paid now)
                // If that's not set, use start_date
                $base_date = $plan['next_payment_due'] ?? $plan['start_date'];
                $next_date = new DateTime($base_date);
                
                // Add frequency period
                if ($frequency_unit === 'week') {
                    $next_date->modify("+{$frequency_number} weeks");
                } elseif ($frequency_unit === 'month') {
                    $next_date->modify("+{$frequency_number} months");
                    // Set payment day (1-28 to avoid month-end issues)
                    if ($payment_day >= 1 && $payment_day <= 28) {
                        $day_to_set = min($payment_day, (int)$next_date->format('t')); // Don't exceed days in month
                        $next_date->setDate((int)$next_date->format('Y'), (int)$next_date->format('m'), $day_to_set);
                    }
                } elseif ($frequency_unit === 'year') {
                    $next_date->modify("+{$frequency_number} years");
                    if ($payment_day >= 1 && $payment_day <= 28) {
                        $day_to_set = min($payment_day, (int)$next_date->format('t'));
                        $next_date->setDate((int)$next_date->format('Y'), (int)$next_date->format('m'), $day_to_set);
                    }
                }
                
                $next_payment_due = $next_date->format('Y-m-d');
            }
            
            // Update payment plan
            $update_plan = $db->prepare("
                UPDATE donor_payment_plans 
                SET 
                    payments_made = ?,
                    amount_paid = ?,
                    next_payment_due = ?,
                    last_payment_date = CURDATE(),
                    status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $update_plan->bind_param('idssi', $new_payments_made, $new_amount_paid, $next_payment_due, $plan_status, $payment_plan_id);
            $update_plan->execute();
            $update_plan->close();
            
            // If plan completed, update donor's active_payment_plan_id
            if ($plan_status === 'completed') {
                $update_donor = $db->prepare("
                    UPDATE donors 
                    SET has_active_plan = 0, 
                        active_payment_plan_id = NULL,
                        payment_status = CASE 
                            WHEN balance <= 0 THEN 'completed'
                            ELSE 'paying'
                        END
                    WHERE id = ? AND active_payment_plan_id = ?
                ");
                $update_donor->bind_param('ii', $donor_id, $payment_plan_id);
                $update_donor->execute();
                $update_donor->close();
            } else {
                // Update donor's next payment due date
                $update_donor = $db->prepare("
                    UPDATE donors 
                    SET plan_next_due_date = ?
                    WHERE id = ? AND active_payment_plan_id = ?
                ");
                $update_donor->bind_param('sii', $next_payment_due, $donor_id, $payment_plan_id);
                $update_donor->execute();
                $update_donor->close();
            }
        }
    }
    
    // 5. Check if pledge is fully paid
    $pledge_id = (int)$payment['pledge_id'];
    $sum_q = $db->prepare("SELECT SUM(amount) as total FROM pledge_payments WHERE pledge_id = ? AND status = 'confirmed'");
    $sum_q->bind_param('i', $pledge_id);
    $sum_q->execute();
    $paid_so_far = (float)$sum_q->get_result()->fetch_assoc()['total'];
    
    // If pledge is fully paid, potentially mark pledge as 'fulfilled' if that status exists
    // For now, we just rely on balance calculations
    
    // 6. Audit Log
    $log_json = json_encode([
        'action' => 'payment_approved',
        'payment_id' => $payment_id,
        'donor_id' => $donor_id,
        'pledge_id' => $pledge_id,
        'amount' => $payment['amount'],
        'approved_by' => $user_id,
        'approved_at' => date('Y-m-d H:i:s')
    ]);
    
    $log = $db->prepare("INSERT INTO audit_logs (user_id, entity_type, entity_id, action, after_json, source) VALUES (?, 'pledge_payment', ?, 'approve', ?, 'admin')");
    $log->bind_param('iis', $user_id, $payment_id, $log_json);
    $log->execute();
    
    $db->commit();
    
    $message = 'Payment approved and donor balance updated';
    if ($plan) {
        $message .= '. Payment plan updated: ' . ($plan_status === 'completed' ? 'Plan completed!' : 'Next payment due ' . date('d M Y', strtotime($next_payment_due)));
    }
    
    echo json_encode([
        'success' => true, 
        'message' => $message,
        'payment_id' => $payment_id,
        'plan_updated' => $plan ? true : false
    ]);
    
} catch (Exception $e) {
    if (isset($db)) $db->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

