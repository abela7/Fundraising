<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/IntelligentGridAllocator.php';
require_login();
require_admin();

header('Content-Type: application/json');

$db = db();
$gridAllocator = new IntelligentGridAllocator($db);

try {
    // Get current grid status
    $gridStatus = $gridAllocator->getGridStatus();
    $stats = $gridAllocator->getAllocationStats();
    
    // Group by rectangle for frontend
    $groupedData = [];
    foreach ($gridStatus as $cell) {
        $rectId = $cell['rectangle_id'];
        if (!isset($groupedData[$rectId])) {
            $groupedData[$rectId] = [];
        }
        $groupedData[$rectId][] = [
            'cell_id' => $cell['cell_id'],
            'status'  => $cell['status'],
            'donor'   => $cell['donor_name'],
            'amount'  => (float)$cell['amount']
        ];
    }
    
    $total_area = (float)($stats['total_possible_area'] ?? 0);
    $allocated_area = (float)($stats['total_allocated_area'] ?? 0);
    $progress_percentage = ($total_area > 0) ? ($allocated_area / $total_area) * 100 : 0;
    
    echo json_encode([
        'success' => true,
        'data' => [
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
        ],
        'timestamp' => date('Y-m-d H:i:s'),
        'force_refresh' => true
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>
