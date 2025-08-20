<?php
declare(strict_types=1);

/**
 * Fix Cell ID Mismatch
 * Clear all allocated cells and reset to available state
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/auth.php';

// Only allow admin access
require_login();
require_admin();

$db = db();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Fix Cell Mismatch</title>
    <link rel=\"stylesheet\" href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css\">
</head>
<body>
<div class=\"container mt-5\">
    <h2>🔧 Fix Cell ID Mismatch</h2>
    <p>This will reset all allocated cells back to 'available' state to fix the mismatch.</p>
";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->begin_transaction();
        
        // Reset all cells to available
        $result = $db->query("
            UPDATE floor_grid_cells 
            SET status = 'available', 
                pledge_id = NULL, 
                payment_id = NULL, 
                donor_name = NULL, 
                amount = NULL, 
                assigned_date = NULL 
            WHERE status != 'available'
        ");
        
        $affectedRows = $db->affected_rows;
        
        $db->commit();
        
        echo "<div class=\"alert alert-success\">";
        echo "<h4>✅ Reset Complete!</h4>";
        echo "<p>Reset {$affectedRows} cells back to available state.</p>";
        echo "</div>";
        
        // Show current stats
        $stats = $db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
                SUM(CASE WHEN status = 'pledged' THEN 1 ELSE 0 END) as pledged,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid
            FROM floor_grid_cells
        ")->fetch_assoc();
        
        echo "<div class=\"alert alert-info\">";
        echo "<h5>📊 Current Status:</h5>";
        echo "<ul>";
        echo "<li>Total cells: {$stats['total']}</li>";
        echo "<li>Available: {$stats['available']}</li>";
        echo "<li>Pledged: {$stats['pledged']}</li>";
        echo "<li>Paid: {$stats['paid']}</li>";
        echo "</ul>";
        echo "</div>";
        
    } catch (Exception $e) {
        $db->rollback();
        echo "<div class=\"alert alert-danger\">";
        echo "<h4>❌ Error:</h4>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "</div>";
    }
} else {
    // Show current allocated cells
    $allocatedCells = $db->query("
        SELECT cell_id, status, donor_name, amount, assigned_date 
        FROM floor_grid_cells 
        WHERE status != 'available' 
        ORDER BY assigned_date DESC
    ")->fetch_all(MYSQLI_ASSOC);
    
    if (!empty($allocatedCells)) {
        echo "<div class=\"alert alert-warning\">";
        echo "<h4>⚠️ Found {count($allocatedCells)} allocated cells:</h4>";
        echo "<table class=\"table table-sm\">";
        echo "<thead><tr><th>Cell ID</th><th>Status</th><th>Donor</th><th>Amount</th><th>Date</th></tr></thead>";
        echo "<tbody>";
        foreach ($allocatedCells as $cell) {
            echo "<tr>";
            echo "<td><code>{$cell['cell_id']}</code></td>";
            echo "<td><span class=\"badge bg-warning\">{$cell['status']}</span></td>";
            echo "<td>{$cell['donor_name']}</td>";
            echo "<td>£{$cell['amount']}</td>";
            echo "<td>{$cell['assigned_date']}</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
        echo "</div>";
        
        echo "<form method=\"post\">";
        echo "<button type=\"submit\" class=\"btn btn-danger\">🔄 Reset All Cells to Available</button>";
        echo "</form>";
    } else {
        echo "<div class=\"alert alert-success\">✅ No allocated cells found - everything is clean!</div>";
    }
}

echo "
    <div class=\"mt-4\">
        <a href=\"../\" class=\"btn btn-secondary\">← Back to Admin Tools</a>
        <a href=\"test_grid_allocation.php\" class=\"btn btn-primary\">Test New Allocation →</a>
        <a href=\"../../projector/floor/\" class=\"btn btn-success\" target=\"_blank\">View Floor Plan →</a>
    </div>
</div>
</body>
</html>";
?>
