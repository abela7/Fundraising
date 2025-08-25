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
    <title>🔍 Remainder Analysis</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .analysis { border: 1px solid #ddd; margin: 10px 0; padding: 15px; border-radius: 5px; }
        .problem { background-color: #f8d7da; border-color: #f5c6cb; }
        .solution { background-color: #d4edda; border-color: #c3e6cb; }
        .example { background-color: #e2e3e5; border-color: #d6d8db; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f8f9fa; }
        .waste { color: #dc3545; font-weight: bold; }
        .efficient { color: #28a745; font-weight: bold; }
    </style>
</head>
<body>

<h1>🔍 REMAINDER WASTE ANALYSIS</h1>

<div class='analysis problem'>
<h2>🚨 THE PROBLEM: WASTING REMAINDERS</h2>

<h3>Current Logic for £350 Donation:</h3>
<pre>
1. calculateCellsForAmount(£350) returns 1 cell (because £350 < £400)
2. allocatedAmount = 1 × £100 = £100
3. remainingAmount = £350 - £100 = £250
4. Allocate only 1 cell for £100
5. Store £250 remainder in custom_amount_tracking
</pre>

<p class="waste">❌ WASTE: £350 donation only gets 1 cell (0.25m²) instead of 3 cells (0.75m²)!</p>

</div>

<div class='analysis'>
<h2>📊 Current calculateCellsForAmount Logic</h2>

<table>
<tr><th>Amount Range</th><th>Cells Allocated</th><th>Actual Value</th><th>Waste Example</th></tr>
<tr><td>£100-£199</td><td>1 cell (0.25m²)</td><td>£100</td><td>£199 → 1 cell, £99 wasted</td></tr>
<tr><td>£200-£399</td><td class="waste">2 cells (0.5m²)</td><td class="waste">£200</td><td class="waste">£350 → 2 cells, £150 wasted</td></tr>
<tr><td>£400+</td><td>4 cells (1m²)</td><td>£400</td><td>£500 → 4 cells, £100 wasted</td></tr>
</table>

<p><strong>The issue:</strong> The current logic uses <em>thresholds</em> instead of <em>proportional allocation</em>!</p>

</div>

<div class='analysis solution'>
<h2>💡 SMART SOLUTION: PROPORTIONAL ALLOCATION</h2>

<h3>Enhanced Logic for £350 Donation:</h3>
<pre>
1. cellsToAllocate = floor(£350 / £100) = 3 cells
2. allocatedAmount = 3 × £100 = £300  
3. remainingAmount = £350 - £300 = £50
4. Allocate 3 cells for £300
5. Store only £50 remainder in custom_amount_tracking
</pre>

<p class="efficient">✅ EFFICIENT: £350 donation gets 3 cells (0.75m²) + £50 remainder!</p>

<h3>New calculateCellsForAmount Logic:</h3>
<pre>
private function calculateCellsForAmount(float $amount): int {
    return (int)floor($amount / 100); // £100 per 0.25m² cell
}
</pre>

<table>
<tr><th>Amount</th><th>Old Logic</th><th>New Logic</th><th>Improvement</th></tr>
<tr><td>£150</td><td>1 cell, £50 waste</td><td class="efficient">1 cell, £50 tracked</td><td>Same result</td></tr>
<tr><td>£250</td><td class="waste">2 cells, £50 waste</td><td class="efficient">2 cells, £50 tracked</td><td>Same result</td></tr>
<tr><td>£350</td><td class="waste">2 cells, £150 waste</td><td class="efficient">3 cells, £50 tracked</td><td class="efficient">+1 cell saved!</td></tr>
<tr><td>£450</td><td class="waste">4 cells, £50 waste</td><td class="efficient">4 cells, £50 tracked</td><td>Same result</td></tr>
<tr><td>£550</td><td class="waste">4 cells, £150 waste</td><td class="efficient">5 cells, £50 tracked</td><td class="efficient">+1 cell saved!</td></tr>
</table>

</div>

<div class='analysis example'>
<h2>🧮 REAL EXAMPLES</h2>

<?php
function calculateCellsOld(float $amount): int {
    if ($amount >= 400) return 4;
    if ($amount >= 200) return 2;
    if ($amount >= 100) return 1;
    return 0;
}

function calculateCellsNew(float $amount): int {
    return (int)floor($amount / 100);
}

$testAmounts = [150, 250, 350, 450, 550, 650, 750];

echo "<table>";
echo "<tr><th>Donation</th><th>Old Method</th><th>New Method</th><th>Cells Saved</th><th>Money Saved</th></tr>";

foreach ($testAmounts as $amount) {
    $oldCells = calculateCellsOld($amount);
    $newCells = calculateCellsNew($amount);
    $cellsSaved = $newCells - $oldCells;
    $moneySaved = $cellsSaved * 100;
    
    $color = $cellsSaved > 0 ? 'color: #28a745; font-weight: bold;' : '';
    
    echo "<tr>";
    echo "<td>£{$amount}</td>";
    echo "<td>{$oldCells} cells (£" . ($oldCells * 100) . ")</td>";
    echo "<td>{$newCells} cells (£" . ($newCells * 100) . ")</td>";
    echo "<td style='$color'>" . ($cellsSaved > 0 ? "+{$cellsSaved}" : $cellsSaved) . "</td>";
    echo "<td style='$color'>" . ($moneySaved > 0 ? "+£{$moneySaved}" : "£0") . "</td>";
    echo "</tr>";
}

echo "</table>";
?>

</div>

<div class='analysis solution'>
<h2>🚀 IMPLEMENTATION PLAN</h2>

<p><strong>The fix is simple - change ONE line in CustomAmountAllocator.php:</strong></p>

<h3>Current (lines 298-303):</h3>
<pre>
private function calculateCellsForAmount(float $amount): int {
    if ($amount >= 400) return 4;      // 1m² = £400
    if ($amount >= 200) return 2;      // 0.5m² = £200  
    if ($amount >= 100) return 1;      // 0.25m² = £100
    return 0;                           // Under £100
}
</pre>

<h3>Enhanced (proportional allocation):</h3>
<pre style="background: #d4edda; padding: 10px;">
private function calculateCellsForAmount(float $amount): int {
    return (int)floor($amount / 100); // £100 per 0.25m² cell
}
</pre>

<p><strong>Benefits:</strong></p>
<ul>
<li>✅ No waste - every £100 gets exactly 1 cell</li>
<li>✅ Remainders are minimized (always < £100)</li>
<li>✅ More efficient use of donations</li>
<li>✅ Maintains under £100 accumulation logic</li>
</ul>

<p><strong>This change affects ONLY custom amounts (package_id 4 or null).</strong></p>
<p><strong>Fixed packages (1m², 0.5m², 0.25m²) are unaffected and work as before.</strong></p>

</div>

</body>
</html>
