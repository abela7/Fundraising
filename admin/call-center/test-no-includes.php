<?php
// Absolute minimal PHP test - NO includes at all
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>PHP Test - No Includes</title>
</head>
<body>
    <h1>✓ PHP Works!</h1>
    <p>PHP Version: <?php echo phpversion(); ?></p>
    <p>Server: <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></p>
    <p>Script: <?php echo $_SERVER['SCRIPT_NAME'] ?? 'Unknown'; ?></p>
    
    <hr>
    <h2>Next Test: Include auth.php</h2>
    <p><a href="test-with-auth.php">Test with auth.php</a></p>
    
    <hr>
    <h2>File Locations (debug)</h2>
    <p>__DIR__: <?php echo __DIR__; ?></p>
    <p>auth.php path: <?php echo __DIR__ . '/../../shared/auth.php'; ?></p>
    <p>auth.php exists: <?php echo file_exists(__DIR__ . '/../../shared/auth.php') ? 'YES' : 'NO'; ?></p>
    <p>db.php path: <?php echo __DIR__ . '/../../config/db.php'; ?></p>
    <p>db.php exists: <?php echo file_exists(__DIR__ . '/../../config/db.php') ? 'YES' : 'NO'; ?></p>
</body>
</html>

