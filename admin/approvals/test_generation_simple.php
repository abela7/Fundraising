<?php
// Simple error checking version
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔍 Simple Generation Test</h1>";

try {
    echo "<p>1. Loading auth...</p>";
    require_once __DIR__ . '/../../shared/auth.php';
    echo "<p>✅ Auth loaded</p>";
    
    echo "<p>2. Loading database...</p>";
    require_once __DIR__ . '/../../config/db.php';
    echo "<p>✅ Database config loaded</p>";
    
    echo "<p>3. Checking login...</p>";
    require_login();
    echo "<p>✅ Login checked</p>";
    
    echo "<p>4. Checking admin...</p>";
    require_admin();
    echo "<p>✅ Admin checked</p>";
    
    echo "<p>5. Connecting to database...</p>";
    $db = db();
    echo "<p>✅ Database connected</p>";
    
    echo "<p>6. Testing query...</p>";
    $result = $db->query("SELECT COUNT(*) as count FROM pledges WHERE status='pending'")->fetch_assoc();
    echo "<p>✅ Query worked. Found: " . $result['count'] . " pending</p>";
    
    echo "<p>7. Testing registrars...</p>";
    $registrars = $db->query("SELECT id, name FROM users WHERE role IN ('registrar', 'admin') ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    echo "<p>✅ Found " . count($registrars) . " registrars</p>";
    
    echo "<p>8. Testing packages...</p>";
    $packages = $db->query("SELECT id, label, price, sqm_meters FROM donation_packages WHERE active=1 ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    echo "<p>✅ Found " . count($packages) . " packages</p>";
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_single'])) {
        echo "<p>9. Testing single pledge creation...</p>";
        
        $stmt = $db->prepare("
            INSERT INTO pledges (
                donor_name, donor_phone, source, amount, type, status, 
                package_id, client_uuid, created_by_user_id, notes
            ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)
        ");
        
        $donorName = "Test User " . time();
        $phone = "07123456789";
        $source = "test";
        $amount = 100.0;
        $type = "pledge";
        $packageId = 3;
        $uuid = "test-" . time();
        $createdBy = $registrars[0]['id'] ?? null;
        $notes = "Simple test";
        
        $stmt->bind_param('sssdsiis', 
            $donorName, $phone, $source, $amount, $type, 
            $packageId, $uuid, $createdBy, $notes
        );
        
        if ($stmt->execute()) {
            echo "<p>✅ Single pledge created successfully!</p>";
        } else {
            echo "<p>❌ Failed to create pledge: " . $stmt->error . "</p>";
        }
    }
    
    echo "<h2>✅ ALL TESTS PASSED!</h2>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
    echo "<h3>❌ ERROR FOUND!</h3>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . $e->getFile() . "</p>";
    echo "<p><strong>Line:</strong> " . $e->getLine() . "</p>";
    echo "<p><strong>Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}
?>

<form method="POST">
    <button type="submit" name="test_single" value="1" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px;">
        🧪 Test Single Pledge Creation
    </button>
</form>

<p><a href="generate_realistic_test_data.php">← Back to Main Generator</a></p>
