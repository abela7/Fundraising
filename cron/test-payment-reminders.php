<?php
/**
 * Test Helper for Payment Reminder Cron Job
 * 
 * This file helps you test the payment reminder system by:
 * 1. Showing payments due in 2 days
 * 2. Allowing you to run the cron job manually
 * 3. Showing recent logs
 * 
 * SECURITY: DELETE THIS FILE AFTER TESTING!
 */

require_once __DIR__ . '/../config/db.php';

$db = db();
$action = $_GET['action'] ?? 'info';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Payment Reminders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 20px; background: #f5f5f5; }
        .card { margin-bottom: 20px; }
        .log-content { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 5px; font-family: 'Courier New', monospace; font-size: 12px; max-height: 400px; overflow-y: auto; }
        .success { color: #4caf50; }
        .error { color: #f44336; }
        .warning { color: #ff9800; }
    </style>
</head>
<body>
    <div class="container">
        <div class="alert alert-danger">
            <strong>⚠️ SECURITY WARNING:</strong> This file is for testing only. DELETE it after testing!
        </div>

        <h1 class="mb-4">🔔 Payment Reminder Test Tool</h1>

        <?php if ($action === 'info'): ?>
            <!-- Step 1: Show payments due in 2 days -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">📅 Step 1: Payments Due in 2 Days</h5>
                </div>
                <div class="card-body">
                    <?php
                    $targetDate = date('Y-m-d', strtotime('+2 days'));
                    $formattedDate = date('l, j F Y', strtotime('+2 days'));
                    
                    echo "<p><strong>Target Date:</strong> {$formattedDate} ({$targetDate})</p>";
                    
                    $query = "
                        SELECT 
                            pp.id, pp.donor_id, pp.monthly_amount, pp.next_payment_due,
                            d.name, d.phone, d.preferred_language, d.sms_opt_in
                        FROM donor_payment_plans pp
                        JOIN donors d ON pp.donor_id = d.id
                        WHERE pp.next_payment_due = ?
                        AND pp.status = 'active'
                        AND d.phone IS NOT NULL
                        ORDER BY d.name
                    ";
                    
                    $stmt = $db->prepare($query);
                    $stmt->bind_param('s', $targetDate);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $count = $result->num_rows;
                    
                    if ($count > 0) {
                        echo "<div class='alert alert-success'><strong>✅ Found {$count} payment(s) due in 2 days</strong></div>";
                        echo "<table class='table table-sm'>";
                        echo "<thead><tr><th>Donor</th><th>Phone</th><th>Amount</th><th>Language</th><th>SMS Opt-in</th></tr></thead><tbody>";
                        
                        while ($row = $result->fetch_assoc()) {
                            $optIn = $row['sms_opt_in'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-danger">No</span>';
                            echo "<tr>";
                            echo "<td>{$row['name']}</td>";
                            echo "<td>{$row['phone']}</td>";
                            echo "<td>£" . number_format($row['monthly_amount'], 2) . "</td>";
                            echo "<td><span class='badge bg-info'>" . strtoupper($row['preferred_language']) . "</span></td>";
                            echo "<td>{$optIn}</td>";
                            echo "</tr>";
                        }
                        echo "</tbody></table>";
                    } else {
                        echo "<div class='alert alert-warning'><strong>⚠️ No payments due in 2 days</strong><br>To test, create a payment plan with next_payment_due = '{$targetDate}'</div>";
                    }
                    ?>
                </div>
            </div>

            <!-- Step 2: Run the cron job -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">▶️ Step 2: Run Cron Job</h5>
                </div>
                <div class="card-body">
                    <p>Click the button below to run the payment reminder cron job manually:</p>
                    <form method="get" action="">
                        <input type="hidden" name="action" value="run">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-play"></i> Run Payment Reminder Job
                        </button>
                    </form>
                </div>
            </div>

            <!-- Step 3: View logs -->
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">📋 Step 3: View Recent Logs</h5>
                </div>
                <div class="card-body">
                    <a href="?action=logs" class="btn btn-info">View Today's Log</a>
                </div>
            </div>

        <?php elseif ($action === 'run'): ?>
            <!-- Run the cron job -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">▶️ Running Cron Job...</h5>
                </div>
                <div class="card-body">
                    <div class="log-content">
                        <?php
                        // Get the absolute path to PHP and the cron script
                        $phpPath = PHP_BINARY;
                        $cronScript = __DIR__ . '/send-payment-reminders-2day.php';
                        
                        echo "<span class='text-info'>Executing: {$phpPath} {$cronScript}</span>\n\n";
                        echo str_repeat('=', 80) . "\n\n";
                        
                        // Execute the cron job via command line
                        $output = [];
                        $return_var = 0;
                        exec("\"{$phpPath}\" \"{$cronScript}\" 2>&1", $output, $return_var);
                        
                        if ($return_var === 0) {
                            echo "<span class='success'>✅ Cron job completed successfully!</span>\n\n";
                        } else {
                            echo "<span class='error'>❌ Cron job failed with exit code: {$return_var}</span>\n\n";
                        }
                        
                        foreach ($output as $line) {
                            if (strpos($line, 'ERROR') !== false || strpos($line, 'FAILED') !== false) {
                                echo "<span class='error'>{$line}</span>\n";
                            } elseif (strpos($line, 'SENT') !== false || strpos($line, 'COMPLETE') !== false) {
                                echo "<span class='success'>{$line}</span>\n";
                            } elseif (strpos($line, 'SKIP') !== false) {
                                echo "<span class='warning'>{$line}</span>\n";
                            } else {
                                echo htmlspecialchars($line) . "\n";
                            }
                        }
                        
                        if (empty($output)) {
                            echo "<span class='warning'>No output received. Check the log file below.</span>\n";
                        }
                        ?>
                    </div>
                    <div class="mt-3">
                        <a href="?action=info" class="btn btn-primary">← Back to Info</a>
                        <a href="?action=logs" class="btn btn-info">View Logs</a>
                    </div>
                </div>
            </div>

        <?php elseif ($action === 'logs'): ?>
            <!-- Show logs -->
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">📋 Today's Log File</h5>
                </div>
                <div class="card-body">
                    <?php
                    $logFile = __DIR__ . '/../logs/payment-reminders-2day-' . date('Y-m-d') . '.log';
                    
                    if (file_exists($logFile)) {
                        $logContent = file_get_contents($logFile);
                        echo "<div class='log-content'>" . nl2br(htmlspecialchars($logContent)) . "</div>";
                    } else {
                        echo "<div class='alert alert-warning'>No log file found for today. The cron job hasn't run yet.</div>";
                    }
                    ?>
                    <div class="mt-3">
                        <a href="?action=info" class="btn btn-primary">← Back to Info</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Check reminder tracking -->
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="mb-0">📊 Reminder Tracking (Today)</h5>
            </div>
            <div class="card-body">
                <?php
                $today = date('Y-m-d');
                $trackQuery = "
                    SELECT prs.*, d.name as donor_name
                    FROM payment_reminders_sent prs
                    JOIN donors d ON prs.donor_id = d.id
                    WHERE DATE(prs.sent_at) = ?
                    ORDER BY prs.sent_at DESC
                ";
                $stmt = $db->prepare($trackQuery);
                $stmt->bind_param('s', $today);
                $stmt->execute();
                $result = $stmt->get_result();
                $trackCount = $result->num_rows;
                
                if ($trackCount > 0) {
                    echo "<div class='alert alert-info'><strong>📤 {$trackCount} reminder(s) sent today</strong></div>";
                    echo "<table class='table table-sm'>";
                    echo "<thead><tr><th>Time</th><th>Donor</th><th>Due Date</th><th>Channel</th><th>Source</th><th>Sent By</th></tr></thead><tbody>";
                    
                    while ($row = $result->fetch_assoc()) {
                        $time = date('H:i:s', strtotime($row['sent_at']));
                        $channel = strtoupper($row['channel']);
                        echo "<tr>";
                        echo "<td>{$time}</td>";
                        echo "<td>{$row['donor_name']}</td>";
                        echo "<td>{$row['due_date']}</td>";
                        echo "<td><span class='badge bg-" . ($row['channel'] === 'whatsapp' ? 'success' : 'primary') . "'>{$channel}</span></td>";
                        echo "<td><span class='badge bg-secondary'>{$row['source_type']}</span></td>";
                        echo "<td>{$row['sent_by_name']}</td>";
                        echo "</tr>";
                    }
                    echo "</tbody></table>";
                } else {
                    echo "<div class='alert alert-secondary'>No reminders sent today yet.</div>";
                }
                ?>
            </div>
        </div>

    </div>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</body>
</html>
