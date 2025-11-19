<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone
date_default_timezone_set('Europe/London');

$db = db();
$user_id = (int)$_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'] ?? 'registrar';
$is_admin = ($user_role === 'admin');

// Get session ID
$session_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$session_id) {
    header('Location: call-history.php');
    exit;
}

// Fetch Call Session Details
$query = "
    SELECT 
        s.*,
        d.name as donor_name,
        d.phone as donor_phone,
        d.email as donor_email,
        d.city as donor_city,
        u.name as agent_name,
        pp.total_amount as plan_amount,
        pp.status as plan_status
    FROM call_center_sessions s
    LEFT JOIN donors d ON s.donor_id = d.id
    LEFT JOIN users u ON s.agent_id = u.id
    LEFT JOIN donor_payment_plans pp ON s.payment_plan_id = pp.id
    WHERE s.id = ?
";

$stmt = $db->prepare($query);
$stmt->bind_param('i', $session_id);
$stmt->execute();
$result = $stmt->get_result();
$call = $result->fetch_object();

if (!$call) {
    header('Location: call-history.php');
    exit;
}

// Format Duration
$duration_sec = (int)($call->duration_seconds ?? 0);
if ($duration_sec > 60) {
    $formatted_duration = floor($duration_sec / 60) . 'm ' . ($duration_sec % 60) . 's';
} elseif ($duration_sec > 0) {
    $formatted_duration = $duration_sec . 's';
} else {
    $formatted_duration = '-';
}

// Format Conversation Stage
$conversation_stage = $call->conversation_stage ?? null;
if (empty($conversation_stage)) {
    // Try to infer from outcome if stage is missing
    $outcome = $call->outcome ?? '';
    if (strpos($outcome, 'payment_plan') !== false || strpos($outcome, 'agreement') !== false) {
        $formatted_stage = 'Completed';
    } elseif (strpos($outcome, 'callback') !== false || strpos($outcome, 'busy') !== false || strpos($outcome, 'not_answered') !== false) {
        $formatted_stage = 'No Connection';
    } elseif (strpos($outcome, 'picked_up') !== false || strpos($outcome, 'connected') !== false) {
        $formatted_stage = 'Connected';
    } else {
        $formatted_stage = 'Not Set';
    }
} else {
    $formatted_stage = ucwords(str_replace('_', ' ', $conversation_stage));
}

$page_title = 'Call Details';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Call Center</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/call-center.css">
    <style>
        /* Call Details - Clean & Compact Design */
        :root {
            --primary-blue: #0a6286;
            --primary-red: #dc3545;
            --light-gray: #f8f9fa;
            --border-color: #e0e0e0;
        }
        
        .call-details-header {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #075985 100%);
            color: white;
            padding: 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(10, 98, 134, 0.15);
        }
        
        .call-details-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .call-details-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        
        .action-buttons .btn {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            border-radius: 6px;
        }
        
        .info-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .info-card-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary-blue);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
        }
        
        .info-item-inline {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .info-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #6c757d;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            font-size: 1rem;
            font-weight: 500;
            color: #212529;
        }
        
        .outcome-pill {
            display: inline-block;
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .outcome-payment-plan-created { background: #d1f4e0; color: #0d7f4d; }
        .outcome-callback-requested { background: #fff3cd; color: #856404; }
        .outcome-not-answered { background: #f8d7da; color: #721c24; }
        .outcome-busy { background: #ffeaa7; color: #d63031; }
        .outcome-invalid-number { background: #e0e0e0; color: #495057; }
        
        .notes-box {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            font-size: 0.9rem;
            color: #495057;
            margin-top: 0.5rem;
        }
        
        .plan-highlight {
            background: linear-gradient(135deg, #d1f4e0 0%, #b8e6cc 100%);
            border: 2px solid #0d7f4d;
            border-radius: 8px;
            padding: 1.25rem;
            margin-bottom: 1rem;
        }
        
        .plan-amount {
            font-size: 1.75rem;
            font-weight: 700;
            color: #0d7f4d;
        }
        
        .callback-highlight {
            background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%);
            border: 2px solid #f59e0b;
            border-radius: 8px;
            padding: 1.25rem;
            margin-bottom: 1rem;
        }
        
        .donor-profile-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1.25rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .donor-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary-blue);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0 auto 0.75rem;
        }
        
        .donor-name {
            font-size: 1.125rem;
            font-weight: 600;
            color: #212529;
            margin-bottom: 0.25rem;
        }
        
        .donor-contact {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
            font-size: 0.9rem;
        }
        
        .btn-primary-custom {
            background: var(--primary-blue);
            border: none;
            color: white;
        }
        
        .btn-primary-custom:hover {
            background: #075985;
            color: white;
        }
        
        .btn-danger-custom {
            background: var(--primary-red);
            border: none;
            color: white;
        }
        
        .btn-danger-custom:hover {
            background: #bb2d3b;
            color: white;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .call-details-header h1 {
                font-size: 1.25rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-buttons .btn {
                width: 100%;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .plan-amount {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <!-- Header -->
            <div class="call-details-header">
                <h1><i class="fas fa-phone-volume me-2"></i>Call Record #<?php echo $call->id; ?></h1>
                <p><i class="far fa-clock me-2"></i><?php echo date('l, F j, Y • g:i A', strtotime($call->call_started_at)); ?></p>
                
                <div class="action-buttons">
                    <a href="call-history.php" class="btn btn-light btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>Back to History
                    </a>
                    <a href="edit-call-record.php?id=<?php echo $call->id; ?>" class="btn btn-light btn-sm">
                        <i class="fas fa-edit me-1"></i>Edit
                    </a>
                    <?php if ($is_admin || $call->agent_id == $user_id): ?>
                    <button type="button" class="btn btn-danger-custom btn-sm" onclick="confirmDelete(<?php echo $call->id; ?>)">
                        <i class="fas fa-trash-alt me-1"></i>Delete
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-3">
                <!-- Main Column -->
                <div class="col-lg-8">
                    <!-- Call Information -->
                    <div class="info-card">
                        <div class="info-card-title">
                            <i class="fas fa-info-circle"></i>Call Information
                        </div>
                        <div class="info-grid">
                            <div class="info-item-inline">
                                <span class="info-label">Outcome</span>
                                <span class="outcome-pill outcome-<?php echo str_replace('_', '-', $call->outcome); ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $call->outcome)); ?>
                                </span>
                            </div>
                            <div class="info-item-inline">
                                <span class="info-label">Duration</span>
                                <span class="info-value"><?php echo $formatted_duration; ?></span>
                            </div>
                            <div class="info-item-inline">
                                <span class="info-label">Agent</span>
                                <span class="info-value"><?php echo htmlspecialchars($call->agent_name ?? 'Unknown'); ?></span>
                            </div>
                            <div class="info-item-inline">
                                <span class="info-label">Stage</span>
                                <span class="info-value"><?php echo htmlspecialchars($formatted_stage); ?></span>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <span class="info-label">Notes</span>
                            <div class="notes-box">
                                <?php echo nl2br(htmlspecialchars($call->notes ?: 'No notes recorded for this call.')); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Plan -->
                    <?php if ($call->payment_plan_id): ?>
                    <div class="plan-highlight">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <div class="info-label mb-2">
                                    <i class="fas fa-file-invoice-dollar me-1"></i>Payment Plan Created
                                </div>
                                <div class="plan-amount">£<?php echo number_format((float)$call->plan_amount, 2); ?></div>
                                <div class="mt-2">
                                    <span class="badge bg-success">Status: <?php echo ucfirst($call->plan_status); ?></span>
                                </div>
                            </div>
                            <div class="d-flex flex-column gap-2">
                                <a href="../donor-management/payment-plans.php?id=<?php echo $call->payment_plan_id; ?>" class="btn btn-sm btn-success">
                                    <i class="fas fa-eye me-1"></i>View Plan
                                </a>
                                <a href="edit-payment-plan-flow.php?plan_id=<?php echo $call->payment_plan_id; ?>&session_id=<?php echo $call->id; ?>" class="btn btn-sm btn-outline-success">
                                    <i class="fas fa-redo me-1"></i>Redo Plan
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Callback -->
                    <?php if ($call->callback_scheduled_for): ?>
                    <div class="callback-highlight">
                        <div class="info-label mb-2">
                            <i class="fas fa-calendar-check me-1"></i>Callback Scheduled
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="info-label mb-1">Scheduled For</div>
                                <div class="fw-bold"><?php echo date('D, M j, Y • g:i A', strtotime($call->callback_scheduled_for)); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label mb-1">Reason</div>
                                <div><?php echo htmlspecialchars($call->callback_reason ?: 'Not specified'); ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Donor Profile Sidebar -->
                <div class="col-lg-4">
                    <div class="donor-profile-card">
                        <div class="donor-avatar">
                            <?php echo strtoupper(substr($call->donor_name, 0, 1)); ?>
                        </div>
                        <div class="donor-name"><?php echo htmlspecialchars($call->donor_name); ?></div>
                        <?php if ($call->donor_city): ?>
                        <div class="text-muted small"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($call->donor_city); ?></div>
                        <?php endif; ?>
                        
                        <div class="donor-contact">
                            <a href="tel:<?php echo htmlspecialchars($call->donor_phone); ?>" class="text-decoration-none">
                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($call->donor_phone); ?>
                            </a>
                        </div>
                        
                        <div class="d-grid gap-2 mt-3">
                            <a href="make-call.php?donor_id=<?php echo $call->donor_id; ?>" class="btn btn-success btn-sm">
                                <i class="fas fa-phone me-1"></i>Call Again
                            </a>
                            <a href="../donor-management/view-donor.php?id=<?php echo $call->donor_id; ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-user me-1"></i>View Full Profile
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
function confirmDelete(id) {
    if (confirm('Are you sure you want to delete this call record?\n\nWARNING: If a payment plan is attached, you will be asked how to handle it on the next screen.')) {
        window.location.href = 'delete-call-record.php?id=' + id;
    }
}
</script>
</body>
</html>

