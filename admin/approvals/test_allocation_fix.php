<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

$db = db();
?>
<!DOCTYPE html>
<html>
<head>
    <title>🔧 Allocation Fix Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .test-section { border: 1px solid #ddd; margin: 10px 0; padding: 15px; border-radius: 5px; }
        .pass { color: #28a745; font-weight: bold; }
        .fail { color: #dc3545; font-weight: bold; }
        .warning { color: #ffc107; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f8f9fa; }
    </style>
</head>
<body>

<h1>🔧 ALLOCATION FIX TEST</h1>

<div class='test-section'>
<h2>1. 📦 Package Analysis</h2>

<?php
// Get all donation packages
$packages = $db->query("SELECT id, label, price, sqm_meters FROM donation_packages WHERE active=1 ORDER BY id")->fetch_all(MYSQLI_ASSOC);

echo "<table>";
echo "<tr><th>Package ID</th><th>Label</th><th>Price</th><th>Area</th><th>Allocator</th></tr>";
foreach ($packages as $pkg) {
    $allocator = ($pkg['id'] <= 3) ? "IntelligentGridAllocator" : "CustomAmountAllocator";
    $color = ($pkg['id'] <= 3) ? "color: blue;" : "color: green;";
    echo "<tr>";
    echo "<td>{$pkg['id']}</td>";
    echo "<td>{$pkg['label']}</td>";
    echo "<td>£{$pkg['price']}</td>";
    echo "<td>{$pkg['sqm_meters']} m²</td>";
    echo "<td style='$color'><strong>$allocator</strong></td>";
    echo "</tr>";
}
echo "</table>";
?>

</div>

<div class='test-section'>
<h2>2. 📊 Pending Donations by Package Type</h2>

<?php
$pendingByPackage = $db->query("
    SELECT 
        p.package_id,
        dp.label as package_label,
        COUNT(*) as count,
        SUM(p.amount) as total_amount,
        p.type,
        p.source
    FROM pledges p 
    LEFT JOIN donation_packages dp ON p.package_id = dp.id
    WHERE p.status = 'pending'
    GROUP BY p.package_id, p.type, p.source
    ORDER BY p.package_id, p.type, p.source
")->fetch_all(MYSQLI_ASSOC);

// Get custom amount breakdown
$customBreakdown = $db->query("
    SELECT 
        CASE 
            WHEN amount < 100 THEN 'Under £100'
            WHEN amount < 400 THEN '£100-£399'
            ELSE '£400+'
        END as amount_range,
        COUNT(*) as count,
        SUM(amount) as total,
        AVG(amount) as avg_amount
    FROM pledges 
    WHERE status = 'pending' AND (package_id = 4 OR package_id IS NULL)
    GROUP BY amount_range
    ORDER BY MIN(amount)
")->fetch_all(MYSQLI_ASSOC);

if (empty($pendingByPackage)) {
    echo "<div class='warning'>⚠️ No pending donations found to test</div>";
    echo "<p><strong>Generate realistic test data:</strong> <a href='generate_realistic_test_data.php'>🧪 Data Generator</a></p>";
} else {
    echo "<h4>Package Distribution Analysis:</h4>";
    echo "<table>";
    echo "<tr><th>Package ID</th><th>Package</th><th>Type</th><th>Source</th><th>Count</th><th>Total</th><th>Allocator</th></tr>";
    foreach ($pendingByPackage as $row) {
        $packageId = $row['package_id'] ?? 'NULL';
        // Updated: ALL packages now use CustomAmountAllocator
        $allocator = "CustomAmountAllocator";
        $color = "color: green;";
        
        echo "<tr>";
        echo "<td>$packageId</td>";
        echo "<td>" . ($row['package_label'] ?? 'Custom/No Package') . "</td>";
        echo "<td>" . ucfirst($row['type']) . "</td>";
        echo "<td>" . ucfirst($row['source']) . "</td>";
        echo "<td>{$row['count']}</td>";
        echo "<td>£" . number_format($row['total_amount'], 2) . "</td>";
        echo "<td style='$color'><strong>$allocator</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
    
    if (!empty($customBreakdown)) {
        echo "<h4>Custom Amount Analysis:</h4>";
        echo "<table>";
        echo "<tr><th>Amount Range</th><th>Count</th><th>Total Value</th><th>Average</th><th>Allocation Behavior</th></tr>";
        foreach ($customBreakdown as $row) {
            $behavior = $row['amount_range'] === 'Under £100' ? 
                       'Accumulate in custom_amount_tracking' : 
                       'Immediate cell allocation + remainder tracking';
            
            echo "<tr>";
            echo "<td><strong>{$row['amount_range']}</strong></td>";
            echo "<td>{$row['count']}</td>";
            echo "<td>£" . number_format($row['total'], 2) . "</td>";
            echo "<td>£" . number_format($row['avg_amount'], 2) . "</td>";
            echo "<td><em>$behavior</em></td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Get totals
    $totalPending = $db->query("SELECT COUNT(*) as count, SUM(amount) as total FROM pledges WHERE status='pending'")->fetch_assoc();
    echo "<h4>Overall Summary:</h4>";
    echo "<p><strong>Total Pending:</strong> {$totalPending['count']} donations worth £" . number_format($totalPending['total'], 2) . "</p>";
}
?>

</div>

<div class='test-section'>
<h2>3. 🔧 Allocation Logic Test</h2>

<p><strong>Enhanced Unified Logic:</strong></p>
<ul>
<li>ALL PACKAGES → <span style="color: green;"><strong>CustomAmountAllocator</strong></span> (Enhanced with proportional allocation)</li>
<li>Fixed packages get immediate allocation at exact package value</li>
<li>Custom amounts get proportional allocation (every £100 = 1 cell)</li>
</ul>

<p><strong>Current Implementation in simple_approve.php:</strong></p>
<pre style="background: #f8f9fa; padding: 10px; border-radius: 5px;">
// Use CustomAmountAllocator for ALL donations (enhanced)
$customAllocator = new CustomAmountAllocator($db);

if ($pledge['type'] === 'paid') {
    $allocationResult = $customAllocator->processPaymentCustomAmount(...)
} else {
    $allocationResult = $customAllocator->processCustomAmount(...)
}
</pre>

<p><strong>Key Enhancement:</strong> <code>calculateCellsForAmount()</code> now uses <code>floor($amount / 100)</code> for maximum efficiency!</p>

</div>

<div class='test-section'>
<h2>4. 🚀 Ready to Test</h2>

<p><strong>To verify the enhanced system:</strong></p>
<ol>
<li><strong>Generate realistic test data:</strong> <a href="generate_realistic_test_data.php">🧪 Use Data Generator</a></li>
<li>Approve a <strong>£100 donation</strong> → Should get exactly 1 cell</li>
<li>Approve a <strong>£350 donation</strong> → Should get 3 cells + £50 remainder</li>
<li>Approve a <strong>£75 donation</strong> → Should accumulate, no immediate cells</li>
<li>Check floor map for proper allocation</li>
<li>Test unapproval → Should restore custom_amount_tracking properly</li>
</ol>

<p><strong>Expected Enhanced Results:</strong></p>
<ul>
<li>✅ <strong>Maximum Efficiency:</strong> Every £100 gets exactly 1 cell (0.25m²)</li>
<li>✅ <strong>No Waste:</strong> £350 now gets 3 cells instead of 2</li>
<li>✅ <strong>Under £100:</strong> Accumulate in custom_amount_tracking</li>
<li>✅ <strong>Proper Deallocation:</strong> Unapproval works for all amounts</li>
<li>✅ <strong>Realistic Distribution:</strong> Most donations are standard packages</li>
</ul>

<div style="background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0;">
<h3>🎯 THE SYSTEM WAS ENHANCED:</h3>
<p>The enhanced allocation system now provides:</p>
<ul>
<li><strong>✅ Unified Allocator:</strong> CustomAmountAllocator handles ALL donations efficiently</li>
<li><strong>✅ Proportional Allocation:</strong> Every £100 = 1 cell (maximum efficiency)</li>
<li><strong>✅ Complete Deallocation:</strong> Fixed the under £100 unapproval bug</li>
<li><strong>✅ Realistic Test Data:</strong> Proper package distribution for real-world testing</li>
</ul>
<p>Now the system is both MORE efficient AND has complete deallocation support! 🚀</p>
</div>

</div>

</body>
</html>
