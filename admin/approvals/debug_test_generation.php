<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

$db = db();

echo "<h1>🔍 DEBUG TEST DATA GENERATION</h1>";

// Test database connection
echo "<h2>1. Database Connection Test</h2>";
try {
    $result = $db->query("SELECT COUNT(*) as count FROM pledges WHERE status='pending'")->fetch_assoc();
    echo "✅ Database connected. Current pending: " . $result['count'] . "<br>";
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "<br>";
    exit;
}

// Test registrars
echo "<h2>2. Registrars Test</h2>";
try {
    $registrars = $db->query("SELECT id, name FROM users WHERE role IN ('registrar', 'admin') ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    echo "✅ Found " . count($registrars) . " registrars:<br>";
    foreach (array_slice($registrars, 0, 4) as $r) {
        echo "- ID: {$r['id']}, Name: {$r['name']}<br>";
    }
} catch (Exception $e) {
    echo "❌ Registrars error: " . $e->getMessage() . "<br>";
}

// Test packages
echo "<h2>3. Packages Test</h2>";
try {
    $packages = $db->query("SELECT id, label, price, sqm_meters FROM donation_packages WHERE active=1 ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    echo "✅ Found " . count($packages) . " packages:<br>";
    foreach ($packages as $p) {
        echo "- ID: {$p['id']}, Label: {$p['label']}, Price: £{$p['price']}<br>";
    }
} catch (Exception $e) {
    echo "❌ Packages error: " . $e->getMessage() . "<br>";
}

// Test single pledge creation
echo "<h2>4. Single Pledge Creation Test</h2>";
try {
    $testRegistrar = $registrars[0] ?? null;
    if ($testRegistrar) {
        $stmt = $db->prepare("
            INSERT INTO pledges (
                donor_name, donor_phone, source, amount, type, status, 
                package_id, client_uuid, created_by_user_id, notes
            ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)
        ");
        
        $donorName = "TEST USER DEBUG";
        $phone = "07123456789";
        $source = "debug";
        $amount = 100.0;
        $type = "pledge";
        $packageId = 3;
        $uuid = "debug-test-" . time();
        $createdBy = $testRegistrar['id'];
        $notes = "Debug test data";
        
        $stmt->bind_param('sssdsiis', 
            $donorName, $phone, $source, $amount, $type, 
            $packageId, $uuid, $createdBy, $notes
        );
        
        if ($stmt->execute()) {
            echo "✅ Test pledge created successfully! ID: " . $db->insert_id . "<br>";
            
            // Verify it's in database
            $verify = $db->query("SELECT COUNT(*) as count FROM pledges WHERE notes = 'Debug test data'")->fetch_assoc();
            echo "✅ Verification: " . $verify['count'] . " debug pledges found<br>";
        } else {
            echo "❌ Failed to create test pledge: " . $stmt->error . "<br>";
        }
    } else {
        echo "❌ No registrars found to test with<br>";
    }
} catch (Exception $e) {
    echo "❌ Single pledge creation error: " . $e->getMessage() . "<br>";
}

// Test the generation function
echo "<h2>5. Test Generation Function</h2>";
if (isset($_GET['test_generate'])) {
    echo "🚀 Testing generation function...<br>";
    
    // Include the functions from the main file
    include_once 'generate_realistic_test_data.php';
    
    try {
        echo "📝 Calling generateRealisticTestData...<br>";
        $result = generateRealisticTestData($db, $registrars, $packages);
        echo "✅ Generation completed!<br>";
        echo "Results: " . json_encode($result) . "<br>";
    } catch (Exception $e) {
        echo "❌ Generation function error: " . $e->getMessage() . "<br>";
        echo "Stack trace: " . $e->getTraceAsString() . "<br>";
    }
} else {
    echo "<a href='?test_generate=1'>🧪 Test Generation Function</a><br>";
}

// Clean up debug data
echo "<h2>6. Cleanup</h2>";
echo "<a href='?cleanup=1'>🗑️ Clean Debug Data</a><br>";

if (isset($_GET['cleanup'])) {
    $stmt = $db->prepare("DELETE FROM pledges WHERE notes IN ('Debug test data', 'Test data - registrar donation', 'Test data - public donation')");
    $stmt->execute();
    echo "✅ Cleaned up " . $stmt->affected_rows . " debug records<br>";
}

?>
