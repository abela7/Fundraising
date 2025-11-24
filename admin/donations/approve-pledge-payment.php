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
    $stmt = $db->prepare("
        SELECT pp.*, p.amount AS pledge_amount
        FROM pledge_payments pp
        LEFT JOIN pledges p ON pp.pledge_id = p.id
        WHERE pp.id = ?
    ");
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    
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
    
    // 4. Check if pledge is fully paid
    $pledge_id = (int)$payment['pledge_id'];
    $sum_q = $db->prepare("SELECT SUM(amount) as total FROM pledge_payments WHERE pledge_id = ? AND status = 'confirmed'");
    $sum_q->bind_param('i', $pledge_id);
    $sum_q->execute();
    $paid_so_far = (float)$sum_q->get_result()->fetch_assoc()['total'];
    
    // If pledge is fully paid, potentially mark pledge as 'fulfilled' if that status exists
    // For now, we just rely on balance calculations
    
    // 5. Audit Log
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
    
    echo json_encode([
        'success' => true, 
        'message' => 'Payment approved and donor balance updated',
        'payment_id' => $payment_id
    ]);
    
} catch (Exception $e) {
    if (isset($db)) $db->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

