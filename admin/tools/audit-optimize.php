<?php
/**
 * Audit Logs Optimization Script
 * Adds indexes for better search performance
 * Run this once to optimize the audit_logs table
 */
declare(strict_types=1);

require_once '../../config/db.php';
require_once '../../shared/auth.php';

require_login();
require_admin();

$db = db();
$results = [];

try {
    // Check if indexes already exist
    $checkIndex = $db->query("SHOW INDEX FROM audit_logs WHERE Key_name = 'idx_entity_action'")->fetch_all(MYSQLI_ASSOC);
    
    if (empty($checkIndex)) {
        // Add composite index on entity_type and action (most searched fields)
        $db->query("ALTER TABLE audit_logs ADD INDEX idx_entity_action (entity_type, action)");
        $results[] = ['status' => 'success', 'message' => 'Created index on entity_type and action'];
    } else {
        $results[] = ['status' => 'info', 'message' => 'Index idx_entity_action already exists'];
    }
    
    // Check if created_at index exists
    $checkDateIndex = $db->query("SHOW INDEX FROM audit_logs WHERE Key_name = 'idx_created_at'")->fetch_all(MYSQLI_ASSOC);
    
    if (empty($checkDateIndex)) {
        $db->query("ALTER TABLE audit_logs ADD INDEX idx_created_at (created_at)");
        $results[] = ['status' => 'success', 'message' => 'Created index on created_at'];
    } else {
        $results[] = ['status' => 'info', 'message' => 'Index idx_created_at already exists'];
    }
    
    // Check if user_id index exists
    $checkUserIndex = $db->query("SHOW INDEX FROM audit_logs WHERE Key_name = 'idx_user_id'")->fetch_all(MYSQLI_ASSOC);
    
    if (empty($checkUserIndex)) {
        $db->query("ALTER TABLE audit_logs ADD INDEX idx_user_id (user_id)");
        $results[] = ['status' => 'success', 'message' => 'Created index on user_id'];
    } else {
        $results[] = ['status' => 'info', 'message' => 'Index idx_user_id already exists'];
    }
    
    // Get table stats
    $stats = $db->query("SELECT 
        (SELECT COUNT(*) FROM audit_logs) as total_rows,
        ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'audit_logs'")->fetch_assoc();
    
    $results[] = ['status' => 'info', 'message' => "Table stats: {$stats['total_rows']} rows, {$stats['size_mb']} MB"];
    
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'results' => $results]);
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'results' => $results
    ]);
}
