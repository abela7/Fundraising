<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

if (!is_logged_in()) {
    die("Please login first: <a href='../login.php'>Login</a>");
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Includes</title>
    <meta charset="utf-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body>
    <div class="container mt-4">
        <h1>Testing Each Include Separately</h1>
        <p class="text-success">✓ auth.php loaded</p>
        <p class="text-success">✓ db.php loaded</p>
        <p class="text-success">✓ User logged in: <?php echo htmlspecialchars($_SESSION['user']['name']); ?></p>
        
        <hr>
        
        <h2>Test 1: Include sidebar.php</h2>
        <?php
        try {
            $sidebar_path = __DIR__ . '/../includes/sidebar.php';
            echo "<p>Path: " . htmlspecialchars($sidebar_path) . "</p>";
            echo "<p>Exists: " . (file_exists($sidebar_path) ? 'YES' : 'NO') . "</p>";
            echo "<p>Readable: " . (is_readable($sidebar_path) ? 'YES' : 'NO') . "</p>";
            
            if (file_exists($sidebar_path)) {
                echo "<p>Attempting to include...</p>";
                
                // Set variables that sidebar might need
                $current_dir = 'call-center';
                
                ob_start();
                include $sidebar_path;
                $sidebar_output = ob_get_clean();
                
                echo "<p class='text-success'>✓ sidebar.php included successfully!</p>";
                echo "<details><summary>Sidebar Output</summary><pre>" . htmlspecialchars(substr($sidebar_output, 0, 500)) . "...</pre></details>";
            }
        } catch (Throwable $e) {
            echo "<p class='text-danger'>✗ sidebar.php FAILED: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        }
        ?>
        
        <hr>
        
        <h2>Test 2: Include topbar.php</h2>
        <?php
        try {
            $topbar_path = __DIR__ . '/../includes/topbar.php';
            echo "<p>Path: " . htmlspecialchars($topbar_path) . "</p>";
            echo "<p>Exists: " . (file_exists($topbar_path) ? 'YES' : 'NO') . "</p>";
            echo "<p>Readable: " . (is_readable($topbar_path) ? 'YES' : 'NO') . "</p>";
            
            if (file_exists($topbar_path)) {
                echo "<p>Attempting to include...</p>";
                
                // Set variables that topbar might need
                $page_title = 'Test Page';
                
                ob_start();
                include $topbar_path;
                $topbar_output = ob_get_clean();
                
                echo "<p class='text-success'>✓ topbar.php included successfully!</p>";
                echo "<details><summary>Topbar Output</summary><pre>" . htmlspecialchars(substr($topbar_output, 0, 500)) . "...</pre></details>";
            }
        } catch (Throwable $e) {
            echo "<p class='text-danger'>✗ topbar.php FAILED: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        }
        ?>
        
        <hr>
        
        <h2>Test 3: CSS Files</h2>
        <?php
        $css_files = [
            'admin.css' => __DIR__ . '/../assets/admin.css',
            'call-center.css' => __DIR__ . '/assets/call-center.css'
        ];
        
        foreach ($css_files as $name => $path) {
            echo "<p><strong>$name:</strong> ";
            if (file_exists($path)) {
                echo "<span class='text-success'>✓ Exists</span> (Size: " . filesize($path) . " bytes)";
            } else {
                echo "<span class='text-danger'>✗ NOT FOUND</span> at " . htmlspecialchars($path);
            }
            echo "</p>";
        }
        ?>
        
        <hr>
        
        <h2>Summary</h2>
        <p>If all tests above passed, then the issue is NOT with the includes.</p>
        <p>If any test failed, that's the culprit causing the 500 error in index.php!</p>
        
        <p><a href="index-safe.php" class="btn btn-primary">Try Safe Mode Dashboard</a></p>
    </div>
</body>
</html>

