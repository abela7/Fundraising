<?php
/**
 * Simple Test Page - Minimal code to isolate issues
 * This page has the absolute minimum code needed
 */

// Enable error display
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Call Center Simple Test</h1>";
echo "<p>Testing step by step...</p>";

// Step 1: Basic PHP
echo "<h2>Step 1: PHP Works</h2>";
echo "<p style='color:green'>✓ PHP is working</p>";

// Step 2: Check file paths
echo "<h2>Step 2: File Paths</h2>";
$auth_file = __DIR__ . '/../../shared/auth.php';
$db_file = __DIR__ . '/../../config/db.php';

echo "<p>Auth file: " . ($auth_file) . "</p>";
echo "<p>Auth exists: " . (file_exists($auth_file) ? 'YES' : 'NO') . "</p>";
echo "<p>DB file: " . ($db_file) . "</p>";
echo "<p>DB exists: " . (file_exists($db_file) ? 'YES' : 'NO') . "</p>";

// Step 3: Try to load auth
echo "<h2>Step 3: Loading Auth</h2>";
try {
    if (file_exists($auth_file)) {
        require_once $auth_file;
        echo "<p style='color:green'>✓ Auth file loaded</p>";
    } else {
        echo "<p style='color:red'>✗ Auth file not found</p>";
        die();
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Error loading auth: " . $e->getMessage() . "</p>";
    echo "<p>File: " . $e->getFile() . " Line: " . $e->getLine() . "</p>";
    die();
}

// Step 4: Check session
echo "<h2>Step 4: Session</h2>";
echo "<p>Session status: " . session_status() . " (2 = active)</p>";
if (session_status() === PHP_SESSION_ACTIVE) {
    echo "<p style='color:green'>✓ Session is active</p>";
    echo "<p>User ID: " . ($_SESSION['user_id'] ?? 'NOT SET') . "</p>";
    echo "<p>Name: " . ($_SESSION['name'] ?? 'NOT SET') . "</p>";
} else {
    echo "<p style='color:orange'>⚠ Session not active (this is OK if not logged in)</p>";
}

// Step 5: Try database connection
echo "<h2>Step 5: Database Connection</h2>";
try {
    if (function_exists('db')) {
        echo "<p style='color:green'>✓ db() function exists</p>";
        
        $db = db();
        if ($db) {
            echo "<p style='color:green'>✓ Database connection successful</p>";
            echo "<p>Host info: " . $db->host_info . "</p>";
            echo "<p>Server info: " . $db->server_info . "</p>";
            
            // Test query
            $test = $db->query("SELECT 1 as test");
            if ($test) {
                echo "<p style='color:green'>✓ Can execute queries</p>";
            } else {
                echo "<p style='color:red'>✗ Cannot execute queries: " . $db->error . "</p>";
            }
            
            // Check call_center_sessions table
            $table_check = $db->query("SHOW TABLES LIKE 'call_center_sessions'");
            if ($table_check && $table_check->num_rows > 0) {
                echo "<p style='color:green'>✓ call_center_sessions table exists</p>";
            } else {
                echo "<p style='color:red'>✗ call_center_sessions table NOT found</p>";
            }
            
        } else {
            echo "<p style='color:red'>✗ db() returned null/false</p>";
        }
    } else {
        echo "<p style='color:red'>✗ db() function does not exist</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Database error: " . $e->getMessage() . "</p>";
    echo "<p>File: " . $e->getFile() . " Line: " . $e->getLine() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// Step 6: Try to include sidebar
echo "<h2>Step 6: Including Sidebar</h2>";
try {
    $sidebar_file = __DIR__ . '/../includes/sidebar.php';
    if (file_exists($sidebar_file)) {
        echo "<p style='color:green'>✓ Sidebar file exists</p>";
        // Don't actually include it, just check
        echo "<p>Sidebar path: " . $sidebar_file . "</p>";
    } else {
        echo "<p style='color:red'>✗ Sidebar file not found: " . $sidebar_file . "</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Error: " . $e->getMessage() . "</p>";
}

// Step 7: Try to simulate index.php query
echo "<h2>Step 7: Testing Index.php Query</h2>";
try {
    if (isset($db) && $db) {
        $user_id = $_SESSION['user_id'] ?? 1; // Use 1 as fallback for testing
        $today_start = date('Y-m-d 00:00:00');
        $today_end = date('Y-m-d 23:59:59');
        
        $stats_query = "
            SELECT 
                COUNT(*) as total_calls,
                SUM(CASE WHEN conversation_stage != 'no_connection' THEN 1 ELSE 0 END) as successful_contacts,
                SUM(CASE WHEN outcome IN ('payment_plan_created', 'agreed_to_pay_full', 'agreed_reduced_amount', 'agreed_cash_collection') THEN 1 ELSE 0 END) as positive_outcomes,
                SUM(CASE WHEN callback_scheduled_for IS NOT NULL THEN 1 ELSE 0 END) as callbacks_scheduled,
                SUM(duration_seconds) as total_talk_time
            FROM call_center_sessions
            WHERE agent_id = ? AND call_started_at BETWEEN ? AND ?
        ";
        
        $stmt = $db->prepare($stats_query);
        if ($stmt) {
            echo "<p style='color:green'>✓ Query prepared successfully</p>";
            $stmt->bind_param('iss', $user_id, $today_start, $today_end);
            $stmt->execute();
            $result = $stmt->get_result();
            $today_stats = $result->fetch_object();
            $stmt->close();
            
            if ($today_stats) {
                echo "<p style='color:green'>✓ Query executed successfully</p>";
                echo "<p>Total calls: " . ($today_stats->total_calls ?? 0) . "</p>";
            } else {
                echo "<p style='color:orange'>⚠ Query returned no rows (this is OK if no calls yet)</p>";
            }
        } else {
            echo "<p style='color:red'>✗ Cannot prepare query: " . $db->error . "</p>";
        }
    } else {
        echo "<p style='color:red'>✗ Database not available</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Query error: " . $e->getMessage() . "</p>";
    echo "<p>File: " . $e->getFile() . " Line: " . $e->getLine() . "</p>";
}

echo "<hr>";
echo "<h2>Test Complete</h2>";
echo "<p><a href='debug.php'>Go to Full Debug Page</a></p>";
echo "<p><a href='index.php'>Try Call Center Dashboard</a></p>";
?>

