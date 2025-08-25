<?php
declare(strict_types=1);

/**
 * Get System Stats for AJAX Updates
 * Returns current counters and floor allocation stats as JSON
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

try {
    require_once '../../config/db.php';
    require_once '../../shared/auth.php';

    // Check authentication
    $user = current_user();
    if (!$user || !in_array($user['role'], ['admin', 'registrar'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $db = db();

    // Get current counter totals
    $counters = $db->query("SELECT paid_total, pledged_total, grand_total, version FROM counters WHERE id = 1")->fetch_assoc();
    
    // Get custom amount tracking
    $customTracking = $db->query("SELECT total_amount, allocated_amount, remaining_amount FROM custom_amount_tracking WHERE id = 1")->fetch_assoc();
    
    // Get grid statistics
    $gridStats = $db->query("
        SELECT 
            COUNT(*) as total_cells,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_cells,
            SUM(CASE WHEN status = 'pledged' THEN 1 ELSE 0 END) as pledged_cells,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_cells
        FROM floor_grid_cells 
        WHERE cell_type = '0.5x0.5'
    ")->fetch_assoc();

    // Return stats as JSON
    echo json_encode([
        'paid_total' => (float)($counters['paid_total'] ?? 0),
        'pledged_total' => (float)($counters['pledged_total'] ?? 0),
        'grand_total' => (float)($counters['grand_total'] ?? 0),
        'version' => (int)($counters['version'] ?? 0),
        'total_amount' => (float)($customTracking['total_amount'] ?? 0),
        'allocated_amount' => (float)($customTracking['allocated_amount'] ?? 0),
        'remaining_amount' => (float)($customTracking['remaining_amount'] ?? 0),
        'total_cells' => (int)($gridStats['total_cells'] ?? 0),
        'available_cells' => (int)($gridStats['available_cells'] ?? 0),
        'pledged_cells' => (int)($gridStats['pledged_cells'] ?? 0),
        'paid_cells' => (int)($gridStats['paid_cells'] ?? 0),
        'allocated_cells' => (int)(($gridStats['pledged_cells'] ?? 0) + ($gridStats['paid_cells'] ?? 0)),
        'timestamp' => time()
    ]);

} catch (Throwable $e) {
    error_log("Get Stats Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>
