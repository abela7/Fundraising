<?php
// admin/donations/get-donor-pledges.php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

try {
    require_login();
    $db = db();
    
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    if ($donor_id <= 0) throw new Exception("Invalid donor");
    
    // Check if pledge_payments exists
    $has_table = $db->query("SHOW TABLES LIKE 'pledge_payments'")->num_rows > 0;
    
    $query = "
        SELECT 
            p.id, 
            p.amount, 
            p.notes, 
            p.created_at,
            " . ($has_table ? "COALESCE(SUM(pp.amount), 0)" : "0") . " as paid
        FROM pledges p
        " . ($has_table ? "LEFT JOIN pledge_payments pp ON p.id = pp.pledge_id AND pp.status = 'confirmed'" : "") . "
        WHERE p.donor_id = ? AND p.status = 'approved'
        GROUP BY p.id
        HAVING (p.amount - paid) > 0.01
        ORDER BY p.created_at ASC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $pledges = [];
    while ($row = $res->fetch_assoc()) {
        $pledges[] = [
            'id' => $row['id'],
            'amount' => (float)$row['amount'],
            'paid' => (float)$row['paid'],
            'remaining' => (float)$row['amount'] - (float)$row['paid'],
            'date' => date('d M Y', strtotime($row['created_at'])),
            'notes' => $row['notes']
        ];
    }
    
    echo json_encode(['success' => true, 'pledges' => $pledges]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

