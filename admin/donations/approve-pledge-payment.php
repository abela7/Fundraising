<?php
// admin/donations/approve-pledge-payment.php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

try {
    // Allow both admin and registrar access
    require_login();
    $current_user = current_user();
    if (!in_array($current_user['role'] ?? '', ['admin', 'registrar'])) {
        throw new Exception('Access denied. Admin or Registrar role required.');
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $payment_id = isset($input['id']) ? (int)$input['id'] : 0;
    $user_id = (int)$_SESSION['user']['id'];
    $user_name = $current_user['name'] ?? 'Unknown';
    $user_role = $current_user['role'] ?? 'unknown';
    
    if ($payment_id <= 0) {
        throw new Exception('Invalid payment ID');
    }
    
    $db = db();
    $db->begin_transaction();
    
    // 1. Fetch payment details with donor info
    // Check if payment_plan_id column exists
    $has_plan_col = $db->query("SHOW COLUMNS FROM pledge_payments LIKE 'payment_plan_id'")->num_rows > 0;
    
    $stmt = $db->prepare("
        SELECT pp.*, 
               p.amount AS pledge_amount,
               d.name AS donor_name,
               d.phone AS donor_phone,
               d.preferred_language AS donor_language,
               d.balance AS donor_balance,
               d.total_paid AS donor_total_paid,
               d.active_payment_plan_id AS donor_plan_id
        FROM pledge_payments pp
        LEFT JOIN pledges p ON pp.pledge_id = p.id
        LEFT JOIN donors d ON pp.donor_id = d.id
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
    
    // 4. Update Payment Plan ONLY if payment is explicitly linked to a plan
    // We do NOT auto-link payments - only update plan if payment_plan_id is set
    $payment_plan_id = null;
    $plan = null;
    $plan_status = null;
    $next_payment_due = null;
    
    // Check if payment has payment_plan_id set (explicitly linked to plan)
    if (isset($payment['payment_plan_id']) && $payment['payment_plan_id'] > 0) {
        $payment_plan_id = (int)$payment['payment_plan_id'];
        
        // Fetch the payment plan to verify it exists and is active
        $plan_stmt = $db->prepare("
            SELECT * FROM donor_payment_plans 
            WHERE id = ? AND donor_id = ? AND status = 'active'
            LIMIT 1
        ");
        $plan_stmt->bind_param('ii', $payment_plan_id, $donor_id);
        $plan_stmt->execute();
        $plan = $plan_stmt->get_result()->fetch_assoc();
        $plan_stmt->close();
        
        // Only proceed if plan exists and is active
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
                $base_date = $plan['next_payment_due'] ?? $plan['start_date'] ?? date('Y-m-d');
                
                try {
                    $next_date = new DateTime($base_date);
                } catch (Exception $date_error) {
                    // Fallback to today if date is invalid
                    $next_date = new DateTime();
                }
                
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
                // Update donor's next payment due date (if column exists)
                $has_donor_plan_col = $db->query("SHOW COLUMNS FROM donors LIKE 'plan_next_due_date'")->num_rows > 0;
                
                if ($has_donor_plan_col) {
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
    $beforeData = [
        'status' => 'pending',
        'amount' => $payment['amount'],
        'payment_method' => $payment['payment_method'] ?? null
    ];
    $afterData = [
        'status' => 'confirmed',
        'amount' => $payment['amount'],
        'payment_method' => $payment['payment_method'] ?? null,
        'pledge_id' => $pledge_id,
        'payment_plan_id' => $payment_plan_id > 0 ? $payment_plan_id : null,
        'plan_updated' => $plan ? true : false,
        'approved_by' => $user_name,
        'approved_by_role' => $user_role
    ];
    
    $source = ($user_role === 'registrar') ? 'registrar_portal' : 'admin_portal';
    log_audit(
        $db,
        'approve',
        'pledge_payment',
        $payment_id,
        $beforeData,
        $afterData,
        $source,
        $user_id
    );
    
    $db->commit();
    
    $message = 'Payment approved and donor balance updated';
    if ($plan && $plan_status) {
        if ($plan_status === 'completed') {
            $message .= '. Payment plan updated: Plan completed!';
        } elseif ($next_payment_due) {
            $message .= '. Payment plan updated: Next payment due ' . date('d M Y', strtotime($next_payment_due));
        } else {
            $message .= '. Payment plan updated.';
        }
    }
    
    // Fetch updated donor data for notification (including assigned agent info)
    $updatedDonorStmt = $db->prepare("
        SELECT d.*, 
               p.amount as pledge_amount,
               dpp.next_payment_due as plan_next_payment,
               dpp.monthly_amount as plan_amount,
               dpp.status as plan_status,
               agent.id as assigned_agent_id,
               agent.name as assigned_agent_name,
               agent.phone as assigned_agent_phone
        FROM donors d
        LEFT JOIN pledges p ON d.id = p.donor_id
        LEFT JOIN donor_payment_plans dpp ON d.active_payment_plan_id = dpp.id
        LEFT JOIN users agent ON d.agent_id = agent.id
        WHERE d.id = ?
        ORDER BY p.created_at DESC
        LIMIT 1
    ");
    $updatedDonorStmt->bind_param('i', $donor_id);
    $updatedDonorStmt->execute();
    $updatedDonor = $updatedDonorStmt->get_result()->fetch_assoc();
    $updatedDonorStmt->close();
    
    // Prepare notification data (including assigned agent for routing)
    $notificationData = [
        'donor_id' => $donor_id,
        'donor_name' => $payment['donor_name'] ?? 'Donor',
        'donor_phone' => $payment['donor_phone'] ?? '',
        'donor_language' => $payment['donor_language'] ?? 'en',
        'payment_amount' => number_format((float)$payment['amount'], 2),
        'payment_date' => date('l, j F Y'), // e.g., "Saturday, 14 December 2024"
        'total_pledge' => number_format((float)($updatedDonor['pledge_amount'] ?? 0), 2),
        'outstanding_balance' => number_format((float)($updatedDonor['balance'] ?? 0), 2),
        'has_plan' => !empty($updatedDonor['plan_next_payment']) && $updatedDonor['plan_status'] === 'active',
        'next_payment_date' => $updatedDonor['plan_next_payment'] 
            ? date('l, j F Y', strtotime($updatedDonor['plan_next_payment'])) 
            : null,
        'next_payment_amount' => $updatedDonor['plan_amount'] 
            ? number_format((float)$updatedDonor['plan_amount'], 2) 
            : null,
        // Assigned agent info for message routing
        'assigned_agent_id' => $updatedDonor['assigned_agent_id'] ?? null,
        'assigned_agent_name' => $updatedDonor['assigned_agent_name'] ?? null,
        'assigned_agent_phone' => $updatedDonor['assigned_agent_phone'] ?? null
    ];
    
    echo json_encode([
        'success' => true, 
        'message' => $message,
        'payment_id' => $payment_id,
        'plan_updated' => $plan ? true : false,
        'notification_data' => $notificationData
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->in_transaction) {
        $db->rollback();
    }
    http_response_code(500);
    error_log("Approve pledge payment error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage(),
        'error' => $e->getMessage()
    ]);
    exit;
}
?>

