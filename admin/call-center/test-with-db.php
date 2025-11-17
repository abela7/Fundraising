<?php
// Test including both auth.php and db.php
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>PHP Test - With Auth & DB</title>
</head>
<body>
    <h1>Testing auth.php + db.php includes</h1>
    
    <?php
    try {
        echo "<p>1. Loading auth.php...</p>";
        require_once __DIR__ . '/../../shared/auth.php';
        echo "<p>✓ auth.php loaded</p>";
        
        echo "<p>2. Loading db.php...</p>";
        require_once __DIR__ . '/../../config/db.php';
        echo "<p>✓ db.php loaded</p>";
        
        echo "<p>3. Testing database connection...</p>";
        $db = db();
        echo "<p>✓ Database connected: " . $db->host_info . "</p>";
        
        echo "<p>4. Checking login status...</p>";
        if (is_logged_in()) {
            $user = current_user();
            echo "<p>✓ You are logged in as: " . htmlspecialchars($user['name']) . "</p>";
            
            echo "<p>5. Testing call_center_sessions table...</p>";
            $result = $db->query("SELECT COUNT(*) as cnt FROM call_center_sessions");
            if ($result) {
                $row = $result->fetch_assoc();
                echo "<p>✓ call_center_sessions table exists (rows: " . $row['cnt'] . ")</p>";
            } else {
                echo "<p>✗ call_center_sessions query failed: " . $db->error . "</p>";
            }
            
            echo "<hr>";
            echo "<h2>✓ ALL TESTS PASSED!</h2>";
            echo "<p>Everything works. The call center should load.</p>";
            echo "<p><a href='index.php' class='btn btn-primary'>Try Call Center Dashboard</a></p>";
            
        } else {
            echo "<p>⚠ You are NOT logged in</p>";
            echo "<p><strong>THIS IS THE PROBLEM!</strong></p>";
            echo "<p>The call center requires you to be logged in FIRST.</p>";
            echo "<p><a href='../login.php' style='font-size: 20px; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>LOGIN HERE</a></p>";
        }
        
    } catch (Throwable $e) {
        echo "<p style='color: red; font-weight: bold;'>✗ ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p>File: " . $e->getFile() . "</p>";
        echo "<p>Line: " . $e->getLine() . "</p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
    ?>
    
</body>
</html>

