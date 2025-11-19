<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone
date_default_timezone_set('Europe/London');

$conn = get_db_connection();
$plan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';

if ($plan_id <= 0 || $donor_id <= 0) {
    header('Location: view-donor.php?id=' . $donor_id);
    exit;
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
    $stmt->bind_param('ii', $plan_id, $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $plan = $result->fetch_object();
    
    if (!$plan) {
        header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Payment plan not found'));
        exit;
    }
    
    // Check for linked call sessions
    $session_query = "SELECT COUNT(*) as count FROM call_center_sessions WHERE payment_plan_id = ?";
    $session_stmt = $conn->prepare($session_query);
    $session_stmt->bind_param('i', $plan_id);
    $session_stmt->execute();
    $session_result = $session_stmt->get_result();
    $linked_sessions = $session_result->fetch_object()->count;
    
    // Check if this is the active plan
    $is_active_plan = ($plan->active_payment_plan_id == $plan_id);
    
} catch (Exception $e) {
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Database error: ' . $e->getMessage()));
    exit;
}

// Handle deletion confirmation
if ($confirm === 'yes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->begin_transaction();
        
        // Step 1: Unlink from call_center_sessions
        $unlink_query = "UPDATE call_center_sessions SET payment_plan_id = NULL WHERE payment_plan_id = ?";
        $unlink_stmt = $conn->prepare($unlink_query);
        $unlink_stmt->bind_param('i', $plan_id);
        $unlink_stmt->execute();
        
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
        
        // Step 3: Delete the payment plan
        $delete_query = "DELETE FROM donor_payment_plans WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param('i', $plan_id);
        $delete_stmt->execute();
        
        $conn->commit();
        
        header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode('Payment plan deleted successfully'));
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
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
            
            <form method="POST">
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

