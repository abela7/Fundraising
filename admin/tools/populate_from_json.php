<?php
declare(strict_types=1);

/**
 * Populate Database from JSON
 * 
 * This script loads your extracted grid_cells_data.json and populates the database
 * with all your exact cell IDs.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';

// Only allow admin access
require_login();
require_admin();

set_time_limit(300); // 5 minutes max
$db = db();
$run_population = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_grid_import']));

echo "<!DOCTYPE html>
<html>
<head>
    <title>Populate Database from JSON</title>
    <link rel=\"stylesheet\" href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css\">
</head>
<body>
<div class=\"container mt-5\">
    <h2>📊 Populate Database from Grid JSON</h2>
";

if (!$run_population) {
    echo "<div class=\"alert alert-danger\">This operation will replace all records in floor_grid_cells. "
        . "Use with caution and only after exporting current data.</div>";
    echo "<form method=\"post\" class=\"mb-3\">";
    echo csrf_input();
    echo "<input type=\"hidden\" name=\"run_grid_import\" value=\"1\">";
    echo "<button type=\"submit\" class=\"btn btn-danger\">Run Grid Population</button>";
    echo " <a href=\"../\" class=\"btn btn-secondary\">â† Back to Admin Tools</a>";
    echo "</form>";
    echo "</div></body></html>";
    exit;
}

if (!verify_csrf(false)) {
    echo "<div class=\"alert alert-danger mt-4\">Invalid security token. Please refresh the page and try again.</div>";
    echo "</div></body></html>";
    exit;
}

try {
    // Check if JSON file exists
    $jsonFile = __DIR__ . '/../../grid_cells_data.json';
    
    if (!file_exists($jsonFile)) {
        throw new Exception("grid_cells_data.json not found. Please ensure it's in the project root.");
    }
    
    echo "<div class=\"alert alert-info\">Found grid_cells_data.json file ✅</div>";
    
    // Read and decode JSON
    $jsonContent = file_get_contents($jsonFile);
    $cellsData = json_decode($jsonContent, true);
    
    if (!$cellsData) {
        throw new Exception('Failed to decode JSON data: ' . json_last_error_msg());
    }
    
    $totalCells = count($cellsData);
    echo "<div class=\"alert alert-success\">Loaded {$totalCells} cells from JSON ✅</div>";
    
    // First, update the database schema
    echo "<h4>Step 1: Updating Database Schema</h4>";
    
    $db->begin_transaction();
    
    // Drop and recreate table with correct structure
    $schemaSql = "
    DROP TABLE IF EXISTS `floor_grid_cells`;
    
    CREATE TABLE `floor_grid_cells` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `cell_id` varchar(20) NOT NULL COMMENT 'Actual cell ID from floor plan (e.g., A0101-01, A0505-15)',
      `rectangle_id` char(1) NOT NULL COMMENT 'A, B, C, D, E, F, G',
      `cell_type` enum('1x1','1x0.5','0.5x0.5') NOT NULL COMMENT 'Cell size type',
      `area_size` decimal(4,2) NOT NULL COMMENT 'Area in m² (1.0, 0.5, or 0.25)',
      `package_id` int(11) DEFAULT NULL COMMENT 'Links to donation_packages table',
      `status` enum('available','pledged','paid') DEFAULT 'available',
      `pledge_id` int(11) DEFAULT NULL,
      `payment_id` int(11) DEFAULT NULL,
      `donor_name` varchar(255) DEFAULT NULL,
      `amount` decimal(10,2) DEFAULT NULL,
      `assigned_date` timestamp NULL DEFAULT NULL,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_cell_id` (`cell_id`),
      KEY `status_idx` (`status`),
      KEY `rectangle_idx` (`rectangle_id`),
      KEY `cell_type_idx` (`cell_type`),
      KEY `package_idx` (`package_id`),
      KEY `pledge_idx` (`pledge_id`),
      KEY `payment_idx` (`payment_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    // Execute schema update (split by semicolons)
    $statements = array_filter(array_map('trim', explode(';', $schemaSql)));
    foreach ($statements as $sql) {
        if (!empty($sql)) {
            $db->query($sql);
        }
    }
    
    echo "<div class=\"alert alert-success\">Database schema updated ✅</div>";
    
    // Step 2: Insert all grid cells
    echo "<h4>Step 2: Inserting Grid Cells</h4>";
    
    $stmt = $db->prepare(
        "INSERT INTO floor_grid_cells (cell_id, rectangle_id, cell_type, area_size, package_id, status) 
         VALUES (?, ?, ?, ?, ?, 'available')"
    );
    
    $inserted = 0;
    $errors = 0;
    $batchSize = 1000;
    $processed = 0;
    
    foreach ($cellsData as $cell) {
        try {
            $stmt->bind_param(
                'sssdi',
                $cell['cell_id'],
                $cell['rectangle_id'],
                $cell['cell_type'],
                $cell['area'],
                $cell['package_id']
            );
            
            if ($stmt->execute()) {
                $inserted++;
            } else {
                $errors++;
                error_log("Failed to insert cell: " . $cell['cell_id'] . " - " . $stmt->error);
            }
            
            $processed++;
            
            // Show progress every 1000 cells
            if ($processed % $batchSize === 0) {
                $percentage = round(($processed / $totalCells) * 100, 1);
                echo "<div class=\"alert alert-info\">Progress: {$processed}/{$totalCells} ({$percentage}%) ⏳</div>";
                flush();
            }
            
        } catch (Exception $e) {
            $errors++;
            error_log("Error inserting cell " . $cell['cell_id'] . ": " . $e->getMessage());
        }
    }
    
    $db->commit();
    
    echo "<div class=\"alert alert-success\">
        <h4>🎉 Database Population Complete!</h4>
        <ul>
            <li><strong>Total cells processed:</strong> {$totalCells}</li>
            <li><strong>Successfully inserted:</strong> {$inserted}</li>
            <li><strong>Errors:</strong> {$errors}</li>
        </ul>
    </div>";
    
    // Step 3: Verification and statistics
    echo "<h4>Step 3: Verification & Statistics</h4>";
    
    $stats = $db->query("
        SELECT 
            cell_type,
            rectangle_id,
            COUNT(*) as count,
            SUM(area_size) as total_area
        FROM floor_grid_cells 
        GROUP BY cell_type, rectangle_id
        ORDER BY rectangle_id, cell_type
    ")->fetch_all(MYSQLI_ASSOC);
    
    echo "<table class=\"table table-striped\">";
    echo "<thead><tr><th>Rectangle</th><th>Cell Type</th><th>Count</th><th>Total Area (m²)</th><th>Package</th></tr></thead>";
    echo "<tbody>";
    
    $grandTotal = 0;
    $grandArea = 0;
    
    foreach ($stats as $stat) {
        $package = match($stat['cell_type']) {
            '1x1' => 'Package 1 (£400)',
            '1x0.5' => 'Package 2 (£200)',
            '0.5x0.5' => 'Package 3 (£100)',
            default => 'Unknown'
        };
        
        $grandTotal += $stat['count'];
        $grandArea += $stat['total_area'];
        
        echo "<tr>";
        echo "<td><strong>{$stat['rectangle_id']}</strong></td>";
        echo "<td>{$stat['cell_type']}</td>";
        echo "<td>{$stat['count']}</td>";
        echo "<td>" . number_format($stat['total_area'], 2) . "</td>";
        echo "<td>{$package}</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "<tfoot>";
    echo "<tr class=\"table-success\"><td colspan=\"2\"><strong>TOTAL</strong></td><td><strong>{$grandTotal}</strong></td><td><strong>" . number_format($grandArea, 2) . " m²</strong></td><td><strong>All Packages</strong></td></tr>";
    echo "</tfoot>";
    echo "</table>";
    
    // Overall summary
    $overallStats = $db->query("
        SELECT 
            COUNT(*) as total_cells,
            SUM(area_size) as total_area,
            SUM(CASE WHEN cell_type = '1x1' THEN 1 ELSE 0 END) as cells_1x1,
            SUM(CASE WHEN cell_type = '1x0.5' THEN 1 ELSE 0 END) as cells_1x05,
            SUM(CASE WHEN cell_type = '0.5x0.5' THEN 1 ELSE 0 END) as cells_05x05
        FROM floor_grid_cells
    ")->fetch_assoc();
    
    echo "<div class=\"alert alert-info\">";
    echo "<h5>📊 Overall Summary:</h5>";
    echo "<ul>";
    echo "<li><strong>Total Cells:</strong> {$overallStats['total_cells']}</li>";
    echo "<li><strong>Total Area:</strong> " . number_format($overallStats['total_area'], 2) . " m²</li>";
    echo "<li><strong>1m × 1m cells:</strong> {$overallStats['cells_1x1']}</li>";
    echo "<li><strong>1m × 0.5m cells:</strong> {$overallStats['cells_1x05']}</li>";
    echo "<li><strong>0.5m × 0.5m cells:</strong> {$overallStats['cells_05x05']}</li>";
    echo "</ul>";
    echo "</div>";
    
    // Expected total check
    if ($overallStats['total_cells'] == $totalCells) {
        echo "<div class=\"alert alert-success\">✅ Perfect match! All cells imported correctly.</div>";
    } else {
        echo "<div class=\"alert alert-warning\">⚠️ Cell count mismatch. Expected: {$totalCells}, Got: {$overallStats['total_cells']}</div>";
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->ping()) {
        $db->rollback();
    }
    echo "<div class=\"alert alert-danger\">";
    echo "<h4>❌ Error occurred:</h4>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "
    <div class=\"mt-4\">
        <a href=\"../\" class=\"btn btn-secondary\">← Back to Admin Tools</a>
        <a href=\"test_grid_allocation.php\" class=\"btn btn-primary\">Test Grid System →</a>
        <a href=\"../../projector/floor/\" class=\"btn btn-success\" target=\"_blank\">View Floor Plan →</a>
    </div>
</div>
</body>
</html>";
?>
