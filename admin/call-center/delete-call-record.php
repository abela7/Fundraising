<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_login();

$db = db();
$user_id = (int)$_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'] ?? 'registrar';
$is_admin = ($user_role === 'admin');

$session_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$session_id) {
    header('Location: call-history.php');
    exit;
}

// Fetch Session & Dependencies
$query = "
    SELECT s.*, pp.id as linked_plan_id, pp.total_amount 
    FROM call_center_sessions s
    LEFT JOIN donor_payment_plans pp ON s.payment_plan_id = pp.id
    WHERE s.id = ?
";
$stmt = $db->prepare($query);
$stmt->bind_param('i', $session_id);
$stmt->execute();
$call = $stmt->get_result()->fetch_object();

if (!$call) {
    die("Record not found.");
}

// Permission Check
if (!$is_admin && $call->agent_id != $user_id) {
    die("Access Denied. You can only delete your own records.");
}

// Handle Confirmation POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? ''; // 'delete_all', 'unlink_plan', 'simple_delete'
    
    $db->begin_transaction();
    try {
        // 1. Handle Appointment (Always delete linked appointment)
        $db->query("DELETE FROM call_center_appointments WHERE session_id = $session_id");
        
        // 2. Handle Payment Plan
        if ($call->linked_plan_id) {
            if ($action === 'delete_all') {
                // Delete Plan and Reset Donor
                $plan_id = $call->linked_plan_id;
                $donor_id = $call->donor_id;
                
                // Reset Donor Status
                $db->query("UPDATE donors SET active_payment_plan_id = NULL, payment_status = 'pending' WHERE id = $donor_id AND active_payment_plan_id = $plan_id");
                
                // Delete Plan
                $db->query("DELETE FROM donor_payment_plans WHERE id = $plan_id");
                
            } elseif ($action === 'unlink_plan') {
                // Just unlink from session (Plan stays active)
                // No extra action needed here, deleting session row handles it? 
                // Wait, if we delete session, payment_plan_id column in session is gone.
                // But we don't touch the plan table.
            }
        }
        
        // 3. Delete Session
        $db->query("DELETE FROM call_center_sessions WHERE id = $session_id");
        
        // Audit log the deletion
        log_audit(
            $db,
            'delete',
            'call_session',
            $session_id,
            [
                'donor_id' => $call->donor_id,
                'agent_id' => $call->agent_id,
                'linked_plan_id' => $call->linked_plan_id,
                'action_type' => $action,
                'total_amount' => $call->total_amount ?? null
            ],
            null,
            'admin_portal',
            $user_id
        );
        
        $db->commit();
        header('Location: call-history.php?msg=deleted');
        exit;
        
    } catch (Exception $e) {
        $db->rollback();
        die("Error deleting record: " . $e->getMessage());
    }
}

// Show Confirmation Page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Delete Call Record</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-5" style="max-width: 600px;">
    <div class="card border-danger">
        <div class="card-header bg-danger text-white">
            <h4 class="mb-0">⚠ Confirm Deletion</h4>
        </div>
        <div class="card-body">
            <p class="lead">You are about to delete Call Record #<?php echo $session_id; ?>.</p>
            
            <?php if ($call->linked_plan_id): ?>
                <div class="alert alert-warning">
                    <strong>Wait! This call created a Payment Plan (ID: <?php echo $call->linked_plan_id; ?>).</strong><br>
                    Amount: £<?php echo number_format($call->total_amount, 2); ?>
                </div>
                
                <form method="POST">
                    <p>How do you want to handle the payment plan?</p>
                    
                    <div class="d-grid gap-3">
                        <button type="submit" name="action" value="delete_all" class="btn btn-danger btn-lg text-start">
                            <strong>1. Delete Everything</strong><br>
                            <small>Delete call record AND the payment plan. Donor status will revert to 'pending'.</small>
                        </button>
                        
                        <button type="submit" name="action" value="unlink_plan" class="btn btn-outline-secondary btn-lg text-start">
                            <strong>2. Keep Plan, Delete Call Record</strong><br>
                            <small>The payment plan stays active. Only the call history log is removed.</small>
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <p>Are you sure you want to proceed? This cannot be undone.</p>
                <form method="POST">
                    <button type="submit" name="action" value="simple_delete" class="btn btn-danger">Yes, Delete Record</button>
                    <a href="call-details.php?id=<?php echo $session_id; ?>" class="btn btn-secondary">Cancel</a>
                </form>
            <?php endif; ?>
            
            <?php if ($call->linked_plan_id): ?>
                <div class="mt-3 text-center">
                    <a href="call-details.php?id=<?php echo $session_id; ?>" class="text-muted">Cancel</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>

