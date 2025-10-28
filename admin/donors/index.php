<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();

$database = db();

// Get overall statistics
$statsQuery = "SELECT 
    COUNT(*) as total_donors,
    COUNT(CASE WHEN total_pledged > 0 THEN 1 END) as donors_with_pledges,
    COUNT(CASE WHEN total_paid > 0 THEN 1 END) as donors_with_payments,
    COUNT(CASE WHEN payment_status = 'completed' THEN 1 END) as fully_paid_donors,
    COUNT(CASE WHEN payment_status = 'paying' THEN 1 END) as actively_paying_donors,
    COUNT(CASE WHEN payment_status = 'not_started' THEN 1 END) as not_started_donors,
    COUNT(CASE WHEN payment_status = 'overdue' THEN 1 END) as overdue_donors,
    SUM(total_pledged) as total_pledged_amount,
    SUM(total_paid) as total_paid_amount,
    SUM(balance) as total_outstanding_balance
FROM donors";

$stats = $database->query($statsQuery)->fetch_assoc();

// Get payment status breakdown
$statusBreakdown = [];
$statusQuery = "SELECT payment_status, COUNT(*) as count 
                FROM donors 
                GROUP BY payment_status 
                ORDER BY FIELD(payment_status, 'no_pledge', 'not_started', 'paying', 'overdue', 'completed', 'defaulted')";
$statusResult = $database->query($statusQuery);
while ($row = $statusResult->fetch_assoc()) {
    $statusBreakdown[$row['payment_status']] = (int)$row['count'];
}

// Get achievement badge breakdown
$badgeBreakdown = [];
$badgeQuery = "SELECT achievement_badge, COUNT(*) as count 
               FROM donors 
               GROUP BY achievement_badge 
               ORDER BY FIELD(achievement_badge, 'pending', 'started', 'on_track', 'fast_finisher', 'completed', 'champion')";
$badgeResult = $database->query($badgeQuery);
while ($row = $badgeResult->fetch_assoc()) {
    $badgeBreakdown[$row['achievement_badge']] = (int)$row['count'];
}

// Get detailed donor list with pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Filters
$searchTerm = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'balance_desc';

// Build WHERE clause
$whereClauses = [];
$params = [];
$types = '';

if ($searchTerm !== '') {
    $whereClauses[] = "(name LIKE ? OR phone LIKE ?)";
    $searchParam = "%{$searchTerm}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= 'ss';
}

if ($statusFilter !== 'all') {
    $whereClauses[] = "payment_status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

$whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Build ORDER BY clause
$orderByMap = [
    'balance_desc' => 'balance DESC',
    'balance_asc' => 'balance ASC',
    'pledged_desc' => 'total_pledged DESC',
    'pledged_asc' => 'total_pledged ASC',
    'paid_desc' => 'total_paid DESC',
    'paid_asc' => 'total_paid ASC',
    'name_asc' => 'name ASC',
    'name_desc' => 'name DESC',
    'created_desc' => 'created_at DESC',
    'created_asc' => 'created_at ASC'
];

$orderBySQL = $orderByMap[$sortBy] ?? 'balance DESC';

// Count total for pagination
$countSQL = "SELECT COUNT(*) as total FROM donors {$whereSQL}";
$countStmt = $database->prepare($countSQL);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalDonors = (int)$countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalDonors / $perPage);

// Get donor list
$donorSQL = "SELECT 
    id,
    name,
    phone,
    total_pledged,
    total_paid,
    balance,
    payment_status,
    achievement_badge,
    pledge_count,
    payment_count,
    preferred_language,
    created_at,
    last_payment_date
FROM donors 
{$whereSQL}
ORDER BY {$orderBySQL}
LIMIT ? OFFSET ?";

$stmt = $database->prepare($donorSQL);
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$donors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Currency
$currency = '£';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Donor Overview - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .stats-card {
            border-radius: 12px;
            padding: 1.5rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin-bottom: 1rem;
            transition: transform 0.2s;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
        }
        
        .stats-card h3 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stats-card p {
            margin: 0;
            opacity: 0.9;
        }
        
        .stats-card.success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .stats-card.warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .stats-card.danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .stats-card.info { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .stats-card.secondary { background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%); }
        
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-block;
        }
        
        .badge-no_pledge { background: #e5e7eb; color: #374151; }
        .badge-not_started { background: #fef3c7; color: #92400e; }
        .badge-paying { background: #dbeafe; color: #1e40af; }
        .badge-overdue { background: #fee2e2; color: #991b1b; }
        .badge-completed { background: #d1fae5; color: #065f46; }
        .badge-defaulted { background: #fecaca; color: #7f1d1d; }
        
        .achievement-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .achievement-pending { background: #fef2f2; color: #991b1b; }
        .achievement-started { background: #fef3c7; color: #92400e; }
        .achievement-on_track { background: #dbeafe; color: #1e40af; }
        .achievement-fast_finisher { background: #dcfce7; color: #166534; }
        .achievement-completed { background: #d1fae5; color: #065f46; }
        .achievement-champion { background: #fce7f3; color: #9f1239; }
        
        .table-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .filter-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }
        
        .donor-row {
            transition: background-color 0.2s;
        }
        
        .donor-row:hover {
            background-color: #f8f9fa;
        }
        
        .balance-positive {
            color: #dc2626;
            font-weight: 600;
        }
        
        .balance-zero {
            color: #10b981;
            font-weight: 600;
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2><i class="fas fa-users"></i> Donor Overview</h2>
                        <p class="text-muted mb-0">Complete donor registry and financial tracking</p>
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="window.print()">
                            <i class="fas fa-print"></i> Print Report
                        </button>
                        <a href="export.php" class="btn btn-success">
                            <i class="fas fa-file-excel"></i> Export CSV
                        </a>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card info">
                            <h3><?= number_format($stats['total_donors']) ?></h3>
                            <p><i class="fas fa-users"></i> Total Donors</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <h3><?= number_format($stats['donors_with_pledges']) ?></h3>
                            <p><i class="fas fa-hand-holding-usd"></i> Made Pledges</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card success">
                            <h3><?= number_format($stats['donors_with_payments']) ?></h3>
                            <p><i class="fas fa-money-bill-wave"></i> Made Payments</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card warning">
                            <h3><?= number_format($stats['fully_paid_donors']) ?></h3>
                            <p><i class="fas fa-check-circle"></i> Fully Paid</p>
                        </div>
                    </div>
                </div>
                
                <!-- Financial Summary -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="stats-card info">
                            <h3><?= $currency . number_format($stats['total_pledged_amount'], 2) ?></h3>
                            <p><i class="fas fa-wallet"></i> Total Pledged</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card success">
                            <h3><?= $currency . number_format($stats['total_paid_amount'], 2) ?></h3>
                            <p><i class="fas fa-pound-sign"></i> Total Paid</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card danger">
                            <h3><?= $currency . number_format($stats['total_outstanding_balance'], 2) ?></h3>
                            <p><i class="fas fa-exclamation-triangle"></i> Outstanding Balance</p>
                        </div>
                    </div>
                </div>
                
                <!-- Status Breakdown -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="table-card">
                            <div class="card-body">
                                <h5 class="card-title"><i class="fas fa-chart-bar"></i> Payment Status Breakdown</h5>
                                <table class="table table-sm">
                                    <tbody>
                                        <?php foreach ($statusBreakdown as $status => $count): ?>
                                            <tr>
                                                <td>
                                                    <span class="status-badge badge-<?= htmlspecialchars($status) ?>">
                                                        <?= ucwords(str_replace('_', ' ', $status)) ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <strong><?= number_format($count) ?></strong> donors
                                                    <small class="text-muted">
                                                        (<?= round($count / $stats['total_donors'] * 100, 1) ?>%)
                                                    </small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="table-card">
                            <div class="card-body">
                                <h5 class="card-title"><i class="fas fa-trophy"></i> Achievement Badges</h5>
                                <table class="table table-sm">
                                    <tbody>
                                        <?php foreach ($badgeBreakdown as $badge => $count): ?>
                                            <tr>
                                                <td>
                                                    <span class="achievement-badge achievement-<?= htmlspecialchars($badge) ?>">
                                                        <?php
                                                        $badgeIcons = [
                                                            'pending' => '🔴',
                                                            'started' => '🟡',
                                                            'on_track' => '🔵',
                                                            'fast_finisher' => '🟢',
                                                            'completed' => '✅',
                                                            'champion' => '⭐'
                                                        ];
                                                        echo $badgeIcons[$badge] ?? '';
                                                        echo ' ' . ucwords(str_replace('_', ' ', $badge));
                                                        ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <strong><?= number_format($count) ?></strong> donors
                                                    <small class="text-muted">
                                                        (<?= round($count / $stats['total_donors'] * 100, 1) ?>%)
                                                    </small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filters -->
                <div class="filter-section">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label"><i class="fas fa-search"></i> Search</label>
                            <input type="text" 
                                   name="search" 
                                   class="form-control" 
                                   placeholder="Search by name or phone..." 
                                   value="<?= htmlspecialchars($searchTerm) ?>">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label"><i class="fas fa-filter"></i> Payment Status</label>
                            <select name="status" class="form-select">
                                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                                <option value="no_pledge" <?= $statusFilter === 'no_pledge' ? 'selected' : '' ?>>No Pledge</option>
                                <option value="not_started" <?= $statusFilter === 'not_started' ? 'selected' : '' ?>>Not Started</option>
                                <option value="paying" <?= $statusFilter === 'paying' ? 'selected' : '' ?>>Actively Paying</option>
                                <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                                <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label"><i class="fas fa-sort"></i> Sort By</label>
                            <select name="sort" class="form-select">
                                <option value="balance_desc" <?= $sortBy === 'balance_desc' ? 'selected' : '' ?>>Balance (High to Low)</option>
                                <option value="balance_asc" <?= $sortBy === 'balance_asc' ? 'selected' : '' ?>>Balance (Low to High)</option>
                                <option value="pledged_desc" <?= $sortBy === 'pledged_desc' ? 'selected' : '' ?>>Pledged (High to Low)</option>
                                <option value="paid_desc" <?= $sortBy === 'paid_desc' ? 'selected' : '' ?>>Paid (High to Low)</option>
                                <option value="name_asc" <?= $sortBy === 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                                <option value="created_desc" <?= $sortBy === 'created_desc' ? 'selected' : '' ?>>Newest First</option>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Donor List Table -->
                <div class="table-card">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th>Language</th>
                                    <th class="text-end">Pledged</th>
                                    <th class="text-end">Paid</th>
                                    <th class="text-end">Balance</th>
                                    <th>Status</th>
                                    <th>Badge</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($donors)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">
                                            <i class="fas fa-inbox" style="font-size: 3rem;"></i>
                                            <p class="mb-0 mt-2">No donors found matching your criteria</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($donors as $donor): ?>
                                        <tr class="donor-row">
                                            <td>
                                                <strong><?= htmlspecialchars($donor['name']) ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <?= $donor['pledge_count'] ?> pledge<?= $donor['pledge_count'] != 1 ? 's' : '' ?>, 
                                                    <?= $donor['payment_count'] ?> payment<?= $donor['payment_count'] != 1 ? 's' : '' ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="font-monospace"><?= htmlspecialchars($donor['phone']) ?></span>
                                            </td>
                                            <td>
                                                <?php
                                                $langIcons = ['en' => '🇬🇧', 'am' => '🇪🇹 AM', 'ti' => '🇪🇷 TI'];
                                                echo $langIcons[$donor['preferred_language']] ?? '🇬🇧';
                                                ?>
                                            </td>
                                            <td class="text-end">
                                                <strong><?= $currency . number_format($donor['total_pledged'], 2) ?></strong>
                                            </td>
                                            <td class="text-end">
                                                <strong class="text-success"><?= $currency . number_format($donor['total_paid'], 2) ?></strong>
                                            </td>
                                            <td class="text-end">
                                                <strong class="<?= $donor['balance'] > 0 ? 'balance-positive' : 'balance-zero' ?>">
                                                    <?= $currency . number_format($donor['balance'], 2) ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <span class="status-badge badge-<?= htmlspecialchars($donor['payment_status']) ?>">
                                                    <?= ucwords(str_replace('_', ' ', $donor['payment_status'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="achievement-badge achievement-<?= htmlspecialchars($donor['achievement_badge']) ?>">
                                                    <?php
                                                    $badgeIcons = [
                                                        'pending' => '🔴',
                                                        'started' => '🟡',
                                                        'on_track' => '🔵',
                                                        'fast_finisher' => '🟢',
                                                        'completed' => '✅',
                                                        'champion' => '⭐'
                                                    ];
                                                    echo $badgeIcons[$donor['achievement_badge']] ?? '';
                                                    ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <a href="view.php?id=<?= $donor['id'] ?>" 
                                                   class="btn btn-sm btn-outline-primary"
                                                   title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="card-footer">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="text-muted">
                                    Showing <?= number_format($offset + 1) ?> to <?= number_format(min($offset + $perPage, $totalDonors)) ?> 
                                    of <?= number_format($totalDonors) ?> donors
                                </div>
                                <nav>
                                    <ul class="pagination mb-0">
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($searchTerm) ?>&status=<?= urlencode($statusFilter) ?>&sort=<?= urlencode($sortBy) ?>">
                                                    Previous
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($searchTerm) ?>&status=<?= urlencode($statusFilter) ?>&sort=<?= urlencode($sortBy) ?>">
                                                    <?= $i ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $totalPages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($searchTerm) ?>&status=<?= urlencode($statusFilter) ?>&sort=<?= urlencode($sortBy) ?>">
                                                    Next
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.js'); ?>"></script>
</body>
</html>

