<?php
// SIMPLE DEBUG - CATCHES ALL ERRORS
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "<div style='background:red;color:white;padding:20px;font-family:monospace;'>";
        echo "<h1>FATAL ERROR DETECTED</h1>";
        echo "<strong>Message:</strong> " . htmlspecialchars($error['message']) . "<br>";
        echo "<strong>File:</strong> " . htmlspecialchars($error['file']) . "<br>";
        echo "<strong>Line:</strong> " . $error['line'] . "<br>";
        echo "<strong>Type:</strong> " . $error['type'] . "<br>";
        echo "</div>";
    }
});

// Set error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo "<div style='background:orange;color:black;padding:10px;margin:5px;font-family:monospace;'>";
    echo "<strong>ERROR:</strong> " . htmlspecialchars($errstr) . "<br>";
    echo "<strong>File:</strong> " . htmlspecialchars($errfile) . " Line: " . $errline . "<br>";
    echo "</div>";
    return false; // Continue with normal error handling
});

// Set exception handler
set_exception_handler(function($exception) {
    echo "<div style='background:red;color:white;padding:20px;font-family:monospace;'>";
    echo "<h1>UNCAUGHT EXCEPTION</h1>";
    echo "<strong>Message:</strong> " . htmlspecialchars($exception->getMessage()) . "<br>";
    echo "<strong>File:</strong> " . htmlspecialchars($exception->getFile()) . "<br>";
    echo "<strong>Line:</strong> " . $exception->getLine() . "<br>";
    echo "<strong>Trace:</strong><pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre>";
    echo "</div>";
});

echo "<!DOCTYPE html><html><head><title>Payment Plan Deletion Debug (Simple)</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#f5f5f5;} .step{background:white;padding:15px;margin:10px 0;border-left:4px solid #007bff;} .success{border-color:#28a745;} .error{border-color:#dc3545;background:#fff5f5;} .info{border-color:#17a2b8;} pre{background:#f8f9fa;padding:10px;border-radius:4px;overflow-x:auto;}</style>";
echo "</head><body>";
echo "<h1>🔍 Payment Plan Deletion - Simple Debug</h1>";

$plan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';

echo "<div class='step info'><strong>Step 0: Parameters</strong><br>";
echo "Plan ID: $plan_id<br>Donor ID: $donor_id<br>Confirm: $confirm<br>";
echo "Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . "<br>";
echo "PHP Version: " . PHP_VERSION . "<br></div>";

if ($plan_id <= 0 || $donor_id <= 0) {
    echo "<div class='step error'><strong>ERROR:</strong> Invalid IDs</div></body></html>";
    exit;
}

// Step 1: Check file paths
echo "<div class='step'><strong>Step 1: Check File Paths</strong><br>";
$auth_path = __DIR__ . '/../../shared/auth.php';
$db_path = __DIR__ . '/../../config/db.php';

echo "Current directory: " . __DIR__ . "<br>";
echo "Auth path: $auth_path<br>";
echo "Auth exists: " . (file_exists($auth_path) ? "YES" : "NO") . "<br>";
echo "DB path: $db_path<br>";
echo "DB exists: " . (file_exists($db_path) ? "YES" : "NO") . "<br>";
echo "</div>";

// Step 2: Try to include files
echo "<div class='step'><strong>Step 2: Include Files</strong><br>";
try {
    if (!file_exists($auth_path)) {
        throw new Exception("Auth file not found: $auth_path");
    }
    require_once $auth_path;
    echo "✓ Auth file included<br>";
} catch (Throwable $e) {
    echo "<div class='error'>✗ Error including auth: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "</div>";
    echo "</div></body></html>";
    exit;
}

try {
    if (!file_exists($db_path)) {
        throw new Exception("DB config file not found: $db_path");
    }
    require_once $db_path;
    echo "✓ DB config file included<br>";
} catch (Throwable $e) {
    echo "<div class='error'>✗ Error including DB config: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "</div>";
    echo "</div></body></html>";
    exit;
}
echo "</div>";

// Step 3: Check if require_login exists
echo "<div class='step'><strong>Step 3: Check require_login()</strong><br>";
if (!function_exists('require_login')) {
    echo "<div class='error'>✗ require_login() function not found!</div>";
    echo "</div></body></html>";
    exit;
}
echo "✓ require_login() function exists<br>";

// Step 4: Try require_login (but catch redirects)
echo "<div class='step'><strong>Step 4: Call require_login()</strong><br>";
try {
    // Temporarily disable output buffering to catch redirects
    ob_start();
    require_login();
    ob_end_clean();
    echo "✓ Login check passed<br>";
} catch (Throwable $e) {
    echo "<div class='error'>✗ Error in require_login: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "</div>";
    echo "</div></body></html>";
    exit;
}
echo "</div>";

// Step 5: Database connection
echo "<div class='step'><strong>Step 5: Database Connection</strong><br>";
try {
    if (!function_exists('db')) {
        throw new Exception("db() function not found");
    }
    date_default_timezone_set('Europe/London');
    $conn = db();
    echo "✓ Database connected<br>";
    echo "Server: " . htmlspecialchars($conn->server_info) . "<br>";
} catch (Throwable $e) {
    echo "<div class='error'>✗ Database connection failed: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "</div>";
    echo "</div></body></html>";
    exit;
}
echo "</div>";

// Step 6: Fetch payment plan
echo "<div class='step'><strong>Step 6: Fetch Payment Plan</strong><br>";
try {
    $query = "
        SELECT 
            dpp.*,
            d.name as donor_name,
            d.phone as donor_phone,
            d.active_payment_plan_id,
            d.payment_status,
            p.amount as pledge_amount
        FROM donor_payment_plans dpp
        JOIN donors d ON dpp.donor_id = d.id
        LEFT JOIN pledges p ON dpp.pledge_id = p.id
        WHERE dpp.id = ? AND dpp.donor_id = ?
    ";
    
    echo "<div class='info'><strong>Query:</strong><pre>" . htmlspecialchars($query) . "</pre></div>";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error . " (Code: " . $conn->errno . ")");
    }
    echo "✓ Query prepared<br>";
    
    $stmt->bind_param('ii', $plan_id, $donor_id);
    echo "✓ Parameters bound<br>";
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error . " (Code: " . $stmt->errno . ")");
    }
    echo "✓ Query executed<br>";
    
    $result = $stmt->get_result();
    $plan = $result->fetch_object();
    
    if (!$plan) {
        echo "<div class='error'>✗ Payment plan not found (ID: $plan_id, Donor: $donor_id)</div>";
        echo "</div></body></html>";
        exit;
    }
    
    echo "<div class='success'><strong>✓ Plan Found:</strong><pre>";
    print_r($plan);
    echo "</pre></div>";
    
} catch (Throwable $e) {
    echo "<div class='error'>✗ Error fetching plan: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "<br>";
    if (isset($conn) && $conn instanceof mysqli) {
        echo "MySQL Error: " . $conn->error . " (Code: " . $conn->errno . ")<br>";
    }
    echo "</div></body></html>";
    exit;
}
echo "</div>";

// Step 7: Check linked sessions
echo "<div class='step'><strong>Step 7: Check Linked Sessions</strong><br>";
try {
    $session_query = "SELECT COUNT(*) as count FROM call_center_sessions WHERE payment_plan_id = ?";
    $session_stmt = $conn->prepare($session_query);
    if (!$session_stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $session_stmt->bind_param('i', $plan_id);
    $session_stmt->execute();
    $session_result = $session_stmt->get_result();
    $session_row = $session_result->fetch_object();
    $linked_sessions = $session_row ? (int)$session_row->count : 0;
    
    echo "✓ Linked sessions: $linked_sessions<br>";
} catch (Throwable $e) {
    echo "<div class='error'>✗ Error checking sessions: " . htmlspecialchars($e->getMessage()) . "</div>";
}
echo "</div>";

// Step 8: Check if active plan
echo "<div class='step'><strong>Step 8: Check Active Plan Status</strong><br>";
$is_active_plan = ($plan->active_payment_plan_id == $plan_id);
echo "Donor's active_payment_plan_id: " . ($plan->active_payment_plan_id ?? 'NULL') . "<br>";
echo "Is Active Plan: " . ($is_active_plan ? 'YES' : 'NO') . "<br>";
echo "</div>";

// Step 9: Test deletion (dry run)
if ($confirm === 'test') {
    echo "<div class='step info'><strong>Step 9: DRY RUN - Test Deletion</strong><br>";
    try {
        $conn->begin_transaction();
        echo "✓ Transaction started<br>";
        
        // Test unlink
        if ($linked_sessions > 0) {
            $unlink_query = "UPDATE call_center_sessions SET payment_plan_id = NULL WHERE payment_plan_id = ?";
            $unlink_stmt = $conn->prepare($unlink_query);
            $unlink_stmt->bind_param('i', $plan_id);
            $unlink_stmt->execute();
            echo "✓ Would unlink " . $unlink_stmt->affected_rows . " sessions<br>";
        }
        
        // Test reset donor
        if ($is_active_plan) {
            $reset_query = "UPDATE donors SET active_payment_plan_id = NULL, has_active_plan = 0 WHERE id = ?";
            $reset_stmt = $conn->prepare($reset_query);
            $reset_stmt->bind_param('i', $donor_id);
            $reset_stmt->execute();
            echo "✓ Would reset donor<br>";
        }
        
        // Test delete
        $delete_query = "DELETE FROM donor_payment_plans WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param('i', $plan_id);
        $delete_stmt->execute();
        echo "✓ Would delete plan<br>";
        
        $conn->rollback();
        echo "✓ Transaction rolled back (DRY RUN)<br>";
    } catch (Throwable $e) {
        $conn->rollback();
        echo "<div class='error'>✗ Error in dry run: " . htmlspecialchars($e->getMessage()) . "<br>";
        echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "</div>";
    }
    echo "</div>";
}

// Action buttons
echo "<div class='step info'>";
echo "<h2>Actions</h2>";
echo "<a href='?id=$plan_id&donor_id=$donor_id&confirm=test' style='display:inline-block;padding:10px 20px;background:#17a2b8;color:white;text-decoration:none;margin:5px;border-radius:4px;'>🧪 Test Deletion (Dry Run)</a>";
echo "<a href='test-deletion-minimal.php?type=plan&id=$plan_id&donor_id=$donor_id' style='display:inline-block;padding:10px 20px;background:#6c757d;color:white;text-decoration:none;margin:5px;border-radius:4px;'>🔍 Minimal Test</a>";
echo "<a href='view-donor.php?id=$donor_id' style='display:inline-block;padding:10px 20px;background:#6c757d;color:white;text-decoration:none;margin:5px;border-radius:4px;'>← Back to Donor</a>";
echo "</div>";

echo "</body></html>";
?>

