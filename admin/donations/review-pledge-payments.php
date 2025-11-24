<?php
// admin/donations/review-pledge-payments.php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();
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

// Fetch payments
$sql = "
    SELECT 
        pp.*,
        d.name AS donor_name,
        d.phone AS donor_phone,
        pl.amount AS pledge_amount,
        pl.created_at AS pledge_date,
        u.name AS processed_by_name,
        approver.name AS approved_by_name,
        voider.name AS voided_by_name
    FROM pledge_payments pp
    LEFT JOIN donors d ON pp.donor_id = d.id
    LEFT JOIN pledges pl ON pp.pledge_id = pl.id
    LEFT JOIN users u ON pp.processed_by_user_id = u.id
    LEFT JOIN users approver ON pp.approved_by_user_id = approver.id
    LEFT JOIN users voider ON pp.voided_by_user_id = voider.id
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
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0"><i class="fas fa-check-double me-2"></i>Review Pledge Payments</h1>
                    <a href="record-pledge-payment.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Record New Payment
                    </a>
                </div>

                <!-- Statistics Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="card border-warning">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h6 class="text-muted mb-1">Pending Review</h6>
                                        <h3 class="mb-0 text-warning"><?php echo $stats['pending']; ?></h3>
                                    </div>
                                    <div class="bg-warning bg-opacity-10 p-3 rounded">
                                        <i class="fas fa-clock fa-2x text-warning"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-success">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h6 class="text-muted mb-1">Approved</h6>
                                        <h3 class="mb-0 text-success"><?php echo $stats['confirmed']; ?></h3>
                                    </div>
                                    <div class="bg-success bg-opacity-10 p-3 rounded">
                                        <i class="fas fa-check-circle fa-2x text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-danger">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h6 class="text-muted mb-1">Rejected/Voided</h6>
                                        <h3 class="mb-0 text-danger"><?php echo $stats['voided']; ?></h3>
                                    </div>
                                    <div class="bg-danger bg-opacity-10 p-3 rounded">
                                        <i class="fas fa-times-circle fa-2x text-danger"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Tabs -->
                <ul class="nav nav-pills mb-4">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter === 'pending' ? 'active' : ''; ?>" href="?filter=pending">
                            <i class="fas fa-clock me-1"></i> Pending (<?php echo $stats['pending']; ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter === 'confirmed' ? 'active' : ''; ?>" href="?filter=confirmed">
                            <i class="fas fa-check me-1"></i> Approved (<?php echo $stats['confirmed']; ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter === 'voided' ? 'active' : ''; ?>" href="?filter=voided">
                            <i class="fas fa-ban me-1"></i> Rejected (<?php echo $stats['voided']; ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter === 'all' ? 'active' : ''; ?>" href="?filter=all">
                            <i class="fas fa-list me-1"></i> All
                        </a>
                    </li>
                </ul>

                <!-- Payments List -->
                <?php if (empty($payments)): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No payments found</h5>
                            <p class="text-muted mb-0">There are no <?php echo $filter === 'all' ? '' : $filter; ?> payments to display.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($payments as $p): ?>
                            <div class="col-12">
                                <div class="card payment-card">
                                    <div class="card-body">
                                        <div class="row align-items-center">
                                            <!-- Payment Proof Thumbnail -->
                                            <div class="col-auto">
                                                <?php if ($p['payment_proof']): ?>
                                                    <?php 
                                                    $ext = strtolower(pathinfo($p['payment_proof'], PATHINFO_EXTENSION));
                                                    $is_pdf = ($ext === 'pdf');
                                                    ?>
                                                    <?php if ($is_pdf): ?>
                                                        <a href="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" target="_blank" class="btn btn-outline-danger btn-sm">
                                                            <i class="fas fa-file-pdf fa-2x"></i>
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
                                            <div class="col">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <h6 class="mb-1">
                                                            <i class="fas fa-user me-1 text-muted"></i>
                                                            <?php echo htmlspecialchars($p['donor_name'] ?? 'Unknown'); ?>
                                                        </h6>
                                                        <p class="mb-1 small text-muted">
                                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($p['donor_phone'] ?? '-'); ?>
                                                        </p>
                                                        <p class="mb-0 small">
                                                            <strong>Amount:</strong> <span class="text-success fw-bold">£<?php echo number_format((float)$p['amount'], 2); ?></span>
                                                        </p>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <p class="mb-1 small">
                                                            <strong>Method:</strong> <?php echo ucfirst($p['payment_method']); ?>
                                                        </p>
                                                        <p class="mb-1 small">
                                                            <strong>Date:</strong> <?php echo date('d M Y', strtotime($p['payment_date'])); ?>
                                                        </p>
                                                        <p class="mb-1 small">
                                                            <strong>Ref:</strong> <?php echo htmlspecialchars($p['reference_number'] ?? '-'); ?>
                                                        </p>
                                                        <?php if ($p['notes']): ?>
                                                            <p class="mb-0 small text-muted fst-italic">
                                                                <i class="fas fa-sticky-note me-1"></i><?php echo htmlspecialchars($p['notes']); ?>
                                                            </p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="mt-2 pt-2 border-top">
                                                    <small class="text-muted">
                                                        Submitted by <strong><?php echo htmlspecialchars($p['processed_by_name'] ?? 'Unknown'); ?></strong> 
                                                        on <?php echo date('d M Y H:i', strtotime($p['created_at'])); ?>
                                                    </small>
                                                    <?php if ($p['status'] === 'confirmed' && $p['approved_by_name']): ?>
                                                        <small class="text-muted ms-3">
                                                            | Approved by <strong><?php echo htmlspecialchars($p['approved_by_name']); ?></strong>
                                                            <?php if ($p['approved_at']): ?>
                                                                on <?php echo date('d M Y H:i', strtotime($p['approved_at'])); ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                    <?php if ($p['status'] === 'voided' && $p['voided_by_name']): ?>
                                                        <small class="text-danger ms-3">
                                                            | Rejected by <strong><?php echo htmlspecialchars($p['voided_by_name']); ?></strong>
                                                            <?php if ($p['voided_at']): ?>
                                                                on <?php echo date('d M Y H:i', strtotime($p['voided_at'])); ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- Status & Actions -->
                                            <div class="col-auto text-end">
                                                <?php if ($p['status'] === 'pending'): ?>
                                                    <span class="badge bg-warning status-badge d-block mb-2">PENDING REVIEW</span>
                                                    <button class="btn btn-success btn-sm me-1" onclick="approvePayment(<?php echo $p['id']; ?>)">
                                                        <i class="fas fa-check me-1"></i>Approve
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="voidPayment(<?php echo $p['id']; ?>)">
                                                        <i class="fas fa-times me-1"></i>Reject
                                                    </button>
                                                <?php elseif ($p['status'] === 'confirmed'): ?>
                                                    <span class="badge bg-success status-badge d-block mb-2">APPROVED</span>
                                                    <button class="btn btn-outline-danger btn-sm" onclick="undoPayment(<?php echo $p['id']; ?>)">
                                                        <i class="fas fa-undo me-1"></i>Undo
                                                    </button>
                                                <?php elseif ($p['status'] === 'voided'): ?>
                                                    <span class="badge bg-danger status-badge d-block mb-2">REJECTED</span>
                                                    <?php if ($p['void_reason']): ?>
                                                        <small class="text-muted d-block">Reason: <?php echo htmlspecialchars($p['void_reason']); ?></small>
                                                    <?php endif; ?>
                                                <?php endif; ?>
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

