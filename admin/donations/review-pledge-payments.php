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
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        :root {
            --review-pending: #f59e0b;
            --review-approved: #10b981;
            --review-rejected: #ef4444;
            --review-bg: #f8fafc;
            --card-shadow: 0 1px 3px rgba(0,0,0,0.08);
            --card-shadow-hover: 0 4px 12px rgba(0,0,0,0.12);
        }

        .review-page {
            background: var(--review-bg);
            min-height: 100vh;
        }

        /* Header */
        .page-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%);
            color: white;
            padding: 1rem;
            margin: -1rem -1rem 1rem -1rem;
            border-radius: 0;
        }
        
        @media (min-width: 768px) {
            .page-header {
                padding: 1.5rem;
                margin: 0 0 1.5rem 0;
                border-radius: 12px;
            }
        }

        .page-header h1 {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
        }

        /* Stats Row - Horizontal scroll on mobile */
        .stats-scroll {
            display: flex;
            gap: 0.75rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .stats-scroll::-webkit-scrollbar {
            display: none;
        }

        .stat-chip {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: white;
            border-radius: 50px;
            box-shadow: var(--card-shadow);
            font-size: 0.875rem;
            white-space: nowrap;
        }
        
        .stat-chip.pending { border-left: 3px solid var(--review-pending); }
        .stat-chip.approved { border-left: 3px solid var(--review-approved); }
        .stat-chip.rejected { border-left: 3px solid var(--review-rejected); }

        .stat-chip .count {
            font-weight: 700;
            font-size: 1.1rem;
        }
        .stat-chip.pending .count { color: var(--review-pending); }
        .stat-chip.approved .count { color: var(--review-approved); }
        .stat-chip.rejected .count { color: var(--review-rejected); }

        /* Filter Chips */
        .filter-row {
            display: flex;
            gap: 0.5rem;
            overflow-x: auto;
            padding: 0.5rem 0;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .filter-row::-webkit-scrollbar {
            display: none;
        }

        .filter-chip {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            background: white;
            color: #64748b;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .filter-chip:hover {
            background: #f1f5f9;
            color: #475569;
        }

        .filter-chip.active {
            background: #1e3a5f;
            color: white;
            border-color: #1e3a5f;
        }

        .filter-chip .badge {
            font-size: 0.7rem;
            padding: 0.2rem 0.4rem;
            border-radius: 50px;
        }

        /* Search Bar */
        .search-container {
            position: relative;
            margin-bottom: 1rem;
        }

        .search-container input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 50px;
            font-size: 0.9rem;
            background: white;
            transition: all 0.2s;
        }

        .search-container input:focus {
            outline: none;
            border-color: #1e3a5f;
            box-shadow: 0 0 0 3px rgba(30, 58, 95, 0.1);
        }

        .search-container .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .search-container .clear-btn {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 0.25rem;
        }

        /* Payment Cards - Mobile First */
        .payment-card {
            background: white;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 0.75rem;
            overflow: hidden;
            transition: all 0.2s;
        }

        .payment-card:active {
            transform: scale(0.98);
        }

        @media (min-width: 768px) {
            .payment-card:hover {
                box-shadow: var(--card-shadow-hover);
            }
        }

        .payment-card.status-pending {
            border-left: 4px solid var(--review-pending);
        }
        .payment-card.status-confirmed {
            border-left: 4px solid var(--review-approved);
        }
        .payment-card.status-voided {
            border-left: 4px solid var(--review-rejected);
        }

        .payment-card-header {
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .donor-info {
            flex: 1;
            min-width: 0;
        }

        .donor-name {
            font-weight: 600;
            font-size: 1rem;
            color: #1e293b;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .donor-phone {
            font-size: 0.8rem;
            color: #64748b;
            margin: 0.25rem 0 0 0;
        }

        .payment-amount {
            text-align: right;
        }

        .payment-amount .amount {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--review-approved);
        }

        .payment-amount .method {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: capitalize;
        }

        .payment-card-body {
            padding: 0 1rem 1rem;
        }

        .payment-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .meta-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            background: #f1f5f9;
            border-radius: 4px;
            font-size: 0.75rem;
            color: #475569;
        }

        .meta-tag i {
            font-size: 0.65rem;
            opacity: 0.7;
        }

        .meta-tag.plan-tag {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Proof Thumbnail */
        .proof-thumb {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid #e2e8f0;
            cursor: pointer;
        }

        .proof-thumb-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 1rem;
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
            font-size: 1.25rem;
            text-decoration: none;
        }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .status-badge.pending {
            background: #fef3c7;
            color: #92400e;
        }
        .status-badge.confirmed {
            background: #d1fae5;
            color: #065f46;
        }
        .status-badge.voided {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Action Buttons - Mobile Optimized */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #f1f5f9;
        }

        .action-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            padding: 0.65rem 0.75rem;
            border: none;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .action-btn i {
            font-size: 0.85rem;
        }

        .action-btn.approve {
            background: var(--review-approved);
            color: white;
        }
        .action-btn.approve:hover {
            background: #059669;
        }

        .action-btn.reject {
            background: #fef2f2;
            color: var(--review-rejected);
        }
        .action-btn.reject:hover {
            background: #fee2e2;
        }

        .action-btn.undo {
            background: #fef2f2;
            color: var(--review-rejected);
        }

        .action-btn.view {
            background: #f1f5f9;
            color: #475569;
        }
        .action-btn.view:hover {
            background: #e2e8f0;
        }

        /* Notes Section */
        .payment-notes {
            background: #eff6ff;
            border-radius: 6px;
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            color: #1e40af;
            margin-bottom: 0.75rem;
        }

        .payment-notes i {
            margin-right: 0.25rem;
        }

        /* Void Reason */
        .void-reason {
            background: #fef2f2;
            border-radius: 6px;
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            color: #991b1b;
            margin-top: 0.5rem;
        }

        /* Processed Info */
        .processed-info {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-top: 0.5rem;
        }

        .processed-info strong {
            color: #64748b;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
        }

        .empty-state-icon {
            width: 64px;
            height: 64px;
            background: #f1f5f9;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: #94a3b8;
            font-size: 1.5rem;
        }

        .empty-state h3 {
            font-size: 1rem;
            color: #475569;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            font-size: 0.875rem;
            color: #94a3b8;
            margin: 0;
        }

        /* Quick Add Button - Mobile FAB Style */
        .quick-add-btn {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 12px rgba(30, 58, 95, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            z-index: 1000;
            text-decoration: none;
            transition: all 0.3s;
        }

        .quick-add-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(30, 58, 95, 0.5);
            color: white;
        }

        @media (min-width: 768px) {
            .quick-add-btn {
                display: none;
            }
        }

        /* Desktop Enhancements */
        @media (min-width: 768px) {
            .stats-scroll {
                gap: 1rem;
            }

            .stat-chip {
                padding: 0.75rem 1.5rem;
                font-size: 0.9rem;
            }

            .payment-card {
                margin-bottom: 1rem;
            }

            .payment-card-header {
                padding: 1.25rem;
            }

            .donor-name {
                font-size: 1.1rem;
            }

            .payment-amount .amount {
                font-size: 1.5rem;
            }

            .action-buttons {
                justify-content: flex-end;
            }

            .action-btn {
                flex: 0 0 auto;
                padding: 0.5rem 1rem;
            }
        }

        /* Proof Modal */
        .proof-modal-img {
            max-height: 80vh;
            object-fit: contain;
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

        .loading-overlay.show {
            display: flex;
        }

        .loading-spinner {
            width: 48px;
            height: 48px;
            border: 4px solid #e2e8f0;
            border-top-color: #1e3a5f;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Toast Notifications */
        .toast-container {
            position: fixed;
            bottom: 5rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 9999;
        }

        .toast-msg {
            background: #1e293b;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-size: 0.875rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            animation: slideUp 0.3s ease;
        }

        .toast-msg.success {
            background: var(--review-approved);
        }

        .toast-msg.error {
            background: var(--review-rejected);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Pull to refresh hint */
        .refresh-hint {
            text-align: center;
            padding: 0.5rem;
            font-size: 0.75rem;
            color: #94a3b8;
        }
    </style>
</head>
<body class="review-page">
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                
                <!-- Page Header -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h1><i class="fas fa-check-double me-2"></i>Review Payments</h1>
                        <a href="record-pledge-payment.php" class="btn btn-light btn-sm d-none d-md-inline-flex">
                            <i class="fas fa-plus me-1"></i>Record Payment
                        </a>
                    </div>
                </div>

                <!-- Stats Chips -->
                <div class="stats-scroll mb-3">
                    <div class="stat-chip pending">
                        <i class="fas fa-clock text-warning"></i>
                        <span>Pending</span>
                        <span class="count"><?php echo $stats['pending']; ?></span>
                    </div>
                    <div class="stat-chip approved">
                        <i class="fas fa-check-circle text-success"></i>
                        <span>Approved</span>
                        <span class="count"><?php echo $stats['confirmed']; ?></span>
                    </div>
                    <div class="stat-chip rejected">
                        <i class="fas fa-times-circle text-danger"></i>
                        <span>Rejected</span>
                        <span class="count"><?php echo $stats['voided']; ?></span>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="search-container">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" 
                           id="searchInput" 
                           placeholder="Search by name, phone, or reference..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <?php if (!empty($search)): ?>
                        <button class="clear-btn" onclick="clearSearch()">
                            <i class="fas fa-times"></i>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Filter Chips -->
                <div class="filter-row mb-3">
                    <a href="?filter=pending<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                       class="filter-chip <?php echo $filter === 'pending' ? 'active' : ''; ?>">
                        <i class="fas fa-clock"></i>
                        Pending
                        <?php if ($stats['pending'] > 0): ?>
                            <span class="badge <?php echo $filter === 'pending' ? 'bg-light text-dark' : 'bg-warning text-dark'; ?>">
                                <?php echo $stats['pending']; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <a href="?filter=confirmed<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                       class="filter-chip <?php echo $filter === 'confirmed' ? 'active' : ''; ?>">
                        <i class="fas fa-check"></i>
                        Approved
                    </a>
                    <a href="?filter=voided<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                       class="filter-chip <?php echo $filter === 'voided' ? 'active' : ''; ?>">
                        <i class="fas fa-ban"></i>
                        Rejected
                    </a>
                    <a href="?filter=all<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                       class="filter-chip <?php echo $filter === 'all' ? 'active' : ''; ?>">
                        <i class="fas fa-list"></i>
                        All
                    </a>
                </div>

                <!-- Payments List -->
                <?php if (empty($payments)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <h3>No payments found</h3>
                        <p>
                            <?php if (!empty($search)): ?>
                                No results for "<?php echo htmlspecialchars($search); ?>"
                            <?php else: ?>
                                There are no <?php echo $filter === 'all' ? '' : $filter; ?> payments to review
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="refresh-hint d-md-none">
                        <i class="fas fa-sync-alt me-1"></i>Pull down to refresh
                    </div>
                    
                    <?php foreach ($payments as $p): ?>
                        <div class="payment-card status-<?php echo $p['status']; ?>">
                            <div class="payment-card-header">
                                <!-- Proof Thumbnail -->
                                <div class="flex-shrink-0">
                                    <?php if ($p['payment_proof']): ?>
                                        <?php 
                                        $ext = strtolower(pathinfo($p['payment_proof'], PATHINFO_EXTENSION));
                                        $is_pdf = ($ext === 'pdf');
                                        ?>
                                        <?php if ($is_pdf): ?>
                                            <a href="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" 
                                               target="_blank" 
                                               class="proof-pdf">
                                                <i class="fas fa-file-pdf"></i>
                                            </a>
                                        <?php else: ?>
                                            <img src="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" 
                                                 alt="Proof" 
                                                 class="proof-thumb"
                                                 onclick="viewProof('../../<?php echo htmlspecialchars($p['payment_proof']); ?>')">
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="proof-thumb-placeholder">
                                            <i class="fas fa-receipt"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Donor Info -->
                                <div class="donor-info">
                                    <h4 class="donor-name"><?php echo htmlspecialchars($p['donor_name'] ?? 'Unknown'); ?></h4>
                                    <p class="donor-phone">
                                        <i class="fas fa-phone fa-xs me-1"></i>
                                        <?php echo htmlspecialchars($p['donor_phone'] ?? '-'); ?>
                                    </p>
                                </div>

                                <!-- Amount & Status -->
                                <div class="payment-amount">
                                    <div class="amount">£<?php echo number_format((float)$p['amount'], 2); ?></div>
                                    <div class="method">
                                        <?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="payment-card-body">
                                <!-- Meta Tags -->
                                <div class="payment-meta">
                                    <span class="meta-tag">
                                        <i class="far fa-calendar"></i>
                                        <?php echo date('d M Y', strtotime($p['payment_date'])); ?>
                                    </span>
                                    
                                    <?php if ($p['reference_number']): ?>
                                        <span class="meta-tag">
                                            <i class="fas fa-hashtag"></i>
                                            <?php echo htmlspecialchars($p['reference_number']); ?>
                                        </span>
                                    <?php endif; ?>

                                    <span class="status-badge <?php echo $p['status']; ?>">
                                        <?php if ($p['status'] === 'pending'): ?>
                                            <i class="fas fa-clock"></i>
                                        <?php elseif ($p['status'] === 'confirmed'): ?>
                                            <i class="fas fa-check"></i>
                                        <?php else: ?>
                                            <i class="fas fa-ban"></i>
                                        <?php endif; ?>
                                        <?php echo ucfirst($p['status']); ?>
                                    </span>

                                    <?php 
                                    // Show payment plan info
                                    $show_plan_info = false;
                                    if ($has_plan_col && isset($p['plan_id']) && $p['plan_id']) {
                                        $show_plan_info = true;
                                    }
                                    ?>
                                    <?php if ($show_plan_info): ?>
                                        <span class="meta-tag plan-tag">
                                            <i class="fas fa-calendar-check"></i>
                                            Plan <?php echo ($p['plan_payments_made'] ?? 0); ?>/<?php echo $p['plan_total_payments'] ?? '?'; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Notes -->
                                <?php if ($p['notes']): ?>
                                    <div class="payment-notes">
                                        <i class="fas fa-sticky-note"></i>
                                        <?php echo htmlspecialchars($p['notes']); ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Processed Info -->
                                <div class="processed-info">
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

                                <!-- Action Buttons -->
                                <div class="action-buttons">
                                    <?php if ($p['status'] === 'pending'): ?>
                                        <button class="action-btn approve" onclick="approvePayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-check"></i>
                                            <span>Approve</span>
                                        </button>
                                        <button class="action-btn reject" onclick="voidPayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-times"></i>
                                            <span>Reject</span>
                                        </button>
                                    <?php elseif ($p['status'] === 'confirmed'): ?>
                                        <button class="action-btn undo" onclick="undoPayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-undo"></i>
                                            <span>Undo</span>
                                        </button>
                                        <a href="../donor-management/view-donor.php?id=<?php echo $p['donor_id']; ?>" class="action-btn view">
                                            <i class="fas fa-user"></i>
                                            <span>View Donor</span>
                                        </a>
                                    <?php else: ?>
                                        <a href="../donor-management/view-donor.php?id=<?php echo $p['donor_id']; ?>" class="action-btn view" style="flex: 1;">
                                            <i class="fas fa-user"></i>
                                            <span>View Donor Profile</span>
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

<!-- Quick Add FAB (Mobile Only) -->
<a href="record-pledge-payment.php" class="quick-add-btn d-md-none">
    <i class="fas fa-plus"></i>
</a>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
    <span>Processing...</span>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<!-- Image Viewer Modal -->
<div class="modal fade" id="proofModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title">Payment Proof</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-2">
                <img id="proofImage" src="" alt="Payment Proof" class="proof-modal-img img-fluid rounded">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// View proof image
function viewProof(src) {
    document.getElementById('proofImage').src = src;
    new bootstrap.Modal(document.getElementById('proofModal')).show();
}

// Show loading
function showLoading() {
    document.getElementById('loadingOverlay').classList.add('show');
}

// Hide loading
function hideLoading() {
    document.getElementById('loadingOverlay').classList.remove('show');
}

// Show toast
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast-msg ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

// Approve payment
function approvePayment(id) {
    if (!confirm('Approve this payment?\n\nThis will update the donor\'s balance and mark the payment as confirmed.')) return;
    
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
    .catch(err => {
        hideLoading();
        showToast('Network error. Please try again.', 'error');
    });
}

// Void/Reject payment
function voidPayment(id) {
    const reason = prompt('Why are you rejecting this payment?\n\n(Required for audit trail)');
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
            showToast('Payment rejected.', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Error: ' + res.message, 'error');
        }
    })
    .catch(err => {
        hideLoading();
        showToast('Network error. Please try again.', 'error');
    });
}

// Undo approved payment
function undoPayment(id) {
    if (!confirm('Undo this approved payment?\n\nWARNING: This will reverse all balance updates.')) return;
    
    const reason = prompt('Why are you undoing this payment?\n\n(Required for audit trail)');
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
            showToast('Payment undone.', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Error: ' + res.message, 'error');
        }
    })
    .catch(err => {
        hideLoading();
        showToast('Network error. Please try again.', 'error');
    });
}

// Search functionality
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

// Pull to refresh (basic implementation)
let touchStart = 0;
let touchEnd = 0;

document.addEventListener('touchstart', e => {
    touchStart = e.changedTouches[0].screenY;
});

document.addEventListener('touchend', e => {
    touchEnd = e.changedTouches[0].screenY;
    if (touchEnd > touchStart + 100 && window.scrollY === 0) {
        location.reload();
    }
});
</script>
</body>
</html>
