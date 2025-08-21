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
require_once __DIR__ . '/../shared/IntelligentGridAllocator.php';

try {
    $db = db();
    $gridAllocator = new IntelligentGridAllocator($db);
    
    // Get request parameters
    $format = $_GET['format'] ?? 'detailed'; // 'detailed' or 'summary'
    
    $response = [
        'success' => true,
        'timestamp' => date('c'),
        'data' => []
    ];
    
    if ($format === 'summary') {
        // Return summary statistics only, focusing on the smallest cell unit
        $stats = $gridAllocator->getAllocationStats();
        $total_area = (float)($stats['total_possible_area'] ?? 0);
        $allocated_area = (float)($stats['total_allocated_area'] ?? 0);
        $progress_percentage = ($total_area > 0) ? ($allocated_area / $total_area) * 100 : 0;

        $response['data'] = [
            'statistics' => [
                'total_cells' => (int)($stats['total_cells'] ?? 0),
                'pledged_cells' => (int)($stats['pledged_cells'] ?? 0),
                'paid_cells' => (int)($stats['paid_cells'] ?? 0),
                'available_cells' => (int)($stats['available_cells'] ?? 0),
                'total_area_sqm' => $total_area,
                'allocated_area_sqm' => $allocated_area,
                'progress_percentage' => round($progress_percentage, 2)
            ]
        ];
        
    } else {
        // Return detailed grid status, grouped by rectangle for the frontend
        $gridStatus = $gridAllocator->getGridStatus();
        $groupedData = [];
        foreach ($gridStatus as $cell) {
            $rectId = $cell['rectangle_id'];
            if (!isset($groupedData[$rectId])) {
                $groupedData[$rectId] = [];
            }
            // The frontend expects a specific structure, let's match it.
            $groupedData[$rectId][] = [
                'cell_id' => $cell['cell_id'],
                'status'  => $cell['status'],
                'donor'   => $cell['donor_name'],
                'amount'  => (float)$cell['amount']
            ];
        }
        
        // Always include both grid data and summary statistics for live updates
        $stats = $gridAllocator->getAllocationStats();
        $total_area = (float)($stats['total_possible_area'] ?? 0);
        $allocated_area = (float)($stats['total_allocated_area'] ?? 0);
        $progress_percentage = ($total_area > 0) ? ($allocated_area / $total_area) * 100 : 0;

        $response['data'] = [
            'grid_cells' => $groupedData,
            'summary' => [
                'total_cells' => (int)($stats['total_cells'] ?? 0),
                'pledged_cells' => (int)($stats['pledged_cells'] ?? 0),
                'paid_cells' => (int)($stats['paid_cells'] ?? 0),
                'available_cells' => (int)($stats['available_cells'] ?? 0),
                'total_area_sqm' => $total_area,
                'allocated_area_sqm' => $allocated_area,
                'progress_percentage' => round($progress_percentage, 2)
            ]
        ];
    }

} catch (Exception $e) {
    http_response_code(500);
    $response = [
        'success' => false,
        'error' => 'An internal server error occurred: ' . $e->getMessage(),
        'timestamp' => date('c'),
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>
