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
    <title>🧪 Generate Realistic Test Data</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .section { border: 1px solid #ddd; margin: 10px 0; padding: 15px; border-radius: 5px; }
        .info { background-color: #d1ecf1; border-color: #bee5eb; }
        .success { background-color: #d4edda; border-color: #c3e6cb; }
        .warning { background-color: #fff3cd; border-color: #ffeaa7; }
        .btn { 
            padding: 15px 30px; 
            margin: 10px; 
            border: none; 
            border-radius: 5px; 
            cursor: pointer; 
            font-size: 16px;
            font-weight: bold;
            display: inline-block;
            text-decoration: none;
        }
        .btn-generate { 
            background-color: #007bff !important; 
            color: white !important; 
            border: 2px solid #007bff !important;
        }
        .btn-clear { 
            background-color: #dc3545 !important; 
            color: white !important; 
            border: 2px solid #dc3545 !important;
        }
        .btn:hover {
            opacity: 0.8;
            transform: scale(1.05);
        }
        .actions-section {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin: 20px 0;
        }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f8f9fa; }
        .stats { display: flex; gap: 20px; }
        .stat-box { flex: 1; text-align: center; padding: 15px; background: #f8f9fa; border-radius: 5px; }
    </style>
</head>
<body>

<h1>🧪 REALISTIC TEST DATA GENERATOR</h1>

<!-- QUICK ACTION BUTTONS (Top of page) -->
<div style="background: #e9ecef; padding: 15px; border-radius: 8px; margin: 20px 0; text-align: center;">
    <h3 style="margin: 0 0 15px 0;">Quick Actions</h3>
    <form method="POST" style="display: inline;">
        <button type="submit" name="action" value="generate" 
                style="background: #28a745; color: white; padding: 12px 25px; border: none; border-radius: 5px; font-size: 16px; margin: 5px; cursor: pointer;"
                onclick="return confirm('Generate 500 realistic test donations?')">
            🚀 GENERATE TEST DATA
        </button>
        <button type="submit" name="action" value="clear" 
                style="background: #dc3545; color: white; padding: 12px 25px; border: none; border-radius: 5px; font-size: 16px; margin: 5px; cursor: pointer;"
                onclick="return confirm('Clear all test data?')">
            🗑️ CLEAR TEST DATA
        </button>
    </form>
</div>

<?php
// Get current system info
$registrars = $db->query("SELECT id, name FROM users WHERE role IN ('registrar', 'admin') ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$packages = $db->query("SELECT id, label, price, sqm_meters FROM donation_packages WHERE active=1 ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$currentPending = $db->query("SELECT COUNT(*) as count FROM pledges WHERE status='pending'")->fetch_assoc()['count'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'generate') {
        echo "<div class='section success'>";
        echo "<h2>🚀 GENERATING REALISTIC TEST DATA</h2>";
        
        try {
            echo "<p>📊 Found " . count($registrars) . " registrars and " . count($packages) . " packages</p>";
            echo "<p>🔄 Starting generation...</p>";
            
            $generated = generateRealisticTestData($db, $registrars, $packages);
            
            echo "<h3>✅ Generation Complete!</h3>";
            echo "<div class='stats'>";
            echo "<div class='stat-box'><strong>" . $generated['registrar_pledges'] . "</strong><br>Registrar Pledges</div>";
            echo "<div class='stat-box'><strong>" . $generated['public_pledges'] . "</strong><br>Public Pledges</div>";
            echo "<div class='stat-box'><strong>" . $generated['total'] . "</strong><br>Total Created</div>";
            echo "</div>";
            
            echo "<h4>Distribution Breakdown:</h4>";
            echo "<ul>";
            foreach ($generated['breakdown'] as $type => $count) {
                echo "<li><strong>$type:</strong> $count donations</li>";
            }
            echo "</ul>";
            
            // Verify data was actually created
            $currentPendingAfter = $db->query("SELECT COUNT(*) as count FROM pledges WHERE status='pending'")->fetch_assoc()['count'];
            echo "<p><strong>Total pending donations now:</strong> $currentPendingAfter</p>";
            
        } catch (Exception $e) {
            echo "<div class='section' style='background-color: #f8d7da;'>";
            echo "<h3>❌ ERROR DURING GENERATION</h3>";
            echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<p><strong>File:</strong> " . $e->getFile() . "</p>";
            echo "<p><strong>Line:</strong> " . $e->getLine() . "</p>";
            echo "</div>";
        }
        
        echo "</div>";
        
    } elseif ($_POST['action'] === 'clear') {
        echo "<div class='section warning'>";
        echo "<h2>🗑️ CLEARING TEST DATA</h2>";
        
        try {
            $cleared = clearTestData($db);
            echo "<p>✅ Cleared $cleared pending test donations</p>";
        } catch (Exception $e) {
            echo "<p>❌ Error clearing data: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        
        echo "</div>";
    }
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
    $firstNames = ['John', 'Mary', 'David', 'Sarah', 'Michael', 'Emma', 'James', 'Lisa', 'Robert', 'Jennifer'];
    $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Miller', 'Davis', 'Garcia', 'Rodriguez', 'Wilson'];
    
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
    
    $notes = "Test data - $source donation";
    $packageIdNullable = $packageId > 0 ? $packageId : null;
    
    $stmt->bind_param('sssdsiis', 
        $donorName, $phone, $source, $amount, $type, 
        $packageIdNullable, $uuid, $createdBy, $notes
    );
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to create pledge: " . $stmt->error . " - Data: $donorName, $amount, $source");
    }
}

function clearTestData($db) {
    $stmt = $db->prepare("DELETE FROM pledges WHERE notes LIKE 'Test data -%' AND status = 'pending'");
    $stmt->execute();
    return $stmt->affected_rows;
}
?>

<div class='section info'>
<h2>📊 CURRENT SYSTEM STATE</h2>

<div class='stats'>
<div class='stat-box'>
    <strong><?= count($registrars) ?></strong><br>
    Total Registrars
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

<h3>Available Registrars:</h3>
<table>
<tr><th>ID</th><th>Name</th></tr>
<?php foreach (array_slice($registrars, 0, 4) as $registrar): ?>
<tr><td><?= $registrar['id'] ?></td><td><?= htmlspecialchars($registrar['name']) ?></td></tr>
<?php endforeach; ?>
</table>

<h3>Available Packages:</h3>
<table>
<tr><th>ID</th><th>Package</th><th>Price</th><th>Area</th></tr>
<?php foreach ($packages as $package): ?>
<tr>
    <td><?= $package['id'] ?></td>
    <td><?= htmlspecialchars($package['label']) ?></td>
    <td>£<?= number_format($package['price'], 2) ?></td>
    <td><?= $package['sqm_meters'] ?> m²</td>
</tr>
<?php endforeach; ?>
</table>

</div>

<div class='section'>
<h2>🎯 REALISTIC TEST DATA PLAN</h2>

<h3>Distribution Strategy:</h3>
<ul>
<li><strong>400 Registrar Pledges:</strong> 4 registrars × 100 each</li>
<li><strong>100 Public Pledges:</strong> From donation page</li>
<li><strong>Total: 500 realistic donations</strong></li>
</ul>

<h3>Package Distribution (Realistic):</h3>
<ul>
<li><strong>40%</strong> - 1/4 m² (£100) - Most popular</li>
<li><strong>30%</strong> - 1/2 m² (£200) - Common</li>
<li><strong>15%</strong> - 1 m² (£400) - Less common</li>
<li><strong>15%</strong> - Custom amounts - Various</li>
</ul>

<h3>Custom Amount Distribution:</h3>
<ul>
<li><strong>40%</strong> - Under £100 (£10-£95)</li>
<li><strong>30%</strong> - £100-£399 range</li>
<li><strong>20%</strong> - £400-£799 range</li>
<li><strong>10%</strong> - Large donations (£800-£2000)</li>
</ul>

<div class="actions-section">
<h3>🎯 ACTIONS</h3>
<form method="POST" style="margin: 0;">
    <button type="submit" name="action" value="generate" class="btn btn-generate" 
            onclick="return confirm('Generate 500 realistic test donations?')">
        🚀 Generate Realistic Test Data
    </button>
    
    <button type="submit" name="action" value="clear" class="btn btn-clear"
            onclick="return confirm('Clear all test data?')">
        🗑️ Clear Test Data
    </button>
</form>
<p style="margin-top: 15px; color: #666;">
    <strong>Generate:</strong> Creates 500 realistic donations<br>
    <strong>Clear:</strong> Removes all test data
</p>
</div>

</div>

<div class='section warning'>
<h2>⚠️ NOTES</h2>
<ul>
<li>This will generate realistic test data with proper package distribution</li>
<li>Most donations will be standard packages (1m², 0.5m², 0.25m²)</li>
<li>Custom amounts will have realistic variety</li>
<li>Data will be marked as test data for easy cleanup</li>
<li>Generated pledges will be in 'pending' status for testing approval workflow</li>
</ul>
</div>

</body>
</html>
