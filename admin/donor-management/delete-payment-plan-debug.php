<?php
// ENABLE DEBUGGING
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

declare(strict_types=1);

echo "<h1>Debug: delete-payment-plan.php</h1>";

// Check paths
echo "<p>Current directory: " . __DIR__ . "</p>";
$auth_path = __DIR__ . '/../../shared/auth.php';
$db_path = __DIR__ . '/../../config/db.php';

echo "<p>Auth path: $auth_path " . (file_exists($auth_path) ? "[FOUND]" : "[MISSING]") . "</p>";
echo "<p>DB path: $db_path " . (file_exists($db_path) ? "[FOUND]" : "[MISSING]") . "</p>";

require_once $auth_path;
require_once $db_path;

echo "<p>Includes loaded.</p>";

require_login();
echo "<p>Logged in.</p>";

// Set timezone
date_default_timezone_set('Europe/London');

$conn = db();
echo "<p>DB Connected.</p>";

$plan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';

echo "<p>Plan ID: $plan_id, Donor ID: $donor_id, Confirm: $confirm</p>";

if ($plan_id <= 0 || $donor_id <= 0) {
    die("Invalid IDs");
}

// Fetch payment plan details
try {
    $query = "
        SELECT 
            dpp.*,
            d.name as donor_name,
            d.phone as donor_phone,
            d.active_payment_plan_id,
            d.payment_status,
            p.amount as pledge_amount
        FROM donor_payment_plans dpp
        JOIN donors d ON dpp.donor_id = d.id
        LEFT JOIN pledges p ON dpp.pledge_id = p.id
        WHERE dpp.id = ? AND dpp.donor_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param('ii', $plan_id, $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $plan = $result->fetch_object();
    
    if (!$plan) {
        die("Payment plan not found");
    }
    
    echo "<pre>Plan Data:\n";
    var_dump($plan);
    echo "</pre>";
    
    // Check for linked call sessions
    $session_query = "SELECT COUNT(*) as count FROM call_center_sessions WHERE payment_plan_id = ?";
    $session_stmt = $conn->prepare($session_query);
    $session_stmt->bind_param('i', $plan_id);
    $session_stmt->execute();
    $session_result = $session_stmt->get_result();
    $linked_sessions = $session_result->fetch_object()->count;
    
    echo "<p>Linked Sessions: $linked_sessions</p>";
    
    // Check if this is the active plan
    $is_active_plan = ($plan->active_payment_plan_id == $plan_id);
    echo "<p>Is Active Plan: " . ($is_active_plan ? 'Yes' : 'No') . "</p>";
    
} catch (Exception $e) {
    die("Exception: " . $e->getMessage());
}

echo "<p>Reached HTML rendering...</p>";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Debug Confirm Delete</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>Confirmation Page Test</h1>
        
        <div class="row">
            <div class="col-md-6">
                <strong>Total Amount:</strong> 
                £<?php echo number_format((float)$plan->total_amount, 2); ?>
            </div>
            <div class="col-md-6">
                <strong>Monthly:</strong> 
                £<?php echo number_format((float)$plan->monthly_amount, 2); ?>
            </div>
            <div class="col-md-6">
                <strong>Amount Paid:</strong> 
                £<?php echo number_format((float)$plan->amount_paid, 2); ?>
            </div>
        </div>
        
        <form method="POST" action="delete-payment-plan.php?id=<?php echo $plan_id; ?>&donor_id=<?php echo $donor_id; ?>&confirm=yes">
            <button type="submit" class="btn btn-danger mt-3">Simulate Delete</button>
        </form>
    </div>
</body>
</html>

