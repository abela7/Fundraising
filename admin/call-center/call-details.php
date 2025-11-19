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

$page_title = 'Call Details';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Call Center</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/call-center.css">
    <style>
        /* Call Details Page Enhancements */
        :root {
            --detail-primary: #1e3a5f;
            --detail-danger: #dc3545;
            --detail-success: #28a745;
            --detail-warning: #ffc107;
            --detail-light-bg: #f8f9fa;
            --detail-border: #e9ecef;
        }
        
        .detail-header {
            background: linear-gradient(135deg, var(--detail-primary) 0%, #2c5282 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .detail-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
            color: white;
        }
        
        .detail-header .subtitle {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-top: 0.25rem;
        }
        
        .detail-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--detail-border);
            overflow: hidden;
            height: 100%;
        }
        
        .detail-card-header {
            background: var(--detail-light-bg);
            padding: 1rem 1.25rem;
            border-bottom: 2px solid var(--detail-primary);
        }
        
        .detail-card-header h5 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            color: var(--detail-primary);
        }
        
        .detail-card-body {
            padding: 1.25rem;
        }
        
        .info-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }
        
        .info-value {
            font-size: 1rem;
            color: #212529;
            font-weight: 500;
        }
        
        .info-value-large {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--detail-primary);
        }
        
        .outcome-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .outcome-badge.outcome-payment-plan-created,
        .outcome-badge.outcome-picked-up {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .outcome-badge.outcome-callback-requested,
        .outcome-badge.outcome-not-answered {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .outcome-badge.outcome-invalid-number {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .notes-box {
            background: var(--detail-light-bg);
            border: 1px solid var(--detail-border);
            border-radius: 6px;
            padding: 1rem;
            min-height: 100px;
            color: #495057;
            font-size: 0.95rem;
            line-height: 1.6;
        }
        
        .donor-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--detail-primary) 0%, #2c5282 100%);
            color: white;
            font-size: 2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            margin: 0 auto 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .donor-name {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--detail-primary);
            margin-bottom: 0.25rem;
        }
        
        .donor-city {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .contact-item {
            padding: 0.75rem;
            background: var(--detail-light-bg);
            border-radius: 6px;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
        }
        
        .contact-item i {
            color: var(--detail-primary);
            font-size: 1.1rem;
            width: 24px;
        }
        
        .contact-item a {
            color: #212529;
            text-decoration: none;
            font-weight: 500;
        }
        
        .contact-item a:hover {
            color: var(--detail-primary);
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .btn-call-primary {
            background: var(--detail-primary);
            border-color: var(--detail-primary);
            color: white;
        }
        
        .btn-call-primary:hover {
            background: #2c5282;
            border-color: #2c5282;
            color: white;
        }
        
        .btn-call-danger {
            background: var(--detail-danger);
            border-color: var(--detail-danger);
            color: white;
        }
        
        .btn-call-danger:hover {
            background: #c82333;
            border-color: #c82333;
            color: white;
        }
        
        .plan-highlight {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            border-left: 4px solid var(--detail-success);
            padding: 1.25rem;
            border-radius: 6px;
        }
        
        .plan-amount {
            font-size: 2rem;
            font-weight: 700;
            color: var(--detail-success);
        }
        
        .callback-highlight {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border-left: 4px solid var(--detail-warning);
            padding: 1.25rem;
            border-radius: 6px;
        }
        
        .timeline-item {
            border-left: 2px solid var(--detail-border);
            padding-left: 1rem;
            padding-bottom: 1rem;
            position: relative;
        }
        
        .timeline-item:last-child {
            border-left: 0;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -6px;
            top: 0;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--detail-primary);
            border: 2px solid white;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .detail-header {
                padding: 1rem;
            }
            
            .detail-header h1 {
                font-size: 1.25rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-buttons .btn {
                width: 100%;
            }
            
            .detail-card-body {
                padding: 1rem;
            }
            
            .donor-avatar {
                width: 64px;
                height: 64px;
                font-size: 1.5rem;
            }
            
            .donor-name {
                font-size: 1.1rem;
            }
            
            .plan-amount {
                font-size: 1.5rem;
            }
            
            .info-value-large {
                font-size: 1.25rem;
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
            <div class="container-fluid">
                <!-- Header -->
                <div class="detail-header">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <h1>
                                <i class="fas fa-phone-alt me-2"></i>
                                Call Record #<?php echo $call->id; ?>
                            </h1>
                            <div class="subtitle">
                                <i class="far fa-clock me-1"></i>
                                <?php echo date('l, F j, Y \a\t g:i A', strtotime($call->call_started_at)); ?>
                            </div>
                        </div>
                        <div class="action-buttons">
                            <a href="call-history.php" class="btn btn-light btn-sm">
                                <i class="fas fa-arrow-left me-1"></i>Back
                            </a>
                            <a href="edit-call-record.php?id=<?php echo $call->id; ?>" class="btn btn-light btn-sm">
                                <i class="fas fa-edit me-1"></i>Edit
                            </a>
                            <?php if ($is_admin || $call->agent_id == $user_id): ?>
                            <button type="button" class="btn btn-light btn-sm" onclick="confirmDelete(<?php echo $call->id; ?>)">
                                <i class="fas fa-trash-alt me-1"></i>Delete
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="row g-3 g-md-4">
                <!-- Main Info -->
                <div class="col-lg-8 col-md-12">
                    <div class="detail-card mb-3 mb-md-4">
                        <div class="detail-card-header">
                            <h5><i class="fas fa-info-circle me-2"></i>Call Information</h5>
                        </div>
                        <div class="detail-card-body">
                            <div class="row g-3 g-md-4">
                                <div class="col-md-6 col-12">
                                    <div class="info-label">Outcome</div>
                                    <div>
                                        <span class="outcome-badge outcome-<?php echo str_replace('_', '-', $call->outcome); ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $call->outcome)); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="info-label">Duration</div>
                                    <div class="info-value-large"><?php echo $formatted_duration; ?></div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="info-label">Agent</div>
                                    <div class="info-value"><?php echo htmlspecialchars($call->agent_name ?? 'Unknown'); ?></div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="info-label">Call Stage</div>
                                    <div class="info-value"><?php echo ucwords(str_replace('_', ' ', $call->conversation_stage)); ?></div>
                                </div>
                                
                                <div class="col-12 mt-3 mt-md-4">
                                    <div class="info-label">Call Notes</div>
                                    <div class="notes-box">
                                        <?php echo nl2br(htmlspecialchars($call->notes ?? 'No notes recorded.')); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Plan Section -->
                    <?php if ($call->payment_plan_id): ?>
                    <div class="detail-card mb-3 mb-md-4">
                        <div class="detail-card-body p-0">
                            <div class="plan-highlight">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                    <div>
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="fas fa-check-circle text-success me-2" style="font-size: 1.5rem;"></i>
                                            <h5 class="mb-0 fw-bold" style="color: #155724;">Payment Plan Created</h5>
                                        </div>
                                        <div class="plan-amount">£<?php echo number_format((float)$call->plan_amount, 2); ?></div>
                                        <div class="text-muted fw-500 mb-2">Total Pledged Amount</div>
                                        <span class="badge bg-success">
                                            <?php echo ucfirst($call->plan_status); ?>
                                        </span>
                                    </div>
                                    <div class="d-flex flex-column gap-2">
                                        <a href="../donor-management/payment-plans.php?id=<?php echo $call->payment_plan_id; ?>" class="btn btn-success btn-sm">
                                            <i class="fas fa-eye me-1"></i>View Plan
                                        </a>
                                        <a href="edit-payment-plan-flow.php?plan_id=<?php echo $call->payment_plan_id; ?>&session_id=<?php echo $call->id; ?>" class="btn btn-outline-success btn-sm">
                                            <i class="fas fa-redo me-1"></i>Redo Plan
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Callback Section -->
                    <?php if ($call->callback_scheduled_for): ?>
                    <div class="detail-card mb-3 mb-md-4">
                        <div class="detail-card-body p-0">
                            <div class="callback-highlight">
                                <div class="d-flex align-items-center mb-3">
                                    <i class="fas fa-calendar-clock text-warning me-2" style="font-size: 1.5rem;"></i>
                                    <h5 class="mb-0 fw-bold" style="color: #856404;">Callback Scheduled</h5>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6 col-12">
                                        <div class="info-label">Scheduled For</div>
                                        <div class="info-value-large" style="color: #856404;"><?php echo date('M j, Y', strtotime($call->callback_scheduled_for)); ?></div>
                                        <div class="text-muted"><?php echo date('g:i A', strtotime($call->callback_scheduled_for)); ?></div>
                                    </div>
                                    <div class="col-md-6 col-12">
                                        <div class="info-label">Reason</div>
                                        <div class="info-value"><?php echo htmlspecialchars($call->callback_reason ?? 'Not specified'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar Info -->
                <div class="col-lg-4 col-md-12">
                    <div class="detail-card mb-3 mb-md-4">
                        <div class="detail-card-header">
                            <h5><i class="fas fa-user me-2"></i>Donor Profile</h5>
                        </div>
                        <div class="detail-card-body">
                            <div class="text-center mb-4">
                                <div class="donor-avatar">
                                    <?php echo strtoupper(substr($call->donor_name, 0, 1)); ?>
                                </div>
                                <div class="donor-name"><?php echo htmlspecialchars($call->donor_name); ?></div>
                                <div class="donor-city">
                                    <i class="fas fa-map-marker-alt me-1"></i>
                                    <?php echo htmlspecialchars($call->donor_city ?? 'Location not set'); ?>
                                </div>
                            </div>
                            
                            <div class="contact-item">
                                <i class="fas fa-phone me-2"></i>
                                <a href="tel:<?php echo htmlspecialchars($call->donor_phone); ?>"><?php echo htmlspecialchars($call->donor_phone); ?></a>
                            </div>
                            
                            <?php if ($call->donor_email): ?>
                            <div class="contact-item">
                                <i class="fas fa-envelope me-2"></i>
                                <a href="mailto:<?php echo htmlspecialchars($call->donor_email); ?>"><?php echo htmlspecialchars($call->donor_email); ?></a>
                            </div>
                            <?php endif; ?>
                            
                            <div class="d-grid gap-2 mt-4">
                                <a href="make-call.php?donor_id=<?php echo $call->donor_id; ?>" class="btn btn-call-primary">
                                    <i class="fas fa-phone me-2"></i>Call Again
                                </a>
                                <a href="../donor-management/view-donor.php?id=<?php echo $call->donor_id; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-user me-2"></i>View Profile
                                </a>
                            </div>
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

