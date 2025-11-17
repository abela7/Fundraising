<?php
// Ultra simple test - isolate exactly where the error is happening
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "<!DOCTYPE html><html><head><title>Ultra Simple Test</title></head><body>";
echo "<h1>Ultra Simple Test - Call Center</h1>";
echo "<hr>";

// Test 1: PHP is working
echo "<h2>Test 1: PHP Basic</h2>";
echo "✓ PHP is working<br>";
echo "PHP Version: " . PHP_VERSION . "<br><br>";

// Test 2: Can we start session?
echo "<h2>Test 2: Session</h2>";
try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    echo "✓ Session started successfully<br>";
    echo "Session ID: " . session_id() . "<br>";
    echo "Session status: " . session_status() . "<br><br>";
} catch (Exception $e) {
    echo "✗ Session failed: " . $e->getMessage() . "<br><br>";
}

// Test 3: Session contents
echo "<h2>Test 3: Session Contents</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre><br>";

// Test 4: Can we include auth.php?
echo "<h2>Test 4: Include auth.php</h2>";
try {
    require_once __DIR__ . '/../../shared/auth.php';
    echo "✓ auth.php loaded<br>";
    
    echo "is_logged_in(): " . (is_logged_in() ? 'YES' : 'NO') . "<br>";
    
    if (is_logged_in()) {
        $user = current_user();
        echo "User ID: " . $user['id'] . "<br>";
        echo "User Name: " . $user['name'] . "<br>";
        echo "User Role: " . $user['role'] . "<br>";
    } else {
        echo "<strong>⚠ NOT LOGGED IN</strong><br>";
        echo "You need to <a href='../login.php'>login first</a><br>";
    }
    echo "<br>";
} catch (Exception $e) {
    echo "✗ auth.php failed: " . $e->getMessage() . "<br><br>";
}

// Test 5: Can we include db.php?
echo "<h2>Test 5: Include db.php</h2>";
try {
    require_once __DIR__ . '/../../config/db.php';
    echo "✓ db.php loaded<br><br>";
} catch (Exception $e) {
    echo "✗ db.php failed: " . $e->getMessage() . "<br><br>";
}

// Test 6: Can we connect to database?
echo "<h2>Test 6: Database Connection</h2>";
try {
    $db = db();
    echo "✓ Database connected<br>";
    echo "Host: " . $db->host_info . "<br><br>";
} catch (Exception $e) {
    echo "✗ Database failed: " . $e->getMessage() . "<br><br>";
}

// Test 7: Can we query call_center_sessions table?
echo "<h2>Test 7: Query call_center_sessions</h2>";
try {
    $db = db();
    $result = $db->query("SELECT COUNT(*) as count FROM call_center_sessions");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "✓ call_center_sessions table exists<br>";
        echo "Row count: " . $row['count'] . "<br><br>";
    } else {
        echo "✗ Query failed: " . $db->error . "<br><br>";
    }
} catch (Exception $e) {
    echo "✗ Query failed: " . $e->getMessage() . "<br><br>";
}

echo "<hr>";
echo "<h2>Summary</h2>";
echo "<p>If all tests passed and you're logged in, the call center should work.</p>";
echo "<p>If you're NOT logged in, that's the problem!</p>";
echo "<p><a href='../login.php'>Go to Login Page</a></p>";
echo "<p><a href='index.php'>Try Call Center Dashboard</a></p>";

echo "</body></html>";

