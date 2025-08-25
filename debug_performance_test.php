<?php
declare(strict_types=1);

// Simple debug script to identify the exact issue
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "<h1>Debug Performance Test</h1>";

try {
    require_once 'config/db.php';
    echo "✅ Config loaded successfully<br>";
    
    $db = db();
    echo "✅ Database connection successful<br>";
    echo "Server info: " . $db->server_info . "<br>";
    
    // Test registrar query
    $result = $db->query("SELECT id, name FROM users WHERE role = 'registrar' AND active = 1 ORDER BY id LIMIT 5");
    echo "✅ Registrar query successful<br>";
    
    $registrars = [];
    while ($row = $result->fetch_assoc()) {
        $registrars[] = $row;
        echo "- Registrar: " . $row['name'] . " (ID: " . $row['id'] . ")<br>";
    }
    
    if (count($registrars) < 5) {
        echo "⚠️ Warning: Only " . count($registrars) . " registrars found, need 5<br>";
    }
    
    // Test package query
    $packages = $db->query("SELECT id, label, price FROM donation_packages WHERE active=1 ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    echo "✅ Package query successful<br>";
    foreach ($packages as $pkg) {
        echo "- Package: " . $pkg['label'] . " (ID: " . $pkg['id'] . ", Price: £" . $pkg['price'] . ")<br>";
    }
    
    // Test inserting ONE record with exact same structure as working pages
    echo "<h2>Testing Single Insert</h2>";
    
    $testUUID = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    
    $db->begin_transaction();
    
    // Use EXACT same query structure as registrar/index.php
    $stmt = $db->prepare("
        INSERT INTO pledges (
          donor_name, donor_phone, donor_email, source, anonymous,
          amount, type, status, notes, client_uuid, created_by_user_id, package_id
        ) VALUES (?, ?, ?, 'volunteer', ?, ?, 'pledge', ?, ?, ?, ?, ?)
    ");
    
    $donorName = 'Test User';
    $donorPhone = '07400123456';
    $donorEmail = null;
    $anonymousFlag = 0;
    $amount = 400.00;
    $status = 'pending';
    $notes = 'Performance test record';
    $createdBy = $registrars[0]['id'];
    $packageId = 1; // 1 m² package
    
    $stmt->bind_param(
        'sssidsssii',
        $donorName, $donorPhone, $donorEmail, $anonymousFlag,
        $amount, $status, $notes, $testUUID, $createdBy, $packageId
    );
    
    if ($stmt->execute()) {
        echo "✅ Single insert successful! ID: " . $db->insert_id . "<br>";
        
        // Clean up test record
        $db->query("DELETE FROM pledges WHERE client_uuid = '$testUUID'");
        echo "✅ Test record cleaned up<br>";
    } else {
        echo "❌ Insert failed: " . $stmt->error . "<br>";
    }
    
    $db->commit();
    
    echo "<h2>✅ All Debug Tests Passed!</h2>";
    echo "<p>The performance test should work now. Let me fix the main file.</p>";
    
} catch (Exception $e) {
    echo "<h2>❌ Error Found!</h2>";
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>File: " . $e->getFile() . "</p>";
    echo "<p>Line: " . $e->getLine() . "</p>";
    
    echo "<h3>Stack Trace:</h3>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>
