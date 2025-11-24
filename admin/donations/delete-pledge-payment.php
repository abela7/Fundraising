<?php
declare(strict_types=1);
// admin/donations/delete-pledge-payment.php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

try {
    require_admin();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $payment_id = isset($input['payment_id']) ? (int)$input['payment_id'] : 0;
    $donor_id = isset($input['donor_id']) ? (int)$input['donor_id'] : 0;
    $user_id = (int)$_SESSION['user']['id'];
    
    if ($payment_id <= 0) {
        throw new Exception('Invalid payment ID');
    }
    
    if ($donor_id <= 0) {
        throw new Exception('Invalid donor ID');
    }
    
    $db = db();
    
    // Check if pledge_payments table exists
    $check = $db->query("SHOW TABLES LIKE 'pledge_payments'");
    if ($check->num_rows === 0) {
        throw new Exception('Pledge payments table not found');
    }
    
    $db->begin_transaction();
    
    // 1. Fetch payment details and verify it exists
    $stmt = $db->prepare("
        SELECT pp.*, d.name as donor_name 
        FROM pledge_payments pp
        LEFT JOIN donors d ON pp.donor_id = d.id
        WHERE pp.id = ? AND pp.donor_id = ?
    ");
    $stmt->bind_param('ii', $payment_id, $donor_id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    
    if (!$payment) {
        throw new Exception('Payment not found or does not belong to this donor');
    }
    
    // 2. CRITICAL VALIDATION: Only allow deletion of VOIDED payments
    if ($payment['status'] !== 'voided') {
        throw new Exception('Cannot delete active payments. Only voided payments can be deleted. Current status: ' . $payment['status']);
    }
    
    // 3. Store payment details for audit log
    $payment_details = json_encode([
        'payment_id' => $payment_id,
        'donor_id' => $donor_id,
        'donor_name' => $payment['donor_name'],
        'pledge_id' => $payment['pledge_id'],
        'amount' => $payment['amount'],
        'payment_method' => $payment['payment_method'],
        'payment_date' => $payment['payment_date'],
        'reference_number' => $payment['reference_number'],
        'status' => $payment['status'],
        'void_reason' => $payment['void_reason'],
        'voided_at' => $payment['voided_at'],
        'voided_by_user_id' => $payment['voided_by_user_id'],
        'deleted_by' => $user_id,
        'deleted_at' => date('Y-m-d H:i:s'),
        'warning' => 'PERMANENT DELETION - Payment record completely removed'
    ]);
    
    // 4. Delete payment proof file if exists
    if (!empty($payment['payment_proof']) && file_exists(__DIR__ . '/../../' . $payment['payment_proof'])) {
        unlink(__DIR__ . '/../../' . $payment['payment_proof']);
    }
    
    // 5. Delete the payment record
    $stmt = $db->prepare("DELETE FROM pledge_payments WHERE id = ? AND status = 'voided'");
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        throw new Exception('Failed to delete payment. Payment may have been modified.');
    }
    
    // 6. Comprehensive Audit Log
    $log_json = json_encode([
        'action' => 'pledge_payment_deleted',
        'reason' => 'Permanent deletion of voided payment record',
        'payment_data' => $payment_details,
        'deleted_by' => $user_id,
        'deleted_at' => date('Y-m-d H:i:s'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'warning' => 'This is a PERMANENT deletion - record cannot be recovered'
    ]);
    
    $log = $db->prepare("
        INSERT INTO audit_logs 
        (user_id, entity_type, entity_id, action, after_json, source) 
        VALUES (?, 'pledge_payment', ?, 'delete', ?, 'admin')
    ");
    $log->bind_param('iis', $user_id, $payment_id, $log_json);
    $log->execute();
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Voided payment deleted permanently',
        'payment_id' => $payment_id,
        'amount' => $payment['amount']
    ]);
    
} catch (Exception $e) {
    if (isset($db)) $db->rollback();
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>

