<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();

$database = db();
$page_title = 'Donor Management';

// ==== STATISTICS ====
$statsResult = $database->query("SELECT 
    COUNT(*) as total_donors,
    SUM(CASE WHEN total_pledged > 0 THEN 1 ELSE 0 END) as donors_with_pledges,
    SUM(CASE WHEN total_paid > 0 THEN 1 ELSE 0 END) as donors_with_payments,
    SUM(CASE WHEN payment_status = 'completed' THEN 1 ELSE 0 END) as fully_paid,
    SUM(CASE WHEN payment_status = 'paying' THEN 1 ELSE 0 END) as actively_paying,
    SUM(CASE WHEN payment_status = 'overdue' THEN 1 ELSE 0 END) as overdue,
    SUM(CASE WHEN has_active_plan = 1 THEN 1 ELSE 0 END) as with_active_plans,
    SUM(CASE WHEN flagged_for_followup = 1 THEN 1 ELSE 0 END) as flagged,
    SUM(total_pledged) as total_pledged_amount,
    SUM(total_paid) as total_paid_amount,
    SUM(balance) as outstanding_balance
FROM donors");

if (!$statsResult) {
    error_log("Donors stats query failed: " . $database->error);
    $stats = [];
} else {
    $stats = $statsResult->fetch_assoc() ?: [];
}

// ==== FILTER & PAGINATION ====
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'balance';
$sortOrder = $_GET['order'] ?? 'DESC';

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

// Validate sort
$validSorts = ['balance', 'total_pledged', 'total_paid', 'name', 'created_at', 'last_payment_date'];
$sortBy = in_array($sortBy, $validSorts) ? $sortBy : 'balance';
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// Count total
$countSQL = "SELECT COUNT(*) as total FROM donors d {$whereSQL}";
$countStmt = $database->prepare($countSQL);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalDonors = (int)$countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalDonors / $perPage);

// Get donors
$donorSQL = "SELECT 
    d.id,
    d.name,
    d.phone,
    d.total_pledged,
    d.total_paid,
    d.balance,
    d.payment_status,
    d.achievement_badge,
    d.preferred_payment_method,
    d.preferred_language,
    d.has_active_plan,
    d.last_payment_date,
    d.flagged_for_followup,
    d.followup_priority,
    d.pledge_count,
    d.payment_count,
    d.created_at
FROM donors d
{$whereSQL}
ORDER BY d.{$sortBy} {$sortOrder}
LIMIT ? OFFSET ?";

$stmt = $database->prepare($donorSQL);
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$donors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$currency = '£';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Donor Management - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .stat-card { 
            border-left: 4px solid #0a6286;
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .badge-status {
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
        }
        .donor-row:hover {
            background-color: rgba(10, 98, 134, 0.05);
        }
        .table-sm td {
            padding: 0.5rem;
            font-size: 0.9rem;
        }
        .flag-icon { color: #ff6b6b; }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <h2 class="mb-4"><i class="fas fa-users"></i> Donor Management</h2>

                <!-- STATISTICS GRID -->
                <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-6 g-3 mb-4">
                    <div class="col">
                        <div class="card stat-card">
                            <div class="card-body">
                                <h6 class="text-muted">Total Donors</h6>
                                <h3><?= number_format($stats['total_donors'] ?? 0) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card stat-card">
                            <div class="card-body">
                                <h6 class="text-muted">Pledged</h6>
                                <h3><?= number_format($stats['donors_with_pledges'] ?? 0) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card stat-card">
                            <div class="card-body">
                                <h6 class="text-muted">Paid</h6>
                                <h3><?= number_format($stats['donors_with_payments'] ?? 0) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card stat-card">
                            <div class="card-body">
                                <h6 class="text-muted">Actively Paying</h6>
                                <h3><?= number_format($stats['actively_paying'] ?? 0) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card stat-card">
                            <div class="card-body">
                                <h6 class="text-muted">Active Plans</h6>
                                <h3><?= number_format($stats['with_active_plans'] ?? 0) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card stat-card">
                            <div class="card-body">
                                <h6 class="text-muted">Flagged</h6>
                                <h3 class="text-danger"><?= number_format($stats['flagged'] ?? 0) ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FINANCIAL SUMMARY -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h6>Total Pledged</h6>
                                <h3><?= $currency . number_format($stats['total_pledged_amount'] ?? 0, 2) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h6>Total Paid</h6>
                                <h3><?= $currency . number_format($stats['total_paid_amount'] ?? 0, 2) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-warning text-dark">
                            <div class="card-body">
                                <h6>Outstanding Balance</h6>
                                <h3><?= $currency . number_format($stats['outstanding_balance'] ?? 0, 2) ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SEARCH & FILTER -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-2">
                            <div class="col-md-5">
                                <input type="text" name="search" class="form-control" 
                                       placeholder="Search by name or phone..." 
                                       value="<?= htmlspecialchars($search) ?>">
                            </div>
                            <div class="col-md-2">
                                <select name="status" class="form-select">
                                    <option value="all">All Status</option>
                                    <option value="not_started" <?= $statusFilter === 'not_started' ? 'selected' : '' ?>>Not Started</option>
                                    <option value="paying" <?= $statusFilter === 'paying' ? 'selected' : '' ?>>Paying</option>
                                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                                    <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select name="sort" class="form-select">
                                    <option value="balance" <?= $sortBy === 'balance' ? 'selected' : '' ?>>Balance</option>
                                    <option value="total_pledged" <?= $sortBy === 'total_pledged' ? 'selected' : '' ?>>Total Pledged</option>
                                    <option value="total_paid" <?= $sortBy === 'total_paid' ? 'selected' : '' ?>>Total Paid</option>
                                    <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Name</option>
                                    <option value="created_at" <?= $sortBy === 'created_at' ? 'selected' : '' ?>>Date Added</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select name="order" class="form-select">
                                    <option value="DESC" <?= $sortOrder === 'DESC' ? 'selected' : '' ?>>Descending</option>
                                    <option value="ASC" <?= $sortOrder === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- DONORS TABLE -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Donors (<?= number_format($totalDonors) ?> total)</h5>
                        <small class="text-muted">Page <?= $page ?> of <?= $totalPages ?></small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th width="2%">#</th>
                                    <th width="15%">Name</th>
                                    <th width="10%">Phone</th>
                                    <th width="8%">Pledged</th>
                                    <th width="8%">Paid</th>
                                    <th width="8%">Balance</th>
                                    <th width="8%">Status</th>
                                    <th width="8%">Badge</th>
                                    <th width="8%">Method</th>
                                    <th width="12%">Last Payment</th>
                                    <th width="5%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($donors)): ?>
                                <tr>
                                    <td colspan="11" class="text-center py-4 text-muted">
                                        No donors found
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($donors as $donor): ?>
                                    <tr class="donor-row">
                                        <td>
                                            <?php if ($donor['flagged_for_followup']): ?>
                                                <i class="fas fa-flag flag-icon"></i>
                                            <?php else: ?>
                                                <?= $donor['id'] ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($donor['name']) ?></strong>
                                            <?php if ($donor['flagged_for_followup']): ?>
                                                <br><small class="text-danger">Follow-up: <?= ucfirst($donor['followup_priority']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?= htmlspecialchars($donor['phone']) ?></small>
                                        </td>
                                        <td class="text-end">
                                            <strong><?= $currency . number_format($donor['total_pledged'], 2) ?></strong>
                                        </td>
                                        <td class="text-end text-success">
                                            <strong><?= $currency . number_format($donor['total_paid'], 2) ?></strong>
                                        </td>
                                        <td class="text-end">
                                            <strong class="<?= $donor['balance'] > 0 ? 'text-warning' : 'text-success' ?>">
                                                <?= $currency . number_format($donor['balance'], 2) ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <span class="badge badge-status 
                                                <?= match($donor['payment_status']) {
                                                    'completed' => 'bg-success',
                                                    'paying' => 'bg-info',
                                                    'overdue' => 'bg-danger',
                                                    'not_started' => 'bg-warning',
                                                    default => 'bg-secondary'
                                                } ?>">
                                                <?= ucfirst(str_replace('_', ' ', $donor['payment_status'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="badge bg-light text-dark">
                                                <?= ucfirst(str_replace('_', ' ', $donor['achievement_badge'])) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small><?= ucfirst(str_replace('_', ' ', $donor['preferred_payment_method'])) ?></small>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php if ($donor['last_payment_date']): ?>
                                                    <?= date('M d, Y', strtotime($donor['last_payment_date'])) ?>
                                                <?php else: ?>
                                                    <span class="text-secondary">-</span>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td class="text-center">
                                            <a href="view.php?id=<?= $donor['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- PAGINATION -->
                    <?php if ($totalPages > 1): ?>
                    <div class="card-footer d-flex justify-content-center">
                        <nav>
                            <ul class="pagination mb-0">
                                <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=1&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&sort=<?= $sortBy ?>&order=<?= $sortOrder ?>">
                                        First
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&sort=<?= $sortBy ?>&order=<?= $sortOrder ?>">
                                        Previous
                                    </a>
                                </li>
                                <?php endif; ?>
                                
                                <li class="page-item active">
                                    <span class="page-link"><?= $page ?> / <?= $totalPages ?></span>
                                </li>
                                
                                <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&sort=<?= $sortBy ?>&order=<?= $sortOrder ?>">
                                        Next
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $totalPages ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&sort=<?= $sortBy ?>&order=<?= $sortOrder ?>">
                                        Last
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
<script src="../assets/admin.js?v=<?php echo filemtime(__DIR__ . '/../assets/admin.js'); ?>"></script>
<script>
// Fallback toggle function in case admin.js doesn't load
if (typeof toggleSidebar === 'undefined') {
    console.log('DEBUG: toggleSidebar not found, creating fallback');
    window.toggleSidebar = function() {
        console.log('DEBUG: toggleSidebar called');
        const sidebar = document.getElementById('sidebar');
        const body = document.body;
        console.log('DEBUG: Sidebar element:', sidebar);
        console.log('DEBUG: Body element:', body);
        if (sidebar) {
            body.classList.toggle('sidebar-collapsed');
            console.log('DEBUG: Sidebar toggled. Class list:', body.classList.toString());
        } else {
            console.log('DEBUG ERROR: Sidebar element not found!');
        }
    };
} else {
    console.log('DEBUG: toggleSidebar already defined');
}

// Test if admin.js loaded
console.log('DEBUG: Admin.js loaded:', typeof toggleSidebar !== 'undefined');
console.log('DEBUG: Bootstrap loaded:', typeof bootstrap !== 'undefined');
console.log('DEBUG: jQuery loaded:', typeof jQuery !== 'undefined');

// Add click handler to all buttons with onclick="toggleSidebar()"
document.querySelectorAll('[onclick="toggleSidebar()"]').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        console.log('DEBUG: Button clicked:', this);
        if (typeof toggleSidebar !== 'undefined') {
            e.preventDefault();
            toggleSidebar();
        } else {
            console.log('DEBUG ERROR: toggleSidebar still undefined on button click!');
        }
    });
});
console.log('DEBUG: Found', document.querySelectorAll('[onclick="toggleSidebar()"]').length, 'buttons with toggleSidebar() onclick');
</script>
</body>
</html>
