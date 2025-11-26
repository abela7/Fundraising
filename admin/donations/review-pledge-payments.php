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
        /* ========== Page Layout ========== */
        .review-page {
            min-height: 100vh;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        .review-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }
        
        /* ========== Page Title ========== */
        .page-title-bar {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: white;
            padding: 1rem 1.25rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            box-shadow: 0 4px 15px rgba(13, 110, 253, 0.3);
        }
        
        .page-title-bar h1 {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0;
        }
        
        .page-title-bar .btn {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 8px;
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            transition: all 0.2s;
        }
        
        .page-title-bar .btn:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        
        /* ========== Stats Cards ========== */
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
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-left: 4px solid;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card:active {
            transform: scale(0.98);
        }
        
        .stat-card.pending { border-left-color: #ffc107; }
        .stat-card.approved { border-left-color: #198754; }
        .stat-card.rejected { border-left-color: #dc3545; }
        
        .stat-card .stat-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }
        
        .stat-card.pending .stat-icon { background: rgba(255, 193, 7, 0.15); color: #ffc107; }
        .stat-card.approved .stat-icon { background: rgba(25, 135, 84, 0.15); color: #198754; }
        .stat-card.rejected .stat-icon { background: rgba(220, 53, 69, 0.15); color: #dc3545; }
        
        .stat-card .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.25rem;
        }
        
        .stat-card.pending .stat-value { color: #ffc107; }
        .stat-card.approved .stat-value { color: #198754; }
        .stat-card.rejected .stat-value { color: #dc3545; }
        
        .stat-card .stat-label {
            font-size: 0.7rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        /* ========== Filter Tabs ========== */
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        
        .filter-tabs::-webkit-scrollbar { display: none; }
        
        .filter-tab {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1rem;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 20px;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #495057;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        
        .filter-tab:hover {
            background: #f8f9fa;
            color: #212529;
        }
        
        .filter-tab.active {
            background: #0d6efd;
            border-color: #0d6efd;
            color: white;
        }
        
        .filter-tab .badge {
            font-size: 0.6875rem;
            padding: 0.25rem 0.5rem;
            border-radius: 10px;
        }
        
        .filter-tab.active .badge {
            background: rgba(255,255,255,0.25) !important;
            color: white !important;
        }
        
        /* ========== Payment Cards ========== */
        .payment-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .payment-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .payment-card:active {
            transform: scale(0.995);
        }
        
        .payment-card.pending-card { border-left: 4px solid #ffc107; }
        .payment-card.approved-card { border-left: 4px solid #198754; }
        .payment-card.rejected-card { border-left: 4px solid #dc3545; }
        
        .payment-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .donor-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex: 1;
            min-width: 0;
        }
        
        .donor-avatar {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        
        .donor-details {
            min-width: 0;
            flex: 1;
        }
        
        .donor-name {
            font-size: 0.9375rem;
            font-weight: 700;
            color: #212529;
            margin: 0 0 0.125rem 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .donor-phone {
            font-size: 0.75rem;
            color: #6c757d;
            margin: 0;
        }
        
        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        
        .status-badge.pending {
            background: rgba(255, 193, 7, 0.15);
            color: #997404;
        }
        
        .status-badge.approved {
            background: rgba(25, 135, 84, 0.15);
            color: #146c43;
        }
        
        .status-badge.rejected {
            background: rgba(220, 53, 69, 0.15);
            color: #b02a37;
        }
        
        .payment-card-body {
            padding: 1rem;
        }
        
        .payment-amount-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        
        .payment-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: #198754;
        }
        
        .payment-amount .currency {
            font-size: 1rem;
            font-weight: 600;
            margin-right: 0.125rem;
        }
        
        .proof-thumbnail {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
            cursor: pointer;
            border: 2px solid #e9ecef;
            transition: transform 0.2s, border-color 0.2s;
        }
        
        .proof-thumbnail:hover {
            border-color: #0d6efd;
            transform: scale(1.05);
        }
        
        .proof-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #adb5bd;
            font-size: 1rem;
        }
        
        .pdf-proof {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: rgba(220, 53, 69, 0.1);
            border: 2px solid rgba(220, 53, 69, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #dc3545;
            font-size: 1.25rem;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .pdf-proof:hover {
            background: rgba(220, 53, 69, 0.2);
            border-color: #dc3545;
        }
        
        .payment-meta {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .meta-item {
            display: flex;
            flex-direction: column;
            gap: 0.125rem;
        }
        
        .meta-label {
            font-size: 0.6875rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .meta-value {
            font-size: 0.8125rem;
            font-weight: 600;
            color: #212529;
        }
        
        .meta-value i {
            margin-right: 0.25rem;
            opacity: 0.7;
        }
        
        .payment-plan-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.625rem;
            background: rgba(13, 202, 240, 0.15);
            color: #087990;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }
        
        .payment-notes {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1rem;
            font-size: 0.8125rem;
            color: #495057;
        }
        
        .payment-notes strong {
            color: #212529;
        }
        
        .payment-card-footer {
            background: #f8f9fa;
            padding: 0.75rem 1rem;
            border-top: 1px solid #e9ecef;
        }
        
        .audit-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            font-size: 0.75rem;
            color: #6c757d;
        }
        
        .audit-info strong {
            color: #495057;
        }
        
        .audit-info .success { color: #198754; }
        .audit-info .danger { color: #dc3545; }
        
        .payment-actions {
            display: flex;
            gap: 0.5rem;
            padding: 1rem;
            background: white;
            border-top: 1px solid #e9ecef;
        }
        
        .payment-actions .btn {
            flex: 1;
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .btn-approve {
            background: linear-gradient(135deg, #198754 0%, #146c43 100%);
            border: none;
            color: white;
            box-shadow: 0 2px 8px rgba(25, 135, 84, 0.3);
        }
        
        .btn-approve:hover {
            background: linear-gradient(135deg, #146c43 0%, #0f5132 100%);
            color: white;
        }
        
        .btn-reject {
            background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%);
            border: none;
            color: white;
            box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
        }
        
        .btn-reject:hover {
            background: linear-gradient(135deg, #b02a37 0%, #9a2530 100%);
            color: white;
        }
        
        .btn-undo {
            background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%);
            border: none;
            color: white;
            box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
        }
        
        .btn-undo:hover {
            background: linear-gradient(135deg, #b02a37 0%, #9a2530 100%);
            color: white;
        }
        
        .btn-view-donor {
            background: white;
            border: 2px solid #0d6efd;
            color: #0d6efd;
        }
        
        .btn-view-donor:hover {
            background: #0d6efd;
            color: white;
        }
        
        /* ========== Empty State ========== */
        .empty-state {
            background: white;
            border-radius: 12px;
            padding: 3rem 2rem;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .empty-state-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
            color: #adb5bd;
        }
        
        .empty-state h5 {
            font-size: 1rem;
            font-weight: 700;
            color: #495057;
            margin-bottom: 0.25rem;
        }
        
        .empty-state p {
            font-size: 0.875rem;
            color: #6c757d;
            margin: 0;
        }
        
        /* ========== Void Reason ========== */
        .void-reason {
            background: rgba(220, 53, 69, 0.1);
            border-radius: 8px;
            padding: 0.75rem;
            margin-top: 0.75rem;
            font-size: 0.8125rem;
            color: #b02a37;
        }
        
        .void-reason strong {
            display: block;
            margin-bottom: 0.25rem;
        }
        
        /* ========== Responsive Adjustments ========== */
        @media (min-width: 576px) {
            .review-container {
                padding: 1.5rem;
            }
            
            .page-title-bar {
                padding: 1.25rem 1.5rem;
            }
            
            .page-title-bar h1 {
                font-size: 1.5rem;
            }
            
            .stat-card {
                padding: 1.25rem;
            }
            
            .stat-card .stat-value {
                font-size: 1.75rem;
            }
            
            .stat-card .stat-label {
                font-size: 0.75rem;
            }
            
            .payment-card-header {
                padding: 1.25rem;
            }
            
            .payment-card-body {
                padding: 1.25rem;
            }
            
            .payment-meta {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        @media (min-width: 768px) {
            .review-container {
                padding: 2rem;
            }
            
            .stats-row {
                gap: 1rem;
            }
            
            .filter-tabs {
                gap: 0.75rem;
            }
            
            .payment-card {
                border-left-width: 5px;
            }
            
            .donor-avatar {
                width: 52px;
                height: 52px;
                font-size: 1.125rem;
            }
            
            .donor-name {
                font-size: 1.0625rem;
            }
            
            .payment-amount {
                font-size: 1.75rem;
            }
        }
        
        @media (min-width: 992px) {
            .payment-card-content {
                display: flex;
                gap: 1.5rem;
            }
            
            .payment-card-main {
                flex: 1;
            }
            
            .payment-card-actions-desktop {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
                min-width: 160px;
            }
            
            .payment-card-actions-desktop .btn {
                padding: 0.625rem 1rem;
                font-size: 0.875rem;
                font-weight: 600;
                border-radius: 8px;
            }
            
            .payment-actions-mobile {
                display: none;
            }
        }
        
        @media (max-width: 991px) {
            .payment-card-actions-desktop {
                display: none;
            }
        }
        
        /* ========== Icon Visibility Fix ========== */
        .fa, .fas, .far, .fal, .fab {
            display: inline-block;
            font-style: normal;
            font-variant: normal;
            text-rendering: auto;
            -webkit-font-smoothing: antialiased;
        }
        
        /* ========== Modal Enhancement ========== */
        #proofModal .modal-content {
            border-radius: 12px;
            border: none;
        }
        
        #proofModal .modal-header {
            border-bottom: 1px solid #e9ecef;
            padding: 1rem 1.25rem;
        }
        
        #proofModal .modal-title {
            font-weight: 700;
        }
        
        #proofModal .modal-body {
            padding: 1.5rem;
        }
        
        #proofModal .modal-body img {
            border-radius: 8px;
        }
    </style>
</head>
<body class="review-page">
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <main class="main-content" style="padding-top: 0;">
            <div class="review-container">
                <!-- Page Title Bar -->
                <div class="page-title-bar d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2">
                    <h1>
                        <i class="fas fa-check-double me-2"></i>Review Payments
                    </h1>
                    <a href="record-pledge-payment.php" class="btn">
                        <i class="fas fa-plus me-1"></i>Record Payment
                    </a>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-row">
                    <div class="stat-card pending">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['pending']; ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <div class="stat-card approved">
                        <div class="stat-icon">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['confirmed']; ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                    <div class="stat-card rejected">
                        <div class="stat-icon">
                            <i class="fas fa-ban"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['voided']; ?></div>
                        <div class="stat-label">Rejected</div>
                    </div>
                </div>

                <!-- Filter Tabs -->
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
                    <div class="payment-list">
                        <?php foreach ($payments as $p): ?>
                            <?php 
                            // Get donor initials
                            $donor_name = $p['donor_name'] ?? 'Unknown';
                            $initials = '';
                            $name_parts = explode(' ', $donor_name);
                            foreach ($name_parts as $part) {
                                $initials .= mb_substr($part, 0, 1);
                            }
                            $initials = mb_strtoupper(mb_substr($initials, 0, 2));
                            
                            // Method icons
                            $method_icons = [
                                'cash' => 'money-bill-wave',
                                'bank_transfer' => 'university',
                                'card' => 'credit-card',
                                'cheque' => 'file-invoice-dollar',
                                'other' => 'hand-holding-usd'
                            ];
                            $method_icon = $method_icons[$p['payment_method']] ?? 'money-bill';
                            
                            // Payment plan info
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
                            ?>
                            <div class="payment-card <?php 
                                if ($p['status'] === 'confirmed') echo 'approved-card';
                                elseif ($p['status'] === 'pending') echo 'pending-card';
                                elseif ($p['status'] === 'voided') echo 'rejected-card';
                            ?>">
                                <!-- Card Header -->
                                <div class="payment-card-header">
                                    <div class="donor-info">
                                        <div class="donor-avatar"><?php echo $initials; ?></div>
                                        <div class="donor-details">
                                            <h6 class="donor-name"><?php echo htmlspecialchars($donor_name); ?></h6>
                                            <p class="donor-phone">
                                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($p['donor_phone'] ?? '-'); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <span class="status-badge <?php 
                                        if ($p['status'] === 'confirmed') echo 'approved';
                                        elseif ($p['status'] === 'pending') echo 'pending';
                                        elseif ($p['status'] === 'voided') echo 'rejected';
                                    ?>">
                                        <?php if ($p['status'] === 'pending'): ?>
                                            <i class="fas fa-clock me-1"></i>Pending
                                        <?php elseif ($p['status'] === 'confirmed'): ?>
                                            <i class="fas fa-check me-1"></i>Approved
                                        <?php elseif ($p['status'] === 'voided'): ?>
                                            <i class="fas fa-ban me-1"></i>Rejected
                                        <?php endif; ?>
                                    </span>
                                </div>
                                
                                <!-- Card Body -->
                                <div class="payment-card-body">
                                    <div class="payment-card-content">
                                        <div class="payment-card-main">
                                            <!-- Amount and Proof Row -->
                                            <div class="payment-amount-row">
                                                <div class="payment-amount">
                                                    <span class="currency">£</span><?php echo number_format((float)$p['amount'], 2); ?>
                                                </div>
                                                <?php if ($p['payment_proof']): ?>
                                                    <?php 
                                                    $ext = strtolower(pathinfo($p['payment_proof'], PATHINFO_EXTENSION));
                                                    $is_pdf = ($ext === 'pdf');
                                                    ?>
                                                    <?php if ($is_pdf): ?>
                                                        <a href="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" target="_blank" class="pdf-proof">
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
                                            </div>
                                            
                                            <!-- Payment Plan Badge -->
                                            <?php if ($show_plan_info): ?>
                                                <div class="payment-plan-badge">
                                                    <i class="fas fa-calendar-check"></i>
                                                    <?php if ($plan_installment !== '?'): ?>
                                                        Installment <?php echo $plan_installment; ?> of <?php echo $p['plan_total_payments'] ?? '?'; ?>
                                                    <?php else: ?>
                                                        Plan Payment
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <!-- Payment Meta -->
                                            <div class="payment-meta">
                                                <div class="meta-item">
                                                    <span class="meta-label">Method</span>
                                                    <span class="meta-value">
                                                        <i class="fas fa-<?php echo $method_icon; ?> text-info"></i>
                                                        <?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?>
                                                    </span>
                                                </div>
                                                <div class="meta-item">
                                                    <span class="meta-label">Date</span>
                                                    <span class="meta-value">
                                                        <i class="far fa-calendar text-secondary"></i>
                                                        <?php echo date('d M Y', strtotime($p['payment_date'])); ?>
                                                    </span>
                                                </div>
                                                <?php if ($p['reference_number']): ?>
                                                <div class="meta-item">
                                                    <span class="meta-label">Reference</span>
                                                    <span class="meta-value font-monospace">
                                                        #<?php echo htmlspecialchars($p['reference_number']); ?>
                                                    </span>
                                                </div>
                                                <?php endif; ?>
                                                <div class="meta-item">
                                                    <span class="meta-label">Submitted</span>
                                                    <span class="meta-value">
                                                        <?php echo date('d M, H:i', strtotime($p['created_at'])); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <!-- Notes -->
                                            <?php if ($p['notes']): ?>
                                                <div class="payment-notes">
                                                    <i class="fas fa-sticky-note me-1 text-info"></i>
                                                    <strong>Notes:</strong> <?php echo htmlspecialchars($p['notes']); ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <!-- Void Reason -->
                                            <?php if ($p['status'] === 'voided' && $p['void_reason']): ?>
                                                <div class="void-reason">
                                                    <strong><i class="fas fa-info-circle me-1"></i>Rejection Reason:</strong>
                                                    <?php echo htmlspecialchars($p['void_reason']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Desktop Actions -->
                                        <div class="payment-card-actions-desktop">
                                            <?php if ($p['status'] === 'pending'): ?>
                                                <button class="btn btn-approve" onclick="approvePayment(<?php echo $p['id']; ?>)">
                                                    <i class="fas fa-check me-1"></i>Approve
                                                </button>
                                                <button class="btn btn-reject" onclick="voidPayment(<?php echo $p['id']; ?>)">
                                                    <i class="fas fa-times me-1"></i>Reject
                                                </button>
                                            <?php elseif ($p['status'] === 'confirmed'): ?>
                                                <button class="btn btn-undo" onclick="undoPayment(<?php echo $p['id']; ?>)">
                                                    <i class="fas fa-undo me-1"></i>Undo
                                                </button>
                                                <a href="../donor-management/view-donor.php?id=<?php echo $p['donor_id']; ?>" class="btn btn-view-donor">
                                                    <i class="fas fa-user me-1"></i>View Donor
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Card Footer - Audit Info -->
                                <div class="payment-card-footer">
                                    <div class="audit-info">
                                        <span>
                                            <i class="fas fa-user-plus me-1"></i>
                                            Submitted by <strong><?php echo htmlspecialchars($p['processed_by_name'] ?? 'Unknown'); ?></strong>
                                        </span>
                                        <?php if ($p['status'] === 'confirmed' && $p['approved_by_name']): ?>
                                            <span class="success">
                                                <i class="fas fa-check-circle me-1"></i>
                                                Approved by <strong><?php echo htmlspecialchars($p['approved_by_name']); ?></strong>
                                                <?php if ($p['approved_at']): ?>
                                                    on <?php echo date('d M Y, H:i', strtotime($p['approved_at'])); ?>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($p['status'] === 'voided' && $p['voided_by_name']): ?>
                                            <span class="danger">
                                                <i class="fas fa-times-circle me-1"></i>
                                                Rejected by <strong><?php echo htmlspecialchars($p['voided_by_name']); ?></strong>
                                                <?php if ($p['voided_at']): ?>
                                                    on <?php echo date('d M Y, H:i', strtotime($p['voided_at'])); ?>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Mobile Actions -->
                                <?php if ($p['status'] === 'pending'): ?>
                                    <div class="payment-actions payment-actions-mobile">
                                        <button class="btn btn-approve" onclick="approvePayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-check"></i>Approve
                                        </button>
                                        <button class="btn btn-reject" onclick="voidPayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-times"></i>Reject
                                        </button>
                                    </div>
                                <?php elseif ($p['status'] === 'confirmed'): ?>
                                    <div class="payment-actions payment-actions-mobile">
                                        <button class="btn btn-undo" onclick="undoPayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-undo"></i>Undo Payment
                                        </button>
                                        <a href="../donor-management/view-donor.php?id=<?php echo $p['donor_id']; ?>" class="btn btn-view-donor">
                                            <i class="fas fa-user"></i>View Donor
                                        </a>
                                    </div>
                                <?php endif; ?>
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

