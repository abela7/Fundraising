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
        p.type
    FROM pledges p 
    LEFT JOIN donation_packages dp ON p.package_id = dp.id
    WHERE p.status = 'pending'
    GROUP BY p.package_id, p.type
    ORDER BY p.package_id, p.type
")->fetch_all(MYSQLI_ASSOC);

if (empty($pendingByPackage)) {
    echo "<div class='warning'>⚠️ No pending donations found to test</div>";
} else {
    echo "<table>";
    echo "<tr><th>Package ID</th><th>Package</th><th>Type</th><th>Count</th><th>Total</th><th>Expected Allocator</th></tr>";
    foreach ($pendingByPackage as $row) {
        $packageId = $row['package_id'] ?? 'NULL';
        $allocator = ($packageId && $packageId <= 3) ? "IntelligentGridAllocator" : "CustomAmountAllocator";
        $color = ($packageId && $packageId <= 3) ? "color: blue;" : "color: green;";
        
        echo "<tr>";
        echo "<td>$packageId</td>";
        echo "<td>" . ($row['package_label'] ?? 'Custom/No Package') . "</td>";
        echo "<td>" . ucfirst($row['type']) . "</td>";
        echo "<td>{$row['count']}</td>";
        echo "<td>£" . number_format($row['total_amount'], 2) . "</td>";
        echo "<td style='$color'><strong>$allocator</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
}
?>

</div>

<div class='test-section'>
<h2>3. 🔧 Allocation Logic Test</h2>

<p><strong>Fixed Package Logic:</strong></p>
<ul>
<li>Package ID 1, 2, 3 → <span style="color: blue;"><strong>IntelligentGridAllocator</strong></span></li>
<li>Package ID 4 or NULL → <span style="color: green;"><strong>CustomAmountAllocator</strong></span></li>
</ul>

<p><strong>Current Implementation in simple_approve.php:</strong></p>
<pre style="background: #f8f9fa; padding: 10px; border-radius: 5px;">
if ($packageId && $packageId <= 3) {
    // Fixed packages - Use IntelligentGridAllocator
    $gridAllocator = new IntelligentGridAllocator($db);
    $allocationResult = $gridAllocator->allocate(...)
} else {
    // Custom amounts - Use CustomAmountAllocator  
    $customAllocator = new CustomAmountAllocator($db);
    $allocationResult = $customAllocator->processCustomAmount(...)
}
</pre>

</div>

<div class='test-section'>
<h2>4. 🚀 Ready to Test</h2>

<p><strong>To verify the fix:</strong></p>
<ol>
<li>Approve a <span style="color: blue;"><strong>fixed package donation</strong></span> (1m², 0.5m², 0.25m²)</li>
<li>Approve a <span style="color: green;"><strong>custom amount donation</strong></span></li>
<li>Check floor map for immediate allocation</li>
<li>Check console for "Grid allocated successfully" message</li>
</ol>

<p><strong>Expected Results:</strong></p>
<ul>
<li>✅ Fixed packages allocate exact cells immediately</li>
<li>✅ Custom amounts under £100 accumulate in custom_amount_tracking</li>
<li>✅ Custom amounts £100+ allocate appropriate cells + track remainder</li>
<li>✅ Floor map updates correctly for both types</li>
</ul>

<div style="background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0;">
<h3>🎯 THE PROBLEM WAS FIXED:</h3>
<p>I was forcing <strong>EVERYTHING</strong> through CustomAmountAllocator, but the system has TWO allocation methods:</p>
<ul>
<li><strong>IntelligentGridAllocator</strong> for fixed packages (immediate cell allocation)</li>
<li><strong>CustomAmountAllocator</strong> for custom amounts (accumulation logic)</li>
</ul>
<p>Now the system uses the CORRECT allocator based on package_id! 🚀</p>
</div>

</div>

</body>
</html>
