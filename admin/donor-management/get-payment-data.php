<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

header('Content-Type: application/json');

$payment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$payment_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment ID']);
    exit;
}

try {
    $db = db();
    
    // Check columns
    $col_query = $db->query("SHOW COLUMNS FROM payments");
    $columns = [];
    while ($col = $col_query->fetch_assoc()) {
        $columns[] = $col['Field'];
    }
    
    $date_col = in_array('payment_date', $columns) ? 'payment_date' : 
               (in_array('received_at', $columns) ? 'received_at' : 'created_at');
    $method_col = in_array('payment_method', $columns) ? 'payment_method' : 'method';
    $ref_col = in_array('transaction_ref', $columns) ? 'transaction_ref' : 'reference';
    
    $stmt = $db->prepare("SELECT *, `{$date_col}` as date, `{$method_col}` as method, `{$ref_col}` as reference FROM payments WHERE id = ?");
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $payment = $result->fetch_assoc();
    
    if (!$payment) {
        echo json_encode(['success' => false, 'error' => 'Payment not found']);
        exit;
    }
    
    echo json_encode(['success' => true, 'payment' => $payment]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

