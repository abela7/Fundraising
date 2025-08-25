<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

$db = db();

echo "<!DOCTYPE html><html><head><title>Quick Fix Generator</title>";
echo "<style>body{font-family:Arial;margin:20px;} .btn{padding:15px 30px;margin:10px;border:none;border-radius:5px;cursor:pointer;font-size:16px;font-weight:bold;} .btn-generate{background:#28a745;color:white;} .btn-clear{background:#dc3545;color:white;} .success{background:#d4edda;padding:15px;border-radius:5px;margin:10px 0;} .info{background:#d1ecf1;padding:15px;border-radius:5px;margin:10px 0;}</style>";
echo "</head><body>";

echo "<h1>🚀 QUICK FIX DATA GENERATOR</h1>";

// Get system info
$currentPending = $db->query("SELECT COUNT(*) as count FROM pledges WHERE status='pending'")->fetch_assoc()['count'];
$registrars = $db->query("SELECT id, name FROM users WHERE role IN ('registrar', 'admin') ORDER BY id LIMIT 4")->fetch_all(MYSQLI_ASSOC);
$packages = $db->query("SELECT id, label, price FROM donation_packages WHERE active=1 ORDER BY id")->fetch_all(MYSQLI_ASSOC);

echo "<div class='info'>";
echo "<p><strong>Current pending:</strong> $currentPending</p>";
echo "<p><strong>Available registrars:</strong> " . count($registrars) . "</p>";
echo "<p><strong>Available packages:</strong> " . count($packages) . "</p>";
echo "</div>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'generate') {
        echo "<div class='success'>";
        echo "<h2>🚀 GENERATING REALISTIC DATA</h2>";
        
        try {
            $count = 0;
            
            // Package weights: 40% pkg3, 30% pkg2, 15% pkg1, 15% custom
            $weights = [
                ['id' => 3, 'weight' => 40],  // £100
                ['id' => 2, 'weight' => 30],  // £200  
                ['id' => 1, 'weight' => 15],  // £400
                ['id' => 4, 'weight' => 15],  // Custom
            ];
            
            // Generate 400 registrar pledges (100 each)
            foreach ($registrars as $reg) {
                for ($i = 0; $i < 100; $i++) {
                    $pkg = selectPackage($weights, $packages);
                    $amount = $pkg['id'] === 4 ? mt_rand(50, 500) : $pkg['price'];
                    
                    createQuickPledge($db, $reg['id'], $pkg['id'], $amount, 'registrar');
                    $count++;
                }
            }
            
            // Generate 100 public pledges
            for ($i = 0; $i < 100; $i++) {
                $pkg = selectPackage($weights, $packages);
                $amount = $pkg['id'] === 4 ? mt_rand(50, 500) : $pkg['price'];
                
                createQuickPledge($db, null, $pkg['id'], $amount, 'public');
                $count++;
            }
            
            echo "<p>✅ Generated $count realistic donations!</p>";
            
            $newPending = $db->query("SELECT COUNT(*) as count FROM pledges WHERE status='pending'")->fetch_assoc()['count'];
            echo "<p><strong>Total pending now:</strong> $newPending</p>";
            
        } catch (Exception $e) {
            echo "<p>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        
        echo "</div>";
        
    } elseif ($_POST['action'] === 'clear') {
        echo "<div class='success'>";
        echo "<h2>🗑️ CLEARING DATA</h2>";
        
        $stmt = $db->prepare("DELETE FROM pledges WHERE status = 'pending'");
        $stmt->execute();
        $cleared = $stmt->affected_rows;
        
        echo "<p>✅ Cleared $cleared pending donations</p>";
        echo "</div>";
    }
}

function selectPackage($weights, $packages) {
    $total = array_sum(array_column($weights, 'weight'));
    $rand = mt_rand(1, $total);
    
    $current = 0;
    foreach ($weights as $w) {
        $current += $w['weight'];
        if ($rand <= $current) {
            $pkg = array_filter($packages, fn($p) => $p['id'] === $w['id']);
            return $pkg ? array_values($pkg)[0] : ['id' => 4, 'price' => 0];
        }
    }
    return ['id' => 3, 'price' => 100];
}

function createQuickPledge($db, $createdBy, $packageId, $amount, $source) {
    $names = ['John Smith', 'Mary Johnson', 'David Brown', 'Sarah Wilson', 'Michael Davis'];
    $name = $names[array_rand($names)];
    $phone = '07' . mt_rand(100000000, 999999999);
    $type = mt_rand(1, 100) <= 80 ? 'pledge' : 'paid';
    $uuid = 'quick-' . time() . '-' . mt_rand(1000, 9999);
    $notes = "Realistic test - $source";
    $pkgId = $packageId === 4 ? null : $packageId;
    
    $stmt = $db->prepare("
        INSERT INTO pledges (donor_name, donor_phone, source, amount, type, status, package_id, client_uuid, created_by_user_id, notes) 
        VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)
    ");
    
    $stmt->bind_param('sssdsiis', $name, $phone, $source, $amount, $type, $pkgId, $uuid, $createdBy, $notes);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to create pledge: " . $stmt->error);
    }
}

echo "<h2>🎯 ACTIONS</h2>";
echo "<form method='POST'>";
echo "<button type='submit' name='action' value='generate' class='btn btn-generate' onclick=\"return confirm('Generate 500 realistic donations?')\">🚀 Generate Realistic Data</button>";
echo "<button type='submit' name='action' value='clear' class='btn btn-clear' onclick=\"return confirm('Clear all pending donations?')\">🗑️ Clear All Pending</button>";
echo "</form>";

echo "<p><strong>This will create:</strong></p>";
echo "<ul>";
echo "<li>200 donations at £100 (1/4 m²)</li>";
echo "<li>150 donations at £200 (1/2 m²)</li>";
echo "<li>75 donations at £400 (1 m²)</li>";
echo "<li>75 custom amount donations</li>";
echo "</ul>";

echo "</body></html>";
?>
