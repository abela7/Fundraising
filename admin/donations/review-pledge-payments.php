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

$db = db();

// Check if table exists
$check = $db->query("SHOW TABLES LIKE 'pledge_payments'");
if ($check->num_rows === 0) {
    die("<div class='alert alert-danger m-4'>Error: Table 'pledge_payments' not found.</div>");
}

// Get filter (status)
$filter = $_GET['filter'] ?? 'pending';
$valid_filters = ['pending', 'confirmed', 'voided', 'all'];
if (!in_array($filter, $valid_filters)) {
    $filter = 'pending';
}

// Pagination settings
$per_page = 15;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Search
$search = trim($_GET['search'] ?? '');

// Build WHERE clause
$where_conditions = [];
$params = [];
$types = '';

if ($filter === 'pending') {
    $where_conditions[] = "pp.status = 'pending'";
} elseif ($filter === 'confirmed') {
    $where_conditions[] = "pp.status = 'confirmed'";
} elseif ($filter === 'voided') {
    $where_conditions[] = "pp.status = 'voided'";
}

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

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total
    FROM pledge_payments pp
    LEFT JOIN donors d ON pp.donor_id = d.id
    {$where_clause}
";

if (!empty($params)) {
    $stmt = $db->prepare($count_sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total_records = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
} else {
    $total_records = $db->query($count_sql)->fetch_assoc()['total'];
}

$total_pages = ceil($total_records / $per_page);

// Fetch payments with pagination
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
    LIMIT {$per_page} OFFSET {$offset}
";

$payments = [];
if (!empty($params)) {
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $payments[] = $row;
    }
    $stmt->close();
} else {
    $res = $db->query($sql);
    while ($row = $res->fetch_assoc()) {
        $payments[] = $row;
    }
}

// Count statistics
$stats = [
    'pending' => $db->query("SELECT COUNT(*) as c FROM pledge_payments WHERE status='pending'")->fetch_assoc()['c'],
    'confirmed' => $db->query("SELECT COUNT(*) as c FROM pledge_payments WHERE status='confirmed'")->fetch_assoc()['c'],
    'voided' => $db->query("SELECT COUNT(*) as c FROM pledge_payments WHERE status='voided'")->fetch_assoc()['c']
];
$stats['all'] = $stats['pending'] + $stats['confirmed'] + $stats['voided'];

// Page titles based on filter
$page_titles = [
    'pending' => 'Pending Payments',
    'confirmed' => 'Approved Payments',
    'voided' => 'Rejected Payments',
    'all' => 'All Payments'
];
$page_title = $page_titles[$filter] ?? 'Review Payments';

// Build pagination URL
function build_url($params) {
    $current = $_GET;
    foreach ($params as $key => $value) {
        if ($value === null) {
            unset($current[$key]);
        } else {
            $current[$key] = $value;
        }
    }
    return '?' . http_build_query($current);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Payment Review</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        :root {
            --color-pending: #f59e0b;
            --color-approved: #10b981;
            --color-rejected: #ef4444;
            --color-all: #6366f1;
        }
        
        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #0f172a 100%);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            color: white;
        }
        .page-header h1 {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
        }
        
        /* Stats Row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .stat-mini {
            background: white;
            border-radius: 10px;
            padding: 0.875rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border-left: 3px solid;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
        }
        .stat-mini:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
            color: inherit;
        }
        .stat-mini.active {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }
        .stat-mini.pending { border-left-color: var(--color-pending); }
        .stat-mini.approved { border-left-color: var(--color-approved); }
        .stat-mini.rejected { border-left-color: var(--color-rejected); }
        .stat-mini.all { border-left-color: var(--color-all); }
        .stat-mini .stat-count {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1;
        }
        .stat-mini.pending .stat-count { color: var(--color-pending); }
        .stat-mini.approved .stat-count { color: var(--color-approved); }
        .stat-mini.rejected .stat-count { color: var(--color-rejected); }
        .stat-mini.all .stat-count { color: var(--color-all); }
        .stat-mini .stat-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-top: 0.25rem;
            font-weight: 600;
        }
        
        /* Search Bar */
        .search-bar {
            background: white;
            border-radius: 10px;
            padding: 0.875rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .search-bar .form-control {
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            padding: 0.625rem 1rem;
            font-size: 0.875rem;
        }
        .search-bar .form-control:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        .search-bar .btn {
            border-radius: 8px;
            padding: 0.625rem 1rem;
        }
        
        /* Payment Cards */
        .payment-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .payment-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: all 0.2s ease;
        }
        .payment-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        .payment-card.status-pending { border-left: 4px solid var(--color-pending); }
        .payment-card.status-confirmed { border-left: 4px solid var(--color-approved); }
        .payment-card.status-voided { border-left: 4px solid var(--color-rejected); }
        
        .card-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .donor-info h5 {
            font-size: 1rem;
            font-weight: 600;
            margin: 0 0 0.25rem;
            color: #1e293b;
        }
        .donor-info .phone {
            font-size: 0.8rem;
            color: #64748b;
        }
        .amount-badge {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--color-approved);
            background: #ecfdf5;
            padding: 0.375rem 0.75rem;
            border-radius: 8px;
        }
        
        .card-body-content {
            padding: 1rem;
        }
        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        .detail-item .label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #94a3b8;
            margin-bottom: 0.125rem;
        }
        .detail-item .value {
            font-size: 0.875rem;
            font-weight: 500;
            color: #334155;
        }
        .detail-item .value i {
            margin-right: 0.375rem;
            width: 14px;
            text-align: center;
        }
        
        /* Payment Plan Badge */
        .plan-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
            padding: 0.375rem 0.625rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }
        
        /* Notes Section */
        .notes-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 0.625rem 0.75rem;
            margin-top: 0.75rem;
            font-size: 0.8rem;
            color: #475569;
        }
        .notes-section i { margin-right: 0.375rem; color: #94a3b8; }
        
        /* Processing Info */
        .processing-info {
            background: #f8fafc;
            padding: 0.625rem 0.875rem;
            font-size: 0.75rem;
            color: #64748b;
            border-top: 1px solid #f1f5f9;
        }
        .processing-info .info-row {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            padding: 0.375rem 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        .processing-info .info-row:last-child { 
            margin-bottom: 0; 
            border-bottom: none;
        }
        .processing-info .info-row i { 
            width: 18px; 
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 0.625rem;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .processing-info .info-row i.fa-user-plus {
            background: #e0e7ff;
            color: #4f46e5;
        }
        .processing-info .info-row i.fa-check-circle {
            background: #d1fae5;
            color: #059669;
        }
        .processing-info .info-row i.fa-times-circle {
            background: #fee2e2;
            color: #dc2626;
        }
        .processing-info .info-content {
            flex: 1;
            min-width: 0;
        }
        .processing-info .info-action {
            font-weight: 500;
            color: #334155;
        }
        .processing-info .info-user {
            font-weight: 600;
            color: #1e293b;
        }
        .processing-info .info-time {
            display: block;
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 2px;
        }
        .processing-info .text-success .info-action { color: var(--color-approved); }
        .processing-info .text-danger .info-action { color: var(--color-rejected); }
        
        @media (min-width: 576px) {
            .processing-info .info-time {
                display: inline;
                margin-top: 0;
                margin-left: 0.25rem;
            }
            .processing-info .info-time::before {
                content: "•";
                margin-right: 0.25rem;
            }
        }
        
        /* Action Buttons */
        .card-actions {
            display: flex;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: #fafafa;
            border-top: 1px solid #f1f5f9;
        }
        .card-actions .btn {
            flex: 1;
            padding: 0.5rem;
            font-size: 0.8rem;
            font-weight: 500;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
        }
        .btn-approve {
            background: var(--color-approved);
            color: white;
            border: none;
        }
        .btn-approve:hover {
            background: #059669;
            color: white;
        }
        .btn-reject {
            background: var(--color-rejected);
            color: white;
            border: none;
        }
        .btn-reject:hover {
            background: #dc2626;
            color: white;
        }
        .btn-undo {
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            color: white;
            border: none;
        }
        .btn-undo:hover {
            background: linear-gradient(135deg, #ea580c 0%, #c2410c 100%);
            color: white;
        }
        .btn-view {
            background: #e2e8f0;
            color: #475569;
            border: none;
        }
        .btn-view:hover {
            background: #cbd5e1;
            color: #1e293b;
        }
        
        /* Proof Thumbnail */
        .proof-thumb {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
            cursor: pointer;
            border: 2px solid #e2e8f0;
            transition: all 0.2s;
        }
        .proof-thumb:hover {
            border-color: #6366f1;
            transform: scale(1.05);
        }
        .proof-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
        }
        
        /* Status Badge */
        .status-badge-inline {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-badge-inline.pending {
            background: #fef3c7;
            color: #92400e;
        }
        .status-badge-inline.confirmed {
            background: #d1fae5;
            color: #065f46;
        }
        .status-badge-inline.voided {
            background: #fee2e2;
            color: #991b1b;
        }
        
        /* Void Reason */
        .void-reason {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            color: #991b1b;
            margin-top: 0.5rem;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .empty-state i {
            font-size: 3rem;
            color: #e2e8f0;
            margin-bottom: 1rem;
        }
        .empty-state h5 {
            color: #64748b;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        .empty-state p {
            color: #94a3b8;
            font-size: 0.875rem;
            margin: 0;
        }
        
        /* Pagination */
        .pagination-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            margin-top: 0.5rem;
        }
        .pagination-wrapper .page-info {
            font-size: 0.8rem;
            color: #64748b;
            margin: 0 0.5rem;
        }
        .pagination-wrapper .btn {
            padding: 0.5rem 0.875rem;
            font-size: 0.8rem;
            border-radius: 8px;
        }
        .pagination-wrapper .btn-page {
            background: white;
            border: 1px solid #e2e8f0;
            color: #475569;
        }
        .pagination-wrapper .btn-page:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        .pagination-wrapper .btn-page.active {
            background: #6366f1;
            border-color: #6366f1;
            color: white;
        }
        .pagination-wrapper .btn-page:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Results Info */
        .results-info {
            background: white;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            margin-bottom: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .results-info .count {
            font-size: 0.875rem;
            color: #64748b;
        }
        .results-info .count strong {
            color: #1e293b;
        }
        .results-info .btn {
            font-size: 0.75rem;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
        }
        
        /* Mobile Responsive */
        @media (max-width: 576px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            .stat-mini {
                padding: 0.75rem 0.5rem;
            }
            .stat-mini .stat-count {
                font-size: 1.25rem;
            }
            .stat-mini .stat-label {
                font-size: 0.65rem;
            }
            .page-header {
                padding: 1rem;
            }
            .page-header h1 {
                font-size: 1.125rem;
            }
            .details-grid {
                grid-template-columns: 1fr;
            }
            .card-actions {
                flex-direction: column;
            }
            .card-actions .btn {
                width: 100%;
            }
            .pagination-wrapper {
                flex-wrap: wrap;
            }
        }
        
        @media (min-width: 768px) {
            .details-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            .card-actions .btn {
                flex: 0 0 auto;
                min-width: 100px;
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
            <div class="container-fluid p-2 p-md-4">
                
                <!-- Page Header -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h1>
                            <i class="fas fa-receipt me-2"></i><?php echo $page_title; ?>
                        </h1>
                        <a href="record-pledge-payment.php" class="btn btn-light btn-sm">
                            <i class="fas fa-plus me-1"></i><span class="d-none d-sm-inline">Record Payment</span>
                        </a>
                    </div>
                </div>
                
                <!-- Stats Row (Clickable Tab Navigation) -->
                <div class="stats-row">
                    <a href="?filter=pending" class="stat-mini pending <?php echo $filter === 'pending' ? 'active' : ''; ?>">
                        <div class="stat-count"><?php echo $stats['pending']; ?></div>
                        <div class="stat-label"><i class="fas fa-clock"></i> Pending</div>
                    </a>
                    <a href="?filter=confirmed" class="stat-mini approved <?php echo $filter === 'confirmed' ? 'active' : ''; ?>">
                        <div class="stat-count"><?php echo $stats['confirmed']; ?></div>
                        <div class="stat-label"><i class="fas fa-check"></i> Approved</div>
                    </a>
                    <a href="?filter=voided" class="stat-mini rejected <?php echo $filter === 'voided' ? 'active' : ''; ?>">
                        <div class="stat-count"><?php echo $stats['voided']; ?></div>
                        <div class="stat-label"><i class="fas fa-ban"></i> Rejected</div>
                    </a>
                    <a href="?filter=all" class="stat-mini all <?php echo $filter === 'all' ? 'active' : ''; ?>">
                        <div class="stat-count"><?php echo $stats['all']; ?></div>
                        <div class="stat-label"><i class="fas fa-list"></i> All</div>
                    </a>
                </div>
                
                <!-- Search Bar -->
                <div class="search-bar">
                    <form method="get" class="d-flex gap-2">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        <div class="flex-grow-1">
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Search by name, phone, or reference..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                        </button>
                        <?php if (!empty($search)): ?>
                            <a href="?filter=<?php echo $filter; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
                
                <!-- Results Info -->
                <?php if ($total_records > 0): ?>
                    <div class="results-info">
                        <span class="count">
                            Showing <strong><?php echo min($offset + 1, $total_records); ?>-<?php echo min($offset + $per_page, $total_records); ?></strong> 
                            of <strong><?php echo $total_records; ?></strong> payments
                        </span>
                        <?php if (!empty($search)): ?>
                            <span class="badge bg-primary">
                                Search: "<?php echo htmlspecialchars($search); ?>"
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Payments List -->
                <?php if (empty($payments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h5>No payments found</h5>
                        <p>
                            <?php if (!empty($search)): ?>
                                No results match your search "<?php echo htmlspecialchars($search); ?>".
                            <?php else: ?>
                                There are no <?php echo $filter === 'all' ? '' : $filter; ?> payments to display.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="payment-list">
                        <?php foreach ($payments as $p): ?>
                            <div class="payment-card status-<?php echo $p['status']; ?>">
                                <!-- Card Header -->
                                <div class="card-header-row">
                                    <div class="d-flex align-items-start gap-3">
                                        <!-- Proof Thumbnail -->
                                        <?php if ($p['payment_proof']): ?>
                                            <?php 
                                            $ext = strtolower(pathinfo($p['payment_proof'], PATHINFO_EXTENSION));
                                            $is_pdf = ($ext === 'pdf');
                                            ?>
                                            <?php if ($is_pdf): ?>
                                                <a href="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" target="_blank" class="proof-placeholder" style="background: #fee2e2; color: #dc2626;">
                                                    <i class="fas fa-file-pdf"></i>
                                                </a>
                                            <?php else: ?>
                                                <img src="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" 
                                                     alt="Proof" 
                                                     class="proof-thumb"
                                                     onclick="viewProof('../../<?php echo htmlspecialchars($p['payment_proof']); ?>')">
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="proof-placeholder">
                                                <i class="fas fa-receipt"></i>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="donor-info">
                                            <h5><?php echo htmlspecialchars($p['donor_name'] ?? 'Unknown Donor'); ?></h5>
                                            <div class="phone">
                                                <i class="fas fa-phone"></i>
                                                <?php echo htmlspecialchars($p['donor_phone'] ?? '-'); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <div class="amount-badge">
                                            £<?php echo number_format((float)$p['amount'], 2); ?>
                                        </div>
                                        <?php if ($filter === 'all'): ?>
                                            <div class="mt-2">
                                                <span class="status-badge-inline <?php echo $p['status']; ?>">
                                                    <?php if ($p['status'] === 'pending'): ?>
                                                        <i class="fas fa-clock"></i> Pending
                                                    <?php elseif ($p['status'] === 'confirmed'): ?>
                                                        <i class="fas fa-check"></i> Approved
                                                    <?php else: ?>
                                                        <i class="fas fa-ban"></i> Rejected
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Card Body -->
                                <div class="card-body-content">
                                    <div class="details-grid">
                                        <div class="detail-item">
                                            <span class="label">Method</span>
                                            <span class="value">
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
                                                <i class="fas fa-<?php echo $icon; ?> text-info"></i>
                                                <?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?>
                                            </span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="label">Date</span>
                                            <span class="value">
                                                <i class="far fa-calendar text-secondary"></i>
                                                <?php echo date('d M Y', strtotime($p['payment_date'])); ?>
                                            </span>
                                        </div>
                                        <?php if ($p['reference_number']): ?>
                                        <div class="detail-item">
                                            <span class="label">Reference</span>
                                            <span class="value">
                                                <i class="fas fa-hashtag text-muted"></i>
                                                <?php echo htmlspecialchars($p['reference_number']); ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="detail-item">
                                            <span class="label">Pledge</span>
                                            <span class="value">
                                                <i class="fas fa-hand-holding-heart text-primary"></i>
                                                £<?php echo number_format((float)($p['pledge_amount'] ?? 0), 2); ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <?php 
                                    // Show payment plan info
                                    $show_plan_info = false;
                                    $plan_installment = null;
                                    if ($has_plan_col && isset($p['plan_id']) && $p['plan_id']) {
                                        $show_plan_info = true;
                                        $plan_installment = ($p['plan_payments_made'] ?? 0) + 1;
                                    }
                                    ?>
                                    <?php if ($show_plan_info): ?>
                                        <div class="plan-badge">
                                            <i class="fas fa-calendar-check"></i>
                                            Plan Payment: <?php echo $plan_installment; ?> of <?php echo $p['plan_total_payments'] ?? '?'; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($p['notes']): ?>
                                        <div class="notes-section">
                                            <i class="fas fa-sticky-note"></i>
                                            <?php echo htmlspecialchars($p['notes']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($p['status'] === 'voided' && $p['void_reason']): ?>
                                        <div class="void-reason">
                                            <i class="fas fa-exclamation-triangle me-1"></i>
                                            <strong>Rejection Reason:</strong> <?php echo htmlspecialchars($p['void_reason']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Processing Info -->
                                <div class="processing-info">
                                    <div class="info-row">
                                        <i class="fas fa-user-plus"></i>
                                        <div class="info-content">
                                            <span class="info-action">Submitted by</span>
                                            <span class="info-user"><?php echo htmlspecialchars($p['processed_by_name'] ?? 'System'); ?></span>
                                            <span class="info-time"><?php echo date('d M Y, H:i', strtotime($p['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <?php if ($p['status'] === 'confirmed' && $p['approved_by_name']): ?>
                                        <div class="info-row text-success">
                                            <i class="fas fa-check-circle"></i>
                                            <div class="info-content">
                                                <span class="info-action">Approved by</span>
                                                <span class="info-user"><?php echo htmlspecialchars($p['approved_by_name']); ?></span>
                                                <?php if ($p['approved_at']): ?>
                                                    <span class="info-time"><?php echo date('d M Y, H:i', strtotime($p['approved_at'])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($p['status'] === 'voided' && $p['voided_by_name']): ?>
                                        <div class="info-row text-danger">
                                            <i class="fas fa-times-circle"></i>
                                            <div class="info-content">
                                                <span class="info-action">Rejected by</span>
                                                <span class="info-user"><?php echo htmlspecialchars($p['voided_by_name']); ?></span>
                                                <?php if ($p['voided_at']): ?>
                                                    <span class="info-time"><?php echo date('d M Y, H:i', strtotime($p['voided_at'])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Actions -->
                                <div class="card-actions">
                                    <?php if ($p['status'] === 'pending'): ?>
                                        <button class="btn btn-approve" onclick="approvePayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-check"></i>
                                            <span>Approve</span>
                                        </button>
                                        <button class="btn btn-reject" onclick="voidPayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-times"></i>
                                            <span>Reject</span>
                                        </button>
                                    <?php elseif ($p['status'] === 'confirmed'): ?>
                                        <button class="btn btn-undo" onclick="undoPayment(<?php echo $p['id']; ?>)">
                                            <i class="fas fa-undo"></i>
                                            <span>Undo</span>
                                        </button>
                                        <a href="../donor-management/view-donor.php?id=<?php echo $p['donor_id']; ?>" class="btn btn-view">
                                            <i class="fas fa-user"></i>
                                            <span>View Donor</span>
                                        </a>
                                    <?php else: ?>
                                        <a href="../donor-management/view-donor.php?id=<?php echo $p['donor_id']; ?>" class="btn btn-view">
                                            <i class="fas fa-user"></i>
                                            <span>View Donor</span>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($p['payment_proof']): ?>
                                        <?php 
                                        $ext = strtolower(pathinfo($p['payment_proof'], PATHINFO_EXTENSION));
                                        $is_pdf = ($ext === 'pdf');
                                        ?>
                                        <?php if ($is_pdf): ?>
                                            <a href="../../<?php echo htmlspecialchars($p['payment_proof']); ?>" target="_blank" class="btn btn-view">
                                                <i class="fas fa-file-pdf"></i>
                                                <span>View PDF</span>
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-view" onclick="viewProof('../../<?php echo htmlspecialchars($p['payment_proof']); ?>')">
                                                <i class="fas fa-image"></i>
                                                <span>View Proof</span>
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-wrapper">
                            <a href="<?php echo build_url(['page' => 1]); ?>" 
                               class="btn btn-page <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="<?php echo build_url(['page' => max(1, $page - 1)]); ?>" 
                               class="btn btn-page <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <i class="fas fa-angle-left"></i>
                            </a>
                            
                            <span class="page-info">
                                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                            </span>
                            
                            <a href="<?php echo build_url(['page' => min($total_pages, $page + 1)]); ?>" 
                               class="btn btn-page <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="<?php echo build_url(['page' => $total_pages]); ?>" 
                               class="btn btn-page <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
            </div>
        </main>
    </div>
</div>

<!-- Image Viewer Modal -->
<div class="modal fade" id="proofModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title">Payment Proof</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-2">
                <img id="proofImage" src="" alt="Payment Proof" class="img-fluid rounded" style="max-height: 70vh;">
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
            alert('✓ Payment approved successfully!');
            location.reload();
        } else {
            alert('Error: ' + res.message);
        }
    })
    .catch(err => {
        alert('Network error. Please try again.');
        console.error(err);
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
    })
    .catch(err => {
        alert('Network error. Please try again.');
        console.error(err);
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
            alert('✓ Payment undone successfully.');
            location.reload();
        } else {
            alert('Error: ' + res.message);
        }
    })
    .catch(err => {
        alert('Network error. Please try again.');
        console.error(err);
    });
}
</script>
</body>
</html>
