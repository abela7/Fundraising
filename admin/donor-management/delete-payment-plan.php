<?php
declare(strict_types=1);

// Error logging function
function log_deletion_error($message, $context = []) {
    $log_file = __DIR__ . '/../../logs/deletion_errors.log';
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    $log_entry = date('Y-m-d H:i:s') . " - " . $message . "\n";
    if (!empty($context)) {
        $log_entry .= "Context: " . json_encode($context, JSON_PRETTY_PRINT) . "\n";
    }
    $log_entry .= str_repeat('-', 80) . "\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND);
}

// Set error handler to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        log_deletion_error("Fatal Error: " . $error['message'], [
            'file' => $error['file'],
            'line' => $error['line'],
            'type' => $error['type']
        ]);
    }
});

try {
    require_once __DIR__ . '/../../shared/auth.php';
    require_once __DIR__ . '/../../config/db.php';
    require_login();

    // Set timezone
    date_default_timezone_set('Europe/London');

$conn = db();
$plan_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : (isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0);
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : (isset($_POST['confirm']) ? $_POST['confirm'] : '');

    if ($plan_id <= 0 || $donor_id <= 0) {
        header('Location: view-donor.php?id=' . $donor_id);
        exit;
    }
} catch (Exception $e) {
    log_deletion_error("Initialization Error: " . $e->getMessage(), [
        'plan_id' => $plan_id ?? 0,
        'donor_id' => $donor_id ?? 0,
        'trace' => $e->getTraceAsString()
    ]);
    die("System Error: " . htmlspecialchars($e->getMessage()));
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
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $plan = $result->fetch_object();
    
    if (!$plan) {
        header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Payment plan not found'));
        exit;
    }
    
    // Check for linked call sessions
    $session_query = "SELECT COUNT(*) as count FROM call_center_sessions WHERE payment_plan_id = ?";
    $session_stmt = $conn->prepare($session_query);
    if (!$session_stmt) {
        throw new Exception("Prepare failed for session query: " . $conn->error);
    }
    $session_stmt->bind_param('i', $plan_id);
    if (!$session_stmt->execute()) {
        throw new Exception("Execute failed for session query: " . $session_stmt->error);
    }
    $session_result = $session_stmt->get_result();
    $session_row = $session_result->fetch_object();
    $linked_sessions = $session_row ? (int)$session_row->count : 0;
    
    // Check if this is the active plan
    $is_active_plan = ($plan->active_payment_plan_id == $plan_id);
    
} catch (mysqli_sql_exception $e) {
    log_deletion_error("Payment Plan Fetch Failed (MySQL)", [
        'plan_id' => $plan_id,
        'donor_id' => $donor_id,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'sql_state' => $e->getSqlState()
    ]);
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Database error: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ')'));
    exit;
} catch (Exception $e) {
    log_deletion_error("Payment Plan Fetch Failed (General)", [
        'plan_id' => $plan_id,
        'donor_id' => $donor_id,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Error: ' . $e->getMessage()));
    exit;
}

// Handle deletion confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $confirm === 'yes') {
    try {
        $conn->begin_transaction();
        
        // Step 1: Unlink from call_center_sessions (must be done BEFORE deleting plan due to RESTRICT constraint)
        // Get all session IDs that reference this plan first
        $get_sessions_query = "SELECT id FROM call_center_sessions WHERE payment_plan_id = ?";
        $get_sessions_stmt = $conn->prepare($get_sessions_query);
        $get_sessions_stmt->bind_param('i', $plan_id);
        $get_sessions_stmt->execute();
        $sessions_result = $get_sessions_stmt->get_result();
        $session_ids = [];
        while ($row = $sessions_result->fetch_assoc()) {
            $session_ids[] = $row['id'];
        }
        
        // Unlink all sessions from this payment plan
        if (!empty($session_ids)) {
            $unlink_query = "UPDATE call_center_sessions SET payment_plan_id = NULL WHERE payment_plan_id = ?";
            $unlink_stmt = $conn->prepare($unlink_query);
            $unlink_stmt->bind_param('i', $plan_id);
            $unlink_stmt->execute();
        }
        
        // Step 2: If this is the active plan, reset donor status
        if ($is_active_plan) {
            $reset_donor_query = "
                UPDATE donors 
                SET active_payment_plan_id = NULL,
                    has_active_plan = 0,
                    payment_status = CASE 
                        WHEN balance > 0 THEN 'not_started'
                        WHEN balance = 0 THEN 'completed'
                        ELSE 'no_pledge'
                    END
                WHERE id = ?
            ";
            $reset_stmt = $conn->prepare($reset_donor_query);
            $reset_stmt->bind_param('i', $donor_id);
            $reset_stmt->execute();
        }
        
        // Step 3: Delete the payment plan (now safe - all references are unlinked)
        $delete_query = "DELETE FROM donor_payment_plans WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param('i', $plan_id);
        $delete_stmt->execute();
        
        $conn->commit();
        
        $message = 'Payment plan deleted successfully.';
        if ($linked_sessions > 0) {
            $message .= " {$linked_sessions} call session(s) were unlinked from this plan.";
        }
        
        header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode($message));
        exit;
        
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        $error_msg = 'Database error: ' . $e->getMessage() . ' (Error Code: ' . $e->getCode() . ')';
        
        log_deletion_error("Payment Plan Deletion Failed (MySQL)", [
            'plan_id' => $plan_id,
            'donor_id' => $donor_id,
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
            'sql_state' => $e->getSqlState(),
            'linked_sessions' => $linked_sessions ?? 0,
            'is_active_plan' => $is_active_plan ?? false,
            'trace' => $e->getTraceAsString()
        ]);
        
        header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode($error_msg));
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        
        log_deletion_error("Payment Plan Deletion Failed (General)", [
            'plan_id' => $plan_id,
            'donor_id' => $donor_id,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        
        header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Failed to delete: ' . $e->getMessage()));
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Delete Payment Plan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .confirm-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 600px;
            width: 100%;
        }
        .confirm-header {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 2rem;
            border-radius: 16px 16px 0 0;
            text-align: center;
        }
        .confirm-header i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.9;
        }
        .confirm-body {
            padding: 2rem;
        }
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #1e3c72;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 4px;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 4px;
        }
        .danger-box {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 4px;
        }
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }
        .action-buttons .btn {
            flex: 1;
            padding: 0.75rem;
            font-weight: 600;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #eee;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #6c757d;
        }
        
        @media (max-width: 768px) {
            .confirm-header {
                padding: 1.5rem;
            }
            .confirm-header i {
                font-size: 3rem;
            }
            .confirm-body {
                padding: 1.5rem;
            }
            .action-buttons {
                flex-direction: column-reverse;
            }
        }
    </style>
</head>
<body>
    <div class="confirm-card">
        <div class="confirm-header">
            <i class="fas fa-exclamation-triangle"></i>
            <h2 class="mb-0">Confirm Delete Payment Plan</h2>
        </div>
        
        <div class="confirm-body">
            <div class="danger-box">
                <strong><i class="fas fa-exclamation-circle me-2"></i>Warning:</strong>
                You are about to permanently delete this payment plan. This action cannot be undone!
            </div>
            
            <div class="info-box">
                <h6 class="mb-3"><strong>Payment Plan Details</strong></h6>
                <div class="detail-row">
                    <span class="detail-label">Donor:</span>
                    <span><?php echo htmlspecialchars($plan->donor_name); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Phone:</span>
                    <span><?php echo htmlspecialchars($plan->donor_phone); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Total Amount:</span>
                    <span class="fw-bold text-primary">£<?php echo number_format((float)$plan->total_amount, 2); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Monthly Payment:</span>
                    <span>£<?php echo number_format((float)$plan->monthly_amount, 2); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Duration:</span>
                    <span><?php echo $plan->total_months; ?> months</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="badge bg-<?php echo $plan->status == 'active' ? 'success' : 'secondary'; ?>">
                        <?php echo ucfirst($plan->status); ?>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Payments Made:</span>
                    <span><?php echo $plan->payments_made; ?> / <?php echo $plan->total_months; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Amount Paid:</span>
                    <span>£<?php echo number_format((float)$plan->amount_paid, 2); ?></span>
                </div>
            </div>
            
            <?php if ($is_active_plan): ?>
            <div class="warning-box">
                <strong><i class="fas fa-info-circle me-2"></i>Active Plan:</strong>
                This is the donor's currently active payment plan. Deleting it will reset their payment status.
            </div>
            <?php endif; ?>
            
            <?php if ($linked_sessions > 0): ?>
            <div class="warning-box">
                <strong><i class="fas fa-link me-2"></i>Linked Records:</strong>
                This plan is linked to <?php echo $linked_sessions; ?> call center session(s). These will be unlinked but not deleted.
            </div>
            <?php endif; ?>
            
            <div class="info-box mt-3">
                <h6 class="mb-2"><strong>What will happen:</strong></h6>
                <ul class="mb-0">
                    <li>The payment plan will be permanently deleted</li>
                    <?php if ($linked_sessions > 0): ?>
                    <li><?php echo $linked_sessions; ?> call session(s) will be unlinked from this plan</li>
                    <?php endif; ?>
                    <?php if ($is_active_plan): ?>
                    <li>Donor's active payment plan will be cleared</li>
                    <li>Payment status will be reset based on current balance</li>
                    <?php endif; ?>
                    <li>All payment history and pledge records will remain intact</li>
                </ul>
            </div>
            
            <form method="POST" action="?id=<?php echo $plan_id; ?>&donor_id=<?php echo $donor_id; ?>&confirm=yes">
                <input type="hidden" name="confirm" value="yes">
                <input type="hidden" name="id" value="<?php echo $plan_id; ?>">
                <input type="hidden" name="donor_id" value="<?php echo $donor_id; ?>">
                <div class="action-buttons">
                    <a href="view-donor.php?id=<?php echo $donor_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash-alt me-2"></i>Yes, Delete Payment Plan
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

