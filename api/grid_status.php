<?php
declare(strict_types=1);

/**
 * Floor Grid Status API
 * 
 * Returns the current status of all floor grid cells for real-time visualization
 * on the projector floor plan page.
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

// CORS headers for projector access
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../shared/FloorGridAllocatorV2.php';

try {
    $db = db();
    $gridAllocator = new FloorGridAllocatorV2($db);
    
    // Get request parameters
    $rectangle = $_GET['rectangle'] ?? null;
    $format = $_GET['format'] ?? 'detailed'; // 'detailed' or 'summary'
    
    $response = [
        'success' => true,
        'timestamp' => date('c'),
        'data' => []
    ];
    
    if ($format === 'summary') {
        // Return summary statistics only
        $response['data'] = $gridAllocator->getAllocationStats();
        
    } else {
        // Return detailed grid status
        $gridStatus = $gridAllocator->getGridStatus();
        
        // Filter by rectangle if specified
        if ($rectangle) {
            $gridStatus = array_filter($gridStatus, function($cell) use ($rectangle) {
                return $cell['rectangle_id'] === strtoupper($rectangle);
            });
        }
        
        // Group by rectangle for easier processing
        $groupedData = [];
        foreach ($gridStatus as $cell) {
            $rectId = $cell['rectangle_id'];
            if (!isset($groupedData[$rectId])) {
                $groupedData[$rectId] = [];
            }
            
            $groupedData[$rectId][] = [
                'cell_id' => $cell['cell_id'],
                'size' => $cell['cell_type'],
                'area' => (float)$cell['area_size'],
                'status' => $cell['status'],
                'donor' => $cell['donor_name'],
                'amount' => (float)$cell['amount'],
                'date' => $cell['assigned_date']
            ];
        }
        
        $response['data'] = [
            'grid_cells' => $groupedData,
            'statistics' => $gridAllocator->getAllocationStats()
        ];
    }
    
    // Add performance info
    $response['query_time'] = number_format((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . 'ms';
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('c')
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
?>
