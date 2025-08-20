<?php
declare(strict_types=1);

/**
 * Fix Floor Area Allocations Table
 * Add missing cell_ids column if it doesn't exist
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
    <title>Fix Allocation Table</title>
    <link rel=\"stylesheet\" href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css\">
</head>
<body>
<div class=\"container mt-5\">
    <h2>Fix Floor Area Allocations Table</h2>
";

try {
    // Check if cell_ids column exists
    $result = $db->query("SHOW COLUMNS FROM floor_area_allocations LIKE 'cell_ids'");
    
    if ($result->num_rows === 0) {
        echo "<div class=\"alert alert-warning\">cell_ids column not found. Adding it now...</div>";
        
        $db->query("ALTER TABLE floor_area_allocations 
                    ADD COLUMN cell_ids JSON DEFAULT NULL 
                    COMMENT 'Array of actual cell IDs allocated (e.g., [\"A0101-01\", \"A0505-12\"])' 
                    AFTER grid_cells");
        
        echo "<div class=\"alert alert-success\">✅ cell_ids column added successfully!</div>";
    } else {
        echo "<div class=\"alert alert-info\">✅ cell_ids column already exists.</div>";
    }
    
    // Show current table structure
    echo "<h4>Current Table Structure:</h4>";
    $columns = $db->query("SHOW COLUMNS FROM floor_area_allocations")->fetch_all(MYSQLI_ASSOC);
    
    echo "<table class=\"table table-striped\">";
    echo "<thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>";
    echo "<tbody>";
    
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>{$col['Default']}</td>";
        echo "<td>{$col['Extra']}</td>";
        echo "</tr>";
    }
    
    echo "</tbody></table>";
    
} catch (Exception $e) {
    echo "<div class=\"alert alert-danger\">";
    echo "<h4>❌ Error occurred:</h4>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "
    <div class=\"mt-4\">
        <a href=\"../\" class=\"btn btn-secondary\">← Back to Admin Tools</a>
        <a href=\"test_grid_allocation.php\" class=\"btn btn-primary\">Test Grid System →</a>
    </div>
</div>
</body>
</html>";
?>
