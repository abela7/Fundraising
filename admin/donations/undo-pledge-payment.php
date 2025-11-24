<?php
// admin/donations/undo-pledge-payment.php
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
    $reason = isset($input['reason']) ? trim($input['reason']) : '';
    $user_id = (int)$_SESSION['user']['id'];
    
    if ($payment_id <= 0) {
        throw new Exception('Invalid payment ID');
    }
    
    if (empty($reason)) {
        throw new Exception('Undo reason is required for audit trail');
    }
    
    $db = db();
    $db->begin_transaction();
    
    // 1. Fetch payment details
    // Check if payment_plan_id column exists
    $has_plan_col = $db->query("SHOW COLUMNS FROM pledge_payments LIKE 'payment_plan_id'")->num_rows > 0;
    
    $stmt = $db->prepare("SELECT * FROM pledge_payments WHERE id = ?");
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
    
    if ($payment['status'] !== 'confirmed') {
        throw new Exception('Only approved payments can be undone. Current status: ' . $payment['status']);
    }
    
    // 2. Store original state for audit
    $original_state = json_encode($payment);
    
    // 3. Update payment status to 'voided' (reversal)
    $stmt = $db->prepare("
        UPDATE pledge_payments 
        SET 
            status = 'voided',
            voided_by_user_id = ?,
            voided_at = NOW(),
            void_reason = ?
        WHERE id = ?
    ");
    $undo_reason = "UNDO: " . $reason;
    $stmt->bind_param('isi', $user_id, $undo_reason, $payment_id);
    $stmt->execute();
    
    // 4. REVERSE donor balance updates using centralized FinancialCalculator
    // Recalculate totals WITHOUT this payment (since it's now voided)
    // Use undo-specific logic to revert status to 'pending' if balance remains
    require_once __DIR__ . '/../../shared/FinancialCalculator.php';
    
    $donor_id = (int)$payment['donor_id'];
    $calculator = new FinancialCalculator();
    
    if (!$calculator->recalculateDonorTotalsAfterUndo($donor_id)) {
        throw new Exception('Failed to reverse donor totals');
    }
    
    // 5. REVERSE Payment Plan updates if payment was linked to a plan
    $payment_plan_id = null;
    if (isset($payment['payment_plan_id']) && $payment['payment_plan_id'] > 0) {
        $payment_plan_id = (int)$payment['payment_plan_id'];
        
        // Fetch payment plan details
        $plan_stmt = $db->prepare("
            SELECT * FROM donor_payment_plans 
            WHERE id = ? AND donor_id = ?
            LIMIT 1
        ");
        $plan_stmt->bind_param('ii', $payment_plan_id, $donor_id);
        $plan_stmt->execute();
        $plan = $plan_stmt->get_result()->fetch_assoc();
        $plan_stmt->close();
        
        if ($plan) {
            $payment_amount = (float)$payment['amount'];
            $current_payments_made = (int)($plan['payments_made'] ?? 0);
            $current_amount_paid = (float)($plan['amount_paid'] ?? 0);
            $total_payments = (int)($plan['total_payments'] ?? $plan['total_months'] ?? 1);
            
            // Reverse: decrement payments_made and subtract amount
            $new_payments_made = max(0, $current_payments_made - 1);
            $new_amount_paid = max(0, $current_amount_paid - $payment_amount);
            
            // Recalculate next payment due (go back one period)
            $next_payment_due = null;
            $plan_status = 'active';
            
            if ($new_payments_made >= $total_payments) {
                // Shouldn't happen, but handle edge case
                $plan_status = 'completed';
            } else {
                // Calculate previous payment date (one period before current next_payment_due)
                $frequency_unit = $plan['plan_frequency_unit'] ?? 'month';
                $frequency_number = (int)($plan['plan_frequency_number'] ?? 1);
                $payment_day = (int)($plan['payment_day'] ?? 1);
                
                // Use current next_payment_due as base, go back one period
                $base_date = $plan['next_payment_due'] ?? $plan['start_date'];
                $prev_date = new DateTime($base_date);
                
                // Subtract frequency period
                if ($frequency_unit === 'week') {
                    $prev_date->modify("-{$frequency_number} weeks");
                } elseif ($frequency_unit === 'month') {
                    $prev_date->modify("-{$frequency_number} months");
                    if ($payment_day >= 1 && $payment_day <= 28) {
                        $day_to_set = min($payment_day, (int)$prev_date->format('t'));
                        $prev_date->setDate((int)$prev_date->format('Y'), (int)$prev_date->format('m'), $day_to_set);
                    }
                } elseif ($frequency_unit === 'year') {
                    $prev_date->modify("-{$frequency_number} years");
                    if ($payment_day >= 1 && $payment_day <= 28) {
                        $day_to_set = min($payment_day, (int)$prev_date->format('t'));
                        $prev_date->setDate((int)$prev_date->format('Y'), (int)$prev_date->format('m'), $day_to_set);
                    }
                }
                
                $next_payment_due = $prev_date->format('Y-m-d');
            }
            
            // Update payment plan (reverse the changes)
            $update_plan = $db->prepare("
                UPDATE donor_payment_plans 
                SET 
                    payments_made = ?,
                    amount_paid = ?,
                    next_payment_due = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $update_plan->bind_param('idssi', $new_payments_made, $new_amount_paid, $next_payment_due, $plan_status, $payment_plan_id);
            $update_plan->execute();
            $update_plan->close();
            
            // If plan was completed and is now reactivated, update donor
            if ($plan['status'] === 'completed' && $plan_status === 'active') {
                $update_donor = $db->prepare("
                    UPDATE donors 
                    SET has_active_plan = 1, 
                        active_payment_plan_id = ?,
                        plan_next_due_date = ?,
                        payment_status = CASE 
                            WHEN balance <= 0 THEN 'completed'
                            ELSE 'paying'
                        END
                    WHERE id = ?
                ");
                $update_donor->bind_param('isi', $payment_plan_id, $next_payment_due, $donor_id);
                $update_donor->execute();
                $update_donor->close();
            } elseif ($plan_status === 'active') {
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
    
    // 6. Comprehensive Audit Log
    $log_json = json_encode([
        'action' => 'payment_undone',
        'payment_id' => $payment_id,
        'donor_id' => $donor_id,
        'pledge_id' => $payment['pledge_id'],
        'payment_plan_id' => $payment_plan_id > 0 ? $payment_plan_id : null,
        'amount' => $payment['amount'],
        'reason' => $reason,
        'undone_by' => $user_id,
        'undone_at' => date('Y-m-d H:i:s'),
        'original_state' => $original_state,
        'plan_reversed' => $payment_plan_id > 0,
        'warning' => 'This payment was previously approved and has now been reversed'
    ]);
    
    $log = $db->prepare("INSERT INTO audit_logs (user_id, entity_type, entity_id, action, after_json, source) VALUES (?, 'pledge_payment', ?, 'undo', ?, 'admin')");
    $log->bind_param('iis', $user_id, $payment_id, $log_json);
    $log->execute();
    
    $db->commit();
    
    $message = 'Payment undone and donor balance reversed';
    if ($payment_plan_id > 0) {
        $message .= '. Payment plan updates have been reversed.';
    }
    
    echo json_encode([
        'success' => true, 
        'message' => $message,
        'payment_id' => $payment_id,
        'plan_reversed' => $payment_plan_id > 0,
        'warning' => 'Financial totals and payment plan have been recalculated'
    ]);
    
} catch (Exception $e) {
    if (isset($db)) $db->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

