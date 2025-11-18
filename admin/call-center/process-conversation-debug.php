<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone
date_default_timezone_set('Europe/London');

// This is a TEST version of process-conversation.php that LOGS everything
// Copy this file and rename it to process-conversation-debug.php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<!DOCTYPE html><html><head><title>Debug - Not POST</title></head><body>";
    echo "<h1>Error: This page must be accessed via POST</h1>";
    echo "<p>Current method: " . $_SERVER['REQUEST_METHOD'] . "</p>";
    echo "<a href='index.php'>Back to Dashboard</a>";
    echo "</body></html>";
    exit;
}

// Start output
echo "<!DOCTYPE html><html><head><title>Process Conversation - Debug Mode</title>";
echo "<style>
    body { font-family: Arial; padding: 20px; }
    .success { background: #d1fae5; padding: 10px; margin: 10px 0; border-left: 4px solid #10b981; }
    .error { background: #fee2e2; padding: 10px; margin: 10px 0; border-left: 4px solid #ef4444; }
    .info { background: #dbeafe; padding: 10px; margin: 10px 0; border-left: 4px solid #3b82f6; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
    th { background: #f0f0f0; }
</style>";
echo "</head><body>";

echo "<h1>🔍 Process Conversation - Debug Mode</h1>";

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    
    // 1. LOG ALL POST DATA
    echo "<div class='info'><h2>Step 1: POST Data Received</h2>";
    echo "<table><tr><th>Parameter</th><th>Value</th><th>Type</th></tr>";
    foreach ($_POST as $key => $value) {
        $type = gettype($value);
        $display_value = is_array($value) ? json_encode($value) : htmlspecialchars((string)$value);
        echo "<tr><td>{$key}</td><td>{$display_value}</td><td>{$type}</td></tr>";
    }
    echo "</table></div>";
    
    // 2. GET CRITICAL PARAMETERS
    $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    $donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;
    $queue_id = isset($_POST['queue_id']) ? (int)$_POST['queue_id'] : 0;
    $pledge_id = isset($_POST['pledge_id']) ? (int)$_POST['pledge_id'] : 0;
    $duration_seconds = isset($_POST['call_duration_seconds']) ? (int)$_POST['call_duration_seconds'] : 0;
    
    echo "<div class='info'><h2>Step 2: Parsed Parameters</h2>";
    echo "<table>";
    echo "<tr><th>Parameter</th><th>Value</th><th>Status</th></tr>";
    echo "<tr><td>session_id</td><td>{$session_id}</td><td>" . ($session_id > 0 ? '✅' : '❌') . "</td></tr>";
    echo "<tr><td>donor_id</td><td>{$donor_id}</td><td>" . ($donor_id > 0 ? '✅' : '❌') . "</td></tr>";
    echo "<tr><td>queue_id</td><td>{$queue_id}</td><td>" . ($queue_id > 0 ? '✅' : '❌') . "</td></tr>";
    echo "<tr><td>pledge_id</td><td>{$pledge_id}</td><td>" . ($pledge_id > 0 ? '✅' : '❌') . "</td></tr>";
    echo "<tr><td style='font-weight:bold; background: " . ($duration_seconds > 0 ? '#d1fae5' : '#fee2e2') . "'>call_duration_seconds</td><td style='font-weight:bold'>{$duration_seconds}</td><td>" . ($duration_seconds > 0 ? '✅ GOOD' : '❌ BAD (ZERO OR MISSING)') . "</td></tr>";
    echo "</table></div>";
    
    // 3. CHECK SESSION STATE
    if ($session_id > 0) {
        $check_query = "SELECT id, donor_id, call_started_at, duration_seconds FROM call_center_sessions WHERE id = ?";
        $stmt = $db->prepare($check_query);
        $stmt->bind_param('i', $session_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            echo "<div class='success'><h2>Step 3: Session Found in Database</h2>";
            echo "<table>";
            echo "<tr><th>Field</th><th>Current Value</th></tr>";
            foreach ($row as $key => $value) {
                echo "<tr><td>{$key}</td><td>{$value}</td></tr>";
            }
            echo "</table></div>";
        } else {
            echo "<div class='error'><h2>Step 3: Session NOT Found</h2>";
            echo "<p>Session ID {$session_id} does not exist in call_center_sessions table.</p></div>";
        }
        $stmt->close();
    } else {
        echo "<div class='error'><h2>Step 3: No Session ID</h2>";
        echo "<p>Session ID is 0 or missing. Cannot update call duration.</p></div>";
    }
    
    // 4. SIMULATE UPDATE (DON'T ACTUALLY RUN IT)
    echo "<div class='info'><h2>Step 4: What Would Be Updated</h2>";
    if ($session_id > 0) {
        echo "<p><strong>SQL Query:</strong></p>";
        echo "<pre>UPDATE call_center_sessions 
SET outcome = 'payment_plan_created',
    conversation_stage = 'completed',
    payment_plan_id = [PLAN_ID],
    duration_seconds = duration_seconds + {$duration_seconds},
    call_ended_at = NOW()
WHERE id = {$session_id}</pre>";
        
        if ($duration_seconds > 0) {
            echo "<div class='success'>✅ Duration would be updated to: <strong>current + {$duration_seconds} seconds</strong></div>";
        } else {
            echo "<div class='error'>❌ Duration would NOT change (adding 0 seconds)</div>";
        }
    } else {
        echo "<div class='error'>❌ No update possible - session_id is missing</div>";
    }
    
    // 5. CHECK JAVASCRIPT CAPTURE
    echo "<div class='info'><h2>Step 5: Diagnosis</h2>";
    if ($duration_seconds === 0) {
        echo "<div class='error'>";
        echo "<h3>❌ Problem: call_duration_seconds is 0 or missing</h3>";
        echo "<p><strong>Possible causes:</strong></p>";
        echo "<ol>";
        echo "<li>JavaScript CallWidget.getDurationSeconds() returned 0</li>";
        echo "<li>Timer was never started (CallWidget.start() not called)</li>";
        echo "<li>Timer state was lost (localStorage cleared)</li>";
        echo "<li>Form submission happened before JS could inject the hidden input</li>";
        echo "<li>Hidden input was created but not added to form properly</li>";
        echo "</ol>";
        echo "<p><strong>Solution:</strong> Check browser console for 'CallWidget: Starting timer' log message</p>";
        echo "</div>";
    } else {
        echo "<div class='success'>";
        echo "<h3>✅ call_duration_seconds is present: {$duration_seconds} seconds</h3>";
        echo "<p>The JavaScript is working correctly. Duration would be saved.</p>";
        echo "</div>";
    }
    
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='error'><h2>Exception Caught</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p></div>";
}

echo "<br><br><a href='index.php'>Back to Dashboard</a>";
echo "</body></html>";

