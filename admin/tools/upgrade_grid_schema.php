<?php
declare(strict_types=1);

/**
 * Upgrade Grid Schema for Smart Overlap Management
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/auth.php';

require_login();
require_admin();

$db = db();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Upgrade Grid Schema</title>
    <link rel=\"stylesheet\" href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css\">
</head>
<body>
<div class=\"container mt-5\">
    <h2>🔧 Upgrade Grid Schema for Smart Overlap Management</h2>
";

try {
    $db->begin_transaction();
    
    // Add 'blocked' status to enum
    echo "<div class=\"alert alert-info\">Adding 'blocked' status for overlap management...</div>";
    
    $db->query("ALTER TABLE floor_grid_cells MODIFY COLUMN status ENUM('available','pledged','paid','blocked') DEFAULT 'available'");
    
    // Reset any existing allocations to start fresh
    echo "<div class=\"alert alert-warning\">Resetting all allocations for clean start...</div>";
    
    $resetResult = $db->query("
        UPDATE floor_grid_cells 
        SET status = 'available', 
            pledge_id = NULL, 
            payment_id = NULL, 
            donor_name = NULL, 
            amount = NULL, 
            assigned_date = NULL 
        WHERE status != 'available'
    ");
    
    $resetCount = $db->affected_rows;
    
    $db->commit();
    
    echo "<div class=\"alert alert-success\">";
    echo "<h4>✅ Schema Upgrade Complete!</h4>";
    echo "<ul>";
    echo "<li>Added 'blocked' status for overlap management</li>";
    echo "<li>Reset {$resetCount} cells to available state</li>";
    echo "<li>System ready for smart allocation</li>";
    echo "</ul>";
    echo "</div>";
    
    // Show updated schema
    $columns = $db->query("SHOW COLUMNS FROM floor_grid_cells WHERE Field = 'status'")->fetch_assoc();
    
    echo "<div class=\"alert alert-info\">";
    echo "<h5>📋 Updated Schema:</h5>";
    echo "<p><strong>Status Column:</strong> {$columns['Type']}</p>";
    echo "<p><strong>Default:</strong> {$columns['Default']}</p>";
    echo "</div>";
    
} catch (Exception $e) {
    $db->rollback();
    echo "<div class=\"alert alert-danger\">";
    echo "<h4>❌ Error:</h4>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "
    <div class=\"mt-4\">
        <a href=\"../\" class=\"btn btn-secondary\">← Back to Admin Tools</a>
        <a href=\"test_smart_allocation.php\" class=\"btn btn-primary\">Test Smart System →</a>
    </div>
</div>
</body>
</html>";
?>
