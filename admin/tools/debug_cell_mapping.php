<?php
declare(strict_types=1);

/**
 * Debug Cell Mapping
 * Check what's happening with cell allocation and mapping
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/FloorGridAllocatorV2.php';

// Only allow admin access
require_login();
require_admin();

$db = db();
$gridAllocator = new FloorGridAllocatorV2($db);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Debug Cell Mapping</title>
    <link rel=\"stylesheet\" href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css\">
</head>
<body>
<div class=\"container mt-5\">
    <h2>🔍 Debug Cell Mapping</h2>
";

try {
    // Check allocated cells in database
    echo "<h3>🗄️ Database Status</h3>";
    
    $allocatedCells = $db->query("
        SELECT cell_id, rectangle_id, cell_type, status, donor_name, amount, assigned_date 
        FROM floor_grid_cells 
        WHERE status != 'available' 
        ORDER BY assigned_date DESC
    ")->fetch_all(MYSQLI_ASSOC);
    
    echo "<h4>Allocated Cells in Database:</h4>";
    if (empty($allocatedCells)) {
        echo "<div class=\"alert alert-warning\">❌ NO allocated cells found in database!</div>";
    } else {
        echo "<table class=\"table table-striped\">";
        echo "<thead><tr><th>Cell ID</th><th>Rectangle</th><th>Type</th><th>Status</th><th>Donor</th><th>Amount</th><th>Date</th></tr></thead>";
        echo "<tbody>";
        foreach ($allocatedCells as $cell) {
            echo "<tr>";
            echo "<td><code>{$cell['cell_id']}</code></td>";
            echo "<td>{$cell['rectangle_id']}</td>";
            echo "<td>{$cell['cell_type']}</td>";
            echo "<td><span class=\"badge bg-" . ($cell['status'] === 'paid' ? 'success' : 'warning') . "\">{$cell['status']}</span></td>";
            echo "<td>{$cell['donor_name']}</td>";
            echo "<td>£{$cell['amount']}</td>";
            echo "<td>{$cell['assigned_date']}</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }
    
    // Check API response
    echo "<h3>🔗 API Response</h3>";
    $apiResponse = file_get_contents('http://' . $_SERVER['HTTP_HOST'] . '/api/grid_status.php');
    $apiData = json_decode($apiResponse, true);
    
    if ($apiData && $apiData['success']) {
        echo "<h4>API Grid Cells:</h4>";
        echo "<pre>" . json_encode($apiData['data']['grid_cells'], JSON_PRETTY_PRINT) . "</pre>";
        
        echo "<h4>API Statistics:</h4>";
        echo "<pre>" . json_encode($apiData['data']['statistics'], JSON_PRETTY_PRINT) . "</pre>";
    } else {
        echo "<div class=\"alert alert-danger\">❌ API Error: " . ($apiData['error'] ?? 'Unknown error') . "</div>";
    }
    
    // Check if cells exist in JavaScript range
    echo "<h3>🧩 Cell ID Analysis</h3>";
    
    $sampleCells = [
        'A0101-100' => 'Should be allocated (pledged)',
        'A0101-101' => 'Should be allocated (pledged)', 
        'A0505-185' => 'Starting point for 0.5x0.5',
        'A0505-186' => 'Next allocation point',
        'A0505-01' => 'First 0.5x0.5 cell',
        'A0101-01' => 'First 1x1 cell'
    ];
    
    echo "<table class=\"table table-striped\">";
    echo "<thead><tr><th>Cell ID</th><th>Expected</th><th>Database Status</th><th>Analysis</th></tr></thead>";
    echo "<tbody>";
    
    foreach ($sampleCells as $cellId => $description) {
        $stmt = $db->prepare("SELECT status, donor_name, amount FROM floor_grid_cells WHERE cell_id = ?");
        $stmt->bind_param('s', $cellId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        echo "<tr>";
        echo "<td><code>{$cellId}</code></td>";
        echo "<td>{$description}</td>";
        if ($result) {
            $statusColor = $result['status'] === 'available' ? 'secondary' : ($result['status'] === 'paid' ? 'success' : 'warning');
            echo "<td><span class=\"badge bg-{$statusColor}\">{$result['status']}</span>";
            if ($result['donor_name']) {
                echo "<br><small>{$result['donor_name']} - £{$result['amount']}</small>";
            }
            echo "</td>";
            echo "<td>✅ Exists in database</td>";
        } else {
            echo "<td><span class=\"badge bg-danger\">NOT FOUND</span></td>";
            echo "<td>❌ Missing from database</td>";
        }
        echo "</tr>";
    }
    echo "</tbody></table>";
    
    // Check grid allocation stats
    echo "<h3>📊 Allocation Statistics</h3>";
    $stats = $gridAllocator->getAllocationStats();
    
    echo "<div class=\"row\">";
    foreach ($stats as $key => $value) {
        echo "<div class=\"col-md-3\">";
        echo "<div class=\"card\">";
        echo "<div class=\"card-body text-center\">";
        echo "<h5>" . str_replace('_', ' ', ucwords($key)) . "</h5>";
        if (is_numeric($value)) {
            echo "<h3>" . number_format($value, 2) . "</h3>";
        } else {
            echo "<h3>{$value}</h3>";
        }
        echo "</div></div></div>";
    }
    echo "</div>";
    
    // Check if the problem is in JavaScript cell generation
    echo "<h3>🎯 Possible Issues</h3>";
    
    $issues = [];
    
    // Check if allocated cells are in the right range
    foreach ($allocatedCells as $cell) {
        $cellId = $cell['cell_id'];
        if (preg_match('/^([A-G])(\d{4})-(\d+)$/', $cellId, $matches)) {
            $rect = $matches[1];
            $type = $matches[2];
            $number = (int)$matches[3];
            
            if ($type === '0101' && $number > 108) {
                $issues[] = "Cell {$cellId}: 1x1 cells for Rectangle A should be 1-108, but found {$number}";
            }
            if ($type === '0505' && $number < 185) {
                $issues[] = "Cell {$cellId}: 0.5x0.5 cells should start from 185, but found {$number}";
            }
        }
    }
    
    if (empty($issues)) {
        echo "<div class=\"alert alert-success\">✅ No obvious range issues found</div>";
    } else {
        echo "<div class=\"alert alert-warning\">";
        echo "<h5>⚠️ Potential Issues:</h5>";
        echo "<ul>";
        foreach ($issues as $issue) {
            echo "<li>{$issue}</li>";
        }
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
        <a href=\"../../projector/floor/\" class=\"btn btn-success\" target=\"_blank\">View Floor Plan →</a>
    </div>
</div>
</body>
</html>";
?>
