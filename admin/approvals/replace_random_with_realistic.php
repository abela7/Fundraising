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
    <title>🔄 Replace Random Data with Realistic Data</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .section { border: 1px solid #ddd; margin: 10px 0; padding: 15px; border-radius: 5px; }
        .info { background-color: #d1ecf1; border-color: #bee5eb; }
        .success { background-color: #d4edda; border-color: #c3e6cb; }
        .warning { background-color: #fff3cd; border-color: #ffeaa7; }
        .danger { background-color: #f8d7da; border-color: #f5c6cb; }
        .btn { 
            padding: 15px 30px; 
            margin: 10px; 
            border: none; 
            border-radius: 5px; 
            cursor: pointer; 
            font-size: 16px;
            font-weight: bold;
            display: inline-block;
        }
        .btn-replace { background-color: #28a745; color: white; }
        .btn-clear { background-color: #dc3545; color: white; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f8f9fa; }
        .stats { display: flex; gap: 20px; margin: 20px 0; }
        .stat-box { flex: 1; text-align: center; padding: 15px; background: #f8f9fa; border-radius: 5px; }
    </style>
</head>
<body>

<h1>🔄 REPLACE RANDOM DATA WITH REALISTIC DATA</h1>

<?php
// Get current system info
$registrars = $db->query("SELECT id, name FROM users WHERE role IN ('registrar', 'admin') ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$packages = $db->query("SELECT id, label, price, sqm_meters FROM donation_packages WHERE active=1 ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$currentPending = $db->query("SELECT COUNT(*) as count FROM pledges WHERE status='pending'")->fetch_assoc()['count'];

// Analyze current data
$currentAnalysis = $db->query("
    SELECT 
        p.package_id,
        dp.label as package_label,
        COUNT(*) as count,
        SUM(p.amount) as total_amount,
        AVG(p.amount) as avg_amount
    FROM pledges p 
    LEFT JOIN donation_packages dp ON p.package_id = dp.id
    WHERE p.status = 'pending'
    GROUP BY p.package_id
    ORDER BY p.package_id
")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'replace') {
        echo "<div class='section success'>";
        echo "<h2>🔄 REPLACING RANDOM DATA WITH REALISTIC DATA</h2>";
        
        try {
            // STEP 1: Clear all current pending donations
            echo "<p>🗑️ Clearing current pending donations...</p>";
            $clearStmt = $db->prepare("DELETE FROM pledges WHERE status = 'pending'");
            $clearStmt->execute();
            $cleared = $clearStmt->affected_rows;
            echo "<p>✅ Cleared $cleared pending donations</p>";
            
            // STEP 2: Generate new realistic data
            echo "<p>🚀 Generating realistic test data...</p>";
            $generated = generateRealisticTestData($db, $registrars, $packages);
            
            echo "<h3>✅ Replacement Complete!</h3>";
            echo "<div class='stats'>";
            echo "<div class='stat-box'><strong>$cleared</strong><br>Random Cleared</div>";
            echo "<div class='stat-box'><strong>" . $generated['total'] . "</strong><br>Realistic Created</div>";
            echo "<div class='stat-box'><strong>" . $generated['registrar_pledges'] . "</strong><br>Registrar Pledges</div>";
            echo "<div class='stat-box'><strong>" . $generated['public_pledges'] . "</strong><br>Public Pledges</div>";
            echo "</div>";
            
            echo "<h4>New Distribution:</h4>";
            echo "<ul>";
            foreach ($generated['breakdown'] as $type => $count) {
                echo "<li><strong>$type:</strong> $count donations</li>";
            }
            echo "</ul>";
            
        } catch (Exception $e) {
            echo "<div class='danger'>";
            echo "<h3>❌ ERROR DURING REPLACEMENT</h3>";
            echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "</div>";
        }
        
        echo "</div>";
        
    } elseif ($_POST['action'] === 'clear_only') {
        echo "<div class='section warning'>";
        echo "<h2>🗑️ CLEARING ALL PENDING DATA</h2>";
        
        try {
            $clearStmt = $db->prepare("DELETE FROM pledges WHERE status = 'pending'");
            $clearStmt->execute();
            $cleared = $clearStmt->affected_rows;
            echo "<p>✅ Cleared $cleared pending donations</p>";
        } catch (Exception $e) {
            echo "<p>❌ Error clearing data: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        
        echo "</div>";
    }
    
    // Refresh data after operations
    $currentPending = $db->query("SELECT COUNT(*) as count FROM pledges WHERE status='pending'")->fetch_assoc()['count'];
    $currentAnalysis = $db->query("
        SELECT 
            p.package_id,
            dp.label as package_label,
            COUNT(*) as count,
            SUM(p.amount) as total_amount,
            AVG(p.amount) as avg_amount
        FROM pledges p 
        LEFT JOIN donation_packages dp ON p.package_id = dp.id
        WHERE p.status = 'pending'
        GROUP BY p.package_id
        ORDER BY p.package_id
    ")->fetch_all(MYSQLI_ASSOC);
}

function generateRealisticTestData($db, $registrars, $packages) {
    $generated = [
        'registrar_pledges' => 0,
        'public_pledges' => 0,
        'total' => 0,
        'breakdown' => []
    ];
    
    // Package distributions (realistic percentages)
    $packageDistribution = [
        ['package' => 3, 'weight' => 40], // 1/4 m² (£100) - Most popular
        ['package' => 2, 'weight' => 30], // 1/2 m² (£200) - Common
        ['package' => 1, 'weight' => 15], // 1 m² (£400) - Less common
        ['package' => 4, 'weight' => 15], // Custom - Various amounts
    ];
    
    // Take first 4 registrars
    $selectedRegistrars = array_slice($registrars, 0, 4);
    
    // Generate 100 pledges per registrar (400 total)
    foreach ($selectedRegistrars as $registrar) {
        for ($i = 0; $i < 100; $i++) {
            $packageData = selectWeightedPackage($packageDistribution, $packages);
            
            if ($packageData['package_id'] === 4) {
                // Custom amount - varied distribution
                $customAmount = generateCustomAmount();
                createPledge($db, $registrar['id'], $packageData['package_id'], $customAmount, 'registrar');
                
                $category = $customAmount < 100 ? 'Custom Under £100' : 
                           ($customAmount >= 400 ? 'Custom £400+' : 'Custom £100-£399');
                $generated['breakdown'][$category] = ($generated['breakdown'][$category] ?? 0) + 1;
            } else {
                // Fixed package
                createPledge($db, $registrar['id'], $packageData['package_id'], $packageData['price'], 'registrar');
                $generated['breakdown'][$packageData['label']] = ($generated['breakdown'][$packageData['label']] ?? 0) + 1;
            }
            
            $generated['registrar_pledges']++;
        }
    }
    
    // Generate 100 public pledges (from donation page)
    for ($i = 0; $i < 100; $i++) {
        $packageData = selectWeightedPackage($packageDistribution, $packages);
        
        if ($packageData['package_id'] === 4) {
            $customAmount = generateCustomAmount();
            createPledge($db, null, $packageData['package_id'], $customAmount, 'public');
            
            $category = $customAmount < 100 ? 'Custom Under £100' : 
                       ($customAmount >= 400 ? 'Custom £400+' : 'Custom £100-£399');
            $generated['breakdown'][$category] = ($generated['breakdown'][$category] ?? 0) + 1;
        } else {
            createPledge($db, null, $packageData['package_id'], $packageData['price'], 'public');
            $generated['breakdown'][$packageData['label']] = ($generated['breakdown'][$packageData['label']] ?? 0) + 1;
        }
        
        $generated['public_pledges']++;
    }
    
    $generated['total'] = $generated['registrar_pledges'] + $generated['public_pledges'];
    
    return $generated;
}

function selectWeightedPackage($distribution, $packages) {
    $totalWeight = array_sum(array_column($distribution, 'weight'));
    $random = mt_rand(1, $totalWeight);
    
    $currentWeight = 0;
    foreach ($distribution as $item) {
        $currentWeight += $item['weight'];
        if ($random <= $currentWeight) {
            $packageId = $item['package'];
            $package = array_filter($packages, fn($p) => $p['id'] === $packageId)[0] ?? null;
            
            return [
                'package_id' => $packageId,
                'label' => $package['label'] ?? 'Custom',
                'price' => (float)($package['price'] ?? 0)
            ];
        }
    }
    
    // Fallback
    return ['package_id' => 3, 'label' => '1/4 m²', 'price' => 100.0];
}

function generateCustomAmount() {
    // Realistic custom amount distribution
    $rand = mt_rand(1, 100);
    
    if ($rand <= 40) {
        // 40% - Under £100 (£10-£95)
        return mt_rand(10, 95);
    } elseif ($rand <= 70) {
        // 30% - £100-£399 range
        return mt_rand(100, 399);
    } elseif ($rand <= 90) {
        // 20% - £400-£799 range
        return mt_rand(400, 799);
    } else {
        // 10% - Large donations £800-£2000
        return mt_rand(800, 2000);
    }
}

function createPledge($db, $createdBy, $packageId, $amount, $source) {
    // Generate realistic names
    $firstNames = ['John', 'Mary', 'David', 'Sarah', 'Michael', 'Emma', 'James', 'Lisa', 'Robert', 'Jennifer', 'William', 'Linda', 'Richard', 'Barbara', 'Charles', 'Elizabeth', 'Joseph', 'Helen', 'Thomas', 'Nancy'];
    $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Miller', 'Davis', 'Garcia', 'Rodriguez', 'Wilson', 'Martinez', 'Anderson', 'Taylor', 'Thomas', 'Hernandez', 'Moore', 'Martin', 'Jackson', 'Thompson', 'White'];
    
    $firstName = $firstNames[array_rand($firstNames)];
    $lastName = $lastNames[array_rand($lastNames)];
    $donorName = "$firstName $lastName";
    
    // Generate realistic phone numbers
    $phone = '07' . mt_rand(100000000, 999999999);
    
    // Determine type (most pledges, some paid)
    $type = (mt_rand(1, 100) <= 80) ? 'pledge' : 'paid';
    
    // Generate UUID
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    
    $stmt = $db->prepare("
        INSERT INTO pledges (
            donor_name, donor_phone, source, amount, type, status, 
            package_id, client_uuid, created_by_user_id, notes
        ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)
    ");
    
    $notes = "Realistic test data - $source donation";
    $packageIdNullable = $packageId > 0 ? $packageId : null;
    
    $stmt->bind_param('sssdsiis', 
        $donorName, $phone, $source, $amount, $type, 
        $packageIdNullable, $uuid, $createdBy, $notes
    );
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to create pledge: " . $stmt->error);
    }
}
?>

<div class='section info'>
<h2>📊 CURRENT SYSTEM STATE</h2>

<div class='stats'>
<div class='stat-box'>
    <strong><?= count($registrars) ?></strong><br>
    Available Registrars
</div>
<div class='stat-box'>
    <strong><?= $currentPending ?></strong><br>
    Current Pending
</div>
<div class='stat-box'>
    <strong><?= count($packages) ?></strong><br>
    Active Packages
</div>
</div>

<h3>Current Pending Distribution:</h3>
<?php if (empty($currentAnalysis)): ?>
<p>No pending donations found.</p>
<?php else: ?>
<table>
<tr><th>Package ID</th><th>Package</th><th>Count</th><th>Total Amount</th><th>Average</th></tr>
<?php foreach ($currentAnalysis as $row): ?>
<tr>
    <td><?= $row['package_id'] ?? 'NULL' ?></td>
    <td><?= htmlspecialchars($row['package_label'] ?? 'Custom/No Package') ?></td>
    <td><?= $row['count'] ?></td>
    <td>£<?= number_format($row['total_amount'], 2) ?></td>
    <td>£<?= number_format($row['avg_amount'], 2) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

</div>

<div class='section danger'>
<h2>🔄 REPLACE RANDOM DATA WITH REALISTIC DATA</h2>

<p><strong>⚠️ THIS WILL:</strong></p>
<ul>
<li>Delete all current pending donations (<?= $currentPending ?> total)</li>
<li>Generate 500 NEW realistic donations with proper distribution</li>
<li>Use 4 registrars × 100 donations each (400 total)</li>
<li>Add 100 public donations</li>
<li>Most will be standard packages (1m², 0.5m², 0.25m²)</li>
<li>Only 15% will be custom amounts</li>
</ul>

<form method="POST" style="margin: 20px 0;">
    <button type="submit" name="action" value="replace" class="btn btn-replace" 
            onclick="return confirm('⚠️ This will DELETE all current pending donations and replace with realistic data. Continue?')">
        🔄 REPLACE WITH REALISTIC DATA
    </button>
    
    <button type="submit" name="action" value="clear_only" class="btn btn-clear"
            onclick="return confirm('⚠️ This will DELETE all pending donations. Continue?')">
        🗑️ CLEAR ALL PENDING ONLY
    </button>
</form>

</div>

<div class='section'>
<h2>🎯 NEW REALISTIC DISTRIBUTION PLAN</h2>

<h3>Package Distribution (Realistic):</h3>
<ul>
<li><strong>40%</strong> - 1/4 m² (£100) - Most popular = ~200 donations</li>
<li><strong>30%</strong> - 1/2 m² (£200) - Common = ~150 donations</li>
<li><strong>15%</strong> - 1 m² (£400) - Less common = ~75 donations</li>
<li><strong>15%</strong> - Custom amounts - Various = ~75 donations</li>
</ul>

<h3>Sources:</h3>
<ul>
<li><strong>400 Registrar Donations:</strong> From 4 registrars (100 each)</li>
<li><strong>100 Public Donations:</strong> Self-submitted via donation page</li>
</ul>

<p><strong>This matches real-world usage patterns where most people donate standard packages!</strong></p>

</div>

</body>
</html>
