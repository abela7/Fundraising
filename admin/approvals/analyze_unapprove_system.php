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
    <title>🔍 Unapprove System Analysis</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .analysis { border: 1px solid #ddd; margin: 10px 0; padding: 15px; border-radius: 5px; }
        .critical { background-color: #f8d7da; border-color: #f5c6cb; }
        .warning { background-color: #fff3cd; border-color: #ffeaa7; }
        .solution { background-color: #d4edda; border-color: #c3e6cb; }
        .flow { background-color: #e2e3e5; border-color: #d6d8db; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f8f9fa; }
        .problem { color: #dc3545; font-weight: bold; }
        .working { color: #28a745; font-weight: bold; }
    </style>
</head>
<body>

<h1>🔍 UNAPPROVE SYSTEM ANALYSIS</h1>

<div class='analysis critical'>
<h2>🚨 CRITICAL PROBLEM: INCOMPLETE DEALLOCATION SYSTEM</h2>

<h3>Current Unapprove Flow (admin/approved/index.php):</h3>
<ol>
<li>✅ Set pledge/payment status back to 'pending'</li>
<li>✅ Decrement counters (remove from totals)</li>
<li>✅ Call IntelligentGridAllocator->deallocate()</li>
<li>❌ <strong>MISSING: CustomAmountAllocator deallocation!</strong></li>
</ol>

<p class="problem">The system ONLY deallocates through IntelligentGridAllocator, but doesn't handle custom amounts properly!</p>

</div>

<div class='analysis flow'>
<h2>📊 Current System Flow Analysis</h2>

<h3>APPROVAL Flow:</h3>
<table>
<tr><th>Package Type</th><th>Allocator Used</th><th>What Happens</th></tr>
<tr><td>Fixed (1-3)</td><td class="working">IntelligentGridAllocator</td><td class="working">Direct cell allocation</td></tr>
<tr><td>Custom (4/null)</td><td class="working">CustomAmountAllocator</td><td class="working">Accumulation + cell allocation + remainder tracking</td></tr>
</table>

<h3>UNAPPROVE Flow:</h3>
<table>
<tr><th>Package Type</th><th>Deallocator Used</th><th>What Happens</th><th>Problem</th></tr>
<tr><td>Fixed (1-3)</td><td class="working">IntelligentGridAllocator->deallocate()</td><td class="working">Cells freed correctly</td><td class="working">✅ Working</td></tr>
<tr><td>Custom (4/null)</td><td class="problem">IntelligentGridAllocator->deallocate()</td><td class="problem">Only cells freed, remainder NOT restored</td><td class="problem">❌ BROKEN</td></tr>
</table>

</div>

<div class='analysis warning'>
<h2>⚠️ SPECIFIC PROBLEMS WITH CUSTOM AMOUNTS</h2>

<h3>Example: £350 Custom Donation</h3>

<h4>On APPROVAL:</h4>
<pre>
1. CustomAmountAllocator->processCustomAmount(£350)
2. calculateCellsForAmount(£350) = 2 cells (current logic)
3. Allocate 2 cells for £200 via IntelligentGridAllocator
4. Track £150 remainder in custom_amount_tracking table
5. Result: 2 cells allocated + £150 in remainder pool
</pre>

<h4>On UNAPPROVE:</h4>
<pre>
1. IntelligentGridAllocator->deallocate() - frees 2 cells ✅
2. custom_amount_tracking table - £150 remainder STILL THERE ❌
3. Result: Cells freed but money still tracked as "available"!
</pre>

<p class="problem"><strong>Problem:</strong> The £150 remainder stays in the system, creating phantom money!</p>

</div>

<div class='analysis critical'>
<h2>🔍 IMPACT OF THE CURRENT ENHANCEMENT</h2>

<h3>If we change calculateCellsForAmount to proportional allocation:</h3>

<h4>Enhanced APPROVAL (£350 donation):</h4>
<pre>
1. calculateCellsForAmount(£350) = 3 cells (enhanced logic)
2. Allocate 3 cells for £300 via IntelligentGridAllocator
3. Track £50 remainder in custom_amount_tracking table
4. Result: 3 cells allocated + £50 in remainder pool
</pre>

<h4>Enhanced UNAPPROVE (£350 donation):</h4>
<pre>
1. IntelligentGridAllocator->deallocate() - frees 3 cells ✅
2. custom_amount_tracking table - £50 remainder STILL THERE ❌
3. Result: More cells freed but money still phantom!
</pre>

<p class="problem"><strong>The enhancement makes the deallocation problem WORSE because more money gets orphaned!</strong></p>

</div>

<div class='analysis solution'>
<h2>💡 COMPLETE SOLUTION REQUIRED</h2>

<h3>To fix the system properly, we need BOTH:</h3>

<h4>1. Enhanced Allocation (the easy part):</h4>
<pre>
private function calculateCellsForAmount(float $amount): int {
    return (int)floor($amount / 100); // Proportional allocation
}
</pre>

<h4>2. Custom Amount Deallocation (the complex part):</h4>
<pre>
// Add to CustomAmountAllocator class:
public function deallocateCustomAmount(int $pledgeId, float $originalAmount): array
{
    // 1. Calculate how much was allocated vs remainder
    // 2. Remove remainder from custom_amount_tracking
    // 3. Handle cases where remainder was already used for other allocations
    // 4. Coordinate with IntelligentGridAllocator for cell deallocation
}
</pre>

<h4>3. Updated Unapprove Logic:</h4>
<pre>
// In admin/approved/index.php:
if ($packageId && $packageId <= 3) {
    // Fixed packages - use IntelligentGridAllocator only
    $gridAllocator->deallocate($pledgeId, $paymentId);
} else {
    // Custom amounts - use CustomAmountAllocator
    $customAllocator->deallocateCustomAmount($pledgeId, $amount);
}
</pre>

</div>

<div class='analysis warning'>
<h2>🧮 COMPLEXITY FACTORS</h2>

<h3>The Custom Amount Deallocation is Complex Because:</h3>

<ol>
<li><strong>Collective Tracking:</strong> Remainders are pooled together in one record (ID=1)</li>
<li><strong>Cross-Donation Usage:</strong> Remainder from donation A might have been used to allocate cells for donation B</li>
<li><strong>Allocation Cascades:</strong> Accumulated amounts trigger auto-allocations</li>
<li><strong>Donor Attribution:</strong> Grid cells lose individual donor tracking when allocated from pool</li>
<li><strong>Partial Deallocation:</strong> What if only some cells from an accumulated allocation need to be freed?</li>
</ol>

<h3>Additional Considerations:</h3>
<ul>
<li>What if the custom_amount_tracking pool was already used for other allocations?</li>
<li>How to restore the exact amount that was contributed by this specific donation?</li>
<li>Should we deallocate cells that were created from accumulated amounts?</li>
<li>How to maintain data integrity during partial operations?</li>
</ul>

</div>

<div class='analysis solution'>
<h2>🎯 RECOMMENDATION</h2>

<p><strong>BEFORE implementing the efficiency enhancement, we MUST implement proper custom amount deallocation!</strong></p>

<h3>Implementation Order:</h3>
<ol>
<li><strong>Step 1:</strong> Design and implement CustomAmountAllocator deallocation system</li>
<li><strong>Step 2:</strong> Update unapprove logic to use correct deallocator</li>
<li><strong>Step 3:</strong> Test deallocation thoroughly with various scenarios</li>
<li><strong>Step 4:</strong> THEN implement the efficiency enhancement</li>
</ol>

<p><strong>This is a complex system that requires careful design to avoid data corruption and phantom money!</strong></p>

</div>

</body>
</html>
