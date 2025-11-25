<?php
// admin/donations/void-pledge-payment.php
require_once __DIR__ . '/../../shared/auth.php';
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
    $reason = isset($input['reason']) ? trim($input['reason']) : '';
    $user_id = (int)$_SESSION['user']['id'];
    $user_name = $current_user['name'] ?? 'Unknown';
    $user_role = $current_user['role'] ?? 'unknown';
    
    if ($payment_id <= 0) {
        throw new Exception('Invalid payment ID');
    }
    
    if (empty($reason)) {
        throw new Exception('Rejection reason is required');
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
    
    if ($payment['status'] !== 'pending') {
        throw new Exception('Only pending payments can be rejected. Current status: ' . $payment['status']);
    }
    
    // 2. Update payment status to 'voided'
    $stmt = $db->prepare("
        UPDATE pledge_payments 
        SET 
            status = 'voided',
            voided_by_user_id = ?,
            voided_at = NOW(),
            void_reason = ?
        WHERE id = ?
    ");
    $stmt->bind_param('isi', $user_id, $reason, $payment_id);
    $stmt->execute();
    
    // 3. NO donor balance updates needed (payment was never confirmed)
    
    // 4. Audit Log - Enhanced with user details
    $log_json = json_encode([
        'action' => 'payment_rejected',
        'payment_id' => $payment_id,
        'donor_id' => $payment['donor_id'],
        'pledge_id' => $payment['pledge_id'],
        'amount' => $payment['amount'],
        'payment_method' => $payment['payment_method'] ?? null,
        'reason' => $reason,
        'rejected_by_user_id' => $user_id,
        'rejected_by_name' => $user_name,
        'rejected_by_role' => $user_role,
        'rejected_at' => date('Y-m-d H:i:s'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
    ]);
    
    $source = ($user_role === 'registrar') ? 'registrar' : 'admin';
    $log = $db->prepare("INSERT INTO audit_logs (user_id, entity_type, entity_id, action, after_json, source) VALUES (?, 'pledge_payment', ?, 'reject', ?, ?)");
    $log->bind_param('iiss', $user_id, $payment_id, $log_json, $source);
    $log->execute();
    
    $db->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Payment rejected',
        'payment_id' => $payment_id
    ]);
    
} catch (Exception $e) {
    if (isset($db)) $db->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

