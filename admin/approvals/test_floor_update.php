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
    <title>Test Floor Update</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .test { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .success { background: #d4edda; border-color: #c3e6cb; }
        .error { background: #f8d7da; border-color: #f5c6cb; }
        button { padding: 10px 20px; margin: 5px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-test { background: #007bff; color: white; }
    </style>
</head>
<body>

<h1>🧪 TEST FLOOR UPDATE AFTER APPROVAL</h1>

<?php
// Get a pending donation to test with
$pendingDonation = $db->query("
    SELECT id, donor_name, amount, type, package_id 
    FROM pledges 
    WHERE status='pending' 
    ORDER BY id DESC 
    LIMIT 1
")->fetch_assoc();

if (!$pendingDonation) {
    echo "<div class='test error'>❌ No pending donations found to test with!</div>";
    exit;
}

echo "<div class='test'>";
echo "<h3>🎯 Test Subject:</h3>";
echo "<p><strong>ID:</strong> {$pendingDonation['id']}</p>";
echo "<p><strong>Donor:</strong> " . htmlspecialchars($pendingDonation['donor_name']) . "</p>";
echo "<p><strong>Amount:</strong> £{$pendingDonation['amount']}</p>";
echo "<p><strong>Type:</strong> {$pendingDonation['type']}</p>";
echo "<p><strong>Package ID:</strong> " . ($pendingDonation['package_id'] ?? 'NULL') . "</p>";
echo "</div>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_approve'])) {
    echo "<div class='test'>";
    echo "<h3>🚀 TESTING APPROVAL WITH FLOOR ALLOCATION</h3>";
    
    try {
        // Get the pledge ID
        $pledgeId = (int)$pendingDonation['id'];
        $uid = (int)current_user()['id'];
        
        $db->begin_transaction();
        
        // Step 1: Update pledge status
        echo "<p>1. Updating pledge status...</p>";
        $upd = $db->prepare("UPDATE pledges SET status='approved', approved_by_user_id=?, approved_at=NOW() WHERE id=?");
        $upd->bind_param('ii', $uid, $pledgeId);
        $upd->execute();
        echo "<p>✅ Pledge status updated</p>";
        
        // Step 2: Update counters
        echo "<p>2. Updating counters...</p>";
        $deltaPaid = 0.0;
        $deltaPledged = 0.0;
        if ((string)$pendingDonation['type'] === 'paid') {
            $deltaPaid = (float)$pendingDonation['amount'];
        } else {
            $deltaPledged = (float)$pendingDonation['amount'];
        }
        
        $grandDelta = $deltaPaid + $deltaPledged;
        $ctr = $db->prepare(
            "INSERT INTO counters (id, paid_total, pledged_total, grand_total, version, recalc_needed)
             VALUES (1, ?, ?, ?, 1, 0)
             ON DUPLICATE KEY UPDATE
               paid_total = paid_total + VALUES(paid_total),
               pledged_total = pledged_total + VALUES(pledged_total),
               grand_total = grand_total + VALUES(grand_total),
               version = version + 1,
               recalc_needed = 0"
        );
        $ctr->bind_param('ddd', $deltaPaid, $deltaPledged, $grandDelta);
        $ctr->execute();
        echo "<p>✅ Counters updated</p>";
        
        // Step 3: Floor allocation
        echo "<p>3. Starting floor allocation...</p>";
        require_once __DIR__ . '/../../shared/CustomAmountAllocator.php';
        $customAllocator = new CustomAmountAllocator($db);
        
        $donorName = (string)($pendingDonation['donor_name'] ?? 'Anonymous');
        $amount = (float)$pendingDonation['amount'];
        $status = ($pendingDonation['type'] === 'paid') ? 'paid' : 'pledged';
        
        echo "<p>   - Donor: $donorName</p>";
        echo "<p>   - Amount: £$amount</p>";
        echo "<p>   - Status: $status</p>";
        
        if ($pendingDonation['type'] === 'paid') {
            $allocationResult = $customAllocator->processPaymentCustomAmount(
                $pledgeId,
                $amount,
                $donorName,
                $status
            );
        } else {
            $allocationResult = $customAllocator->processCustomAmount(
                $pledgeId,
                $amount,
                $donorName,
                $status
            );
        }
        
        echo "<p><strong>Allocation Result:</strong></p>";
        echo "<pre>" . json_encode($allocationResult, JSON_PRETTY_PRINT) . "</pre>";
        
        if (isset($allocationResult['success']) && $allocationResult['success']) {
            echo "<p>✅ Floor allocation successful!</p>";
        } else {
            echo "<p>❌ Floor allocation failed: " . ($allocationResult['error'] ?? 'Unknown error') . "</p>";
        }
        
        $db->commit();
        echo "<p>✅ All changes committed</p>";
        
        echo "<h4>🎯 RESULT: APPROVAL AND FLOOR ALLOCATION COMPLETE!</h4>";
        echo "<p><strong>Now check the projector/floor map to see if it updated!</strong></p>";
        
    } catch (Exception $e) {
        $db->rollback();
        echo "<p>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p>File: " . $e->getFile() . "</p>";
        echo "<p>Line: " . $e->getLine() . "</p>";
    }
    
    echo "</div>";
    
    // Refresh the pending donation
    $pendingDonation = $db->query("
        SELECT id, donor_name, amount, type, package_id 
        FROM pledges 
        WHERE status='pending' 
        ORDER BY id DESC 
        LIMIT 1
    ")->fetch_assoc();
}
?>

<div class='test'>
<h3>🧪 Manual Test</h3>
<p>This will approve the donation above and test floor allocation step by step.</p>

<form method="POST">
    <button type="submit" name="test_approve" value="1" class="btn-test" onclick="return confirm('Approve this donation and test floor allocation?')">
        🚀 APPROVE & TEST FLOOR ALLOCATION
    </button>
</form>
</div>

<div class='test'>
<h3>📝 What This Test Does:</h3>
<ol>
<li>Updates pledge status to 'approved'</li>
<li>Updates donation counters</li>
<li>Calls CustomAmountAllocator to allocate floor cells</li>
<li>Shows detailed results of each step</li>
</ol>
<p><strong>After running this test, check the projector screen to see if the floor map updated!</strong></p>
</div>

</body>
</html>
