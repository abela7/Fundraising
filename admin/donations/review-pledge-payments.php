<?php
// admin/donations/review-pledge-payments.php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

// Allow both admin and registrar access
require_login();
$current_user = current_user();
if (!in_array($current_user['role'] ?? '', ['admin', 'registrar'])) {
    header('Location: ' . url_for('index.php'));
    exit;
}

$page_title = 'Review Pledge Payments';

$db = db();

// Check if table exists
$check = $db->query("SHOW TABLES LIKE 'pledge_payments'");
if ($check->num_rows === 0) {
    die("<div class='alert alert-danger m-4'>Error: Table 'pledge_payments' not found.</div>");
}

// Get filter
$filter = $_GET['filter'] ?? 'pending';

// Build query based on filter
$where_clause = "";
if ($filter === 'pending') {
    $where_clause = "pp.status = 'pending'";
} elseif ($filter === 'confirmed') {
    $where_clause = "pp.status = 'confirmed'";
} elseif ($filter === 'voided') {
    $where_clause = "pp.status = 'voided'";
} else {
    $where_clause = "1=1"; // all
}

// Check if payment_plan_id column exists
$has_plan_col = $db->query("SHOW COLUMNS FROM pledge_payments LIKE 'payment_plan_id'")->num_rows > 0;

// Fetch payments
$sql = "
    SELECT 
        pp.*,
        d.name AS donor_name,
        d.phone AS donor_phone,
        d.active_payment_plan_id AS donor_active_plan_id,
        pl.amount AS pledge_amount,
        pl.created_at AS pledge_date,
        u.name AS processed_by_name,
        approver.name AS approved_by_name,
        voider.name AS voided_by_name" . 
        ($has_plan_col ? ",
        pplan.id AS plan_id,
        pplan.monthly_amount AS plan_monthly_amount,
        pplan.payments_made AS plan_payments_made,
        pplan.total_payments AS plan_total_payments" : "") . "
    FROM pledge_payments pp
    LEFT JOIN donors d ON pp.donor_id = d.id
    LEFT JOIN pledges pl ON pp.pledge_id = pl.id
    LEFT JOIN users u ON pp.processed_by_user_id = u.id
    LEFT JOIN users approver ON pp.approved_by_user_id = approver.id
    LEFT JOIN users voider ON pp.voided_by_user_id = voider.id" . 
    ($has_plan_col ? "
    LEFT JOIN donor_payment_plans pplan ON pp.payment_plan_id = pplan.id" : "") . "
    WHERE $where_clause
    ORDER BY pp.created_at DESC
";

$payments = [];
$res = $db->query($sql);
while ($row = $res->fetch_assoc()) {
    $payments[] = $row;
}

// Count statistics
$stats = [
    'pending' => $db->query("SELECT COUNT(*) as c FROM pledge_payments WHERE status='pending'")->fetch_assoc()['c'],
    'confirmed' => $db->query("SELECT COUNT(*) as c FROM pledge_payments WHERE status='confirmed'")->fetch_assoc()['c'],
    'voided' => $db->query("SELECT COUNT(*) as c FROM pledge_payments WHERE status='voided'")->fetch_assoc()['c']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .proof-thumbnail {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 4px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .proof-thumbnail:hover {
            transform: scale(1.05);
        }
        .payment-card {
            transition: all 0.2s;
        }
        .payment-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .status-badge {
            font-size: 0.85rem;
            padding: 0.35rem 0.75rem;
        }
        /* Fix nav-pills visibility */
        .nav-pills .nav-link {
            color: #495057;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .nav-pills .nav-link:hover {
            background: #e9ecef;
            color: #212529;
        }
        .nav-pills .nav-link.active {
            background: #0d6efd;
            color: #fff;
            border-color: #0d6efd;
        }
        .stat-card {
            border-left: 4px solid;
        }
        .stat-card.pending {
            border-left-color: #ffc107;
        }
        .stat-card.approved {
            border-left-color: #198754;
        }
        .stat-card.rejected {
            border-left-color: #dc3545;
        }
        
        /* Enhanced button styles */
        .btn-undo {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border: none;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);
            transition: all 0.3s ease;
        }
        .btn-undo:hover {
            background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
        }
        
        /* Approved payment card enhancement */
        .approved-card {
            border-left: 4px solid #198754;
        }
        .pending-card {
            border-left: 4px solid #ffc107;
        }
        .rejected-card {
            border-left: 4px solid #dc3545;
        }
        
        /* Icon visibility fix */
        .fa, .fas, .far, .fal, .fab {
            display: inline-block;
            font-style: normal;
            font-variant: normal;
            text-rendering: auto;
            -webkit-font-smoothing: antialiased;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .stat-card h3 {
                font-size: 1.5rem;
            }
            .nav-pills {
                flex-direction: column;
            }
            .nav-pills .nav-link {
                width: 100%;
                margin-right: 0;
                text-align: left;
            }
            .payment-card .col-auto {
                margin-bottom: 1rem;
            }
            .btn-undo {
                width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .proof-thumbnail {
                width: 50px;
                height: 50px;
            }
            .payment-card .card-body {
                padding: 1rem !important;
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
            <div class="container-fluid p-3 p-md-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 mb-md-4">
                    <h1 class="h4 mb-2 mb-md-0">
                        <i class="fas fa-check-double text-primary me-2"></i>Review Pledge Payments
                    </h1>
                    <a href="record-pledge-payment.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Record New Payment
                    </a>
                </div>

                <!-- Statistics Cards -->
                <div class="row g-3 mb-3 mb-md-4">
                    <div class="col-6 col-md-4">
                        <div class="card shadow-sm stat-card pending">
                            <div class="card-body">
                                <h6 class="text-muted small mb-1">
                                    <i class="fas fa-hourglass-half me-1 text-warning"></i>Pending Review
                                </h6>
                                <h3 class="mb-0 text-warning fw-bold"><?php echo $stats['pending']; ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="card shadow-sm stat-card approved">
                            <div class="card-body">
                                <h6 class="text-muted small mb-1">
                                    <i class="fas fa-check-double me-1 text-success"></i>Approved
                                </h6>
                                <h3 class="mb-0 text-success fw-bold"><?php echo $stats['confirmed']; ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="card shadow-sm stat-card rejected">
                            <div class="card-body">
                                <h6 class="text-muted small mb-1">
                                    <i class="fas fa-ban me-1 text-danger"></i>Rejected
                                </h6>
                                <h3 class="mb-0 text-danger fw-bold"><?php echo $stats['voided']; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Tabs -->
                <div class="mb-3 mb-md-4">
                    <div class="nav nav-pills flex-wrap" role="tablist">
                        <a class="nav-link <?php echo $filter === 'pending' ? 'active' : ''; ?>" 
                           href="?filter=pending"
                           style="color: <?php echo $filter === 'pending' ? '#fff' : '#495057'; ?>;">
                            <i class="fas fa-clock me-1"></i> 
                            <span class="fw-bold">Pending</span>
                            <span class="badge bg-<?php echo $filter === 'pending' ? 'light text-dark' : 'warning'; ?> ms-1">
                                <?php echo $stats['pending']; ?>
                            </span>
                        </a>
                        <a class="nav-link <?php echo $filter === 'confirmed' ? 'active' : ''; ?>" 
                           href="?filter=confirmed"
                           style="color: <?php echo $filter === 'confirmed' ? '#fff' : '#495057'; ?>;">
                            <i class="fas fa-check me-1"></i> 
                            <span class="fw-bold">Approved</span>
                            <span class="badge bg-<?php echo $filter === 'confirmed' ? 'light text-dark' : 'success'; ?> ms-1">
                                <?php echo $stats['confirmed']; ?>
                            </span>
                        </a>
                        <a class="nav-link <?php echo $filter === 'voided' ? 'active' : ''; ?>" 
                           href="?filter=voided"
                           style="color: <?php echo $filter === 'voided' ? '#fff' : '#495057'; ?>;">
                            <i class="fas fa-ban me-1"></i> 
                            <span class="fw-bold">Rejected</span>
                            <span class="badge bg-<?php echo $filter === 'voided' ? 'light text-dark' : 'danger'; ?> ms-1">
                                <?php echo $stats['voided']; ?>
                            </span>
                        </a>
                        <a class="nav-link <?php echo $filter === 'all' ? 'active' : ''; ?>" 
                           href="?filter=all"
                           style="color: <?php echo $filter === 'all' ? '#fff' : '#495057'; ?>;">
                            <i class="fas fa-list me-1"></i> 
                            <span class="fw-bold">All</span>
                        </a>
                    </div>
                </div>

                <!-- Payments List -->
                <?php if (empty($payments)): ?>
                    <div class="card shadow-sm border-0">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3 opacity-25"></i>
                            <h5 class="text-muted mb-2">No payments found</h5>
                            <p class="text-muted mb-0 small">
                                There are no <?php echo $filter === 'all' ? '' : $filter; ?> payments to display.
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($payments as $p): ?>
                            <div class="col-12">
                                <div class="card payment-card shadow-sm <?php 
                                    if ($p['status'] === 'confirmed') echo 'approved-card';
                                    elseif ($p['status'] === 'pending') echo 'pending-card';
                                    elseif ($p['status'] === 'voided') echo 'rejected-card';
                                ?>">
                                    <div class="card-body p-3 p-md-4">
                                        <div class="row g-3 align-items-start">
                                            <!-- Payment Proof Thumbnail -->
                                            <div class="col-auto">
                                                <?php if ($p['payment_proof']): ?>
                                                    <?php 
                                                    $ext = strtolower(pathinfo($p['payment_proof'], PATHINFO_EXTENSION));
                                                    $is_pdf = ($ext === 'pdf');
                                                    ?>
                                                    <?php if ($is_pdf): ?>
                                                        <a href="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" target="_blank" class="btn btn-outline-danger btn-sm d-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                                                            <i class="fas fa-file-pdf fa-lg"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <img src="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" 
                                                             alt="Payment Proof" 
                                                             class="proof-thumbnail border"
                                                             onclick="viewProof('../../<?php echo htmlspecialchars($p['payment_proof']); ?>')">
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <div class="proof-thumbnail border d-flex align-items-center justify-content-center bg-light">
                                                        <i class="fas fa-file-image text-muted"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Payment Details -->
                                            <div class="col-12 col-md">
                                                <div class="row g-2">
                                                    <div class="col-12">
                                                        <h5 class="mb-1 fw-bold">
                                                            <i class="fas fa-user me-2 text-primary"></i>
                                                            <?php echo htmlspecialchars($p['donor_name'] ?? 'Unknown'); ?>
                                                        </h5>
                                                        <p class="mb-2 text-muted">
                                                            <i class="fas fa-phone me-1"></i>
                                                            <?php echo htmlspecialchars($p['donor_phone'] ?? '-'); ?>
                                                        </p>
                                                    </div>
                                                    <div class="col-12">
                                                        <div class="d-flex flex-wrap gap-3 mb-2">
                                                            <div>
                                                                <small class="text-muted d-block">Amount</small>
                                                                <span class="text-success fw-bold fs-5">
                                                                    <i class="fas fa-pound-sign me-1"></i>
                                                                    <?php echo number_format((float)$p['amount'], 2); ?>
                                                                </span>
                                                            </div>
                                                            <div>
                                                                <small class="text-muted d-block">Method</small>
                                                                <span class="fw-semibold">
                                                                    <?php 
                                                                    $method_icons = [
                                                                        'cash' => 'money-bill-wave',
                                                                        'bank_transfer' => 'university',
                                                                        'card' => 'credit-card',
                                                                        'cheque' => 'file-invoice-dollar',
                                                                        'other' => 'hand-holding-usd'
                                                                    ];
                                                                    $icon = $method_icons[$p['payment_method']] ?? 'money-bill';
                                                                    ?>
                                                                    <i class="fas fa-<?php echo $icon; ?> me-1 text-info"></i>
                                                                    <?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?>
                                                                </span>
                                                            </div>
                                                            <div>
                                                                <small class="text-muted d-block">Date</small>
                                                                <span class="fw-semibold">
                                                                    <i class="far fa-calendar-alt me-1 text-secondary"></i>
                                                                    <?php echo date('d M Y', strtotime($p['payment_date'])); ?>
                                                                </span>
                                                            </div>
                                                            <?php if ($p['reference_number']): ?>
                                                            <div>
                                                                <small class="text-muted d-block">Reference</small>
                                                                <span class="fw-semibold font-monospace">
                                                                    <i class="fas fa-hashtag me-1 text-muted"></i>
                                                                    <?php echo htmlspecialchars($p['reference_number']); ?>
                                                                </span>
                                                            </div>
                                                            <?php endif; ?>
                                                            <?php 
                                                            // Show payment plan info if linked or if donor has active plan
                                                            $show_plan_info = false;
                                                            $plan_installment = null;
                                                            if ($has_plan_col && isset($p['plan_id']) && $p['plan_id']) {
                                                                $show_plan_info = true;
                                                                $plan_installment = ($p['plan_payments_made'] ?? 0) + 1;
                                                            } elseif (isset($p['donor_active_plan_id']) && $p['donor_active_plan_id']) {
                                                                // Check if amount matches monthly amount (potential plan payment)
                                                                $plan_check = $db->prepare("SELECT monthly_amount FROM donor_payment_plans WHERE id = ? LIMIT 1");
                                                                $plan_check->bind_param('i', $p['donor_active_plan_id']);
                                                                $plan_check->execute();
                                                                $plan_data = $plan_check->get_result()->fetch_assoc();
                                                                $plan_check->close();
                                                                
                                                                if ($plan_data && abs((float)$p['amount'] - (float)$plan_data['monthly_amount']) < 0.01) {
                                                                    $show_plan_info = true;
                                                                    $plan_installment = '?'; // Will be calculated on approval
                                                                }
                                                            }
                                                            ?>
                                                            <?php if ($show_plan_info): ?>
                                                            <div>
                                                                <small class="text-muted d-block">Payment Plan</small>
                                                                <span class="badge bg-info">
                                                                    <i class="fas fa-calendar-check me-1"></i>
                                                                    <?php if ($plan_installment !== '?'): ?>
                                                                        Installment <?php echo $plan_installment; ?> of <?php echo $p['plan_total_payments'] ?? '?'; ?>
                                                                    <?php else: ?>
                                                                        Plan Payment (Auto-link on approval)
                                                                    <?php endif; ?>
                                                                </span>
                                                            </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <?php if ($p['notes']): ?>
                                                        <div class="col-12">
                                                            <div class="alert alert-info py-2 px-3 mb-0">
                                                                <small>
                                                                    <i class="fas fa-sticky-note me-1"></i>
                                                                    <strong>Notes:</strong> <?php echo htmlspecialchars($p['notes']); ?>
                                                                </small>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="mt-3 pt-3 border-top">
                                                    <div class="d-flex flex-column gap-1">
                                                        <small class="text-muted">
                                                            <i class="fas fa-user-plus me-1"></i>
                                                            Submitted by <strong class="text-dark"><?php echo htmlspecialchars($p['processed_by_name'] ?? 'Unknown'); ?></strong> 
                                                            <span class="text-muted">on <?php echo date('d M Y', strtotime($p['created_at'])); ?> at <?php echo date('H:i', strtotime($p['created_at'])); ?></span>
                                                        </small>
                                                        <?php if ($p['status'] === 'confirmed' && $p['approved_by_name']): ?>
                                                            <small class="text-success">
                                                                <i class="fas fa-check-circle me-1"></i>
                                                                Approved by <strong><?php echo htmlspecialchars($p['approved_by_name']); ?></strong>
                                                                <?php if ($p['approved_at']): ?>
                                                                    <span class="text-muted">on <?php echo date('d M Y', strtotime($p['approved_at'])); ?> at <?php echo date('H:i', strtotime($p['approved_at'])); ?></span>
                                                                <?php endif; ?>
                                                            </small>
                                                        <?php endif; ?>
                                                        <?php if ($p['status'] === 'voided' && $p['voided_by_name']): ?>
                                                            <small class="text-danger">
                                                                <i class="fas fa-times-circle me-1"></i>
                                                                Rejected by <strong><?php echo htmlspecialchars($p['voided_by_name']); ?></strong>
                                                                <?php if ($p['voided_at']): ?>
                                                                    <span class="text-muted">on <?php echo date('d M Y', strtotime($p['voided_at'])); ?> at <?php echo date('H:i', strtotime($p['voided_at'])); ?></span>
                                                                <?php endif; ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Status & Actions -->
                                            <div class="col-12 col-md-auto ms-md-auto">
                                                <div class="d-flex flex-column align-items-start align-items-md-end">
                                                    <?php if ($p['status'] === 'pending'): ?>
                                                        <span class="badge bg-warning text-dark status-badge mb-2">
                                                            <i class="fas fa-clock me-1"></i>PENDING REVIEW
                                                        </span>
                                                        <div class="d-flex flex-column flex-md-row gap-2 w-100">
                                                            <button class="btn btn-success btn-sm" onclick="approvePayment(<?php echo $p['id']; ?>)">
                                                                <i class="fas fa-check me-1"></i>Approve
                                                            </button>
                                                            <button class="btn btn-danger btn-sm" onclick="voidPayment(<?php echo $p['id']; ?>)">
                                                                <i class="fas fa-times me-1"></i>Reject
                                                            </button>
                                                        </div>
                                                    <?php elseif ($p['status'] === 'confirmed'): ?>
                                                        <span class="badge bg-success status-badge mb-2">
                                                            <i class="fas fa-check-circle me-1"></i>APPROVED
                                                        </span>
                                                        <div class="d-flex flex-column gap-2">
                                                            <button class="btn btn-undo btn-sm" onclick="undoPayment(<?php echo $p['id']; ?>)">
                                                                <i class="fas fa-undo me-1"></i>Undo Payment
                                                            </button>
                                                            <a href="../donor-management/view-donor.php?id=<?php echo $p['donor_id']; ?>" 
                                                               class="btn btn-outline-primary btn-sm">
                                                                <i class="fas fa-user me-1"></i>View Donor
                                                            </a>
                                                        </div>
                                                    <?php elseif ($p['status'] === 'voided'): ?>
                                                        <span class="badge bg-danger status-badge mb-2">
                                                            <i class="fas fa-ban me-1"></i>REJECTED
                                                        </span>
                                                        <?php if ($p['void_reason']): ?>
                                                            <div class="alert alert-danger py-2 px-3 mb-0 small" role="alert" style="max-width: 250px;">
                                                                <i class="fas fa-info-circle me-1"></i>
                                                                <strong>Reason:</strong><br>
                                                                <?php echo htmlspecialchars($p['void_reason']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Image Viewer Modal -->
<div class="modal fade" id="proofModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Payment Proof</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="proofImage" src="" alt="Payment Proof" class="img-fluid">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
function viewProof(src) {
    document.getElementById('proofImage').src = src;
    new bootstrap.Modal(document.getElementById('proofModal')).show();
}

function approvePayment(id) {
    if (!confirm('Approve this payment?\n\nThis will:\n• Update donor balance\n• Mark payment as confirmed\n• Update financial totals')) return;
    
    fetch('approve-pledge-payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id})
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            alert('Payment approved successfully!');
            location.reload();
        } else {
            alert('Error: ' + res.message);
        }
    });
}

function voidPayment(id) {
    const reason = prompt('Why are you rejecting this payment?\n\n(This will be logged in the audit trail)');
    if (!reason) return;
    
    fetch('void-pledge-payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id, reason: reason})
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            alert('Payment rejected.');
            location.reload();
        } else {
            alert('Error: ' + res.message);
        }
    });
}

function undoPayment(id) {
    if (!confirm('Undo this approved payment?\n\nWARNING: This will:\n• Reverse donor balance updates\n• Mark payment as voided\n• Update financial totals\n\nOnly do this if the approval was a mistake!')) return;
    
    const reason = prompt('Why are you undoing this payment?\n\n(Required for audit trail)');
    if (!reason) return;
    
    fetch('undo-pledge-payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id, reason: reason})
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            alert('Payment undone successfully.');
            location.reload();
        } else {
            alert('Error: ' + res.message);
        }
    });
}
</script>
</body>
</html>

