<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$db = db();
$plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;

if (!$plan_id || !$session_id) {
    die("Invalid request.");
}

// Confirmation Step
if (!isset($_POST['confirm'])) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Redo Payment Plan</title>
        <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    </head>
    <body class="bg-light">
        <div class="container mt-5" style="max-width: 600px;">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h4>Confirm Redo Plan</h4>
                </div>
                <div class="card-body">
                    <p>You are about to <strong>DELETE the current payment plan</strong> and return to the "Choose Plan" screen for this call.</p>
                    <ul>
                        <li>Current Plan #<?php echo $plan_id; ?> will be deleted permanently.</li>
                        <li>Donor status will be reset.</li>
                        <li>You will be redirected to the plan selection wizard.</li>
                    </ul>
                    <form method="POST">
                        <button type="submit" name="confirm" value="1" class="btn btn-warning w-100">Yes, Redo Plan</button>
                        <a href="call-details.php?id=<?php echo $session_id; ?>" class="btn btn-link w-100 mt-2">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Execute Redo Logic
$db->begin_transaction();
try {
    // 1. Get Donor ID
    $res = $db->query("SELECT donor_id FROM donor_payment_plans WHERE id = $plan_id");
    $row = $res->fetch_assoc();
    $donor_id = $row['donor_id'];
    
    // 2. Reset Donor
    $db->query("UPDATE donors SET active_payment_plan_id = NULL, payment_status = 'pending' WHERE id = $donor_id AND active_payment_plan_id = $plan_id");
    
    // 3. Delete Plan
    $db->query("DELETE FROM donor_payment_plans WHERE id = $plan_id");
    
    // 4. Reset Session (Unlink plan, reset outcome)
    // We keep duration accumulated so far!
    $db->query("UPDATE call_center_sessions SET payment_plan_id = NULL, outcome = NULL, conversation_stage = 'connected_no_identity_check' WHERE id = $session_id");
    
    // 5. Get Queue ID (needed for conversation.php)
    // Find queue from session or guess? conversation.php needs queue_id for links.
    // We can fetch it from appointments or history, or just pass 0 if handled.
    // Let's try to find the queue item for this donor/agent.
    // Or just pass queue_id=0 if the page supports it (it checks for it).
    // Let's find the most recent queue item for this session if possible.
    
    $q_res = $db->query("SELECT id FROM call_center_queues WHERE donor_id = $donor_id ORDER BY id DESC LIMIT 1");
    $q_row = $q_res->fetch_assoc();
    $queue_id = $q_row ? $q_row['id'] : 0;
    
    $db->commit();
    
    // Redirect to Conversation Page (Step 3 usually implies plan selection, but my conversation.php is a wizard. 
    // If I load it, it starts from beginning?
    // conversation.php doesn't have 'step' param logic visible in my edits (it uses JS steps).
    // But it checks if session exists.
    // I will redirect to conversation.php. The user will have to click "Can Talk" -> "Yes" -> Plan.
    // Or I can pass a flag to auto-jump?
    
    header("Location: conversation.php?session_id=$session_id&donor_id=$donor_id&queue_id=$queue_id");
    exit;
    
} catch (Exception $e) {
    $db->rollback();
    die("Error: " . $e->getMessage());
}

