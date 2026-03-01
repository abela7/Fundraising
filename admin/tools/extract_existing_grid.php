<?php
declare(strict_types=1);

/**
 * Extract Existing Grid Cells
 * 
 * This script will read the actual floor plan page and extract all existing
 * grid cell IDs to populate the database correctly.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';

// Only allow admin access
require_login();
require_admin();

$db = db();
$csrfField = csrf_input();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Extract Existing Grid Cells</title>
    <link rel=\"stylesheet\" href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css\">
</head>
<body>
<div class=\"container mt-5\">
    <h2>Extract Existing Grid Cell IDs</h2>
    <p>This page will help us extract the actual grid cell IDs from your existing floor plan.</p>
    
    <div class=\"alert alert-info\">
        <h5>Instructions:</h5>
        <ol>
            <li>Open your floor plan page: <a href=\"../../projector/floor/\" target=\"_blank\">Floor Plan</a></li>
            <li>Open browser console (F12)</li>
            <li>Copy and paste this JavaScript code to extract all IDs:</li>
        </ol>
    </div>
    
    <div class=\"card\">
        <div class=\"card-header\">
            <h5>JavaScript Code to Extract Grid IDs</h5>
        </div>
        <div class=\"card-body\">
            <pre class=\"bg-light p-3\" style=\"overflow-x: auto;\"><code>// Extract all grid cell IDs from the floor plan
const extractGridCells = () => {
    const gridCells = {
        '1x1': [],
        '1x0.5': [],
        '0.5x0.5': []
    };
    
    // Find all elements with grid cell IDs
    document.querySelectorAll('[id*=\"0101-\"], [id*=\"0105-\"], [id*=\"0505-\"]').forEach(element => {
        const id = element.id;
        const type = element.getAttribute('data-type') || '';
        const area = element.getAttribute('data-area') || '';
        
        let category = 'unknown';
        if (id.includes('0101-')) category = '1x1';
        else if (id.includes('0105-')) category = '1x0.5';
        else if (id.includes('0505-')) category = '0.5x0.5';
        
        gridCells[category].push({
            id: id,
            type: type,
            area: parseFloat(area) || 0,
            rectangle: id.charAt(0)
        });
    });
    
    // Output results
    console.log('=== GRID CELLS EXTRACTED ===');
    console.log('1m × 1m cells:', gridCells['1x1'].length);
    console.log('1m × 0.5m cells:', gridCells['1x0.5'].length);
    console.log('0.5m × 0.5m cells:', gridCells['0.5x0.5'].length);
    console.log('Total cells:', gridCells['1x1'].length + gridCells['1x0.5'].length + gridCells['0.5x0.5'].length);
    
    // Create JSON for database insertion
    const dbInsertData = [];
    
    Object.entries(gridCells).forEach(([cellType, cells]) => {
        cells.forEach(cell => {
            dbInsertData.push({
                cell_id: cell.id,
                rectangle_id: cell.rectangle,
                cell_type: cellType,
                area: cell.area,
                package_id: getPackageId(cellType)
            });
        });
    });
    
    function getPackageId(cellType) {
        switch(cellType) {
            case '1x1': return 1;      // 1m² package
            case '1x0.5': return 2;    // 0.5m² package  
            case '0.5x0.5': return 3;  // 0.25m² package
            default: return null;
        }
    }
    
    console.log('=== DATABASE INSERT DATA ===');
    console.log('Copy this JSON to populate the database:');
    console.log(JSON.stringify(dbInsertData, null, 2));
    
    // Also create a downloadable file
    const dataStr = \"data:text/json;charset=utf-8,\" + encodeURIComponent(JSON.stringify(dbInsertData, null, 2));
    const downloadAnchor = document.createElement('a');
    downloadAnchor.setAttribute(\"href\", dataStr);
    downloadAnchor.setAttribute(\"download\", \"grid_cells_data.json\");
    downloadAnchor.click();
    
    return dbInsertData;
};

// Run the extraction
extractGridCells();</code></pre>
        </div>
    </div>
    
    <div class=\"card mt-4\">
        <div class=\"card-header\">
            <h5>Manual Input Form</h5>
        </div>
        <div class=\"card-body\">
            <p>If you prefer, you can manually paste the JSON data here to populate the database:</p>
            <form method=\"post\">{$csrfField}
                <div class=\"mb-3\">
                    <label for=\"grid_data\" class=\"form-label\">Grid Cells JSON Data</label>
                    <textarea class=\"form-control\" id=\"grid_data\" name=\"grid_data\" rows=\"10\" placeholder=\"Paste the JSON data from console here...\"></textarea>
                </div>
                <button type=\"submit\" name=\"populate_from_json\" class=\"btn btn-primary\">
                    <i class=\"fas fa-database me-1\"></i>Populate Database
                </button>
            </form>
        </div>
    </div>
    
    <div class=\"mt-4\">
        <a href=\"../\" class=\"btn btn-secondary\">← Back to Admin Tools</a>
        <a href=\"../../projector/floor/\" class=\"btn btn-success\" target=\"_blank\">Open Floor Plan →</a>
    </div>
</div>

<script src=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js\"></script>
</body>
</html>";

// Handle JSON data submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['populate_from_json'])) {
    if (!verify_csrf(false)) {
        echo "<div class=\"alert alert-danger mt-4\">Invalid security token. Please refresh the page and try again.</div>";
        exit;
    }

    $gridData = trim($_POST['grid_data'] ?? '');
    
    if ($gridData) {
        try {
            $cellsData = json_decode($gridData, true);
            
            if (!$cellsData) {
                throw new Exception('Invalid JSON data');
            }
            
            $db->begin_transaction();
            
            // Clear existing cells
            $db->query("DELETE FROM floor_grid_cells");
            
            // Prepare insert statement  
            $stmt = $db->prepare(
                "INSERT INTO floor_grid_cells (cell_id, rectangle_id, cell_type, area_size, package_id, status) 
                 VALUES (?, ?, ?, ?, ?, 'available')"
            );
            
            $inserted = 0;
            foreach ($cellsData as $cell) {
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
                }
            }
            
            $db->commit();
            
            echo "<div class=\"alert alert-success mt-4\">";
            echo "<h5>Success! ✅</h5>";
            echo "<p>Inserted {$inserted} grid cells into the database.</p>";
            echo "</div>";
            
        } catch (Exception $e) {
            $db->rollback();
            echo "<div class=\"alert alert-danger mt-4\">";
            echo "<h5>Error ❌</h5>";
            echo "<p>Failed to populate database: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "</div>";
        }
    }
}
?>
