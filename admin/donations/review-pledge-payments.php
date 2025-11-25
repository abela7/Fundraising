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
        :root {
            --pending-color: #f59e0b;
            --approved-color: #10b981;
            --rejected-color: #ef4444;
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        /* Page Header */
        .page-header {
            background: var(--primary-gradient);
            border-radius: 16px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            color: white;
        }
        .page-header h1 {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0;
        }
        .page-header .btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            backdrop-filter: blur(10px);
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
        }
        .page-header .btn:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        
        /* Stats Cards - Compact */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid #f0f0f0;
            transition: all 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .stat-card.pending { border-top: 3px solid var(--pending-color); }
        .stat-card.approved { border-top: 3px solid var(--approved-color); }
        .stat-card.rejected { border-top: 3px solid var(--rejected-color); }
        .stat-card .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-size: 1rem;
        }
        .stat-card.pending .stat-icon { background: #fef3c7; color: var(--pending-color); }
        .stat-card.approved .stat-icon { background: #d1fae5; color: var(--approved-color); }
        .stat-card.rejected .stat-icon { background: #fee2e2; color: var(--rejected-color); }
        .stat-card .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.25rem;
        }
        .stat-card.pending .stat-value { color: var(--pending-color); }
        .stat-card.approved .stat-value { color: var(--approved-color); }
        .stat-card.rejected .stat-value { color: var(--rejected-color); }
        .stat-card .stat-label {
            font-size: 0.7rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        /* Filter Tabs - Horizontal Scroll on Mobile */
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .filter-tabs::-webkit-scrollbar { display: none; }
        .filter-tab {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s ease;
            background: #f3f4f6;
            color: #6b7280;
            border: 1px solid transparent;
        }
        .filter-tab:hover {
            background: #e5e7eb;
            color: #374151;
        }
        .filter-tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .filter-tab .badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-weight: 700;
        }
        .filter-tab.active .badge {
            background: rgba(255,255,255,0.25);
            color: white;
        }
        
        /* Payment Cards */
        .payment-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid #f0f0f0;
            margin-bottom: 0.75rem;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        .payment-card:hover {
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        }
        .payment-card.pending-card { border-left: 4px solid var(--pending-color); }
        .payment-card.approved-card { border-left: 4px solid var(--approved-color); }
        .payment-card.rejected-card { border-left: 4px solid var(--rejected-color); }
        
        .payment-card-body {
            padding: 1rem;
        }
        
        /* Payment Header */
        .payment-header {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        .proof-thumbnail {
            width: 48px;
            height: 48px;
            min-width: 48px;
            object-fit: cover;
            border-radius: 10px;
            cursor: pointer;
            transition: transform 0.2s;
            border: 2px solid #f0f0f0;
        }
        .proof-thumbnail:hover {
            transform: scale(1.05);
        }
        .proof-placeholder {
            width: 48px;
            height: 48px;
            min-width: 48px;
            border-radius: 10px;
            background: #f9fafb;
            border: 2px dashed #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
        }
        .donor-info {
            flex: 1;
            min-width: 0;
        }
        .donor-name {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1f2937;
            margin: 0 0 0.125rem 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .donor-phone {
            font-size: 0.8rem;
            color: #6b7280;
            margin: 0;
        }
        .payment-status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .payment-status-badge.pending {
            background: #fef3c7;
            color: #b45309;
        }
        .payment-status-badge.approved {
            background: #d1fae5;
            color: #047857;
        }
        .payment-status-badge.rejected {
            background: #fee2e2;
            color: #b91c1c;
        }
        
        /* Payment Details Grid */
        .payment-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        .payment-detail {
            background: #f9fafb;
            border-radius: 10px;
            padding: 0.625rem 0.75rem;
        }
        .payment-detail-label {
            font-size: 0.65rem;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 0.125rem;
        }
        .payment-detail-value {
            font-size: 0.875rem;
            font-weight: 600;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }
        .payment-detail-value.amount {
            color: var(--approved-color);
            font-size: 1rem;
            font-weight: 700;
        }
        .payment-detail.full-width {
            grid-column: span 2;
        }
        
        /* Plan Badge */
        .plan-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: white;
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        /* Notes */
        .payment-notes {
            background: #eff6ff;
            border-left: 3px solid #3b82f6;
            border-radius: 0 10px 10px 0;
            padding: 0.625rem 0.875rem;
            margin-bottom: 0.75rem;
        }
        .payment-notes-text {
            font-size: 0.8rem;
            color: #1e40af;
            margin: 0;
        }
        
        /* Meta Info */
        .payment-meta {
            border-top: 1px solid #f0f0f0;
            padding-top: 0.75rem;
            margin-bottom: 0.75rem;
        }
        .meta-item {
            font-size: 0.75rem;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 0.35rem;
            margin-bottom: 0.25rem;
        }
        .meta-item:last-child { margin-bottom: 0; }
        .meta-item strong { color: #374151; }
        .meta-item.approved { color: var(--approved-color); }
        .meta-item.rejected { color: var(--rejected-color); }
        
        /* Action Buttons */
        .payment-actions {
            display: flex;
            gap: 0.5rem;
        }
        .btn-action {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            padding: 0.625rem 0.75rem;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-action.approve {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        .btn-action.approve:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        .btn-action.reject {
            background: #fee2e2;
            color: #dc2626;
        }
        .btn-action.reject:hover {
            background: #fecaca;
        }
        .btn-action.undo {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        .btn-action.undo:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }
        .btn-action.view {
            background: #e0e7ff;
            color: #4338ca;
        }
        .btn-action.view:hover {
            background: #c7d2fe;
        }
        
        /* Void Reason */
        .void-reason {
            background: #fee2e2;
            border-radius: 10px;
            padding: 0.625rem 0.875rem;
            margin-top: 0.5rem;
        }
        .void-reason-text {
            font-size: 0.8rem;
            color: #991b1b;
            margin: 0;
        }
        
        /* Empty State */
        .empty-state {
            background: white;
            border-radius: 16px;
            padding: 3rem 1.5rem;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .empty-state-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
            color: #9ca3af;
        }
        .empty-state h5 {
            font-size: 1rem;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        .empty-state p {
            font-size: 0.875rem;
            color: #6b7280;
            margin: 0;
        }
        
        /* Modal Enhancement */
        .modal-content {
            border-radius: 16px;
            border: none;
            overflow: hidden;
        }
        .modal-header {
            background: #f9fafb;
            border-bottom: 1px solid #f0f0f0;
            padding: 1rem 1.25rem;
        }
        .modal-title {
            font-weight: 700;
            font-size: 1rem;
        }
        .modal-body {
            padding: 0;
        }
        .modal-body img {
            display: block;
            width: 100%;
            max-height: 70vh;
            object-fit: contain;
        }
        
        /* Desktop Enhancements */
        @media (min-width: 768px) {
            .page-header {
                padding: 1.5rem 2rem;
            }
            .page-header h1 {
                font-size: 1.5rem;
            }
            .stats-row {
                gap: 1rem;
            }
            .stat-card {
                padding: 1.25rem;
            }
            .stat-card .stat-icon {
                width: 44px;
                height: 44px;
                font-size: 1.125rem;
            }
            .stat-card .stat-value {
                font-size: 2rem;
            }
            .stat-card .stat-label {
                font-size: 0.75rem;
            }
            .payment-card-body {
                padding: 1.25rem;
            }
            .payment-header {
                gap: 1rem;
            }
            .proof-thumbnail,
            .proof-placeholder {
                width: 56px;
                height: 56px;
                min-width: 56px;
            }
            .donor-name {
                font-size: 1.05rem;
            }
            .payment-details {
                grid-template-columns: repeat(4, 1fr);
            }
            .payment-detail.full-width {
                grid-column: span 4;
            }
            .payment-actions {
                justify-content: flex-end;
            }
            .btn-action {
                flex: none;
                min-width: 120px;
            }
        }
        
        /* Touch-friendly for mobile */
        @media (max-width: 767px) {
            .btn-action {
                min-height: 44px;
            }
            .filter-tab {
                min-height: 40px;
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
                <!-- Page Header -->
                <div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                    <h1>
                        <i class="fas fa-check-double me-2"></i>Review Payments
                    </h1>
                    <a href="record-pledge-payment.php" class="btn">
                        <i class="fas fa-plus me-1"></i>New Payment
                    </a>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-row">
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['pending']; ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <div class="stat-card approved">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['confirmed']; ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                    <div class="stat-card rejected">
                        <div class="stat-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['voided']; ?></div>
                        <div class="stat-label">Rejected</div>
                    </div>
                </div>

                <!-- Filter Tabs - Horizontal Scroll -->
                <div class="filter-tabs">
                    <a class="filter-tab <?php echo $filter === 'pending' ? 'active' : ''; ?>" href="?filter=pending">
                        <i class="fas fa-clock"></i>
                        <span>Pending</span>
                        <span class="badge bg-warning text-dark"><?php echo $stats['pending']; ?></span>
                    </a>
                    <a class="filter-tab <?php echo $filter === 'confirmed' ? 'active' : ''; ?>" href="?filter=confirmed">
                        <i class="fas fa-check"></i>
                        <span>Approved</span>
                        <span class="badge bg-success"><?php echo $stats['confirmed']; ?></span>
                    </a>
                    <a class="filter-tab <?php echo $filter === 'voided' ? 'active' : ''; ?>" href="?filter=voided">
                        <i class="fas fa-ban"></i>
                        <span>Rejected</span>
                        <span class="badge bg-danger"><?php echo $stats['voided']; ?></span>
                    </a>
                    <a class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>" href="?filter=all">
                        <i class="fas fa-list"></i>
                        <span>All</span>
                    </a>
                </div>

                <!-- Payments List -->
                <?php if (empty($payments)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <h5>No payments found</h5>
                        <p>There are no <?php echo $filter === 'all' ? '' : $filter; ?> payments to display.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($payments as $p): 
                        // Determine status class
                        $status_class = '';
                        if ($p['status'] === 'confirmed') $status_class = 'approved-card';
                        elseif ($p['status'] === 'pending') $status_class = 'pending-card';
                        elseif ($p['status'] === 'voided') $status_class = 'rejected-card';
                        
                        // Check payment plan info
                        $show_plan_info = false;
                        $plan_installment = null;
                        if ($has_plan_col && isset($p['plan_id']) && $p['plan_id']) {
                            $show_plan_info = true;
                            $plan_installment = ($p['plan_payments_made'] ?? 0) + 1;
                        } elseif (isset($p['donor_active_plan_id']) && $p['donor_active_plan_id']) {
                            $plan_check = $db->prepare("SELECT monthly_amount FROM donor_payment_plans WHERE id = ? LIMIT 1");
                            $plan_check->bind_param('i', $p['donor_active_plan_id']);
                            $plan_check->execute();
                            $plan_data = $plan_check->get_result()->fetch_assoc();
                            $plan_check->close();
                            if ($plan_data && abs((float)$p['amount'] - (float)$plan_data['monthly_amount']) < 0.01) {
                                $show_plan_info = true;
                                $plan_installment = '?';
                            }
                        }
                        
                        // Method icons
                        $method_icons = [
                            'cash' => 'money-bill-wave',
                            'bank_transfer' => 'university',
                            'card' => 'credit-card',
                            'cheque' => 'file-invoice-dollar',
                            'other' => 'hand-holding-usd'
                        ];
                        $icon = $method_icons[$p['payment_method']] ?? 'money-bill';
                    ?>
                        <div class="payment-card <?php echo $status_class; ?>">
                            <div class="payment-card-body">
                                <!-- Header: Proof + Donor + Status Badge -->
                                <div class="payment-header">
                                    <?php if ($p['payment_proof']): ?>
                                        <?php 
                                        $ext = strtolower(pathinfo($p['payment_proof'], PATHINFO_EXTENSION));
                                        $is_pdf = ($ext === 'pdf');
                                        ?>
                                        <?php if ($is_pdf): ?>
                                            <a href="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" target="_blank" class="proof-placeholder" style="background:#fee2e2; border-color:#fecaca; color:#ef4444;">
                                                <i class="fas fa-file-pdf"></i>
                                            </a>
                                        <?php else: ?>
                                            <img src="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" 
                                                 alt="Proof" 
                                                 class="proof-thumbnail"
                                                 onclick="viewProof('../../<?php echo htmlspecialchars($p['payment_proof']); ?>')">
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="proof-placeholder">
                                            <i class="fas fa-image"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="donor-info">
                                        <p class="donor-name">
                                            <i class="fas fa-user me-1 text-primary"></i>
                                            <?php echo htmlspecialchars($p['donor_name'] ?? 'Unknown'); ?>
                                        </p>
                                        <p class="donor-phone">
                                            <i class="fas fa-phone me-1"></i>
                                            <?php echo htmlspecialchars($p['donor_phone'] ?? '-'); ?>
                                        </p>
                                    </div>
                                    
                                    <span class="payment-status-badge <?php echo $p['status'] === 'confirmed' ? 'approved' : $p['status']; ?>">
                                        <?php if ($p['status'] === 'pending'): ?>
                                            <i class="fas fa-clock"></i> Pending
                                        <?php elseif ($p['status'] === 'confirmed'): ?>
                                            <i class="fas fa-check"></i> Approved
                                        <?php else: ?>
                                            <i class="fas fa-ban"></i> Rejected
                                        <?php endif; ?>
                                    </span>
                                </div>
                                
                                <!-- Payment Details Grid -->
                                <div class="payment-details">
                                    <div class="payment-detail">
                                        <div class="payment-detail-label">Amount</div>
                                        <div class="payment-detail-value amount">
                                            <i class="fas fa-pound-sign"></i>
                                            <?php echo number_format((float)$p['amount'], 2); ?>
                                        </div>
                                    </div>
                                    <div class="payment-detail">
                                        <div class="payment-detail-label">Method</div>
                                        <div class="payment-detail-value">
                                            <i class="fas fa-<?php echo $icon; ?> text-info"></i>
                                            <?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?>
                                        </div>
                                    </div>
                                    <div class="payment-detail">
                                        <div class="payment-detail-label">Date</div>
                                        <div class="payment-detail-value">
                                            <i class="far fa-calendar-alt text-secondary"></i>
                                            <?php echo date('d M Y', strtotime($p['payment_date'])); ?>
                                        </div>
                                    </div>
                                    <?php if ($p['reference_number']): ?>
                                    <div class="payment-detail">
                                        <div class="payment-detail-label">Reference</div>
                                        <div class="payment-detail-value" style="font-family: monospace;">
                                            #<?php echo htmlspecialchars($p['reference_number']); ?>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="payment-detail">
                                        <div class="payment-detail-label">ID</div>
                                        <div class="payment-detail-value" style="font-family: monospace;">
                                            #<?php echo $p['id']; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Plan Badge -->
                                <?php if ($show_plan_info): ?>
                                <div class="mb-3">
                                    <span class="plan-badge">
                                        <i class="fas fa-calendar-check"></i>
                                        <?php if ($plan_installment !== '?'): ?>
                                            Installment <?php echo $plan_installment; ?> of <?php echo $p['plan_total_payments'] ?? '?'; ?>
                                        <?php else: ?>
                                            Plan Payment
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Notes -->
                                <?php if ($p['notes']): ?>
                                <div class="payment-notes">
                                    <p class="payment-notes-text">
                                        <i class="fas fa-sticky-note me-1"></i>
                                        <strong>Note:</strong> <?php echo htmlspecialchars($p['notes']); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Meta Info -->
                                <div class="payment-meta">
                                    <div class="meta-item">
                                        <i class="fas fa-user-plus"></i>
                                        Submitted by <strong><?php echo htmlspecialchars($p['processed_by_name'] ?? 'Unknown'); ?></strong>
                                        &bull; <?php echo date('d M Y, H:i', strtotime($p['created_at'])); ?>
                                    </div>
                                    <?php if ($p['status'] === 'confirmed' && $p['approved_by_name']): ?>
                                    <div class="meta-item approved">
                                        <i class="fas fa-check-circle"></i>
                                        Approved by <strong><?php echo htmlspecialchars($p['approved_by_name']); ?></strong>
                                        <?php if ($p['approved_at']): ?>
                                            &bull; <?php echo date('d M Y, H:i', strtotime($p['approved_at'])); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($p['status'] === 'voided' && $p['voided_by_name']): ?>
                                    <div class="meta-item rejected">
                                        <i class="fas fa-times-circle"></i>
                                        Rejected by <strong><?php echo htmlspecialchars($p['voided_by_name']); ?></strong>
                                        <?php if ($p['voided_at']): ?>
                                            &bull; <?php echo date('d M Y, H:i', strtotime($p['voided_at'])); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Void Reason -->
                                <?php if ($p['status'] === 'voided' && !empty($p['void_reason'])): ?>
                                <div class="void-reason">
                                    <p class="void-reason-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        <strong>Reason:</strong> <?php echo htmlspecialchars($p['void_reason']); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Action Buttons -->
                                <div class="payment-actions">
                                    <?php if ($p['status'] === 'pending'): ?>
                                        <button class="btn-action approve" onclick="approvePayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button class="btn-action reject" onclick="voidPayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    <?php elseif ($p['status'] === 'confirmed'): ?>
                                        <button class="btn-action undo" onclick="undoPayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-undo"></i> Undo
                                        </button>
                                        <a href="../donor-management/view-donor.php?id=<?php echo $p['donor_id']; ?>" class="btn-action view">
                                            <i class="fas fa-user"></i> View Donor
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Image Viewer Modal -->
<div class="modal fade" id="proofModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-image me-2 text-primary"></i>Payment Proof
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-2">
                <img id="proofImage" src="" alt="Payment Proof" class="img-fluid" style="border-radius: 8px;">
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

