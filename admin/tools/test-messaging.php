<?php
/**
 * Test Unified Messaging System
 * 
 * Simple test script to verify SMS and WhatsApp integration
 * Run this from admin panel: /admin/tools/test-messaging.php
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../services/MessagingHelper.php';

// Check authentication
$current_user = get_current_user();
if (!$current_user || $current_user['role'] !== 'admin') {
    die('Access denied. Admin only.');
}

$db = get_db_connection();
$msg = new MessagingHelper($db);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Messaging System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>Unified Messaging System Test</h1>
        
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5>System Status</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $status = $msg->getStatus();
                        ?>
                        <table class="table table-sm">
                            <tr>
                                <th>SMS Available:</th>
                                <td>
                                    <?php if ($status['sms_available']): ?>
                                        <span class="badge bg-success">Yes</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">No</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>WhatsApp Available:</th>
                                <td>
                                    <?php if ($status['whatsapp_available']): ?>
                                        <span class="badge bg-success">Yes</span>
                                        <?php if ($status['whatsapp_status']): ?>
                                            <small class="text-muted">
                                                (<?= htmlspecialchars($status['whatsapp_status']['status'] ?? 'unknown') ?>)
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-danger">No</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Initialized:</th>
                                <td>
                                    <?php if ($status['initialized']): ?>
                                        <span class="badge bg-success">Yes</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">No</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        
                        <?php if (!empty($status['errors'])): ?>
                            <div class="alert alert-warning">
                                <strong>Errors:</strong>
                                <ul class="mb-0">
                                    <?php foreach ($status['errors'] as $error): ?>
                                        <li><?= htmlspecialchars($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5>Test Send</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="text" name="phone" class="form-control" 
                                       placeholder="07123456789" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Message</label>
                                <textarea name="message" class="form-control" rows="3" 
                                          placeholder="Test message" required></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Channel</label>
                                <select name="channel" class="form-select">
                                    <option value="auto">Auto (Smart Selection)</option>
                                    <option value="sms">SMS Only</option>
                                    <option value="whatsapp">WhatsApp Only</option>
                                    <option value="both">Both Channels</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Send Test Message</button>
                        </form>
                        
                        <?php
                        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['phone'])) {
                            $phone = trim($_POST['phone']);
                            $message = trim($_POST['message']);
                            $channel = $_POST['channel'] ?? 'auto';
                            
                            echo '<hr>';
                            echo '<h6>Result:</h6>';
                            
                            $result = $msg->sendDirect($phone, $message, $channel, null, 'test');
                            
                            if ($result['success']) {
                                echo '<div class="alert alert-success">';
                                echo '<strong>Success!</strong><br>';
                                echo 'Channel: ' . htmlspecialchars($result['channel'] ?? 'unknown') . '<br>';
                                if (isset($result['message_id'])) {
                                    echo 'Message ID: ' . htmlspecialchars($result['message_id']) . '<br>';
                                }
                                if ($channel === 'both' && isset($result['sms']) && isset($result['whatsapp'])) {
                                    echo '<br><strong>SMS:</strong> ' . 
                                         ($result['sms']['success'] ? 'Sent' : 'Failed') . '<br>';
                                    echo '<strong>WhatsApp:</strong> ' . 
                                         ($result['whatsapp']['success'] ? 'Sent' : 'Failed');
                                }
                                echo '</div>';
                            } else {
                                echo '<div class="alert alert-danger">';
                                echo '<strong>Failed:</strong> ' . htmlspecialchars($result['error'] ?? 'Unknown error');
                                echo '</div>';
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="mt-4">
            <div class="card">
                <div class="card-header">
                    <h5>Usage Examples</h5>
                </div>
                <div class="card-body">
                    <pre><code><?php
echo htmlspecialchars('// Send using template
$msg = new MessagingHelper($db);
$result = $msg->sendFromTemplate(
    \'payment_reminder_3day\',
    $donorId,
    [\'name\' => \'John\', \'amount\' => \'£50\'],
    \'auto\'  // Smart channel selection
);

// Send direct message
$result = $msg->sendDirect(
    \'07123456789\',
    \'Hello! Your payment is due soon.\',
    \'auto\'
);

// Send to donor
$result = $msg->sendToDonor(
    $donorId,
    \'Thank you for your payment!\',
    \'auto\'
);
');
                    ?></code></pre>
                    <p class="text-muted mt-2">
                        See <code>docs/MESSAGING_SYSTEM_USAGE.md</code> for full documentation.
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

