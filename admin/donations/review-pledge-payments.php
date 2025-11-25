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

$page_title = 'Review Payments';

$db = db();

// Check if table exists
$check = $db->query("SHOW TABLES LIKE 'pledge_payments'");
if ($check->num_rows === 0) {
    die("<div class='alert alert-danger m-4'>Error: Table 'pledge_payments' not found.</div>");
}

// Get filter
$filter = $_GET['filter'] ?? 'pending';
$search = trim($_GET['search'] ?? '');

// Build query based on filter
$where_conditions = [];
if ($filter === 'pending') {
    $where_conditions[] = "pp.status = 'pending'";
} elseif ($filter === 'confirmed') {
    $where_conditions[] = "pp.status = 'confirmed'";
} elseif ($filter === 'voided') {
    $where_conditions[] = "pp.status = 'voided'";
}

// Search
$params = [];
$types = '';
if (!empty($search)) {
    $where_conditions[] = "(d.name LIKE ? OR d.phone LIKE ? OR pp.reference_number LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

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
    {$where_clause}
    ORDER BY pp.created_at DESC
    LIMIT 200
";

$payments = [];
if (!empty($params)) {
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $res = $db->query($sql);
}
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
    <title><?php echo $page_title; ?> - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        /* ========================================
           REVIEW PAYMENTS - Mobile First Styles
           ======================================== */
        
        /* Stats Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .stat-box {
            background: #fff;
            border-radius: 10px;
            padding: 0.875rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border-left: 3px solid transparent;
        }
        .stat-box.pending { border-left-color: #f59e0b; }
        .stat-box.approved { border-left-color: #10b981; }
        .stat-box.rejected { border-left-color: #ef4444; }
        
        .stat-box .num {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1;
        }
        .stat-box.pending .num { color: #f59e0b; }
        .stat-box.approved .num { color: #10b981; }
        .stat-box.rejected .num { color: #ef4444; }
        
        .stat-box .lbl {
            font-size: 0.7rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 0.25rem;
        }
        
        /* Search */
        .search-box {
            position: relative;
            margin-bottom: 1rem;
        }
        .search-box input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            font-size: 0.9rem;
            background: #fff;
        }
        .search-box input:focus {
            outline: none;
            border-color: #0a6286;
            box-shadow: 0 0 0 3px rgba(10, 98, 134, 0.1);
        }
        .search-box .icon {
            position: absolute;
            left: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }
        .search-box .clear-btn {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            padding: 0.25rem;
            font-size: 0.9rem;
        }
        
        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
            -webkit-overflow-scrolling: touch;
        }
        .filter-tabs::-webkit-scrollbar { display: none; }
        
        .filter-tab {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            background: #f3f4f6;
            color: #4b5563;
            border: none;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .filter-tab:hover {
            background: #e5e7eb;
            color: #1f2937;
        }
        .filter-tab.active {
            background: #0a6286;
            color: #fff;
        }
        .filter-tab .badge {
            font-size: 0.7rem;
            padding: 0.15rem 0.4rem;
            border-radius: 50px;
        }
        
        /* Payment Card - Mobile First */
        .pmt-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            margin-bottom: 0.75rem;
            overflow: hidden;
            border-left: 4px solid transparent;
        }
        .pmt-card.status-pending { border-left-color: #f59e0b; }
        .pmt-card.status-confirmed { border-left-color: #10b981; }
        .pmt-card.status-voided { border-left-color: #ef4444; }
        
        .pmt-header {
            padding: 1rem;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }
        
        .pmt-proof {
            flex: 0 0 auto;
        }
        .proof-img {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid #e5e7eb;
            cursor: pointer;
        }
        .proof-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
        }
        .proof-pdf {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: #fef2f2;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #dc2626;
            text-decoration: none;
        }
        
        .pmt-info {
            flex: 1;
            min-width: 0;
        }
        .pmt-donor {
            font-weight: 600;
            font-size: 0.95rem;
            color: #1f2937;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .pmt-phone {
            font-size: 0.8rem;
            color: #6b7280;
            margin: 0.125rem 0 0 0;
        }
        
        .pmt-amount {
            text-align: right;
            flex: 0 0 auto;
        }
        .pmt-amount .val {
            font-size: 1.125rem;
            font-weight: 700;
            color: #10b981;
        }
        .pmt-amount .method {
            font-size: 0.7rem;
            color: #6b7280;
            text-transform: capitalize;
        }
        
        .pmt-body {
            padding: 0 1rem 1rem;
        }
        
        .pmt-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem;
            margin-bottom: 0.75rem;
        }
        .tag {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            background: #f3f4f6;
            border-radius: 4px;
            font-size: 0.7rem;
            color: #4b5563;
        }
        .tag i { font-size: 0.6rem; opacity: 0.7; }
        .tag.plan { background: #dbeafe; color: #1e40af; }
        
        .status-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.6rem;
            border-radius: 50px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-tag.pending { background: #fef3c7; color: #92400e; }
        .status-tag.confirmed { background: #d1fae5; color: #065f46; }
        .status-tag.voided { background: #fee2e2; color: #991b1b; }
        
        .pmt-notes {
            background: #eff6ff;
            border-radius: 6px;
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            color: #1e40af;
            margin-bottom: 0.75rem;
        }
        
        .pmt-meta {
            font-size: 0.75rem;
            color: #9ca3af;
            margin-bottom: 0.5rem;
        }
        .pmt-meta strong { color: #6b7280; }
        
        .void-reason {
            background: #fef2f2;
            border-radius: 6px;
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            color: #991b1b;
            margin-top: 0.5rem;
        }
        
        /* Action Buttons */
        .pmt-actions {
            display: flex;
            gap: 0.5rem;
            padding-top: 0.75rem;
            border-top: 1px solid #f3f4f6;
        }
        .act-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            padding: 0.6rem;
            border: none;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .act-btn.approve {
            background: #10b981;
            color: #fff;
        }
        .act-btn.approve:hover { background: #059669; }
        
        .act-btn.reject {
            background: #fef2f2;
            color: #ef4444;
        }
        .act-btn.reject:hover { background: #fee2e2; }
        
        .act-btn.undo {
            background: #fef2f2;
            color: #ef4444;
        }
        .act-btn.undo:hover { background: #fee2e2; }
        
        .act-btn.view {
            background: #f3f4f6;
            color: #4b5563;
        }
        .act-btn.view:hover { background: #e5e7eb; color: #1f2937; }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .empty-icon {
            width: 56px;
            height: 56px;
            background: #f3f4f6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: #9ca3af;
            font-size: 1.25rem;
        }
        .empty-state h4 {
            font-size: 1rem;
            color: #4b5563;
            margin-bottom: 0.5rem;
        }
        .empty-state p {
            font-size: 0.875rem;
            color: #9ca3af;
            margin: 0;
        }
        
        /* Mobile FAB */
        .mobile-fab {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0a6286 0%, #0ea5e9 100%);
            color: #fff;
            border: none;
            box-shadow: 0 4px 12px rgba(10, 98, 134, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            z-index: 100;
            text-decoration: none;
        }
        .mobile-fab:hover { 
            transform: scale(1.1); 
            color: #fff;
        }
        
        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            flex-direction: column;
            gap: 1rem;
        }
        .loading-overlay.show { display: flex; }
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #e5e7eb;
            border-top-color: #0a6286;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        /* Toast */
        .toast-box {
            position: fixed;
            bottom: 5rem;
            left: 50%;
            transform: translateX(-50%);
            background: #1f2937;
            color: #fff;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-size: 0.875rem;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            animation: slideUp 0.3s ease;
        }
        .toast-box.success { background: #10b981; }
        .toast-box.error { background: #ef4444; }
        @keyframes slideUp {
            from { opacity: 0; transform: translate(-50%, 20px); }
            to { opacity: 1; transform: translate(-50%, 0); }
        }
        
        /* Desktop Enhancements */
        @media (min-width: 768px) {
            .stats-row {
                gap: 1rem;
            }
            .stat-box {
                padding: 1rem;
            }
            .stat-box .num {
                font-size: 1.75rem;
            }
            .pmt-card {
                margin-bottom: 1rem;
            }
            .pmt-header {
                padding: 1.25rem;
            }
            .pmt-body {
                padding: 0 1.25rem 1.25rem;
            }
            .pmt-actions {
                justify-content: flex-end;
            }
            .act-btn {
                flex: 0 0 auto;
                padding: 0.5rem 1rem;
            }
            .mobile-fab {
                display: none;
            }
        }
        
        /* Larger screens */
        @media (min-width: 992px) {
            .pmt-amount .val {
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
                
                <!-- Page Header -->
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3">
                    <h1 class="h4 mb-2 mb-md-0">
                        <i class="fas fa-check-double text-primary me-2"></i>Review Payments
                    </h1>
                    <a href="record-pledge-payment.php" class="btn btn-primary btn-sm d-none d-md-inline-flex">
                        <i class="fas fa-plus me-1"></i>Record Payment
                    </a>
                </div>

                <!-- Stats Row -->
                <div class="stats-row">
                    <div class="stat-box pending">
                        <div class="num"><?php echo $stats['pending']; ?></div>
                        <div class="lbl">Pending</div>
                    </div>
                    <div class="stat-box approved">
                        <div class="num"><?php echo $stats['confirmed']; ?></div>
                        <div class="lbl">Approved</div>
                    </div>
                    <div class="stat-box rejected">
                        <div class="num"><?php echo $stats['voided']; ?></div>
                        <div class="lbl">Rejected</div>
                    </div>
                </div>

                <!-- Search -->
                <div class="search-box">
                    <i class="fas fa-search icon"></i>
                    <input type="text" 
                           id="searchInput" 
                           placeholder="Search name, phone, reference..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <?php if (!empty($search)): ?>
                        <button class="clear-btn" onclick="clearSearch()">
                            <i class="fas fa-times"></i>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Filter Tabs -->
                <div class="filter-tabs">
                    <a href="?filter=pending<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                       class="filter-tab <?php echo $filter === 'pending' ? 'active' : ''; ?>">
                        <i class="fas fa-clock"></i> Pending
                        <?php if ($stats['pending'] > 0): ?>
                            <span class="badge <?php echo $filter === 'pending' ? 'bg-light text-dark' : 'bg-warning text-dark'; ?>">
                                <?php echo $stats['pending']; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <a href="?filter=confirmed<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                       class="filter-tab <?php echo $filter === 'confirmed' ? 'active' : ''; ?>">
                        <i class="fas fa-check"></i> Approved
                    </a>
                    <a href="?filter=voided<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                       class="filter-tab <?php echo $filter === 'voided' ? 'active' : ''; ?>">
                        <i class="fas fa-ban"></i> Rejected
                    </a>
                    <a href="?filter=all<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                       class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
                        <i class="fas fa-list"></i> All
                    </a>
                </div>

                <!-- Payments List -->
                <?php if (empty($payments)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <h4>No payments found</h4>
                        <p>
                            <?php if (!empty($search)): ?>
                                No results for "<?php echo htmlspecialchars($search); ?>"
                            <?php else: ?>
                                There are no <?php echo $filter === 'all' ? '' : $filter; ?> payments
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <div class="pmt-card status-<?php echo $p['status']; ?>">
                            <div class="pmt-header">
                                <!-- Proof -->
                                <div class="pmt-proof">
                                    <?php if ($p['payment_proof']): ?>
                                        <?php 
                                        $ext = strtolower(pathinfo($p['payment_proof'], PATHINFO_EXTENSION));
                                        $is_pdf = ($ext === 'pdf');
                                        ?>
                                        <?php if ($is_pdf): ?>
                                            <a href="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" 
                                               target="_blank" class="proof-pdf">
                                                <i class="fas fa-file-pdf"></i>
                                            </a>
                                        <?php else: ?>
                                            <img src="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" 
                                                 alt="Proof" class="proof-img"
                                                 onclick="viewProof('../../<?php echo htmlspecialchars($p['payment_proof']); ?>')">
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="proof-placeholder">
                                            <i class="fas fa-receipt"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Info -->
                                <div class="pmt-info">
                                    <h5 class="pmt-donor"><?php echo htmlspecialchars($p['donor_name'] ?? 'Unknown'); ?></h5>
                                    <p class="pmt-phone">
                                        <i class="fas fa-phone fa-xs me-1"></i>
                                        <?php echo htmlspecialchars($p['donor_phone'] ?? '-'); ?>
                                    </p>
                                </div>

                                <!-- Amount -->
                                <div class="pmt-amount">
                                    <div class="val">£<?php echo number_format((float)$p['amount'], 2); ?></div>
                                    <div class="method"><?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?></div>
                                </div>
                            </div>

                            <div class="pmt-body">
                                <!-- Tags -->
                                <div class="pmt-tags">
                                    <span class="tag">
                                        <i class="far fa-calendar"></i>
                                        <?php echo date('d M Y', strtotime($p['payment_date'])); ?>
                                    </span>
                                    
                                    <?php if ($p['reference_number']): ?>
                                        <span class="tag">
                                            <i class="fas fa-hashtag"></i>
                                            <?php echo htmlspecialchars($p['reference_number']); ?>
                                        </span>
                                    <?php endif; ?>

                                    <span class="status-tag <?php echo $p['status']; ?>">
                                        <?php if ($p['status'] === 'pending'): ?>
                                            <i class="fas fa-clock"></i>
                                        <?php elseif ($p['status'] === 'confirmed'): ?>
                                            <i class="fas fa-check"></i>
                                        <?php else: ?>
                                            <i class="fas fa-ban"></i>
                                        <?php endif; ?>
                                        <?php echo ucfirst($p['status']); ?>
                                    </span>

                                    <?php if ($has_plan_col && isset($p['plan_id']) && $p['plan_id']): ?>
                                        <span class="tag plan">
                                            <i class="fas fa-calendar-check"></i>
                                            Plan <?php echo ($p['plan_payments_made'] ?? 0); ?>/<?php echo $p['plan_total_payments'] ?? '?'; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Notes -->
                                <?php if ($p['notes']): ?>
                                    <div class="pmt-notes">
                                        <i class="fas fa-sticky-note me-1"></i>
                                        <?php echo htmlspecialchars($p['notes']); ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Meta -->
                                <div class="pmt-meta">
                                    <i class="fas fa-user-plus me-1"></i>
                                    By <strong><?php echo htmlspecialchars($p['processed_by_name'] ?? 'System'); ?></strong>
                                    • <?php echo date('d M, H:i', strtotime($p['created_at'])); ?>
                                    
                                    <?php if ($p['status'] === 'confirmed' && $p['approved_by_name']): ?>
                                        <br>
                                        <i class="fas fa-check-circle text-success me-1"></i>
                                        Approved by <strong><?php echo htmlspecialchars($p['approved_by_name']); ?></strong>
                                        <?php if ($p['approved_at']): ?>
                                            • <?php echo date('d M, H:i', strtotime($p['approved_at'])); ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php if ($p['status'] === 'voided' && $p['voided_by_name']): ?>
                                        <br>
                                        <i class="fas fa-times-circle text-danger me-1"></i>
                                        Rejected by <strong><?php echo htmlspecialchars($p['voided_by_name']); ?></strong>
                                        <?php if ($p['voided_at']): ?>
                                            • <?php echo date('d M, H:i', strtotime($p['voided_at'])); ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <!-- Void Reason -->
                                <?php if ($p['status'] === 'voided' && isset($p['void_reason']) && $p['void_reason']): ?>
                                    <div class="void-reason">
                                        <i class="fas fa-info-circle me-1"></i>
                                        <strong>Reason:</strong> <?php echo htmlspecialchars($p['void_reason']); ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Actions -->
                                <div class="pmt-actions">
                                    <?php if ($p['status'] === 'pending'): ?>
                                        <button class="act-btn approve" onclick="approvePayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button class="act-btn reject" onclick="voidPayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    <?php elseif ($p['status'] === 'confirmed'): ?>
                                        <button class="act-btn undo" onclick="undoPayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-undo"></i> Undo
                                        </button>
                                        <a href="../donor-management/view-donor.php?id=<?php echo $p['donor_id']; ?>" class="act-btn view">
                                            <i class="fas fa-user"></i> View Donor
                                        </a>
                                    <?php else: ?>
                                        <a href="../donor-management/view-donor.php?id=<?php echo $p['donor_id']; ?>" class="act-btn view" style="flex: 1;">
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

<!-- Mobile FAB -->
<a href="record-pledge-payment.php" class="mobile-fab d-md-none">
    <i class="fas fa-plus"></i>
</a>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
    <span>Processing...</span>
</div>

<!-- Toast Container -->
<div id="toastContainer"></div>

<!-- Image Modal -->
<div class="modal fade" id="proofModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title">Payment Proof</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-2">
                <img id="proofImage" src="" alt="Payment Proof" class="img-fluid rounded" style="max-height: 80vh;">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// View proof
function viewProof(src) {
    document.getElementById('proofImage').src = src;
    new bootstrap.Modal(document.getElementById('proofModal')).show();
}

// Loading
function showLoading() {
    document.getElementById('loadingOverlay').classList.add('show');
}
function hideLoading() {
    document.getElementById('loadingOverlay').classList.remove('show');
}

// Toast
function showToast(msg, type) {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = 'toast-box ' + type;
    toast.textContent = msg;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// Approve
function approvePayment(id) {
    if (!confirm('Approve this payment?')) return;
    showLoading();
    fetch('approve-pledge-payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id})
    })
    .then(r => r.json())
    .then(res => {
        hideLoading();
        if (res.success) {
            showToast('Payment approved!', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Error: ' + res.message, 'error');
        }
    })
    .catch(() => {
        hideLoading();
        showToast('Network error', 'error');
    });
}

// Reject
function voidPayment(id) {
    const reason = prompt('Rejection reason:');
    if (!reason) return;
    showLoading();
    fetch('void-pledge-payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id, reason: reason})
    })
    .then(r => r.json())
    .then(res => {
        hideLoading();
        if (res.success) {
            showToast('Payment rejected', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Error: ' + res.message, 'error');
        }
    })
    .catch(() => {
        hideLoading();
        showToast('Network error', 'error');
    });
}

// Undo
function undoPayment(id) {
    if (!confirm('Undo this approved payment?')) return;
    const reason = prompt('Reason for undo:');
    if (!reason) return;
    showLoading();
    fetch('undo-pledge-payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id, reason: reason})
    })
    .then(r => r.json())
    .then(res => {
        hideLoading();
        if (res.success) {
            showToast('Payment undone', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Error: ' + res.message, 'error');
        }
    })
    .catch(() => {
        hideLoading();
        showToast('Network error', 'error');
    });
}

// Search
const searchInput = document.getElementById('searchInput');
let searchTimeout;
searchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        const search = this.value.trim();
        const url = new URL(window.location);
        if (search) {
            url.searchParams.set('search', search);
        } else {
            url.searchParams.delete('search');
        }
        window.location = url.toString();
    }, 500);
});

function clearSearch() {
    const url = new URL(window.location);
    url.searchParams.delete('search');
    window.location = url.toString();
}
</script>
</body>
</html>
