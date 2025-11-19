<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$page_title = 'Assigned Donors';

$church_id = (int)($_GET['church_id'] ?? 0);

if ($church_id <= 0) {
    header("Location: churches.php?error=" . urlencode("Invalid church ID."));
    exit;
}

// Check if representative_id column exists
$check_column = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
$has_rep_column = $check_column && $check_column->num_rows > 0;

try {
    // Fetch church data
    $stmt = $db->prepare("SELECT * FROM churches WHERE id = ?");
    $stmt->bind_param("i", $church_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $church = $result->fetch_assoc();
    
    if (!$church) {
        header("Location: churches.php?error=" . urlencode("Church not found."));
        exit;
    }
} catch (Exception $e) {
    header("Location: churches.php?error=" . urlencode("Error loading church: " . $e->getMessage()));
    exit;
}

// Get filter parameters
$search = trim($_GET['search'] ?? '');
$city_filter = trim($_GET['city'] ?? '');
$donor_type_filter = trim($_GET['donor_type'] ?? '');
$payment_status_filter = trim($_GET['payment_status'] ?? '');
$representative_filter = (int)($_GET['representative_id'] ?? 0);

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

// Build query
$where_conditions = ["d.church_id = ?"];
$params = [$church_id];
$types = 'i';

if (!empty($search)) {
    $where_conditions[] = "(d.name LIKE ? OR d.phone LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ss';
}

if (!empty($city_filter)) {
    $where_conditions[] = "d.city = ?";
    $params[] = $city_filter;
    $types .= 's';
}

if (!empty($donor_type_filter)) {
    $where_conditions[] = "d.donor_type = ?";
    $params[] = $donor_type_filter;
    $types .= 's';
}

if (!empty($payment_status_filter)) {
    $where_conditions[] = "d.payment_status = ?";
    $params[] = $payment_status_filter;
    $types .= 's';
}

if ($has_rep_column && $representative_filter > 0) {
    $where_conditions[] = "d.representative_id = ?";
    $params[] = $representative_filter;
    $types .= 'i';
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count (use separate params array)
$count_params = $params;
$count_types = $types;

$count_query = "
    SELECT COUNT(*) as total
    FROM donors d
    WHERE {$where_clause}
";

$count_stmt = $db->prepare($count_query);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$total_count = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = max(1, (int)ceil($total_count / $per_page));

// Get donors (add LIMIT/OFFSET params)
$donors_query = "
    SELECT 
        d.id,
        d.name,
        d.phone,
        d.city,
        d.donor_type,
        d.payment_status,
        d.total_pledged,
        d.total_paid,
        d.balance,
        d.created_at
";

if ($has_rep_column) {
    $donors_query .= ",
        d.representative_id,
        cr.name as representative_name
    ";
}

$donors_query .= "
    FROM donors d
";

if ($has_rep_column) {
    $donors_query .= "
        LEFT JOIN church_representatives cr ON d.representative_id = cr.id
    ";
}

$donors_query .= "
    WHERE {$where_clause}
    ORDER BY d.name ASC
    LIMIT ? OFFSET ?
";

$donors_params = $params;
$donors_params[] = $per_page;
$donors_params[] = $offset;
$donors_types = $types . 'ii';

$donors_stmt = $db->prepare($donors_query);
$donors_stmt->bind_param($donors_types, ...$donors_params);
$donors_stmt->execute();
$donors = $donors_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get filter options
$cities = [];
try {
    $cities_query = $db->prepare("
        SELECT DISTINCT d.city 
        FROM donors d 
        WHERE d.church_id = ? AND d.city IS NOT NULL AND d.city != ''
        ORDER BY d.city ASC
    ");
    $cities_query->bind_param("i", $church_id);
    $cities_query->execute();
    $cities_result = $cities_query->get_result();
    while ($row = $cities_result->fetch_assoc()) {
        $cities[] = $row['city'];
    }
} catch (Exception $e) {
    // Ignore
}

$representatives = [];
if ($has_rep_column) {
    try {
        $reps_query = $db->prepare("
            SELECT id, name 
            FROM church_representatives 
            WHERE church_id = ? AND is_active = 1
            ORDER BY is_primary DESC, name ASC
        ");
        $reps_query->bind_param("i", $church_id);
        $reps_query->execute();
        $reps_result = $reps_query->get_result();
        while ($row = $reps_result->fetch_assoc()) {
            $representatives[] = $row;
        }
    } catch (Exception $e) {
        // Ignore
    }
}

// Payment statuses
$payment_statuses = [
    'no_pledge' => 'No Pledge',
    'not_started' => 'Not Started',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
    'overdue' => 'Overdue'
];

// Donor types
$donor_types = [
    'pledge' => 'Pledge',
    'immediate_payment' => 'Immediate Payment'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Assigned Donors - <?php echo htmlspecialchars($church['name']); ?> - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        :root {
            --church-primary: #0a6286;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--church-primary) 0%, #084767 100%);
            color: white;
            padding: 2rem 1.5rem;
            margin-bottom: 2rem;
            border-radius: 12px;
        }
        
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .donor-table-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
        }
        
        .table thead {
            background-color: #f8fafc;
        }
        
        .table thead th {
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
            color: #475569;
            padding: 1rem;
        }
        
        .table tbody td {
            padding: 1rem;
            vertical-align: middle;
        }
        
        .table tbody tr:hover {
            background-color: #f8fafc;
        }
        
        .badge-status {
            padding: 0.35rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .page-header {
                padding: 1.5rem 1rem;
            }
            
            .page-header h1 {
                font-size: 1.5rem;
            }
            
            .filter-card {
                padding: 1rem;
            }
            
            .table-responsive {
                font-size: 0.875rem;
            }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                
                <!-- Page Header -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <h1 class="mb-2">
                                <i class="fas fa-users me-2"></i>Assigned Donors
                            </h1>
                            <p class="mb-0 opacity-75">
                                <i class="fas fa-church me-2"></i><?php echo htmlspecialchars($church['name']); ?>
                            </p>
                        </div>
                        <div class="d-flex gap-2 mt-2 mt-md-0 flex-wrap">
                            <a href="view-church.php?id=<?php echo $church_id; ?>" class="btn btn-light">
                                <i class="fas fa-arrow-left me-2"></i>Back to Church
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Filters -->
                <div class="filter-card">
                    <form method="GET" action="" class="row g-3">
                        <input type="hidden" name="church_id" value="<?php echo $church_id; ?>">
                        
                        <div class="col-md-3">
                            <label class="form-label small text-muted">Search</label>
                            <input type="text" 
                                   name="search" 
                                   class="form-control" 
                                   placeholder="Name or phone..."
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label small text-muted">City</label>
                            <select name="city" class="form-select">
                                <option value="">All Cities</option>
                                <?php foreach ($cities as $city): ?>
                                    <option value="<?php echo htmlspecialchars($city); ?>" 
                                            <?php echo $city_filter === $city ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($city); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label small text-muted">Donor Type</label>
                            <select name="donor_type" class="form-select">
                                <option value="">All Types</option>
                                <?php foreach ($donor_types as $key => $label): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>" 
                                            <?php echo $donor_type_filter === $key ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label small text-muted">Payment Status</label>
                            <select name="payment_status" class="form-select">
                                <option value="">All Statuses</option>
                                <?php foreach ($payment_statuses as $key => $label): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>" 
                                            <?php echo $payment_status_filter === $key ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($has_rep_column && !empty($representatives)): ?>
                        <div class="col-md-2">
                            <label class="form-label small text-muted">Representative</label>
                            <select name="representative_id" class="form-select">
                                <option value="">All Representatives</option>
                                <?php foreach ($representatives as $rep): ?>
                                    <option value="<?php echo $rep['id']; ?>" 
                                            <?php echo $representative_filter === $rep['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($rep['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-1">
                            <label class="form-label small text-muted d-block">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                        </div>
                        
                        <?php if ($search || $city_filter || $donor_type_filter || $payment_status_filter || $representative_filter): ?>
                        <div class="col-12">
                            <a href="church-assigned-donors.php?church_id=<?php echo $church_id; ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-times me-1"></i>Clear Filters
                            </a>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
                
                <!-- Donors Table -->
                <div class="donor-table-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Donors
                            <span class="badge bg-primary ms-2"><?php echo number_format($total_count); ?></span>
                        </h5>
                    </div>
                    
                    <?php if (empty($donors)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-users fa-3x mb-3 opacity-25"></i>
                            <h5 class="mb-2">No Donors Found</h5>
                            <p>
                                <?php if ($search || $city_filter || $donor_type_filter || $payment_status_filter || $representative_filter): ?>
                                    Try adjusting your filters or 
                                    <a href="church-assigned-donors.php?church_id=<?php echo $church_id; ?>">clear all filters</a>.
                                <?php else: ?>
                                    No donors are currently assigned to this church.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Phone</th>
                                        <th>City</th>
                                        <?php if ($has_rep_column): ?>
                                        <th>Representative</th>
                                        <?php endif; ?>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th class="text-end">Pledged</th>
                                        <th class="text-end">Paid</th>
                                        <th class="text-end">Balance</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($donors as $donor): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($donor['name']); ?></strong>
                                        </td>
                                        <td>
                                            <a href="tel:<?php echo htmlspecialchars($donor['phone']); ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($donor['phone']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ($donor['city']): ?>
                                                <span class="badge bg-info"><?php echo htmlspecialchars($donor['city']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($has_rep_column): ?>
                                        <td>
                                            <?php if (!empty($donor['representative_name'])): ?>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($donor['representative_name']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td>
                                            <?php if ($donor['donor_type']): ?>
                                                <span class="badge bg-primary">
                                                    <?php echo ucfirst(str_replace('_', ' ', $donor['donor_type'])); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($donor['payment_status']): ?>
                                                <?php
                                                $status_class = match($donor['payment_status']) {
                                                    'completed' => 'bg-success',
                                                    'in_progress' => 'bg-info',
                                                    'not_started' => 'bg-warning',
                                                    'overdue' => 'bg-danger',
                                                    default => 'bg-secondary'
                                                };
                                                ?>
                                                <span class="badge badge-status <?php echo $status_class; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $donor['payment_status'])); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ((float)$donor['total_pledged'] > 0): ?>
                                                <strong>£<?php echo number_format((float)$donor['total_pledged'], 2); ?></strong>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ((float)$donor['total_paid'] > 0): ?>
                                                <span class="text-success">£<?php echo number_format((float)$donor['total_paid'], 2); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ((float)$donor['balance'] > 0): ?>
                                                <span class="text-warning"><strong>£<?php echo number_format((float)$donor['balance'], 2); ?></strong></span>
                                            <?php elseif ((float)$donor['balance'] < 0): ?>
                                                <span class="text-danger"><strong>£<?php echo number_format((float)$donor['balance'], 2); ?></strong></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <a href="../donor-management/view-donor.php?id=<?php echo $donor['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" 
                                               title="View Donor">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <nav aria-label="Donor pagination" class="mt-4">
                            <ul class="pagination justify-content-center">
                                <?php
                                $query_params = $_GET;
                                
                                // Previous
                                if ($page > 1):
                                    $query_params['page'] = $page - 1;
                                    $prev_url = '?' . http_build_query($query_params);
                                ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars($prev_url); ?>">
                                            <i class="fas fa-chevron-left"></i> Previous
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">
                                            <i class="fas fa-chevron-left"></i> Previous
                                        </span>
                                    </li>
                                <?php endif; ?>
                                
                                <?php
                                // Page numbers
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                if ($start_page > 1):
                                    $query_params['page'] = 1;
                                ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars('?' . http_build_query($query_params)); ?>">1</a>
                                    </li>
                                    <?php if ($start_page > 2): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <?php
                                    $query_params['page'] = $i;
                                    $page_url = '?' . http_build_query($query_params);
                                    ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars($page_url); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php
                                if ($end_page < $total_pages):
                                    $query_params['page'] = $total_pages;
                                ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars('?' . http_build_query($query_params)); ?>">
                                            <?php echo $total_pages; ?>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php
                                // Next
                                if ($page < $total_pages):
                                    $query_params['page'] = $page + 1;
                                    $next_url = '?' . http_build_query($query_params);
                                ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo htmlspecialchars($next_url); ?>">
                                            Next <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">
                                            Next <i class="fas fa-chevron-right"></i>
                                        </span>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                        
                        <div class="text-center text-muted small mt-2">
                            Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format(min($offset + $per_page, $total_count)); ?> 
                            of <?php echo number_format($total_count); ?> donors
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

