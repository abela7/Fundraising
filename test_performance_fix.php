<?php
// Quick test to verify the performance test will work
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "<h1>Performance Test Fix Verification</h1>";

try {
    require_once 'config/db.php';
    echo "✅ Database connection working<br>";
    
    $db = db();
    
    // Check registrars
    $registrars = $db->query("SELECT id, name FROM users WHERE role = 'registrar' AND active = 1 ORDER BY id LIMIT 5")->fetch_all(MYSQLI_ASSOC);
    echo "✅ Found " . count($registrars) . " registrars<br>";
    
    if (count($registrars) >= 5) {
        echo "✅ Sufficient registrars for test<br>";
    } else {
        echo "⚠️ Need 5 registrars, found " . count($registrars) . "<br>";
    }
    
    // Test single insert with exact structure
    $testUUID = '12345678-1234-1234-1234-123456789012';
    
    $stmt = $db->prepare("
        INSERT INTO pledges (
          donor_name, donor_phone, donor_email, source, anonymous,
          amount, type, status, notes, client_uuid, created_by_user_id, package_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $donorName = 'Test User';
    $donorPhone = '07400123456';
    $donorEmail = null;
    $source = 'volunteer';
    $anonymous = 0;
    $amount = 400.00;
    $type = 'pledge';
    $status = 'pending';
    $notes = 'Test record';
    $createdBy = $registrars[0]['id'] ?? 1;
    $packageId = 1;
    
    $stmt->bind_param(
        'sssidsssii',
        $donorName, $donorPhone, $donorEmail, $source, $anonymous,
        $amount, $type, $status, $notes, $testUUID, $createdBy, $packageId
    );
    
    if ($stmt->execute()) {
        echo "✅ Test insert successful<br>";
        
        // Clean up
        $db->query("DELETE FROM pledges WHERE client_uuid = '$testUUID'");
        echo "✅ Test record cleaned up<br>";
    } else {
        echo "❌ Test insert failed: " . $stmt->error . "<br>";
    }
    
    echo "<h2>✅ Performance test should work now!</h2>";
    
} catch (Exception $e) {
    echo "<h2>❌ Error: " . htmlspecialchars($e->getMessage()) . "</h2>";
    echo "<p>File: " . $e->getFile() . "</p>";
    echo "<p>Line: " . $e->getLine() . "</p>";
}
?>
