<?php
/**
 * Debug wrapper for inbound-callbacks.php
 * This page captures and displays all PHP errors
 * DELETE THIS FILE AFTER DEBUGGING
 */

// Capture ALL errors
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Store errors in array
$captured_errors = [];

// Custom error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$captured_errors) {
    $captured_errors[] = [
        'type' => 'Error',
        'code' => $errno,
        'message' => $errstr,
        'file' => $errfile,
        'line' => $errline
    ];
    return true; // Don't execute PHP's internal error handler
});

// Capture fatal errors on shutdown
register_shutdown_function(function() use (&$captured_errors) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // This is a fatal error - display it
        echo '<div style="background:#ff0000;color:white;padding:20px;margin:20px;font-family:monospace;border-radius:8px;">';
        echo '<h2>⚠️ FATAL ERROR DETECTED</h2>';
        echo '<p><strong>Type:</strong> ' . $error['type'] . '</p>';
        echo '<p><strong>Message:</strong> ' . htmlspecialchars($error['message']) . '</p>';
        echo '<p><strong>File:</strong> ' . htmlspecialchars($error['file']) . '</p>';
        echo '<p><strong>Line:</strong> ' . $error['line'] . '</p>';
        echo '<hr>';
        echo '<p><strong>Copy this text and send it:</strong></p>';
        echo '<textarea style="width:100%;height:150px;font-family:monospace;" onclick="this.select()">';
        echo "FATAL ERROR\n";
        echo "Type: " . $error['type'] . "\n";
        echo "Message: " . $error['message'] . "\n";
        echo "File: " . $error['file'] . "\n";
        echo "Line: " . $error['line'] . "\n";
        echo '</textarea>';
        echo '</div>';
    }
});

// Capture exceptions
set_exception_handler(function($e) {
    echo '<div style="background:#ff0000;color:white;padding:20px;margin:20px;font-family:monospace;border-radius:8px;">';
    echo '<h2>⚠️ UNCAUGHT EXCEPTION</h2>';
    echo '<p><strong>Type:</strong> ' . get_class($e) . '</p>';
    echo '<p><strong>Message:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . '</p>';
    echo '<p><strong>Line:</strong> ' . $e->getLine() . '</p>';
    echo '<p><strong>Trace:</strong></p>';
    echo '<pre style="background:#333;padding:10px;overflow:auto;max-height:300px;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '<hr>';
    echo '<p><strong>Copy this text and send it:</strong></p>';
    echo '<textarea style="width:100%;height:200px;font-family:monospace;" onclick="this.select()">';
    echo "EXCEPTION: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    echo '</textarea>';
    echo '</div>';
    exit;
});

?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug - Inbound Callbacks</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .debug-header { background: #333; color: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .debug-info { background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #2196f3; }
        .error-box { background: #ffebee; padding: 15px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #f44336; }
        .success-box { background: #e8f5e9; padding: 15px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #4caf50; }
        pre { background: #263238; color: #aed581; padding: 15px; border-radius: 8px; overflow: auto; }
        .copy-area { width: 100%; height: 150px; font-family: monospace; font-size: 12px; }
    </style>
</head>
<body>
    <div class="debug-header">
        <h1>🔍 Debug Mode - Inbound Callbacks</h1>
        <p>This page will capture and display any errors from loading inbound-callbacks.php</p>
    </div>
    
    <div class="debug-info">
        <strong>PHP Version:</strong> <?php echo phpversion(); ?><br>
        <strong>Server:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?><br>
        <strong>Time:</strong> <?php echo date('Y-m-d H:i:s'); ?><br>
        <strong>Memory Limit:</strong> <?php echo ini_get('memory_limit'); ?>
    </div>

    <h2>Step 1: Testing Database Connection</h2>
    <?php
    try {
        require_once __DIR__ . '/../../config/db.php';
        $db = db();
        if ($db) {
            echo '<div class="success-box">✅ Database connection successful</div>';
        } else {
            echo '<div class="error-box">❌ Database connection returned null</div>';
        }
    } catch (Throwable $e) {
        echo '<div class="error-box">';
        echo '<strong>❌ Database Error:</strong><br>';
        echo htmlspecialchars($e->getMessage()) . '<br>';
        echo 'File: ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine();
        echo '</div>';
    }
    ?>

    <h2>Step 2: Testing Auth System</h2>
    <?php
    try {
        require_once __DIR__ . '/../../shared/auth.php';
        echo '<div class="success-box">✅ Auth file loaded successfully</div>';
        
        // Check if session is started
        if (session_status() === PHP_SESSION_ACTIVE) {
            echo '<div class="success-box">✅ Session is active</div>';
        } else {
            echo '<div class="error-box">❌ Session is not active</div>';
        }
        
        // Check if user is logged in
        if (isset($_SESSION['user'])) {
            echo '<div class="success-box">✅ User is logged in: ' . htmlspecialchars($_SESSION['user']['name'] ?? 'Unknown') . '</div>';
            echo '<div class="debug-info"><strong>User Role:</strong> ' . htmlspecialchars($_SESSION['user']['role'] ?? 'Unknown') . '</div>';
        } else {
            echo '<div class="error-box">❌ User is NOT logged in - this will cause a redirect</div>';
        }
        
    } catch (Throwable $e) {
        echo '<div class="error-box">';
        echo '<strong>❌ Auth Error:</strong><br>';
        echo htmlspecialchars($e->getMessage()) . '<br>';
        echo 'File: ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine();
        echo '</div>';
    }
    ?>

    <h2>Step 3: Testing Table Existence</h2>
    <?php
    try {
        if (isset($db) && $db) {
            $tableCheck = $db->query("SHOW TABLES LIKE 'twilio_inbound_calls'");
            if ($tableCheck && $tableCheck->num_rows > 0) {
                echo '<div class="success-box">✅ Table twilio_inbound_calls exists</div>';
                
                // Check table structure
                $columns = $db->query("SHOW COLUMNS FROM twilio_inbound_calls");
                if ($columns) {
                    echo '<div class="debug-info"><strong>Table Columns:</strong><br>';
                    while ($col = $columns->fetch_assoc()) {
                        echo '- ' . htmlspecialchars($col['Field']) . ' (' . htmlspecialchars($col['Type']) . ')<br>';
                    }
                    echo '</div>';
                }
            } else {
                echo '<div class="error-box">❌ Table twilio_inbound_calls does NOT exist</div>';
            }
        }
    } catch (Throwable $e) {
        echo '<div class="error-box">';
        echo '<strong>❌ Table Check Error:</strong><br>';
        echo htmlspecialchars($e->getMessage());
        echo '</div>';
    }
    ?>

    <h2>Step 4: Testing require_admin Function</h2>
    <?php
    try {
        if (function_exists('require_admin')) {
            echo '<div class="success-box">✅ require_admin function exists</div>';
        } else {
            echo '<div class="error-box">❌ require_admin function does NOT exist</div>';
        }
    } catch (Throwable $e) {
        echo '<div class="error-box">';
        echo '<strong>❌ Error:</strong> ' . htmlspecialchars($e->getMessage());
        echo '</div>';
    }
    ?>

    <h2>Step 5: Captured Errors (if any)</h2>
    <?php
    if (empty($captured_errors)) {
        echo '<div class="success-box">✅ No errors captured so far</div>';
    } else {
        foreach ($captured_errors as $err) {
            echo '<div class="error-box">';
            echo '<strong>' . htmlspecialchars($err['type']) . ' (Code: ' . $err['code'] . ')</strong><br>';
            echo 'Message: ' . htmlspecialchars($err['message']) . '<br>';
            echo 'File: ' . htmlspecialchars($err['file']) . ':' . $err['line'];
            echo '</div>';
        }
    }
    ?>

    <h2>Step 6: Full Error Log (copy and send this)</h2>
    <textarea class="copy-area" onclick="this.select()" readonly><?php
echo "=== DEBUG REPORT ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n\n";

echo "Database: " . (isset($db) && $db ? "Connected" : "FAILED") . "\n";
echo "Session Active: " . (session_status() === PHP_SESSION_ACTIVE ? "Yes" : "No") . "\n";
echo "User Logged In: " . (isset($_SESSION['user']) ? "Yes (" . ($_SESSION['user']['name'] ?? 'Unknown') . ")" : "No") . "\n";
echo "User Role: " . ($_SESSION['user']['role'] ?? 'Unknown') . "\n";
echo "require_admin exists: " . (function_exists('require_admin') ? "Yes" : "No") . "\n\n";

if (!empty($captured_errors)) {
    echo "CAPTURED ERRORS:\n";
    foreach ($captured_errors as $err) {
        echo "- [{$err['code']}] {$err['message']} in {$err['file']}:{$err['line']}\n";
    }
}
?></textarea>

    <p style="margin-top: 20px; color: #666;">
        <strong>⚠️ DELETE THIS FILE (debug-inbound.php) AFTER DEBUGGING!</strong>
    </p>
</body>
</html>

