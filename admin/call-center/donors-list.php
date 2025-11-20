<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$db = db();
$user_id = (int)$_SESSION['user']['id'];

// Filters
$search = trim($_GET['search'] ?? '');
$city_filter = trim($_GET['city'] ?? '');
$balance_min = isset($_GET['balance_min']) && $_GET['balance_min'] !== '' ? (float)$_GET['balance_min'] : null;
$balance_max = isset($_GET['balance_max']) && $_GET['balance_max'] !== '' ? (float)$_GET['balance_max'] : null;
$has_balance = isset($_GET['has_balance']) ? $_GET['has_balance'] === '1' : false;

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 30;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where_conditions = ["d.donor_type = 'pledge'"];
$params = [];
$types = '';

if ($search) {
    $where_conditions[] = "(d.name LIKE ? OR d.phone LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}

if ($city_filter) {
    $where_conditions[] = "d.city = ?";
    $params[] = $city_filter;
    $types .= 's';
}

if ($balance_min !== null) {
    $where_conditions[] = "d.balance >= ?";
    $params[] = $balance_min;
    $types .= 'd';
}

if ($balance_max !== null) {
    $where_conditions[] = "d.balance <= ?";
    $params[] = $balance_max;
    $types .= 'd';
}

if ($has_balance) {
    $where_conditions[] = "d.balance > 0";
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count
$count_query = "SELECT COUNT(*) as total FROM donors d WHERE $where_clause";
$count_stmt = $db->prepare($count_query);
if ($params) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_count = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = ceil($total_count / $per_page);

// Get donors
$query = "
    SELECT 
        d.id,
        d.name,
        d.phone,
        d.city,
        d.balance,
        d.total_pledged,
        d.total_paid,
        d.payment_status,
        d.last_contacted_at,
        d.created_at,
        pp.id as plan_id,
        pp.status as plan_status,
        pp.monthly_amount,
        pp.next_payment_due
    FROM donors d
    LEFT JOIN donor_payment_plans pp ON d.active_payment_plan_id = pp.id AND pp.status = 'active'
    WHERE $where_clause
    ORDER BY d.balance DESC, d.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $db->prepare($query);
if ($params) {
    $all_params = array_merge($params, [$per_page, $offset]);
    $all_types = $types . 'ii';
    $stmt->bind_param($all_types, ...$all_params);
} else {
    $stmt->bind_param('ii', $per_page, $offset);
}
$stmt->execute();
$donors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get cities for filter
$cities_query = $db->query("SELECT DISTINCT city FROM donors WHERE city IS NOT NULL AND city != '' ORDER BY city");
$cities = [];
while ($row = $cities_query->fetch_assoc()) {
    $cities[] = $row['city'];
}

$page_title = 'Donors List - Call Center';
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
        .donor-card {
            background: white;
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .donor-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .donor-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #0a6286;
            margin-bottom: 0.5rem;
        }
        .donor-info {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }
        .balance-badge {
            font-size: 1.25rem;
            font-weight: 700;
            color: #dc3545;
        }
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
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
                    <h2><i class="fas fa-phone me-2"></i>Select Donor to Call</h2>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                    </a>
                </div>

                <!-- Filters -->
                <div class="filter-card">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Search</label>
                            <input type="text" class="form-control" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Name or Phone">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">City</label>
                            <select class="form-select" name="city">
                                <option value="">All Cities</option>
                                <?php foreach($cities as $city): ?>
                                <option value="<?php echo htmlspecialchars($city); ?>" 
                                        <?php echo $city_filter === $city ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($city); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Min Balance</label>
                            <input type="number" step="0.01" class="form-control" name="balance_min" 
                                   value="<?php echo $balance_min !== null ? $balance_min : ''; ?>" 
                                   placeholder="£0.00">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Max Balance</label>
                            <input type="number" step="0.01" class="form-control" name="balance_max" 
                                   value="<?php echo $balance_max !== null ? $balance_max : ''; ?>" 
                                   placeholder="£0.00">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">&nbsp;</label>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="has_balance" 
                                       value="1" id="hasBalance" <?php echo $has_balance ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="hasBalance">
                                    Has Balance Only
                                </label>
                            </div>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label fw-bold">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Results Count -->
                <div class="mb-3">
                    <strong><?php echo number_format($total_count); ?></strong> donor(s) found
                    <?php if($total_pages > 1): ?>
                        (Page <?php echo $page; ?> of <?php echo $total_pages; ?>)
                    <?php endif; ?>
                </div>

                <!-- Donors List -->
                <?php if(empty($donors)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>No donors found matching your criteria.
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach($donors as $donor): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="donor-card">
                                <div class="donor-name">
                                    <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($donor['name']); ?>
                                </div>
                                <div class="donor-info">
                                    <div><i class="fas fa-phone me-2"></i><?php echo htmlspecialchars($donor['phone']); ?></div>
                                    <?php if(!empty($donor['city'])): ?>
                                    <div><i class="fas fa-map-marker-alt me-2"></i><?php echo htmlspecialchars($donor['city']); ?></div>
                                    <?php endif; ?>
                                    <?php if($donor['plan_id']): ?>
                                    <div class="mt-2">
                                        <span class="badge bg-success">
                                            <i class="fas fa-calendar-check me-1"></i>Active Plan
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <div>
                                        <div class="small text-muted">Balance</div>
                                        <div class="balance-badge">£<?php echo number_format((float)$donor['balance'], 2); ?></div>
                                    </div>
                                    <a href="make-call.php?donor_id=<?php echo $donor['id']; ?>" 
                                       class="btn btn-primary">
                                        <i class="fas fa-phone me-2"></i>Call
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if($total_pages > 1): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <?php if($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                            
                            <?php for($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                            
                            <?php if($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                <?php endif; ?>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

