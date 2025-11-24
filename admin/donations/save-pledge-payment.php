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
    
    // Validate Payment Proof Upload (but don't move file yet)
    if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Payment proof is required");
    }
    
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp', 'application/pdf'];
    $file_type = $_FILES['payment_proof']['type'];
    
    if (!in_array($file_type, $allowed_types)) {
        throw new Exception("Invalid file type. Only images (JPG, PNG, GIF, WEBP) and PDF allowed.");
    }
    
    if ($_FILES['payment_proof']['size'] > 5 * 1024 * 1024) { // 5MB max
        throw new Exception("File too large. Maximum 5MB allowed.");
    }
    
    $db = db();
    $db->begin_transaction();
    
    // 1. Verify Pledge Ownership & Status
    $pledge_q = $db->prepare("SELECT amount, donor_id, status FROM pledges WHERE id = ?");
    $pledge_q->bind_param('i', $pledge_id);
    $pledge_q->execute();
    $pledge = $pledge_q->get_result()->fetch_assoc();
    
    if (!$pledge) throw new Exception("Pledge #$pledge_id not found");
    if ($pledge['donor_id'] != $donor_id) throw new Exception("Pledge does not belong to this donor");
    if ($pledge['status'] === 'cancelled') throw new Exception("Cannot pay towards a cancelled pledge");
    
    // 2. NOW Upload the file (after validation, before final insert)
    // Create uploads directory if not exists
    $upload_dir = __DIR__ . '/../../uploads/payment_proofs/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate unique filename
    $ext = pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION);
    $filename = 'proof_' . time() . '_' . uniqid() . '.' . $ext;
    $filepath = $upload_dir . $filename;
    
    if (!move_uploaded_file($_FILES['payment_proof']['tmp_name'], $filepath)) {
        throw new Exception("Failed to upload payment proof");
    }
    
    $payment_proof = 'uploads/payment_proofs/' . $filename; // Relative path for DB
    
    // 3. Insert Payment with PENDING status (awaits approval)
    $stmt = $db->prepare("
        INSERT INTO pledge_payments 
        (pledge_id, donor_id, amount, payment_method, payment_date, reference_number, payment_proof, notes, processed_by_user_id, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param('iidsssssi', $pledge_id, $donor_id, $amount, $method, $payment_date, $reference, $payment_proof, $notes, $user_id);
    $stmt->execute();
    $payment_id = $db->insert_id;
    
    // 4. DO NOT UPDATE DONOR TOTALS YET
    // Totals will be updated only when payment is APPROVED by admin
    // This ensures financial integrity - no double-counting or premature reporting
    
    // 5. Audit Log
    $log_json = json_encode([
        'action' => 'pledge_payment_submitted',
        'amount' => $amount,
        'pledge_id' => $pledge_id,
        'donor_id' => $donor_id,
        'method' => $method,
        'payment_id' => $payment_id,
        'status' => 'pending',
        'proof_file' => $payment_proof
    ]);
    
    $log = $db->prepare("INSERT INTO audit_logs (user_id, entity_type, entity_id, action, after_json, source) VALUES (?, 'pledge_payment', ?, 'create', ?, 'admin')");
    $log->bind_param('iis', $user_id, $payment_id, $log_json);
    $log->execute();
    
    $db->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Payment submitted for approval. An admin will review it shortly.', 
        'id' => $payment_id,
        'status' => 'pending'
    ]);
    
} catch (Exception $e) {
    if (isset($db)) $db->rollback();
    
    // Cleanup: Delete uploaded file if transaction failed
    if (isset($filepath) && file_exists($filepath)) {
        unlink($filepath);
    }
    
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

