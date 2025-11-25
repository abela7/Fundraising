<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

// Resiliently load settings and check for DB errors
require_once __DIR__ . '/../includes/resilient_db_loader.php';

$page_title = 'Payment Management';
$current_user = current_user();
$db = db();

$success_message = '';
$error_message = '';

// Check if pledge_payments table exists
$table_check = $db->query("SHOW TABLES LIKE 'pledge_payments'");
if ($table_check->num_rows === 0) {
    $error_message = "The pledge_payments table does not exist. Please ensure the database is properly set up.";
}

// Check if payment_plan_id column exists
$has_plan_col = false;
if (empty($error_message)) {
    $has_plan_col = $db->query("SHOW COLUMNS FROM pledge_payments LIKE 'payment_plan_id'")->num_rows > 0;
}

// Get filter parameters
$filter_status = $_GET['status'] ?? 'all';
$filter_method = $_GET['method'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$payments = [];
$stats = [
    'total' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'voided' => 0,
    'total_amount' => 0,
    'confirmed_amount' => 0,
    'pending_amount' => 0
];

if (empty($error_message)) {
    try {
        // Get statistics
        $stats_query = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN status = 'voided' THEN 1 ELSE 0 END) as voided,
                COALESCE(SUM(amount), 0) as total_amount,
                COALESCE(SUM(CASE WHEN status = 'confirmed' THEN amount ELSE 0 END), 0) as confirmed_amount,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_amount
            FROM pledge_payments
        ";
        $stats_result = $db->query($stats_query);
        if ($stats_result) {
            $stats = $stats_result->fetch_assoc();
        }
        
        // Build main query with filters
        $where_conditions = [];
        $params = [];
        $types = '';
        
        if ($filter_status !== 'all') {
            $where_conditions[] = "pp.status = ?";
            $params[] = $filter_status;
            $types .= 's';
        }
        
        if (!empty($filter_method)) {
            $where_conditions[] = "pp.payment_method = ?";
            $params[] = $filter_method;
            $types .= 's';
        }
        
        if (!empty($filter_date_from)) {
            $where_conditions[] = "DATE(pp.payment_date) >= ?";
            $params[] = $filter_date_from;
            $types .= 's';
        }
        
        if (!empty($filter_date_to)) {
            $where_conditions[] = "DATE(pp.payment_date) <= ?";
            $params[] = $filter_date_to;
            $types .= 's';
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
        
        $sql = "
            SELECT 
                pp.id,
                pp.pledge_id,
                pp.donor_id,
                pp.amount,
                pp.payment_method,
                pp.payment_date,
                pp.reference_number,
                pp.payment_proof,
                pp.notes,
                pp.status,
                pp.approved_at,
                pp.approved_by_user_id,
                pp.voided_at,
                pp.voided_by_user_id,
                pp.created_at,
                " . ($has_plan_col ? "pp.payment_plan_id," : "") . "
                d.id as donor_id,
                d.name as donor_name,
                d.phone as donor_phone,
                d.total_pledged,
                d.total_paid,
                d.balance,
                d.payment_status as donor_payment_status,
                d.has_active_plan,
                pl.amount as pledge_amount,
                approver.name as approved_by_name,
                voider.name as voided_by_name
                " . ($has_plan_col ? ",
                pplan.id as plan_id,
                pplan.monthly_amount as plan_monthly_amount,
                pplan.payments_made as plan_payments_made,
                pplan.total_payments as plan_total_payments,
                pplan.status as plan_status
                " : "") . "
            FROM pledge_payments pp
            LEFT JOIN donors d ON pp.donor_id = d.id
            LEFT JOIN pledges pl ON pp.pledge_id = pl.id
            LEFT JOIN users approver ON pp.approved_by_user_id = approver.id
            LEFT JOIN users voider ON pp.voided_by_user_id = voider.id
            " . ($has_plan_col ? "LEFT JOIN donor_payment_plans pplan ON pp.payment_plan_id = pplan.id" : "") . "
            {$where_clause}
            ORDER BY pp.created_at DESC
            LIMIT 500
        ";
        
        if (!empty($params)) {
            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $db->query($sql);
        }
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $payments[] = $row;
            }
        }
        
    } catch (Exception $e) {
        $error_message = "Error loading payments: " . $e->getMessage();
    }
}

// Get unique donors who have paid
$paying_donors_count = 0;
if (empty($error_message)) {
    $unique_donors = $db->query("SELECT COUNT(DISTINCT donor_id) as cnt FROM pledge_payments WHERE status = 'confirmed'");
    if ($unique_donors) {
        $paying_donors_count = (int)$unique_donors->fetch_assoc()['cnt'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/donor-management.css">
    <style>
        .payment-card {
            transition: all 0.2s ease;
            border-left: 4px solid transparent;
        }
        .payment-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .payment-card.status-pending {
            border-left-color: #ffc107;
        }
        .payment-card.status-confirmed {
            border-left-color: #198754;
        }
        .payment-card.status-voided {
            border-left-color: #dc3545;
        }
        .proof-thumbnail {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 6px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .proof-thumbnail:hover {
            transform: scale(1.1);
        }
        .filter-btn {
            border-radius: 20px;
            padding: 0.375rem 1rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }
        .filter-btn.active {
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .donor-link {
            color: inherit;
            text-decoration: none;
        }
        .donor-link:hover {
            color: #0d6efd;
        }
        .plan-badge {
            font-size: 0.7rem;
            padding: 0.2rem 0.4rem;
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 768px) {
            .filter-btn {
                font-size: 0.75rem;
                padding: 0.25rem 0.75rem;
            }
            .table td, .table th {
                padding: 0.5rem 0.35rem;
                font-size: 0.8rem;
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
                <?php include '../includes/db_error_banner.php'; ?>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Page Header -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
                    <div>
                        <h4 class="mb-1">
                            <i class="fas fa-money-bill-wave me-2 text-success"></i>Payment Management
                        </h4>
                        <p class="text-muted mb-0 small">Track and manage all donor payments</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="../donations/record-pledge-payment.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-1"></i>Record Payment
                        </a>
                        <a href="../donations/review-pledge-payments.php" class="btn btn-warning btn-sm">
                            <i class="fas fa-clock me-1"></i>Pending (<?php echo (int)$stats['pending']; ?>)
                        </a>
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3">
                        <div class="stat-card" style="color: #0a6286;">
                            <div class="stat-icon bg-primary">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?php echo number_format($paying_donors_count); ?></h3>
                                <p class="stat-label">Paying Donors</p>
                                <div class="stat-trend text-muted">
                                    <i class="fas fa-user-check"></i> Have paid
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 col-lg-3">
                        <div class="stat-card" style="color: #0d7f4d;">
                            <div class="stat-icon bg-success">
                                <i class="fas fa-check-double"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?php echo number_format((int)$stats['confirmed']); ?></h3>
                                <p class="stat-label">Confirmed</p>
                                <div class="stat-trend text-success">
                                    £<?php echo number_format((float)$stats['confirmed_amount'], 2); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 col-lg-3">
                        <div class="stat-card" style="color: #b88a1a;">
                            <div class="stat-icon bg-warning">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?php echo number_format((int)$stats['pending']); ?></h3>
                                <p class="stat-label">Pending</p>
                                <div class="stat-trend text-warning">
                                    £<?php echo number_format((float)$stats['pending_amount'], 2); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 col-lg-3">
                        <div class="stat-card" style="color: #6b7280;">
                            <div class="stat-icon bg-secondary">
                                <i class="fas fa-pound-sign"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value">£<?php echo number_format((float)$stats['total_amount'], 0); ?></h3>
                                <p class="stat-label">Total Amount</p>
                                <div class="stat-trend text-muted">
                                    <i class="fas fa-receipt"></i> <?php echo number_format((int)$stats['total']); ?> payments
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Payments Table Card -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h5 class="mb-0">
                                <i class="fas fa-list me-2 text-primary"></i>All Payments
                            </h5>
                            <div class="d-flex flex-wrap gap-2">
                                <!-- Quick Status Filters -->
                                <a href="?status=all" class="btn btn-sm filter-btn <?php echo $filter_status === 'all' ? 'btn-primary active' : 'btn-outline-secondary'; ?>">
                                    All
                                </a>
                                <a href="?status=confirmed" class="btn btn-sm filter-btn <?php echo $filter_status === 'confirmed' ? 'btn-success active' : 'btn-outline-success'; ?>">
                                    <i class="fas fa-check me-1"></i>Confirmed
                                </a>
                                <a href="?status=pending" class="btn btn-sm filter-btn <?php echo $filter_status === 'pending' ? 'btn-warning active' : 'btn-outline-warning'; ?>">
                                    <i class="fas fa-clock me-1"></i>Pending
                                </a>
                                <a href="?status=voided" class="btn btn-sm filter-btn <?php echo $filter_status === 'voided' ? 'btn-danger active' : 'btn-outline-danger'; ?>">
                                    <i class="fas fa-ban me-1"></i>Voided
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Advanced Filters -->
                    <div class="card-body border-bottom bg-light py-3">
                        <form method="GET" class="row g-2 align-items-end">
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                            
                            <div class="col-12 col-sm-6 col-lg-3">
                                <label class="form-label small fw-bold mb-1">
                                    <i class="fas fa-search me-1"></i>Search
                                </label>
                                <input type="text" class="form-control form-control-sm" name="search" 
                                       placeholder="Name, phone, or reference..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            
                            <div class="col-6 col-sm-3 col-lg-2">
                                <label class="form-label small fw-bold mb-1">
                                    <i class="fas fa-credit-card me-1"></i>Method
                                </label>
                                <select class="form-select form-select-sm" name="method">
                                    <option value="">All Methods</option>
                                    <option value="bank_transfer" <?php echo $filter_method === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                    <option value="cash" <?php echo $filter_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                    <option value="card" <?php echo $filter_method === 'card' ? 'selected' : ''; ?>>Card</option>
                                </select>
                            </div>
                            
                            <div class="col-6 col-sm-3 col-lg-2">
                                <label class="form-label small fw-bold mb-1">
                                    <i class="fas fa-calendar me-1"></i>From
                                </label>
                                <input type="date" class="form-control form-control-sm" name="date_from" 
                                       value="<?php echo htmlspecialchars($filter_date_from); ?>">
                            </div>
                            
                            <div class="col-6 col-sm-3 col-lg-2">
                                <label class="form-label small fw-bold mb-1">
                                    <i class="fas fa-calendar me-1"></i>To
                                </label>
                                <input type="date" class="form-control form-control-sm" name="date_to" 
                                       value="<?php echo htmlspecialchars($filter_date_to); ?>">
                            </div>
                            
                            <div class="col-6 col-sm-3 col-lg-3">
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-sm btn-primary">
                                        <i class="fas fa-filter me-1"></i>Filter
                                    </button>
                                    <a href="payments.php" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-times me-1"></i>Clear
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <div class="card-body p-0">
                        <?php if (empty($payments)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No payments found matching your criteria.</p>
                                <a href="payments.php" class="btn btn-sm btn-outline-primary">Clear Filters</a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table id="paymentsTable" class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-3">#</th>
                                            <th>Donor</th>
                                            <th>Amount</th>
                                            <th>Method</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                            <th>Plan</th>
                                            <th>Reference</th>
                                            <th class="text-end pe-3">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payments as $index => $payment): ?>
                                            <?php
                                            $status_class = match($payment['status']) {
                                                'confirmed' => 'success',
                                                'pending' => 'warning',
                                                'voided' => 'danger',
                                                default => 'secondary'
                                            };
                                            $method_icon = match($payment['payment_method']) {
                                                'bank_transfer' => 'fa-university',
                                                'cash' => 'fa-money-bill',
                                                'card' => 'fa-credit-card',
                                                default => 'fa-wallet'
                                            };
                                            ?>
                                            <tr class="payment-row" data-payment='<?php echo htmlspecialchars(json_encode($payment), ENT_QUOTES); ?>'>
                                                <td class="ps-3 text-muted"><?php echo $index + 1; ?></td>
                                                <td>
                                                    <a href="view-donor.php?id=<?php echo (int)$payment['donor_id']; ?>" 
                                                       class="donor-link fw-bold" 
                                                       onclick="event.stopPropagation();">
                                                        <?php echo htmlspecialchars($payment['donor_name'] ?? 'Unknown'); ?>
                                                    </a>
                                                    <div class="small text-muted">
                                                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($payment['donor_phone'] ?? '-'); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="fw-bold text-success">£<?php echo number_format((float)$payment['amount'], 2); ?></span>
                                                    <?php if (!empty($payment['pledge_amount'])): ?>
                                                        <div class="small text-muted">
                                                            of £<?php echo number_format((float)$payment['pledge_amount'], 2); ?> pledge
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border">
                                                        <i class="fas <?php echo $method_icon; ?> me-1"></i>
                                                        <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'] ?? 'Unknown')); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <?php 
                                                        $date = $payment['payment_date'] ?? $payment['created_at'];
                                                        echo $date ? date('d M Y', strtotime($date)) : '-'; 
                                                        ?>
                                                    </div>
                                                    <div class="small text-muted">
                                                        <?php echo $date ? date('H:i', strtotime($date)) : ''; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $status_class; ?>">
                                                        <?php echo ucfirst($payment['status']); ?>
                                                    </span>
                                                    <?php if ($payment['status'] === 'confirmed' && !empty($payment['approved_by_name'])): ?>
                                                        <div class="small text-muted">by <?php echo htmlspecialchars($payment['approved_by_name']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($has_plan_col && !empty($payment['plan_id'])): ?>
                                                        <span class="badge bg-info plan-badge">
                                                            <i class="fas fa-calendar-check me-1"></i>
                                                            <?php echo (int)$payment['plan_payments_made']; ?>/<?php echo (int)$payment['plan_total_payments']; ?>
                                                        </span>
                                                    <?php elseif (!empty($payment['has_active_plan'])): ?>
                                                        <span class="badge bg-secondary plan-badge">
                                                            <i class="fas fa-calendar me-1"></i>Has Plan
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($payment['reference_number'])): ?>
                                                        <code class="small"><?php echo htmlspecialchars(substr($payment['reference_number'], 0, 15)); ?></code>
                                                    <?php else: ?>
                                                        <span class="text-muted small">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <div class="btn-group btn-group-sm">
                                                        <?php if (!empty($payment['payment_proof'])): ?>
                                                            <button type="button" class="btn btn-outline-secondary btn-view-proof" 
                                                                    data-proof="../../<?php echo htmlspecialchars($payment['payment_proof']); ?>"
                                                                    title="View Proof"
                                                                    onclick="event.stopPropagation();">
                                                                <i class="fas fa-image"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($payment['status'] === 'pending'): ?>
                                                            <a href="../donations/review-pledge-payments.php?filter=pending" 
                                                               class="btn btn-outline-warning"
                                                               title="Review"
                                                               onclick="event.stopPropagation();">
                                                                <i class="fas fa-clock"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        
                                                        <a href="view-donor.php?id=<?php echo (int)$payment['donor_id']; ?>" 
                                                           class="btn btn-outline-primary"
                                                           title="View Donor"
                                                           onclick="event.stopPropagation();">
                                                            <i class="fas fa-user"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($payments)): ?>
                        <div class="card-footer bg-white border-top">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <span class="text-muted small">
                                    Showing <?php echo count($payments); ?> payment<?php echo count($payments) !== 1 ? 's' : ''; ?>
                                </span>
                                <div class="small text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Click on a row to view payment details
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Payment Detail Modal -->
<div class="modal fade" id="paymentDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <div>
                    <h5 class="modal-title mb-1">
                        <i class="fas fa-receipt me-2"></i>Payment Details
                    </h5>
                    <small class="text-white-50">Payment #<span id="modal_payment_id">-</span></small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4">
                    <!-- Payment Info -->
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0 small"><i class="fas fa-pound-sign me-2 text-success"></i>Payment Information</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-6">
                                        <small class="text-muted d-block mb-1">Amount</small>
                                        <h4 class="text-success mb-0" id="modal_amount">£0.00</h4>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block mb-1">Status</small>
                                        <span id="modal_status" class="badge">-</span>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block mb-1">Method</small>
                                        <strong id="modal_method">-</strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block mb-1">Date</small>
                                        <strong id="modal_date">-</strong>
                                    </div>
                                    <div class="col-12">
                                        <small class="text-muted d-block mb-1">Reference</small>
                                        <code id="modal_reference">-</code>
                                    </div>
                                    <div class="col-12" id="modal_notes_section" style="display: none;">
                                        <small class="text-muted d-block mb-1">Notes</small>
                                        <p id="modal_notes" class="mb-0 small">-</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Donor Info -->
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0 small"><i class="fas fa-user me-2 text-primary"></i>Donor Information</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <small class="text-muted d-block mb-1">Name</small>
                                        <strong id="modal_donor_name">-</strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block mb-1">Phone</small>
                                        <strong id="modal_donor_phone">-</strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block mb-1">Pledge</small>
                                        <strong id="modal_pledge_amount">-</strong>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-muted d-block mb-1">Total Paid</small>
                                        <strong class="text-success" id="modal_total_paid">£0.00</strong>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-muted d-block mb-1">Balance</small>
                                        <strong class="text-danger" id="modal_balance">£0.00</strong>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-muted d-block mb-1">Status</small>
                                        <span id="modal_donor_status" class="badge">-</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Plan Info (if applicable) -->
                    <div class="col-12" id="modal_plan_section" style="display: none;">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0 small"><i class="fas fa-calendar-alt me-2 text-info"></i>Payment Plan</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-4">
                                        <small class="text-muted d-block mb-1">Progress</small>
                                        <strong id="modal_plan_progress">-</strong>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-muted d-block mb-1">Monthly Amount</small>
                                        <strong id="modal_plan_amount">-</strong>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-muted d-block mb-1">Plan Status</small>
                                        <span id="modal_plan_status" class="badge">-</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Proof Image -->
                    <div class="col-12" id="modal_proof_section" style="display: none;">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0 small"><i class="fas fa-image me-2 text-secondary"></i>Payment Proof</h6>
                            </div>
                            <div class="card-body text-center">
                                <img id="modal_proof_image" src="" alt="Payment Proof" class="img-fluid rounded" style="max-height: 300px;">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Processing Info -->
                    <div class="col-12" id="modal_processing_section" style="display: none;">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0 small"><i class="fas fa-history me-2 text-secondary"></i>Processing History</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-6" id="modal_approved_section" style="display: none;">
                                        <small class="text-muted d-block mb-1">Approved By</small>
                                        <strong id="modal_approved_by">-</strong>
                                        <div class="small text-muted" id="modal_approved_at">-</div>
                                    </div>
                                    <div class="col-6" id="modal_voided_section" style="display: none;">
                                        <small class="text-muted d-block mb-1">Voided By</small>
                                        <strong id="modal_voided_by">-</strong>
                                        <div class="small text-muted" id="modal_voided_at">-</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="#" id="modal_view_donor_btn" class="btn btn-primary">
                    <i class="fas fa-user me-1"></i>View Donor
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Proof Image Modal -->
<div class="modal fade" id="proofModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-image me-2"></i>Payment Proof</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-0">
                <img id="proofImage" src="" alt="Payment Proof" class="img-fluid">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="../assets/admin.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    const table = $('#paymentsTable').DataTable({
        order: [[4, 'desc']], // Order by date
        pageLength: 25,
        lengthMenu: [[25, 50, 100, -1], [25, 50, 100, "All"]],
        language: {
            search: "Search:",
            lengthMenu: "Show _MENU_ per page"
        },
        columnDefs: [
            { orderable: false, targets: [8] } // Disable sorting on actions column
        ]
    });
    
    // View proof button
    $(document).on('click', '.btn-view-proof', function(e) {
        e.stopPropagation();
        const proofUrl = $(this).data('proof');
        $('#proofImage').attr('src', proofUrl);
        new bootstrap.Modal('#proofModal').show();
    });
    
    // Click on row to show details
    $(document).on('click', '.payment-row', function() {
        const payment = JSON.parse($(this).attr('data-payment'));
        
        // Basic payment info
        $('#modal_payment_id').text(payment.id);
        $('#modal_amount').text('£' + parseFloat(payment.amount).toFixed(2));
        
        // Status badge
        const statusClass = {
            'confirmed': 'bg-success',
            'pending': 'bg-warning',
            'voided': 'bg-danger'
        }[payment.status] || 'bg-secondary';
        $('#modal_status').removeClass().addClass('badge ' + statusClass).text(payment.status.charAt(0).toUpperCase() + payment.status.slice(1));
        
        // Method
        const methodText = (payment.payment_method || 'Unknown').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        $('#modal_method').text(methodText);
        
        // Date
        const date = payment.payment_date || payment.created_at;
        $('#modal_date').text(date ? new Date(date).toLocaleString('en-GB') : '-');
        
        // Reference
        $('#modal_reference').text(payment.reference_number || '-');
        
        // Notes
        if (payment.notes && payment.notes.trim()) {
            $('#modal_notes').text(payment.notes);
            $('#modal_notes_section').show();
        } else {
            $('#modal_notes_section').hide();
        }
        
        // Donor info
        $('#modal_donor_name').text(payment.donor_name || 'Unknown');
        $('#modal_donor_phone').text(payment.donor_phone || '-');
        $('#modal_pledge_amount').text(payment.pledge_amount ? '£' + parseFloat(payment.pledge_amount).toFixed(2) : '-');
        $('#modal_total_paid').text('£' + parseFloat(payment.total_paid || 0).toFixed(2));
        $('#modal_balance').text('£' + parseFloat(payment.balance || 0).toFixed(2));
        
        // Donor status
        const donorStatusClass = {
            'completed': 'bg-success',
            'paying': 'bg-primary',
            'overdue': 'bg-danger',
            'not_started': 'bg-warning'
        }[payment.donor_payment_status] || 'bg-secondary';
        const donorStatusText = (payment.donor_payment_status || 'no_pledge').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        $('#modal_donor_status').removeClass().addClass('badge ' + donorStatusClass).text(donorStatusText);
        
        // Payment plan
        if (payment.plan_id) {
            $('#modal_plan_progress').text((payment.plan_payments_made || 0) + ' / ' + (payment.plan_total_payments || 0));
            $('#modal_plan_amount').text('£' + parseFloat(payment.plan_monthly_amount || 0).toFixed(2));
            
            const planStatusClass = {
                'active': 'bg-success',
                'completed': 'bg-primary',
                'paused': 'bg-warning'
            }[payment.plan_status] || 'bg-secondary';
            $('#modal_plan_status').removeClass().addClass('badge ' + planStatusClass).text((payment.plan_status || 'Unknown').charAt(0).toUpperCase() + (payment.plan_status || '').slice(1));
            
            $('#modal_plan_section').show();
        } else {
            $('#modal_plan_section').hide();
        }
        
        // Proof image
        if (payment.payment_proof) {
            $('#modal_proof_image').attr('src', '../../' + payment.payment_proof);
            $('#modal_proof_section').show();
        } else {
            $('#modal_proof_section').hide();
        }
        
        // Processing history
        let showProcessing = false;
        
        if (payment.approved_by_name) {
            $('#modal_approved_by').text(payment.approved_by_name);
            $('#modal_approved_at').text(payment.approved_at ? new Date(payment.approved_at).toLocaleString('en-GB') : '-');
            $('#modal_approved_section').show();
            showProcessing = true;
        } else {
            $('#modal_approved_section').hide();
        }
        
        if (payment.voided_by_name) {
            $('#modal_voided_by').text(payment.voided_by_name);
            $('#modal_voided_at').text(payment.voided_at ? new Date(payment.voided_at).toLocaleString('en-GB') : '-');
            $('#modal_voided_section').show();
            showProcessing = true;
        } else {
            $('#modal_voided_section').hide();
        }
        
        $('#modal_processing_section').toggle(showProcessing);
        
        // View donor button
        $('#modal_view_donor_btn').attr('href', 'view-donor.php?id=' + payment.donor_id);
        
        // Show modal
        new bootstrap.Modal('#paymentDetailModal').show();
    });
});

// Fallback for sidebar toggle
if (typeof window.toggleSidebar !== 'function') {
    window.toggleSidebar = function() {
        var body = document.body;
        var sidebar = document.getElementById('sidebar');
        var overlay = document.querySelector('.sidebar-overlay');
        if (window.innerWidth <= 991.98) {
            if (sidebar) sidebar.classList.toggle('active');
            if (overlay) overlay.classList.toggle('active');
            body.style.overflow = (sidebar && sidebar.classList.contains('active')) ? 'hidden' : '';
        } else {
            body.classList.toggle('sidebar-collapsed');
        }
    };
}
</script>
</body>
</html>

