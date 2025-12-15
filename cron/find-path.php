<?php
/**
 * Helper Script to Find Cron File Path
 * 
 * Visit this file in your browser to see the exact file path needed for cPanel cron jobs.
 * DELETE THIS FILE after you get the path!
 */

$scriptPath = __DIR__ . '/send-payment-reminders-2day.php';
$absolutePath = realpath($scriptPath);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Find Cron File Path</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .box {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #333; }
        .path {
            background: #1e1e1e;
            color: #0ea5e9;
            padding: 15px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 14px;
            margin: 15px 0;
            word-break: break-all;
        }
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .success {
            background: #d1e7dd;
            border-left: 4px solid #198754;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="box">
        <h1>🔍 Cron File Path Finder</h1>
        
        <?php if ($absolutePath && file_exists($absolutePath)): ?>
            <div class="success">
                <strong>✅ File Found!</strong>
            </div>
            
            <h3>Use this path in your cPanel Cron Job:</h3>
            <div class="path">
                /usr/bin/php <?php echo htmlspecialchars($absolutePath); ?>
            </div>
            
            <h3>Or use the relative path:</h3>
            <div class="path">
                /usr/bin/php <?php echo htmlspecialchars($scriptPath); ?>
            </div>
            
            <h3>For Web URL method (Option B):</h3>
            <div class="path">
                curl -s "<?php echo (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/send-payment-reminders-2day.php?cron_key=YOUR_SECRET_KEY'; ?>" > /dev/null
            </div>
            
        <?php else: ?>
            <div class="warning">
                <strong>⚠️ File Not Found</strong><br>
                The file <code>send-payment-reminders-2day.php</code> doesn't exist in this directory.
            </div>
        <?php endif; ?>
        
        <div class="warning">
            <strong>⚠️ Security Warning</strong><br>
            <strong>DELETE THIS FILE</strong> (<code>find-path.php</code>) after you get the path!
        </div>
        
        <h3>Your Current Directory:</h3>
        <div class="path">
            <?php echo htmlspecialchars(__DIR__); ?>
        </div>
        
        <h3>Server Information:</h3>
        <ul>
            <li><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></li>
            <li><strong>Server:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></li>
            <li><strong>Document Root:</strong> <?php echo htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'); ?></li>
        </ul>
    </div>
</body>
</html>
