<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config/db.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get grid status data
    $query = "
        SELECT 
            gc.row_position,
            gc.col_position,
            gc.status,
            gc.donor_id,
            gc.amount,
            gc.created_at,
            gc.updated_at,
            d.name as donor_name,
            d.email as donor_email
        FROM grid_cells gc
        LEFT JOIN donors d ON gc.donor_id = d.id
        ORDER BY gc.row_position, gc.col_position
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $cells = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get summary statistics
    $statsQuery = "
        SELECT 
            COUNT(*) as total_cells,
            SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied_cells,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_cells,
            SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) as reserved_cells,
            SUM(CASE WHEN status = 'premium' THEN 1 ELSE 0 END) as premium_cells,
            SUM(COALESCE(amount, 0)) as total_revenue
        FROM grid_cells
    ";
    
    $statsStmt = $pdo->prepare($statsQuery);
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Format the response
    $response = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'total_cells' => (int)$stats['total_cells'],
        'occupied_cells' => (int)$stats['occupied_cells'],
        'available_cells' => (int)$stats['available_cells'],
        'reserved_cells' => (int)$stats['reserved_cells'],
        'premium_cells' => (int)$stats['premium_cells'],
        'total_revenue' => (float)$stats['total_revenue'],
        'cells' => []
    ];
    
    foreach ($cells as $cell) {
        $response['cells'][] = [
            'row' => (int)$cell['row_position'],
            'col' => (int)$cell['col_position'],
            'status' => $cell['status'] ?: 'available',
            'donor' => $cell['donor_name'] ?: null,
            'donor_email' => $cell['donor_email'] ?: null,
            'amount' => (float)($cell['amount'] ?: 0),
            'created_at' => $cell['created_at'],
            'updated_at' => $cell['updated_at']
        ];
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
}
?>
