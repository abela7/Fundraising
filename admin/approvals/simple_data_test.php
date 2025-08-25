<?php
// Simple test without authentication
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><title>Simple Data Test</title></head><body>";
echo "<h1>🔍 SIMPLE DATA TEST (NO AUTH)</h1>";

try {
    echo "<p>1. Testing database connection...</p>";
    
    // Direct database connection test (CORRECT CREDENTIALS)
    $host = 'localhost';
    $username = 'abunetdg_abela';
    $password = '2424@Admin';
    $database = 'abunetdg_fundraising';
    
    $db = new mysqli($host, $username, $password, $database);
    
    if ($db->connect_error) {
        throw new Exception("Database connection failed: " . $db->connect_error);
    }
    
    echo "<p>✅ Database connected successfully!</p>";
    
    echo "<p>2. Testing basic query...</p>";
    $result = $db->query("SELECT COUNT(*) as count FROM pledges WHERE status='pending'");
    if (!$result) {
        throw new Exception("Query failed: " . $db->error);
    }
    
    $row = $result->fetch_assoc();
    echo "<p>✅ Current pending donations: " . $row['count'] . "</p>";
    
    echo "<p>3. Testing registrars...</p>";
    $result = $db->query("SELECT id, name FROM users WHERE role IN ('registrar', 'admin') ORDER BY id LIMIT 5");
    if (!$result) {
        throw new Exception("Registrars query failed: " . $db->error);
    }
    
    $registrars = $result->fetch_all(MYSQLI_ASSOC);
    echo "<p>✅ Found " . count($registrars) . " registrars:</p>";
    echo "<ul>";
    foreach ($registrars as $r) {
        echo "<li>ID: {$r['id']}, Name: " . htmlspecialchars($r['name']) . "</li>";
    }
    echo "</ul>";
    
    echo "<p>4. Testing packages...</p>";
    $result = $db->query("SELECT id, label, price FROM donation_packages WHERE active=1 ORDER BY id");
    if (!$result) {
        throw new Exception("Packages query failed: " . $db->error);
    }
    
    $packages = $result->fetch_all(MYSQLI_ASSOC);
    echo "<p>✅ Found " . count($packages) . " packages:</p>";
    echo "<ul>";
    foreach ($packages as $p) {
        echo "<li>ID: {$p['id']}, Label: " . htmlspecialchars($p['label']) . ", Price: £{$p['price']}</li>";
    }
    echo "</ul>";
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_test'])) {
        echo "<p>5. Testing pledge creation...</p>";
        
        $stmt = $db->prepare("
            INSERT INTO pledges (
                donor_name, donor_phone, source, amount, type, status, 
                package_id, client_uuid, created_by_user_id, notes
            ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }
        
        $donorName = "TEST DONOR " . date('Y-m-d H:i:s');
        $phone = "07987654321";
        $source = "test";
        $amount = 100.0;
        $type = "pledge";
        $packageId = 3;
        $uuid = "test-" . time();
        $createdBy = $registrars[0]['id'] ?? 1;
        $notes = "Simple test donation";
        
        $stmt->bind_param('sssdsiis', 
            $donorName, $phone, $source, $amount, $type, 
            $packageId, $uuid, $createdBy, $notes
        );
        
        if ($stmt->execute()) {
            echo "<p>✅ Test pledge created successfully! ID: " . $db->insert_id . "</p>";
        } else {
            echo "<p>❌ Failed to create pledge: " . $stmt->error . "</p>";
        }
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_realistic'])) {
        echo "<p>6. GENERATING REALISTIC DATA...</p>";
        
        $created = 0;
        $packages_dist = [
            ['id' => 3, 'price' => 100, 'count' => 200], // 40%
            ['id' => 2, 'price' => 200, 'count' => 150], // 30%  
            ['id' => 1, 'price' => 400, 'count' => 75],  // 15%
            ['id' => 4, 'price' => 0, 'count' => 75],    // 15% custom
        ];
        
        $stmt = $db->prepare("
            INSERT INTO pledges (
                donor_name, donor_phone, source, amount, type, status, 
                package_id, client_uuid, created_by_user_id, notes
            ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)
        ");
        
        foreach ($packages_dist as $pkg) {
            for ($i = 0; $i < $pkg['count']; $i++) {
                $donorName = "Donor " . ($created + 1);
                $phone = "07" . str_pad(mt_rand(100000000, 999999999), 9, '0', STR_PAD_LEFT);
                $source = $created < 400 ? "registrar" : "public";
                $amount = $pkg['id'] === 4 ? mt_rand(50, 500) : $pkg['price'];
                $type = mt_rand(1, 100) <= 80 ? "pledge" : "paid";
                $packageId = $pkg['id'] === 4 ? null : $pkg['id'];
                $uuid = "realistic-" . time() . "-" . $created;
                $createdBy = $registrars[($created % count($registrars))]['id'] ?? 1;
                $notes = "Realistic test data";
                
                $stmt->bind_param('sssdsiis', 
                    $donorName, $phone, $source, $amount, $type, 
                    $packageId, $uuid, $createdBy, $notes
                );
                
                if ($stmt->execute()) {
                    $created++;
                } else {
                    echo "<p>❌ Failed at #$created: " . $stmt->error . "</p>";
                    break 2;
                }
            }
        }
        
        echo "<p>✅ Created $created realistic donations!</p>";
        
        $newCount = $db->query("SELECT COUNT(*) as count FROM pledges WHERE status='pending'")->fetch_assoc();
        echo "<p><strong>Total pending now:</strong> " . $newCount['count'] . "</p>";
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_all'])) {
        echo "<p>7. CLEARING ALL PENDING...</p>";
        
        $result = $db->query("DELETE FROM pledges WHERE status = 'pending'");
        if ($result) {
            echo "<p>✅ Cleared all pending donations. Affected rows: " . $db->affected_rows . "</p>";
        } else {
            echo "<p>❌ Failed to clear: " . $db->error . "</p>";
        }
    }
    
    echo "<h2>✅ ALL BASIC TESTS PASSED!</h2>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3>❌ ERROR FOUND!</h3>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . $e->getFile() . "</p>";
    echo "<p><strong>Line:</strong> " . $e->getLine() . "</p>";
    echo "</div>";
}
?>

<div style="margin: 20px 0; padding: 20px; background: #f8f9fa; border-radius: 5px;">
<h2>🎯 TEST ACTIONS</h2>
<form method="POST" style="margin: 10px 0;">
    <button type="submit" name="create_test" value="1" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; margin: 5px;">
        🧪 Create Single Test Pledge
    </button>
</form>

<form method="POST" style="margin: 10px 0;">
    <button type="submit" name="generate_realistic" value="1" style="padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 4px; margin: 5px;" onclick="return confirm('Generate 500 realistic donations?')">
        🚀 Generate 500 Realistic Donations
    </button>
</form>

<form method="POST" style="margin: 10px 0;">
    <button type="submit" name="clear_all" value="1" style="padding: 10px 20px; background: #dc3545; color: white; border: none; border-radius: 4px; margin: 5px;" onclick="return confirm('Clear ALL pending donations?')">
        🗑️ Clear All Pending
    </button>
</form>
</div>

<p><strong>This version bypasses authentication to test the core functionality!</strong></p>

</body></html>
