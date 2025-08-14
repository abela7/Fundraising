<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$page_title = 'Pledges Management';
$db = db();

// Get filter parameters (normalized)
$allowedStatus = ['all','pending','approved','rejected'];
$allowedType   = ['all','paid','pledge'];
$statusParam   = $_GET['status'] ?? 'all';
$typeParam     = $_GET['type'] ?? 'all';
$filter_status = in_array($statusParam, $allowedStatus, true) ? $statusParam : 'all';
$filter_type   = in_array($typeParam, $allowedType, true) ? $typeParam : 'all';
$search        = trim((string)($_GET['search'] ?? ''));

// Build query
$where_conditions = [];
$params = [];
$types = '';

if ($filter_status !== 'all') {
    $where_conditions[] = 'p.status = ?';
    $params[] = $filter_status;
    $types .= 's';
}

if ($filter_type !== 'all') {
    $where_conditions[] = 'p.type = ?';
    $params[] = $filter_type;
    $types .= 's';
}

if ($search) {
	$where_conditions[] = '(p.donor_name LIKE ? OR p.donor_phone LIKE ?)';
	$searchTerm = '%' . $search . '%';
	$params[] = $searchTerm;
	$params[] = $searchTerm;
	$types .= 'ss';
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Export CSV with current filters
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="pledges_export.csv"');
    $exportSql = "SELECT 
    \t p.id,
    \t p.donor_name AS full_name,
    \t p.donor_phone AS phone,
    \t p.anonymous,
    \t p.type,
    \t p.status,
    \t p.amount,
    \t dp.sqm_meters AS sqm_meters,
    \t p.created_at,
    \t u.name AS approved_by_name
    FROM pledges p
    LEFT JOIN donation_packages dp ON dp.id = p.package_id
    LEFT JOIN users u ON p.approved_by_user_id = u.id
    $where_clause
    ORDER BY p.created_at DESC";
    $expStmt = $db->prepare($exportSql);
    if (!empty($params)) { $expStmt->bind_param($types, ...$params); }
    $expStmt->execute();
    $res = $expStmt->get_result();
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Donor','Phone','Anonymous','Type','Status','Amount','Sqm','Created At','Approved By']);
    while ($row = $res->fetch_assoc()) {
        $showAnonymous = ($row['type'] === 'paid' && (int)$row['anonymous'] === 1);
        fputcsv($out, [
            (int)$row['id'],
            $showAnonymous ? 'Anonymous' : ($row['full_name'] ?? ''),
            $showAnonymous ? '' : ($row['phone'] ?? ''),
            (int)$row['anonymous'],
            $row['type'],
            $row['status'],
            number_format((float)$row['amount'], 2, '.', ''),
            number_format((float)($row['sqm_meters'] ?? 0), 2, '.', ''),
            $row['created_at'],
            $row['approved_by_name'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// Get pledges with pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Count total records
$count_sql = "SELECT COUNT(*) as total FROM pledges p $where_clause";
$stmt = $db->prepare($count_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_records = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$total_pages = (int)ceil($total_records / $per_page);

// Build base query string for pagination without duplicate page param
$baseQuery = $_GET;
unset($baseQuery['page']);
$queryStringBase = http_build_query($baseQuery);

// Get records
$sql = "SELECT 
\t p.*, 
\t p.donor_name AS full_name,
\t p.donor_phone AS phone,
\t dp.sqm_meters AS sqm_meters,
\t dp.label AS package_label,
\t u.name AS approved_by_name
FROM pledges p
LEFT JOIN donation_packages dp ON dp.id = p.package_id
LEFT JOIN users u ON p.approved_by_user_id = u.id
$where_clause
ORDER BY p.created_at DESC
LIMIT ? OFFSET ?";

$stmt = $db->prepare($sql);
if (!empty($params)) {
    $params[] = $per_page;
    $params[] = $offset;
    $types .= 'ii';
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param('ii', $per_page, $offset);
}
$stmt->execute();
$pledges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN type = 'paid' THEN amount ELSE 0 END) as total_paid,
        SUM(CASE WHEN type = 'pledge' THEN amount ELSE 0 END) as total_pledged
    FROM pledges
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pledges Management - Fundraising Admin</title>
  <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../assets/admin.css">
  <link rel="stylesheet" href="assets/pledges.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include '../includes/sidebar.php'; ?>
  
  <div class="admin-content">
    <?php include '../includes/topbar.php'; ?>
    
    <main class="main-content">
      <!-- Page Header -->
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h1 class="h3 mb-1">Pledges Management</h1>
          <p class="text-muted mb-0">View and manage all fundraising pledges</p>
        </div>
        <button type="button" class="btn btn-primary" onclick="exportPledges()">
          <i class="fas fa-download me-2"></i>Export
        </button>
      </div>

      <!-- Statistics Cards -->
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
          <div class="stat-mini-card">
            <div class="stat-mini-icon bg-primary">
              <i class="fas fa-list"></i>
            </div>
            <div class="stat-mini-content">
              <div class="stat-mini-value"><?php echo number_format((int)($stats['total'] ?? 0)); ?></div>
              <div class="stat-mini-label">Total Pledges</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-mini-card">
            <div class="stat-mini-icon bg-success">
              <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-mini-content">
              <div class="stat-mini-value"><?php echo number_format((int)($stats['approved'] ?? 0)); ?></div>
              <div class="stat-mini-label">Approved</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-mini-card">
            <div class="stat-mini-icon bg-warning">
              <i class="fas fa-clock"></i>
            </div>
            <div class="stat-mini-content">
              <div class="stat-mini-value"><?php echo number_format((int)($stats['pending'] ?? 0)); ?></div>
              <div class="stat-mini-label">Pending</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-mini-card">
            <div class="stat-mini-icon bg-danger">
              <i class="fas fa-times-circle"></i>
            </div>
            <div class="stat-mini-content">
              <div class="stat-mini-value"><?php echo number_format((int)($stats['rejected'] ?? 0)); ?></div>
              <div class="stat-mini-label">Rejected</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <div class="card mb-4">
        <div class="card-body">
          <form method="get" class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Search</label>
              <div class="input-group">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Name or phone..." 
                       value="<?php echo htmlspecialchars($search); ?>">
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="all">All Status</option>
                <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Type</label>
              <select name="type" class="form-select">
                <option value="all">All Types</option>
                <option value="paid" <?php echo $filter_type === 'paid' ? 'selected' : ''; ?>>Paid</option>
                <option value="pledge" <?php echo $filter_type === 'pledge' ? 'selected' : ''; ?>>Pledge</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">&nbsp;</label>
              <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">
                  <i class="fas fa-filter me-2"></i>Filter
                </button>
                <a href="?" class="btn btn-outline-secondary">
                  <i class="fas fa-redo"></i>
                </a>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Pledges Table -->
      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Donor</th>
                  <th>Amount</th>
                  <th>Type</th>
                  <th>Sqm</th>
                  <th>Status</th>
                  <th>Date</th>
                  <th>Approved By</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($pledges)): ?>
                <tr>
                  <td colspan="9" class="text-center py-4 text-muted">
                    <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                    No pledges found
                  </td>
                </tr>
                <?php else: ?>
                  <?php foreach ($pledges as $pledge): ?>
                  <tr>
                    <td>#<?php echo str_pad((string)$pledge['id'], 5, '0', STR_PAD_LEFT); ?></td>
                    <td>
                      <?php 
                        $showAnonymous = ((int)$pledge['anonymous'] === 1);
                      ?>
                      <?php if ($showAnonymous): ?>
                        <span class="text-muted"><i class="fas fa-user-secret me-1"></i>Anonymous</span>
                      <?php else: ?>
                        <div>
                          <div class="fw-semibold"><?php echo htmlspecialchars($pledge['full_name'] ?? ''); ?></div>
                          <small class="text-muted"><?php echo htmlspecialchars($pledge['phone'] ?? ''); ?></small>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <strong>£<?php echo number_format((float)($pledge['amount'] ?? 0), 2); ?></strong>
                    </td>
                    <td>
                      <?php if ($pledge['type'] === 'paid'): ?>
                        <span class="badge rounded-pill bg-success">
                          <i class="fas fa-check me-1"></i>Paid
                        </span>
                      <?php else: ?>
                        <span class="badge rounded-pill bg-warning">
                          <i class="fas fa-clock me-1"></i>Pledge
                        </span>
                      <?php endif; ?>
                    </td>
                     <td><?php echo $pledge['sqm_meters'] !== null ? number_format((float)$pledge['sqm_meters'], 2) . ' m²' : '-'; ?></td>
                    <td>
                      <?php if ($pledge['status'] === 'approved'): ?>
                        <span class="badge rounded-pill bg-success-subtle text-success">
                          <i class="fas fa-check-circle me-1"></i>Approved
                        </span>
                      <?php elseif ($pledge['status'] === 'pending'): ?>
                        <span class="badge rounded-pill bg-warning-subtle text-warning">
                          <i class="fas fa-clock me-1"></i>Pending
                        </span>
                      <?php else: ?>
                        <span class="badge rounded-pill bg-danger-subtle text-danger">
                          <i class="fas fa-times-circle me-1"></i>Rejected
                        </span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <small><?php echo date('M d, Y', strtotime($pledge['created_at'])); ?></small>
                    </td>
                    <td>
                      <?php if ($pledge['approved_by_name']): ?>
                        <small class="text-muted"><?php echo htmlspecialchars($pledge['approved_by_name']); ?></small>
                      <?php else: ?>
                        <small class="text-muted">-</small>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <div class="btn-group" role="group">
                        <button type="button" class="btn btn-sm btn-light" 
                                onclick="viewPledgeDetails(<?php echo htmlspecialchars(json_encode($pledge)); ?>)"
                                data-bs-toggle="tooltip" title="View Details">
                          <i class="fas fa-eye"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
      <nav class="mt-4">
        <ul class="pagination justify-content-center">
          <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link" href="?<?php echo $queryStringBase ? $queryStringBase . '&' : ''; ?>page=<?php echo $page - 1; ?>">
              <i class="fas fa-chevron-left"></i>
            </a>
          </li>
          
          <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
              <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                <a class="page-link" href="?<?php echo $queryStringBase ? $queryStringBase . '&' : ''; ?>page=<?php echo $i; ?>">
                  <?php echo $i; ?>
                </a>
              </li>
            <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
              <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
          <?php endfor; ?>
          
          <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
            <a class="page-link" href="?<?php echo $queryStringBase ? $queryStringBase . '&' : ''; ?>page=<?php echo $page + 1; ?>">
              <i class="fas fa-chevron-right"></i>
            </a>
          </li>
        </ul>
      </nav>
      <?php endif; ?>
    </main>
  </div>
</div>

<!-- View Details Modal -->
<div class="modal fade" id="pledgeDetailsModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fas fa-file-invoice-dollar text-primary me-2"></i>Pledge Details
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="pledgeDetailsBody">
        <!-- Details will be populated by JavaScript -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/pledges.js"></script>
</body>
</html>
