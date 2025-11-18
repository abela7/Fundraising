<?php
declare(strict_types=1);

// Start output buffering to capture any early output
ob_start();

echo "<!DOCTYPE html><html><head><title>Debug Call Status</title></head><body>";
echo "<h1>Call Status Debug</h1>";

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

echo "<h2>Step 1: Authentication ✓</h2>";
echo "<pre>";
echo "User ID: " . (isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : 'NOT SET') . "\n";
echo "User Name: " . (isset($_SESSION['user']['name']) ? $_SESSION['user']['name'] : 'NOT SET') . "\n";
echo "</pre>";

echo "<h2>Step 2: Request Data</h2>";
echo "<pre>";
echo "REQUEST METHOD: " . $_SERVER['REQUEST_METHOD'] . "\n\n";
echo "POST Data:\n";
print_r($_POST);
echo "\nGET Data:\n";
print_r($_GET);
echo "</pre>";

$donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : (isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0);
$queue_id = isset($_POST['queue_id']) ? (int)$_POST['queue_id'] : (isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0);

echo "<h2>Step 3: Parsed Values</h2>";
echo "<pre>";
echo "Donor ID: " . $donor_id . " (from " . (isset($_POST['donor_id']) ? "POST" : (isset($_GET['donor_id']) ? "GET" : "NONE")) . ")\n";
echo "Queue ID: " . $queue_id . " (from " . (isset($_POST['queue_id']) ? "POST" : (isset($_GET['queue_id']) ? "GET" : "NONE")) . ")\n";
echo "</pre>";

if (!$donor_id || !$queue_id) {
    echo "<h2>❌ REDIRECT TRIGGERED</h2>";
    echo "<p style='color: red; font-weight: bold;'>Donor ID or Queue ID is missing/zero. This causes redirect to index.php</p>";
    echo "<p><a href='index.php'>Back to Call Center</a></p>";
    echo "</body></html>";
    exit;
}

echo "<h2>Step 4: Database Connection</h2>";
try {
    $db = db();
    echo "<p>✓ Database connected</p>";
    
    $user_id = (int)$_SESSION['user']['id'];
    echo "<p>✓ User ID: {$user_id}</p>";
    
    echo "<h2>Step 5: Query Donor</h2>";
    $donor_query = "SELECT name, phone FROM donors WHERE id = ? LIMIT 1";
    $stmt = $db->prepare($donor_query);
    if (!$stmt) {
        echo "<p style='color: red;'>❌ Failed to prepare statement: " . $db->error . "</p>";
        exit;
    }
    
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $donor = $result->fetch_object();
    $stmt->close();
    
    if (!$donor) {
        echo "<p style='color: red;'>❌ Donor not found with ID: {$donor_id}</p>";
        echo "<p>This causes redirect to index.php</p>";
        exit;
    }
    
    echo "<p>✓ Donor found:</p>";
    echo "<pre>";
    print_r($donor);
    echo "</pre>";
    
    echo "<h2>Step 6: Create Session Record</h2>";
    $call_started_at = date('Y-m-d H:i:s');
    echo "<p>Call started at: {$call_started_at}</p>";
    
    $session_query = "
        INSERT INTO call_center_sessions 
        (donor_id, agent_id, queue_id, call_started_at, conversation_stage, status, created_at)
        VALUES (?, ?, ?, ?, 'phone_status', 'in_progress', NOW())
    ";
    
    $stmt = $db->prepare($session_query);
    if (!$stmt) {
        echo "<p style='color: red;'>❌ Failed to prepare session insert: " . $db->error . "</p>";
        exit;
    }
    
    $stmt->bind_param('iiis', $donor_id, $user_id, $queue_id, $call_started_at);
    $result = $stmt->execute();
    
    if (!$result) {
        echo "<p style='color: red;'>❌ Failed to insert session: " . $stmt->error . "</p>";
        exit;
    }
    
    $session_id = $db->insert_id;
    $stmt->close();
    
    echo "<p>✓ Session created with ID: {$session_id}</p>";
    
    echo "<h2>Step 7: Update Queue</h2>";
    $update_queue = "UPDATE call_center_queues SET attempts_count = attempts_count + 1 WHERE id = ?";
    $stmt = $db->prepare($update_queue);
    if ($stmt) {
        $stmt->bind_param('i', $queue_id);
        $stmt->execute();
        echo "<p>✓ Queue updated (affected rows: " . $stmt->affected_rows . ")</p>";
        $stmt->close();
    }
    
    echo "<h2>✅ ALL STEPS PASSED</h2>";
    echo "<p style='color: green; font-weight: bold;'>The page should load normally!</p>";
    echo "<p><a href='call-status.php?donor_id={$donor_id}&queue_id={$queue_id}'>Continue to Call Status Page</a></p>";
    
} catch (Exception $e) {
    echo "<h2>❌ EXCEPTION CAUGHT</h2>";
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>";
    echo $e->getTraceAsString();
    echo "</pre>";
    echo "<p>This causes redirect to index.php</p>";
}

echo "</body></html>";
ob_end_flush();
?>

