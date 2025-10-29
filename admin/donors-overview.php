<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../config/db.php';

require_admin();

$database = db();

// ==== STATISTICS ====
$stats = $database->query("SELECT 
    COUNT(DISTINCT d.id) as total_donors,
    COUNT(DISTINCT CASE WHEN d.total_pledged > 0 THEN d.id END) as donors_with_pledges,
    COUNT(DISTINCT CASE WHEN d.total_paid > 0 THEN d.id END) as donors_with_payments,
    COUNT(DISTINCT CASE WHEN d.payment_status = 'completed' THEN d.id END) as fully_paid_donors,
    COUNT(DISTINCT CASE WHEN d.payment_status = 'paying' THEN d.id END) as actively_paying_donors,
    COUNT(DISTINCT CASE WHEN d.payment_status = 'not_started' THEN d.id END) as not_started_donors,
    COUNT(DISTINCT CASE WHEN d.payment_status = 'overdue' THEN d.id END) as overdue_donors,
    SUM(COALESCE(d.total_pledged, 0)) as total_pledged_amount,
    SUM(COALESCE(d.total_paid, 0)) as total_paid_amount,
    SUM(COALESCE(d.balance, 0)) as total_outstanding_balance,
    COUNT(DISTINCT p.id) as total_pledges,
    COUNT(DISTINCT pa.id) as total_payments
FROM donors d
LEFT JOIN pledges p ON p.donor_id = d.id
LEFT JOIN payments pa ON pa.donor_id = d.id")->fetch_assoc() ?: [];

// ==== FILTER & PAGINATION ====
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';

// Build WHERE clause
$whereClauses = [];
$params = [];
$types = '';

if ($search !== '') {
    $whereClauses[] = "(d.name LIKE ? OR d.phone LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= 'ss';
}

if ($statusFilter !== 'all') {
    $whereClauses[] = "d.payment_status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

$whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Count total
$countSQL = "SELECT COUNT(*) as total FROM donors d {$whereSQL}";
$countStmt = $database->prepare($countSQL);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalDonors = (int)$countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalDonors / $perPage);

// Get donors with their pledge and payment counts
$donorSQL = "SELECT 
    d.id,
    d.name,
    d.phone,
    d.total_pledged,
    d.total_paid,
    d.balance,
    d.payment_status,
    d.achievement_badge,
    d.preferred_language,
    d.preferred_payment_method,
    d.has_active_plan,
    d.last_payment_date,
    d.created_at,
    COUNT(DISTINCT p.id) as pledge_count,
    COUNT(DISTINCT pa.id) as payment_count
FROM donors d
LEFT JOIN pledges p ON p.donor_id = d.id
LEFT JOIN payments pa ON pa.donor_id = d.id
{$whereSQL}
GROUP BY d.id
ORDER BY d.balance DESC, d.total_pledged DESC
LIMIT ? OFFSET ?";

$stmt = $database->prepare($donorSQL);
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$donors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Status badge colors
$statusColors = [
    'no_pledge' => 'secondary',
    'not_started' => 'warning',
    'paying' => 'info',
    'overdue' => 'danger',
    'completed' => 'success',
    'defaulted' => 'danger'
];

$badgeColors = [
    'pending' => 'secondary',
    'started' => 'warning',
    'on_track' => 'info',
    'fast_finisher' => 'primary',
    'completed' => 'success',
    'champion' => 'success'
];

$currency = '£';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Donors - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/admin.css">
    <style>
        .stat-box { min-height: 100px; }
        .donor-row-highlight:hover { background-color: rgba(10, 98, 134, 0.05); }
        .pledge-badge, .payment-badge { font-size: 0.85rem; padding: 0.3rem 0.6rem; }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include 'includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <h2 class="mb-4"><i class="fas fa-users"></i> Donor Overview</h2>
                
                <!-- ===== STATISTICS CARDS ===== -->
                <div class="row mb-4">
                    <div class="col-md-2 mb-3">
                        <div class="card stat-box">
                            <div class="card-body text-center">
                                <h5 class="card-title mb-2"><i class="fas fa-users"></i></h5>
                                <h3><?= number_format($stats['total_donors'] ?? 0) ?></h3>
                                <small class="text-muted">Total Donors</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card stat-box">
                            <div class="card-body text-center">
                                <h5 class="card-title mb-2"><i class="fas fa-handshake"></i></h5>
                                <h3><?= number_format($stats['donors_with_pledges'] ?? 0) ?></h3>
                                <small class="text-muted">Pledged</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card stat-box">
                            <div class="card-body text-center">
                                <h5 class="card-title mb-2"><i class="fas fa-money-bill"></i></h5>
                                <h3><?= number_format($stats['donors_with_payments'] ?? 0) ?></h3>
                                <small class="text-muted">Paid</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card stat-box">
                            <div class="card-body text-center">
                                <h5 class="card-title mb-2"><i class="fas fa-check-circle"></i></h5>
                                <h3><?= number_format($stats['fully_paid_donors'] ?? 0) ?></h3>
                                <small class="text-muted">Completed</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card stat-box">
                            <div class="card-body text-center">
                                <h5 class="card-title mb-2"><i class="fas fa-chart-line"></i></h5>
                                <h3><?= number_format($stats['actively_paying_donors'] ?? 0) ?></h3>
                                <small class="text-muted">Paying</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card stat-box">
                            <div class="card-body text-center">
                                <h5 class="card-title mb-2"><i class="fas fa-hourglass"></i></h5>
                                <h3><?= number_format($stats['overdue_donors'] ?? 0) ?></h3>
                                <small class="text-muted">Overdue</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== FINANCIAL SUMMARY ===== -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h6 class="card-subtitle mb-2 opacity-75">Total Pledged</h6>
                                <h3><?= $currency . number_format($stats['total_pledged_amount'] ?? 0, 2) ?></h3>
                                <small>from <?= number_format($stats['total_pledges'] ?? 0) ?> pledges</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h6 class="card-subtitle mb-2 opacity-75">Total Paid</h6>
                                <h3><?= $currency . number_format($stats['total_paid_amount'] ?? 0, 2) ?></h3>
                                <small>from <?= number_format($stats['total_payments'] ?? 0) ?> payments</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card bg-warning text-dark">
                            <div class="card-body">
                                <h6 class="card-subtitle mb-2 opacity-75">Outstanding Balance</h6>
                                <h3><?= $currency . number_format($stats['total_outstanding_balance'] ?? 0, 2) ?></h3>
                                <small><?php 
                                    $percentage = $stats['total_pledged_amount'] > 0 ? 
                                        (($stats['total_paid_amount'] / $stats['total_pledged_amount']) * 100) : 0;
                                    echo number_format($percentage, 1) . '% paid';
                                ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== SEARCH & FILTER ===== -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-6">
                                <input type="text" name="search" class="form-control" 
                                       placeholder="Search by name or phone..." 
                                       value="<?= htmlspecialchars($search) ?>">
                            </div>
                            <div class="col-md-4">
                                <select name="status" class="form-select">
                                    <option value="all">All Statuses</option>
                                    <option value="no_pledge" <?= $statusFilter === 'no_pledge' ? 'selected' : '' ?>>No Pledge</option>
                                    <option value="not_started" <?= $statusFilter === 'not_started' ? 'selected' : '' ?>>Not Started</option>
                                    <option value="paying" <?= $statusFilter === 'paying' ? 'selected' : '' ?>>Paying</option>
                                    <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search"></i> Search
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ===== DONORS TABLE ===== -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Donors List (<?= number_format($totalDonors) ?> total)</h5>
                        <small class="text-muted">Page <?= $page ?> of <?= $totalPages ?></small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th width="20%">Donor Info</th>
                                    <th width="12%" class="text-end">Pledged</th>
                                    <th width="12%" class="text-end">Paid</th>
                                    <th width="12%" class="text-end">Balance</th>
                                    <th width="10%">Pledges</th>
                                    <th width="10%">Payments</th>
                                    <th width="14%">Status & Badge</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($donors)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox"></i> No donors found
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($donors as $donor): ?>
                                    <tr class="donor-row-highlight">
                                        <td>
                                            <div>
                                                <strong><?= htmlspecialchars($donor['name']) ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-phone"></i> <?= htmlspecialchars($donor['phone']) ?>
                                                </small>
                                            </div>
                                            <small class="text-muted d-block mt-1">
                                                Lang: <strong><?= strtoupper($donor['preferred_language'] ?? 'EN') ?></strong> | 
                                                Method: <strong><?= ucfirst(str_replace('_', ' ', $donor['preferred_payment_method'] ?? 'Unknown')) ?></strong>
                                            </small>
                                            <?php if ($donor['last_payment_date']): ?>
                                            <small class="text-success d-block">
                                                <i class="fas fa-calendar"></i> Last: <?= date('M d, Y', strtotime($donor['last_payment_date'])) ?>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <strong><?= $currency . number_format($donor['total_pledged'], 2) ?></strong>
                                        </td>
                                        <td class="text-end">
                                            <strong class="text-success"><?= $currency . number_format($donor['total_paid'], 2) ?></strong>
                                        </td>
                                        <td class="text-end">
                                            <strong class="<?= $donor['balance'] > 0 ? 'text-warning' : 'text-success' ?>">
                                                <?= $currency . number_format($donor['balance'], 2) ?>
                                            </strong>
                                        </td>
                                        <td class="text-center">
                                            <span class="pledge-badge bg-info-light text-dark" title="Total pledges made">
                                                <i class="fas fa-handshake"></i> <?= (int)$donor['pledge_count'] ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="payment-badge bg-success-light text-dark" title="Total payments received">
                                                <i class="fas fa-money-bill"></i> <?= (int)$donor['payment_count'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $statusColors[$donor['payment_status']] ?? 'secondary' ?> me-1">
                                                <?= ucfirst(str_replace('_', ' ', $donor['payment_status'])) ?>
                                            </span>
                                            <br>
                                            <span class="badge bg-<?= $badgeColors[$donor['achievement_badge']] ?? 'secondary' ?> mt-1">
                                                <i class="fas fa-star"></i> <?= ucfirst(str_replace('_', ' ', $donor['achievement_badge'])) ?>
                                            </span>
                                            <?php if ($donor['has_active_plan']): ?>
                                            <br>
                                            <span class="badge bg-primary mt-1">
                                                <i class="fas fa-calendar-alt"></i> Active Plan
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- ===== PAGINATION ===== -->
                    <?php if ($totalPages > 1): ?>
                    <div class="card-footer d-flex justify-content-between">
                        <nav>
                            <ul class="pagination mb-0">
                                <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=1&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>">
                                        <i class="fas fa-chevron-left"></i> First
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>">
                                        Previous
                                    </a>
                                </li>
                                <?php endif; ?>
                                
                                <li class="page-item active">
                                    <span class="page-link"><?= $page ?> / <?= $totalPages ?></span>
                                </li>
                                
                                <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>">
                                        Next
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $totalPages ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>">
                                        Last <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/admin.js"></script>
</body>
</html>
