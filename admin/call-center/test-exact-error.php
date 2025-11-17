<?php
// Ultra-comprehensive error catching
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Catch ALL errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo "<div style='background:yellow;padding:10px;margin:5px;'>";
    echo "<strong>Error [$errno]:</strong> $errstr<br>";
    echo "File: $errfile<br>";
    echo "Line: $errline<br>";
    echo "</div>";
    return true;
});

// Catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null) {
        echo "<div style='background:red;color:white;padding:10px;margin:5px;'>";
        echo "<strong>Fatal Error:</strong> {$error['message']}<br>";
        echo "File: {$error['file']}<br>";
        echo "Line: {$error['line']}<br>";
        echo "</div>";
    }
});
?>
<!DOCTYPE html>
<html>
<head>
    <title>Exact Error Finder</title>
    <style>
        .test { margin: 20px; padding: 20px; border: 1px solid #ddd; }
        .ok { background: #d4f4dd; }
        .error { background: #f4d4d4; }
        pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
<h1>Finding the EXACT Error in index.php</h1>

<div class="test">
    <h2>Step 1: Include auth.php</h2>
    <?php
    try {
        require_once __DIR__ . '/../../shared/auth.php';
        echo "<p class='ok'>✓ auth.php loaded</p>";
    } catch (Throwable $e) {
        echo "<p class='error'>✗ auth.php failed: " . $e->getMessage() . "</p>";
        die();
    }
    ?>
</div>

<div class="test">
    <h2>Step 2: Include db.php</h2>
    <?php
    try {
        require_once __DIR__ . '/../../config/db.php';
        echo "<p class='ok'>✓ db.php loaded</p>";
    } catch (Throwable $e) {
        echo "<p class='error'>✗ db.php failed: " . $e->getMessage() . "</p>";
        die();
    }
    ?>
</div>

<div class="test">
    <h2>Step 3: Check login (WITHOUT redirect)</h2>
    <?php
    if (!is_logged_in()) {
        echo "<p class='error'>✗ Not logged in. <a href='../login.php'>Login first</a></p>";
        die();
    } else {
        echo "<p class='ok'>✓ Logged in as: " . htmlspecialchars($_SESSION['user']['name']) . "</p>";
    }
    ?>
</div>

<div class="test">
    <h2>Step 4: Test require_login()</h2>
    <?php
    // This is what index.php does - it might redirect
    echo "<p>About to call require_login()...</p>";
    ob_start();
    require_login();
    $output = ob_get_clean();
    echo "<p class='ok'>✓ require_login() passed</p>";
    if ($output) {
        echo "<p>Output: " . htmlspecialchars($output) . "</p>";
    }
    ?>
</div>

<div class="test">
    <h2>Step 5: Get database connection</h2>
    <?php
    try {
        $db = db();
        echo "<p class='ok'>✓ Database connected</p>";
    } catch (Exception $e) {
        echo "<p class='error'>✗ Database failed: " . $e->getMessage() . "</p>";
        die();
    }
    ?>
</div>

<div class="test">
    <h2>Step 6: Set user variables</h2>
    <?php
    $user_id = (int)$_SESSION['user']['id'];
    $user_name = $_SESSION['user']['name'] ?? 'Agent';
    echo "<p class='ok'>✓ User ID: $user_id, Name: $user_name</p>";
    ?>
</div>

<div class="test">
    <h2>Step 7: Initialize default stats</h2>
    <?php
    $today_stats = (object)[
        'total_calls' => 0,
        'successful_contacts' => 0,
        'positive_outcomes' => 0,
        'callbacks_scheduled' => 0,
        'total_talk_time' => 0
    ];
    echo "<p class='ok'>✓ Default stats initialized</p>";
    ?>
</div>

<div class="test">
    <h2>Step 8: Check tables existence</h2>
    <?php
    try {
        $tables_check = $db->query("SHOW TABLES LIKE 'call_center_sessions'");
        if (!$tables_check) {
            echo "<p class='error'>✗ Query failed: " . $db->error . "</p>";
        } else {
            $tables_exist = $tables_check->num_rows > 0;
            echo "<p class='ok'>✓ Tables exist: " . ($tables_exist ? 'YES' : 'NO') . "</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>✗ Table check failed: " . $e->getMessage() . "</p>";
    }
    ?>
</div>

<div class="test">
    <h2>Step 9: Test stats query</h2>
    <?php
    if (isset($tables_exist) && $tables_exist) {
        $today_start = date('Y-m-d 00:00:00');
        $today_end = date('Y-m-d 23:59:59');
        
        $stats_query = "
        SELECT 
            COUNT(*) as total_calls,
            SUM(CASE WHEN conversation_stage != 'no_connection' THEN 1 ELSE 0 END) as successful_contacts,
            SUM(CASE WHEN outcome IN ('payment_plan_created', 'agreed_to_pay_full', 'agreed_reduced_amount', 'agreed_cash_collection') THEN 1 ELSE 0 END) as positive_outcomes,
            SUM(CASE WHEN callback_scheduled_for IS NOT NULL THEN 1 ELSE 0 END) as callbacks_scheduled,
            SUM(duration_seconds) as total_talk_time
        FROM call_center_sessions
        WHERE agent_id = ? AND call_started_at BETWEEN ? AND ?
        ";
        
        echo "<p>Running stats query...</p>";
        
        $stmt = $db->prepare($stats_query);
        if (!$stmt) {
            echo "<p class='error'>✗ Prepare failed: " . $db->error . "</p>";
        } else {
            echo "<p class='ok'>✓ Query prepared</p>";
            
            $bind_result = $stmt->bind_param('iss', $user_id, $today_start, $today_end);
            if (!$bind_result) {
                echo "<p class='error'>✗ Bind failed</p>";
            } else {
                echo "<p class='ok'>✓ Parameters bound</p>";
                
                $exec_result = $stmt->execute();
                if (!$exec_result) {
                    echo "<p class='error'>✗ Execute failed: " . $stmt->error . "</p>";
                } else {
                    echo "<p class='ok'>✓ Query executed</p>";
                    
                    $result = $stmt->get_result();
                    if (!$result) {
                        echo "<p class='error'>✗ Get result failed</p>";
                    } else {
                        $row = $result->fetch_object();
                        if ($row) {
                            echo "<p class='ok'>✓ Stats retrieved:</p>";
                            echo "<pre>" . json_encode($row, JSON_PRETTY_PRINT) . "</pre>";
                            $today_stats = $row;
                        } else {
                            echo "<p>No stats found (empty result)</p>";
                        }
                    }
                }
            }
            $stmt->close();
        }
    }
    ?>
</div>

<div class="test">
    <h2>Step 10: Test queue query</h2>
    <?php
    if (isset($tables_exist) && $tables_exist) {
        $queue_query = "
        SELECT 
            q.id as queue_id,
            q.donor_id,
            q.queue_type,
            q.priority,
            q.attempts_count,
            q.next_attempt_after,
            q.reason_for_queue,
            q.preferred_contact_time,
            d.name,
            d.phone,
            d.balance,
            d.city,
            d.last_contacted_at,
            (SELECT outcome FROM call_center_sessions WHERE donor_id = d.id ORDER BY call_started_at DESC LIMIT 1) as last_outcome
        FROM call_center_queues q
        JOIN donors d ON q.donor_id = d.id
        WHERE q.status = 'pending' 
            AND (q.assigned_to IS NULL OR q.assigned_to = ?)
            AND (q.next_attempt_after IS NULL OR q.next_attempt_after <= NOW())
        ORDER BY q.priority DESC, q.next_attempt_after ASC, q.created_at ASC
        LIMIT 50
        ";
        
        echo "<p>Running queue query...</p>";
        
        $stmt = $db->prepare($queue_query);
        if (!$stmt) {
            echo "<p class='error'>✗ Prepare failed: " . $db->error . "</p>";
        } else {
            echo "<p class='ok'>✓ Query prepared</p>";
            
            if (!$stmt->bind_param('i', $user_id)) {
                echo "<p class='error'>✗ Bind failed</p>";
            } else {
                echo "<p class='ok'>✓ Parameters bound</p>";
                
                if (!$stmt->execute()) {
                    echo "<p class='error'>✗ Execute failed: " . $stmt->error . "</p>";
                } else {
                    echo "<p class='ok'>✓ Query executed</p>";
                    
                    $queue_result = $stmt->get_result();
                    if (!$queue_result) {
                        echo "<p class='error'>✗ Get result failed</p>";
                    } else {
                        echo "<p class='ok'>✓ Queue rows: " . $queue_result->num_rows . "</p>";
                        if ($queue_result->num_rows > 0) {
                            $first_row = $queue_result->fetch_object();
                            echo "<pre>First row: " . json_encode($first_row, JSON_PRETTY_PRINT) . "</pre>";
                            
                            // Test the problematic code
                            echo "<p>Testing priority comparison: ";
                            $priority_class = (int)$first_row->priority >= 8 ? 'urgent' : ((int)$first_row->priority >= 5 ? 'high' : 'normal');
                            echo "Priority {$first_row->priority} → class '$priority_class' ✓</p>";
                            
                            echo "<p>Testing balance format: ";
                            $formatted_balance = number_format((float)$first_row->balance, 2);
                            echo "£$formatted_balance ✓</p>";
                        }
                    }
                }
            }
            $stmt->close();
        }
    }
    ?>
</div>

<div class="test">
    <h2>Step 11: Test conversion rate calculation</h2>
    <?php
    $conversion_rate = isset($today_stats->total_calls) && (int)$today_stats->total_calls > 0 
        ? round(((int)$today_stats->positive_outcomes / (int)$today_stats->total_calls) * 100, 1) 
        : 0;
    echo "<p class='ok'>✓ Conversion rate: $conversion_rate%</p>";
    ?>
</div>

<div class="test">
    <h2>Step 12: Test includes</h2>
    <?php
    $page_title = 'Call Center Dashboard';
    $current_dir = 'call-center';
    
    // Test sidebar
    echo "<p>Testing sidebar.php...</p>";
    ob_start();
    $sidebar_error = null;
    try {
        include __DIR__ . '/../includes/sidebar.php';
        $sidebar_output = ob_get_clean();
        echo "<p class='ok'>✓ sidebar.php loaded (" . strlen($sidebar_output) . " bytes)</p>";
    } catch (Throwable $e) {
        ob_end_clean();
        $sidebar_error = $e->getMessage();
        echo "<p class='error'>✗ sidebar.php failed: $sidebar_error</p>";
    }
    
    // Test topbar
    echo "<p>Testing topbar.php...</p>";
    ob_start();
    $topbar_error = null;
    try {
        include __DIR__ . '/../includes/topbar.php';
        $topbar_output = ob_get_clean();
        echo "<p class='ok'>✓ topbar.php loaded (" . strlen($topbar_output) . " bytes)</p>";
    } catch (Throwable $e) {
        ob_end_clean();
        $topbar_error = $e->getMessage();
        echo "<p class='error'>✗ topbar.php failed: $topbar_error</p>";
    }
    ?>
</div>

<div class="test" style="background: #e0f7fa;">
    <h2>🎯 Summary</h2>
    <p>If all tests passed above, then index.php should work!</p>
    <p>If any test failed, that's where the 500 error is coming from.</p>
    <p><strong>Look for any red error boxes above!</strong></p>
    
    <hr>
    
    <p><a href="index.php" class="button">Try index.php now</a></p>
    <p><a href="index-safe.php" class="button">Try index-safe.php</a></p>
    <p><a href="index-minimal.php" class="button">Try index-minimal.php</a></p>
</div>

</body>
</html>
