<?php
declare(strict_types=1);

/**
 * Analyze Grid Totals
 * Check why we have 3,591 cells instead of expected 2,052
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
    <title>Analyze Grid Totals</title>
    <link rel=\"stylesheet\" href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css\">
</head>
<body>
<div class=\"container mt-5\">
    <h2>📊 Grid Totals Analysis</h2>
";

try {
    // Count by cell type and rectangle
    $analysis = $db->query("
        SELECT 
            rectangle_id,
            cell_type,
            COUNT(*) as cell_count,
            SUM(area_size) as total_area
        FROM floor_grid_cells 
        GROUP BY rectangle_id, cell_type
        ORDER BY rectangle_id, cell_type
    ")->fetch_all(MYSQLI_ASSOC);
    
    echo "<table class=\"table table-striped\">";
    echo "<thead><tr><th>Rectangle</th><th>Cell Type</th><th>Cell Count</th><th>Total Area (m²)</th><th>Expected for 513m²</th></tr></thead>";
    echo "<tbody>";
    
    $grandTotal = 0;
    $grandArea = 0;
    
    foreach ($analysis as $row) {
        $grandTotal += $row['cell_count'];
        $grandArea += $row['total_area'];
        
        // Calculate what would be expected for 513m² total
        $areaPerCell = $row['total_area'] / $row['cell_count'];
        $expectedForTarget = 513 * ($row['total_area'] / $grandArea);
        
        echo "<tr>";
        echo "<td><strong>{$row['rectangle_id']}</strong></td>";
        echo "<td>{$row['cell_type']}</td>";
        echo "<td>{$row['cell_count']}</td>";
        echo "<td>" . number_format($row['total_area'], 2) . "</td>";
        echo "<td>" . number_format($expectedForTarget, 2) . "</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "<tfoot>";
    echo "<tr class=\"table-warning\"><td colspan=\"2\"><strong>TOTAL</strong></td><td><strong>{$grandTotal}</strong></td><td><strong>" . number_format($grandArea, 2) . " m²</strong></td><td><strong>513 m² (target)</strong></td></tr>";
    echo "</tfoot>";
    echo "</table>";
    
    // Summary analysis
    echo "<div class=\"row mt-4\">";
    echo "<div class=\"col-md-6\">";
    echo "<div class=\"card\">";
    echo "<div class=\"card-header\"><h5>📈 Current Status</h5></div>";
    echo "<div class=\"card-body\">";
    echo "<ul>";
    echo "<li><strong>Total cells:</strong> {$grandTotal}</li>";
    echo "<li><strong>Total area:</strong> " . number_format($grandArea, 2) . " m²</li>";
    echo "<li><strong>Average per cell:</strong> " . number_format($grandArea / $grandTotal, 4) . " m²</li>";
    echo "</ul>";
    echo "</div></div></div>";
    
    echo "<div class=\"col-md-6\">";
    echo "<div class=\"card\">";
    echo "<div class=\"card-header\"><h5>🎯 Expected (513m²)</h5></div>";
    echo "<div class=\"card-body\">";
    $expectedCells = 513 / 0.25; // Assuming 0.25m² per cell
    echo "<ul>";
    echo "<li><strong>Expected cells:</strong> " . number_format($expectedCells, 0) . "</li>";
    echo "<li><strong>Target area:</strong> 513 m²</li>";
    echo "<li><strong>Difference:</strong> " . number_format($grandTotal - $expectedCells, 0) . " extra cells</li>";
    echo "<li><strong>Area difference:</strong> " . number_format($grandArea - 513, 2) . " m² extra</li>";
    echo "</ul>";
    echo "</div></div></div>";
    echo "</div>";
    
    // Explanation
    echo "<div class=\"alert alert-info mt-4\">";
    echo "<h4>🤔 Why the Difference?</h4>";
    echo "<p>The discrepancy between <strong>{$grandTotal} cells</strong> and expected <strong>2,052 cells</strong> suggests:</p>";
    echo "<ol>";
    echo "<li><strong>Multiple cell sizes:</strong> Your grid includes 1m×1m, 1m×0.5m, and 0.5m×0.5m cells</li>";
    echo "<li><strong>Different areas per cell:</strong> Not all cells are 0.25m²</li>";
    echo "<li><strong>JavaScript generation:</strong> Your floor plan JavaScript creates more cells than the theoretical minimum</li>";
    echo "</ol>";
    echo "</div>";
    
    // Real area calculation
    if ($grandArea > 513) {
        echo "<div class=\"alert alert-warning\">";
        echo "<h4>⚠️ Area Mismatch</h4>";
        echo "<p>Your grid covers <strong>" . number_format($grandArea, 2) . " m²</strong> but you mentioned <strong>513 m²</strong> target.</p>";
        echo "<p>This means either:</p>";
        echo "<ul>";
        echo "<li>The 513m² refers to a different measurement</li>";
        echo "<li>There are extra cells in the grid</li>";
        echo "<li>The grid generation created overlapping areas</li>";
        echo "</ul>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div class=\"alert alert-danger\">";
    echo "<h4>❌ Error occurred:</h4>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "
    <div class=\"mt-4\">
        <a href=\"../\" class=\"btn btn-secondary\">← Back to Admin Tools</a>
    </div>
</div>
</body>
</html>";
?>
