<?php
/**
 * Webhook Test Page
 * 
 * Use this to verify the webhook is reachable and check logs
 */

header('Content-Type: text/html; charset=utf-8');

$logFile = __DIR__ . '/../logs/webhook_debug.log';
$errorLog = __DIR__ . '/../logs/webhook_errors.log';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Webhook Test</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #1a1a2e; color: #eee; }
        .card { background: #16213e; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        h1 { color: #00a884; }
        h2 { color: #8696a0; font-size: 1rem; margin-top: 0; }
        pre { background: #0f0f23; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; max-height: 400px; overflow-y: auto; }
        .success { color: #25D366; }
        .error { color: #dc3545; }
        .btn { background: #00a884; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin-right: 10px; }
        .btn:hover { background: #00917a; }
        .btn-danger { background: #dc3545; }
    </style>
</head>
<body>
    <h1>🔗 Webhook Test</h1>
    
    <div class="card">
        <h2>Webhook URL</h2>
        <p><code><?php echo 'https://' . $_SERVER['HTTP_HOST'] . '/webhooks/ultramsg.php'; ?></code></p>
        <p>Copy this URL to your UltraMsg Instance Settings → Webhook URL</p>
    </div>
    
    <div class="card">
        <h2>Status Check</h2>
        <?php
        // Check if logs directory exists
        $logsDir = __DIR__ . '/../logs';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
            echo '<p class="success">✅ Created logs directory</p>';
        } else {
            echo '<p class="success">✅ Logs directory exists</p>';
        }
        
        // Check if log files are writable
        if (is_writable($logsDir)) {
            echo '<p class="success">✅ Logs directory is writable</p>';
        } else {
            echo '<p class="error">❌ Logs directory is NOT writable</p>';
        }
        
        // Check database connection
        try {
            require_once __DIR__ . '/../config/db.php';
            $db = db();
            $check = $db->query("SHOW TABLES LIKE 'whatsapp_conversations'");
            if ($check && $check->num_rows > 0) {
                echo '<p class="success">✅ Database tables exist</p>';
                
                // Count records
                $convCount = $db->query("SELECT COUNT(*) as c FROM whatsapp_conversations")->fetch_assoc()['c'];
                $msgCount = $db->query("SELECT COUNT(*) as c FROM whatsapp_messages")->fetch_assoc()['c'];
                $logCount = $db->query("SELECT COUNT(*) as c FROM whatsapp_webhook_logs")->fetch_assoc()['c'];
                
                echo "<p>📊 Conversations: $convCount | Messages: $msgCount | Webhook Logs: $logCount</p>";
            } else {
                echo '<p class="error">❌ Database tables NOT found</p>';
            }
        } catch (Exception $e) {
            echo '<p class="error">❌ Database error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        ?>
    </div>
    
    <div class="card">
        <h2>Recent Webhook Logs (Database)</h2>
        <?php
        try {
            $logs = $db->query("SELECT * FROM whatsapp_webhook_logs ORDER BY created_at DESC LIMIT 10");
            if ($logs && $logs->num_rows > 0) {
                echo '<pre>';
                while ($log = $logs->fetch_assoc()) {
                    echo date('M j H:i:s', strtotime($log['created_at'])) . ' | ';
                    echo $log['event_type'] . ' | ';
                    echo ($log['processed'] ? '✅' : '❌') . ' | ';
                    echo substr($log['payload'], 0, 100) . "...\n";
                }
                echo '</pre>';
            } else {
                echo '<p class="error">No webhook logs found. Send a test message!</p>';
            }
        } catch (Exception $e) {
            echo '<p class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        ?>
    </div>
    
    <div class="card">
        <h2>Debug Log File</h2>
        <?php
        if (file_exists($logFile)) {
            $content = file_get_contents($logFile);
            $lines = explode("\n", $content);
            $recent = array_slice($lines, -50);
            echo '<pre>' . htmlspecialchars(implode("\n", $recent)) . '</pre>';
            echo '<form method="POST"><button type="submit" name="clear_log" class="btn btn-danger">Clear Log</button></form>';
            
            if (isset($_POST['clear_log'])) {
                file_put_contents($logFile, '');
                echo '<script>location.reload();</script>';
            }
        } else {
            echo '<p>No debug log file yet. Webhook hasn\'t been called.</p>';
        }
        ?>
    </div>
    
    <div class="card">
        <h2>Send Test Webhook</h2>
        <p>Click to simulate an incoming message:</p>
        <form method="POST">
            <button type="submit" name="test_webhook" class="btn">Send Test Message</button>
        </form>
        <?php
        if (isset($_POST['test_webhook'])) {
            $testPayload = [
                'event_type' => 'message_received',
                'from' => '447123456789@c.us',
                'body' => 'Test message from webhook test page',
                'type' => 'chat',
                'pushname' => 'Test User',
                'id' => 'test_' . time(),
                'fromMe' => false
            ];
            
            $ch = curl_init('https://' . $_SERVER['HTTP_HOST'] . '/webhooks/ultramsg.php');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testPayload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            echo '<div style="margin-top:15px;padding:10px;background:#0f0f23;border-radius:5px;">';
            echo '<strong>Response (HTTP ' . $httpCode . '):</strong><br>';
            echo '<pre>' . htmlspecialchars($response) . '</pre>';
            echo '</div>';
        }
        ?>
    </div>
    
    <p style="margin-top:30px;"><a href="../admin/messaging/whatsapp/inbox.php" style="color:#00a884;">← Back to WhatsApp Inbox</a></p>
</body>
</html>

