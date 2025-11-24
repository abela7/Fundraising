<?php
// admin/donations/save-pledge-payment.php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

try {
    require_login();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $db = db();
    
    // Check if table exists (Safety check)
    $check = $db->query("SHOW TABLES LIKE 'pledge_payments'");
    if ($check->num_rows === 0) {
        throw new Exception("Database table 'pledge_payments' missing. Please run the migration SQL.");
    }
    
    // Get Input
    $donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;
    $pledge_id = isset($_POST['pledge_id']) ? (int)$_POST['pledge_id'] : 0;
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $payment_date = isset($_POST['payment_date']) ? $_POST['payment_date'] : date('Y-m-d');
    $method = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'cash';
    $reference = isset($_POST['reference_number']) ? trim($_POST['reference_number']) : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    $user_id = (int)$_SESSION['user']['id'];
    
    // Validate
    if ($donor_id <= 0) throw new Exception("Invalid donor");
    if ($pledge_id <= 0) throw new Exception("Invalid pledge");
    if ($amount <= 0) throw new Exception("Amount must be greater than 0");
    
    $db->begin_transaction();
    
    // 1. Verify Pledge Ownership & Status
    $pledge_q = $db->prepare("SELECT amount, donor_id, status FROM pledges WHERE id = ?");
    $pledge_q->bind_param('i', $pledge_id);
    $pledge_q->execute();
    $pledge = $pledge_q->get_result()->fetch_assoc();
    
    if (!$pledge) throw new Exception("Pledge #$pledge_id not found");
    if ($pledge['donor_id'] != $donor_id) throw new Exception("Pledge does not belong to this donor");
    if ($pledge['status'] === 'cancelled') throw new Exception("Cannot pay towards a cancelled pledge");
    
    // 2. Insert Payment
    $stmt = $db->prepare("
        INSERT INTO pledge_payments 
        (pledge_id, donor_id, amount, payment_method, payment_date, reference_number, notes, processed_by_user_id, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed')
    ");
    $stmt->bind_param('iidsssis', $pledge_id, $donor_id, $amount, $method, $payment_date, $reference, $notes, $user_id);
    $stmt->execute();
    $payment_id = $db->insert_id;
    
    // 3. Update Donor Totals (The Smart Logic)
    // total_paid = SUM(payments) + SUM(pledge_payments)
    // balance = total_pledged - SUM(pledge_payments)
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
            d.last_payment_date = NOW(),
            d.payment_status = 'paying'
        WHERE d.id = ?
    ");
    $update_donor->bind_param('i', $donor_id);
    $update_donor->execute();
    
    // 4. Check Pledge Full Fulfillment
    // Calculate total paid for THIS pledge
    $sum_q = $db->prepare("SELECT SUM(amount) as total FROM pledge_payments WHERE pledge_id = ? AND status = 'confirmed'");
    $sum_q->bind_param('i', $pledge_id);
    $sum_q->execute();
    $paid_so_far = (float)$sum_q->get_result()->fetch_assoc()['total'];
    
    // Update pledge status if fully paid
    // Use a small epsilon for float comparison safety
    if ($paid_so_far >= ((float)$pledge['amount'] - 0.01)) {
        // Mark pledge as completed/paid? 
        // The enum in DB is 'pending','approved','rejected','cancelled'. 
        // It doesn't have 'completed' or 'paid'.
        // Maybe we should just leave it as 'approved' but update donor balance (which is done).
        // Or maybe we should add 'fulfilled' to the enum?
        // For now, we rely on the balance being 0.
        
        // Update donor payment_status to 'completed' if balance is 0
        // Check if donor has ANY balance left
        $bal_q = $db->prepare("SELECT balance FROM donors WHERE id = ?");
        $bal_q->bind_param('i', $donor_id);
        $bal_q->execute();
        $d_bal = (float)$bal_q->get_result()->fetch_assoc()['balance'];
        
        if ($d_bal <= 0.01) {
            $db->query("UPDATE donors SET payment_status = 'completed' WHERE id = $donor_id");
        }
    }
    
    // 5. Audit Log
    $log_json = json_encode([
        'action' => 'pledge_payment',
        'amount' => $amount,
        'pledge_id' => $pledge_id,
        'method' => $method,
        'payment_id' => $payment_id
    ]);
    
    $log = $db->prepare("INSERT INTO audit_logs (user_id, entity_type, entity_id, action, after_json, source) VALUES (?, 'pledge_payment', ?, 'create', ?, 'admin')");
    $log->bind_param('iis', $user_id, $payment_id, $log_json);
    $log->execute();
    
    $db->commit();
    
    echo json_encode(['success' => true, 'message' => 'Payment recorded successfully', 'id' => $payment_id]);
    
} catch (Exception $e) {
    if (isset($db)) $db->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

