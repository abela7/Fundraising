<?php
// ENABLE DEBUGGING
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

declare(strict_types=1);

echo "<h1>Debug: delete-call-session.php</h1>";

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone
date_default_timezone_set('Europe/London');

$conn = get_db_connection();
$session_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;

echo "<p>Session ID: $session_id, Donor ID: $donor_id</p>";

if ($session_id <= 0 || $donor_id <= 0) {
    die("Invalid IDs");
}

// Fetch call session details
try {
    $query = "
        SELECT 
            ccs.*,
            d.name as donor_name,
            d.phone as donor_phone,
            u.name as agent_name,
            dpp.id as has_payment_plan
        FROM call_center_sessions ccs
        JOIN donors d ON ccs.donor_id = d.id
        LEFT JOIN users u ON ccs.agent_id = u.id
        LEFT JOIN donor_payment_plans dpp ON ccs.payment_plan_id = dpp.id
        WHERE ccs.id = ? AND ccs.donor_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param('ii', $session_id, $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $session = $result->fetch_object();
    
    if (!$session) {
        die("Call session not found");
    }
    
    echo "<pre>Session Data:\n";
    var_dump($session);
    echo "</pre>";
    
} catch (Exception $e) {
    die("Exception: " . $e->getMessage());
}

// Calculate duration display
echo "<p>Calculating duration...</p>";
$duration_sec = (int)($session->duration_seconds ?? 0);
echo "<p>Duration Seconds (cast): $duration_sec</p>";

$minutes = floor($duration_sec / 60);
$seconds = $duration_sec % 60;
if ($minutes > 0) {
    $duration_display = $minutes . 'm ' . $seconds . 's';
} else {
    $duration_display = $seconds . 's';
}
echo "<p>Duration Display: $duration_display</p>";

echo "<p>Reached End of Debug Script</p>";
?>

