<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

require_login();
require_admin();

try {
    $db = db();
    
    echo "<h2>🔍 FLOOR ALLOCATION SYSTEM TEST</h2>";
    
    // Test 1: Check floor_grid_cells table
    echo "<h3>1. Floor Grid Cells Table</h3>";
    $floorCells = $db->query("SELECT COUNT(*) as total, 
                              SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) as available,
                              SUM(CASE WHEN status='pledged' THEN 1 ELSE 0 END) as pledged,
                              SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) as paid
                              FROM floor_grid_cells")->fetch_assoc();
    
    echo "<ul>";
    echo "<li>Total cells: " . $floorCells['total'] . "</li>";
    echo "<li>Available: " . $floorCells['available'] . "</li>";
    echo "<li>Pledged: " . $floorCells['pledged'] . "</li>";
    echo "<li>Paid: " . $floorCells['paid'] . "</li>";
    echo "</ul>";
    
    // Test 2: Check custom_amount_tracking table
    echo "<h3>2. Custom Amount Tracking Table</h3>";
    $customTracking = $db->query("SELECT * FROM custom_amount_tracking WHERE id=1")->fetch_assoc();
    
    if ($customTracking) {
        echo "<ul>";
        echo "<li>Total Amount: £" . $customTracking['total_amount'] . "</li>";
        echo "<li>Allocated Amount: £" . $customTracking['allocated_amount'] . "</li>";
        echo "<li>Remaining Amount: £" . $customTracking['remaining_amount'] . "</li>";
        echo "<li>Last Updated: " . $customTracking['last_updated'] . "</li>";
        echo "</ul>";
    } else {
        echo "<div style='color: red;'>❌ ERROR: custom_amount_tracking table record ID=1 not found!</div>";
        
        // Try to initialize it
        echo "<h4>Initializing custom_amount_tracking...</h4>";
        $init = $db->prepare("
            INSERT INTO custom_amount_tracking (id, donor_id, donor_name, total_amount, allocated_amount, remaining_amount, last_updated, created_at)
            VALUES (1, 0, 'Collective Custom', 0.00, 0.00, 0.00, NOW(), NOW())
            ON DUPLICATE KEY UPDATE last_updated = NOW()
        ");
        $init->execute();
        echo "<div style='color: green;'>✅ Initialized custom_amount_tracking table</div>";
    }
    
    // Test 3: Check CustomAmountAllocator class
    echo "<h3>3. CustomAmountAllocator Class</h3>";
    try {
        require_once __DIR__ . '/../../shared/CustomAmountAllocator.php';
        $allocator = new CustomAmountAllocator($db);
        echo "<div style='color: green;'>✅ CustomAmountAllocator class loaded successfully</div>";
        
        $summary = $allocator->getCustomAmountSummary();
        echo "<ul>";
        echo "<li>Total Tracked: £" . $summary['total_tracked'] . "</li>";
        echo "<li>Total Allocated: £" . $summary['total_allocated'] . "</li>";
        echo "<li>Total Remaining: £" . $summary['total_remaining'] . "</li>";
        echo "<li>Donor Count: " . $summary['donor_count'] . "</li>";
        echo "</ul>";
        
    } catch (Exception $e) {
        echo "<div style='color: red;'>❌ ERROR: " . $e->getMessage() . "</div>";
    }
    
    // Test 4: Check IntelligentGridAllocator class
    echo "<h3>4. IntelligentGridAllocator Class</h3>";
    try {
        require_once __DIR__ . '/../../shared/IntelligentGridAllocator.php';
        $gridAllocator = new IntelligentGridAllocator($db);
        echo "<div style='color: green;'>✅ IntelligentGridAllocator class loaded successfully</div>";
        
        $stats = $gridAllocator->getAllocationStats();
        echo "<ul>";
        echo "<li>Total Cells: " . ($stats['total_cells'] ?? 'N/A') . "</li>";
        echo "<li>Available Cells: " . ($stats['available_cells'] ?? 'N/A') . "</li>";
        echo "<li>Pledged Cells: " . ($stats['pledged_cells'] ?? 'N/A') . "</li>";
        echo "<li>Paid Cells: " . ($stats['paid_cells'] ?? 'N/A') . "</li>";
        echo "</ul>";
        
    } catch (Exception $e) {
        echo "<div style='color: red;'>❌ ERROR: " . $e->getMessage() . "</div>";
    }
    
    // Test 5: Check API endpoint
    echo "<h3>5. Grid Status API</h3>";
    $apiUrl = '/api/grid_status.php';
    $fullUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $apiUrl;
    echo "<p>API URL: <a href='$fullUrl' target='_blank'>$fullUrl</a></p>";
    
    // Test 6: Recent allocations
    echo "<h3>6. Recent Allocations</h3>";
    $recent = $db->query("SELECT id, cell_id, status, donor_name, amount, assigned_date 
                          FROM floor_grid_cells 
                          WHERE status IN ('pledged', 'paid') 
                          ORDER BY assigned_date DESC 
                          LIMIT 10")->fetch_all(MYSQLI_ASSOC);
    
    if (empty($recent)) {
        echo "<div style='color: orange;'>⚠️ No recent allocations found</div>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Cell ID</th><th>Status</th><th>Donor</th><th>Amount</th><th>Date</th></tr>";
        foreach ($recent as $allocation) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($allocation['cell_id']) . "</td>";
            echo "<td>" . htmlspecialchars($allocation['status']) . "</td>";
            echo "<td>" . htmlspecialchars($allocation['donor_name'] ?? 'N/A') . "</td>";
            echo "<td>£" . number_format($allocation['amount'] ?? 0, 2) . "</td>";
            echo "<td>" . ($allocation['assigned_date'] ?? 'N/A') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<h3>✅ SYSTEM TEST COMPLETE</h3>";
    echo "<p><strong>If all tests pass, the floor allocation system should work correctly!</strong></p>";
    
} catch (Exception $e) {
    echo "<div style='color: red;'>❌ CRITICAL ERROR: " . $e->getMessage() . "</div>";
}
?>
