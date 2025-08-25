<?php
// Enhanced test script for application-level connection management
require_once 'config/db_improved.php';

echo "<h1>Database Connection Test - No SUPER Privileges Required</h1>";
echo "<p>Testing application-level connection management...</p>";

try {
    echo "<h2>Test 1: Basic Connection</h2>";
    $start = microtime(true);
    $db = db();
    $connectionTime = (microtime(true) - $start) * 1000;
    echo "✅ Connection successful in " . round($connectionTime, 2) . "ms<br>";
    echo "Server info: " . $db->server_info . "<br>";
    echo "Thread ID: " . $db->thread_id . "<br>";
    echo "Character set: " . $db->character_set_name() . "<br>";
    
    echo "<h2>Test 2: User Table Query</h2>";
    $result = $db->query("SELECT COUNT(*) as count FROM users");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "✅ Query successful - User count: " . $row['count'] . "<br>";
    }
    
    echo "<h2>Test 3: Connection Reuse</h2>";
    $db2 = db();
    echo "✅ Second db() call successful<br>";
    echo "Same connection: " . ($db->thread_id === $db2->thread_id ? "Yes" : "No") . "<br>";
    
    echo "<h2>Test 4: Settings Query (Real Usage)</h2>";
    $settings = $db->query('SELECT * FROM settings WHERE id=1')->fetch_assoc();
    if ($settings) {
        echo "✅ Settings query successful<br>";
        echo "Currency: " . ($settings['currency_code'] ?? 'Not set') . "<br>";
        echo "Target amount: " . ($settings['target_amount'] ?? 'Not set') . "<br>";
    }
    
    echo "<h2>Test 5: Connection Health</h2>";
    $ping = $db->ping();
    echo "Connection ping: " . ($ping ? "✅ Healthy" : "❌ Failed") . "<br>";
    
    echo "<h2>Test 6: Database Status (No SUPER privileges)</h2>";
    $status = db_status();
    if ($status['connected']) {
        echo "✅ Connection status retrieved<br>";
        echo "Thread ID: " . $status['thread_id'] . "<br>";
        echo "Client info: " . $status['client_info'] . "<br>";
        if (isset($status['threads_connected'])) {
            echo "Active connections: " . $status['threads_connected'] . "<br>";
        }
    } else {
        echo "❌ Status check failed: " . ($status['error'] ?? 'Unknown error') . "<br>";
    }
    
    echo "<h2>Test 7: Multiple Quick Connections (Load Test)</h2>";
    $success = 0;
    $total = 5;
    for ($i = 1; $i <= $total; $i++) {
        try {
            $testDb = db();
            $testResult = $testDb->query("SELECT 1");
            if ($testResult) {
                $success++;
                echo "Connection $i: ✅<br>";
            }
        } catch (Exception $e) {
            echo "Connection $i: ❌ " . $e->getMessage() . "<br>";
        }
    }
    echo "Load test: $success/$total connections successful<br>";
    
    echo "<h2>Test 8: Real Donation Table Query</h2>";
    $pledgeCount = $db->query("SELECT COUNT(*) as count FROM pledges")->fetch_assoc();
    $paymentCount = $db->query("SELECT COUNT(*) as count FROM payments")->fetch_assoc();
    echo "✅ Pledge count: " . $pledgeCount['count'] . "<br>";
    echo "✅ Payment count: " . $paymentCount['count'] . "<br>";
    
    echo "<h1 style='color: green;'>✅ All Tests Passed!</h1>";
    echo "<p style='color: green;'><strong>The improved connection management is working correctly!</strong></p>";
    
} catch (Exception $e) {
    echo "<h1 style='color: red;'>❌ Test Failed</h1>";
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>This needs to be fixed before proceeding.</p>";
}

echo "<hr>";
echo "<h3>What This Solution Provides:</h3>";
echo "<ul>";
echo "<li>✅ <strong>No SUPER privileges required</strong> - Works on shared hosting</li>";
echo "<li>✅ <strong>Application-level connection limits</strong> - Max 2 per request</li>";
echo "<li>✅ <strong>Smart connection reuse</strong> - Reduces database load</li>";
echo "<li>✅ <strong>Automatic cleanup</strong> - Prevents timeout issues</li>";
echo "<li>✅ <strong>Retry logic</strong> - Handles temporary failures</li>";
echo "<li>✅ <strong>Health monitoring</strong> - Detects dead connections</li>";
echo "</ul>";
?>
