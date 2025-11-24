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
    $stmt = $db->prepare("SELECT * FROM pledge_payments WHERE id = ?");
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    
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
    
    // 4. REVERSE donor balance updates (THE CRITICAL REVERSAL)
    // Recalculate totals WITHOUT this payment
    $donor_id = (int)$payment['donor_id'];
    $update_donor = $db->prepare("
        UPDATE donors d
        SET 
            d.total_paid = (
                COALESCE((SELECT SUM(amount) FROM payments WHERE donor_id = d.id AND status = 'approved'), 0) + 
                COALESCE((SELECT SUM(amount) FROM pledge_payments WHERE donor_id = d.id AND status = 'confirmed'), 0)
            ),
            d.balance = (
                d.total_pledged - 
                COALESCE((SELECT SUM(amount) FROM pledge_payments WHERE donor_id = d.id AND status = 'confirmed'), 0)
            ),
            d.payment_status = CASE
                WHEN d.total_pledged > 0 AND (d.total_pledged - COALESCE((SELECT SUM(amount) FROM pledge_payments WHERE donor_id = d.id AND status = 'confirmed'), 0)) > 0.01 
                THEN 'pending'
                WHEN (d.total_pledged - COALESCE((SELECT SUM(amount) FROM pledge_payments WHERE donor_id = d.id AND status = 'confirmed'), 0)) <= 0.01 
                THEN 'completed'
                ELSE 'pending'
            END
        WHERE d.id = ?
    ");
    $update_donor->bind_param('i', $donor_id);
    $update_donor->execute();
    
    // 5. Comprehensive Audit Log
    $log_json = json_encode([
        'action' => 'payment_undone',
        'payment_id' => $payment_id,
        'donor_id' => $donor_id,
        'pledge_id' => $payment['pledge_id'],
        'amount' => $payment['amount'],
        'reason' => $reason,
        'undone_by' => $user_id,
        'undone_at' => date('Y-m-d H:i:s'),
        'original_state' => $original_state,
        'warning' => 'This payment was previously approved and has now been reversed'
    ]);
    
    $log = $db->prepare("INSERT INTO audit_logs (user_id, entity_type, entity_id, action, after_json, source) VALUES (?, 'pledge_payment', ?, 'undo', ?, 'admin')");
    $log->bind_param('iis', $user_id, $payment_id, $log_json);
    $log->execute();
    
    $db->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Payment undone and donor balance reversed',
        'payment_id' => $payment_id,
        'warning' => 'Financial totals have been recalculated'
    ]);
    
} catch (Exception $e) {
    if (isset($db)) $db->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

