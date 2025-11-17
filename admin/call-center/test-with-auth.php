<?php
// Test including auth.php specifically
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>PHP Test - With Auth</title>
</head>
<body>
    <h1>Testing auth.php include</h1>
    
    <?php
    try {
        echo "<p>About to require auth.php...</p>";
        require_once __DIR__ . '/../../shared/auth.php';
        echo "<p>✓ auth.php loaded successfully!</p>";
        
        echo "<p>is_logged_in(): " . (is_logged_in() ? 'YES' : 'NO') . "</p>";
        
        if (is_logged_in()) {
            $user = current_user();
            echo "<p>✓ You are logged in as: " . htmlspecialchars($user['name']) . "</p>";
            echo "<p>User ID: " . $user['id'] . "</p>";
            echo "<p>Role: " . $user['role'] . "</p>";
        } else {
            echo "<p>⚠ You are NOT logged in</p>";
            echo "<p><a href='../login.php'>Click here to login</a></p>";
        }
        
    } catch (Throwable $e) {
        echo "<p>✗ ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p>File: " . $e->getFile() . "</p>";
        echo "<p>Line: " . $e->getLine() . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
    ?>
    
    <hr>
    <p><a href="test-with-db.php">Next: Test with db.php</a></p>
</body>
</html>

