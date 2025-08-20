<?php
declare(strict_types=1);

/**
 * Populate Floor Grid Cells
 * 
 * This script initializes the floor_grid_cells table with all possible 0.5m x 0.5m grid positions
 * based on the 7 rectangles (A-G) configuration from your floor plan.
 * 
 * Run this ONCE to set up the grid system.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/auth.php';

// Only allow admin access
require_login();
require_admin();

set_time_limit(300); // 5 minutes max
$db = db();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Populate Grid Cells</title>
    <link rel=\"stylesheet\" href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css\">
</head>
<body>
<div class=\"container mt-5\">
    <h2>Floor Grid Cells Population</h2>
";

try {
    // Rectangle configurations matching your CSS grid
    $rectangleConfig = [
        'A' => ['start_col' => 1, 'end_col' => 9, 'start_row' => 5, 'end_row' => 16],   // 9×12 = 108 cells
        'B' => ['start_col' => 1, 'end_col' => 3, 'start_row' => 17, 'end_row' => 19], // 3×3 = 9 cells  
        'C' => ['start_col' => 10, 'end_col' => 11, 'start_row' => 9, 'end_row' => 16], // 2×8 = 16 cells
        'D' => ['start_col' => 24, 'end_col' => 33, 'start_row' => 5, 'end_row' => 16], // 10×12 = 120 cells
        'E' => ['start_col' => 12, 'end_col' => 23, 'start_row' => 7, 'end_row' => 16], // 12×10 = 120 cells
        'F' => ['start_col' => 34, 'end_col' => 38, 'start_row' => 2, 'end_row' => 5],  // 5×4 = 20 cells
        'G' => ['start_col' => 34, 'end_col' => 41, 'start_row' => 6, 'end_row' => 20]  // 8×15 = 120 cells
    ];
    
    echo "<div class=\"alert alert-info\">Starting grid cell population...</div>";
    
    $db->begin_transaction();
    
    // Clear existing cells (if any)
    $clearResult = $db->query("DELETE FROM floor_grid_cells");
    echo "<div class=\"alert alert-warning\">Cleared existing grid cells.</div>";
    
    $totalCells = 0;
    $cellsPerRectangle = [];
    
    // Prepare insert statement
    $stmt = $db->prepare(
        "INSERT INTO floor_grid_cells (rectangle_id, grid_x, grid_y, cell_size, status) 
         VALUES (?, ?, ?, '0.5x0.5', 'available')"
    );
    
    foreach ($rectangleConfig as $rectId => $config) {
        $rectCells = 0;
        
        echo "<h4>Processing Rectangle {$rectId}</h4>";
        echo "<p>Columns: {$config['start_col']}-{$config['end_col']}, Rows: {$config['start_row']}-{$config['end_row']}</p>";
        
        for ($row = $config['start_row']; $row <= $config['end_row']; $row++) {
            for ($col = $config['start_col']; $col <= $config['end_col']; $col++) {
                $stmt->bind_param('sii', $rectId, $col, $row);
                
                if ($stmt->execute()) {
                    $rectCells++;
                    $totalCells++;
                } else {
                    throw new Exception("Failed to insert cell {$rectId}({$col},{$row}): " . $stmt->error);
                }
            }
        }
        
        $cellsPerRectangle[$rectId] = $rectCells;
        echo "<div class=\"alert alert-success\">Rectangle {$rectId}: {$rectCells} cells created</div>";
    }
    
    $db->commit();
    
    echo "<div class=\"alert alert-success\">
        <h4>Grid Population Complete! ✅</h4>
        <p><strong>Total cells created: {$totalCells}</strong></p>
        <p><strong>Total area covered: " . ($totalCells * 0.25) . " m²</strong></p>
    </div>";
    
    echo "<h4>Breakdown by Rectangle:</h4>";
    echo "<table class=\"table table-striped\">";
    echo "<thead><tr><th>Rectangle</th><th>Cells</th><th>Area (m²)</th><th>Dimensions</th></tr></thead>";
    echo "<tbody>";
    
    foreach ($cellsPerRectangle as $rectId => $cellCount) {
        $area = $cellCount * 0.25;
        $config = $rectangleConfig[$rectId];
        $width = $config['end_col'] - $config['start_col'] + 1;
        $height = $config['end_row'] - $config['start_row'] + 1;
        
        echo "<tr>";
        echo "<td><strong>{$rectId}</strong></td>";
        echo "<td>{$cellCount}</td>";
        echo "<td>{$area} m²</td>";
        echo "<td>{$width} × {$height} (0.5m units)</td>";
        echo "</tr>";
    }
    
    echo "</tbody></table>";
    
    // Verification query
    $verify = $db->query("SELECT COUNT(*) as total FROM floor_grid_cells WHERE status = 'available'");
    $verifyResult = $verify->fetch_assoc();
    
    echo "<div class=\"alert alert-info\">";
    echo "<strong>Verification:</strong> Database contains {$verifyResult['total']} available cells.";
    echo "</div>";
    
    // Expected total calculation
    $expectedTotal = 0;
    foreach ($rectangleConfig as $config) {
        $width = $config['end_col'] - $config['start_col'] + 1;
        $height = $config['end_row'] - $config['start_row'] + 1;
        $expectedTotal += $width * $height;
    }
    
    echo "<div class=\"alert " . ($totalCells === $expectedTotal ? "alert-success" : "alert-danger") . "\">";
    echo "Expected: {$expectedTotal} cells | Created: {$totalCells} cells";
    if ($totalCells === $expectedTotal) {
        echo " ✅ Perfect match!";
    } else {
        echo " ❌ Mismatch detected!";
    }
    echo "</div>";
    
} catch (Exception $e) {
    $db->rollback();
    echo "<div class=\"alert alert-danger\">";
    echo "<h4>Error occurred:</h4>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "
    <div class=\"mt-4\">
        <a href=\"../\" class=\"btn btn-primary\">← Back to Admin Tools</a>
        <a href=\"../../projector/floor/\" class=\"btn btn-success\" target=\"_blank\">View Floor Plan →</a>
    </div>
</div>
</body>
</html>";
?>
