<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/db.php';

try {
    $db = db();
    
    // Test basic database connection
    $testQuery = $db->query("SELECT COUNT(*) as total FROM floor_grid_cells");
    $totalCells = $testQuery->fetch_assoc()['total'];
    
    // Get sample allocations
    $allocationsQuery = $db->query("SELECT * FROM floor_grid_cells WHERE status IN ('pledged', 'paid') LIMIT 5");
    $allocations = $allocationsQuery->fetch_all(MYSQLI_ASSOC);
    
    // Get schema info
    $schemaQuery = $db->query("SHOW COLUMNS FROM floor_grid_cells WHERE Field = 'status'");
    $statusColumn = $schemaQuery->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'debug_info' => [
            'total_cells' => $totalCells,
            'sample_allocations' => $allocations,
            'status_column_type' => $statusColumn['Type'] ?? 'unknown',
            'current_time' => date('Y-m-d H:i:s')
        ],
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'php_version' => PHP_VERSION,
            'mysql_available' => extension_loaded('mysqli'),
            'file_path' => __FILE__
        ],
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);
}
?>
