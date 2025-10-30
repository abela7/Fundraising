<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$page_title = 'Grid Allocation Viewer';
$db = db();

// Get filter parameters
$search = trim((string)($_GET['search'] ?? ''));
$filter_status = $_GET['status'] ?? 'all';
$filter_rectangle = $_GET['rectangle'] ?? 'all';
$filter_donor = trim((string)($_GET['donor'] ?? ''));

// Build WHERE conditions
$where_conditions = [];
$params = [];
$types = '';

// Status filter (available, pledged, paid, blocked)
if ($filter_status !== 'all' && in_array($filter_status, ['available', 'pledged', 'paid', 'blocked'], true)) {
    $where_conditions[] = 'c.status = ?';
    $params[] = $filter_status;
    $types .= 's';
} elseif ($filter_status === 'all') {
    // Only show allocated cells (not available ones) when status is 'all'
    $where_conditions[] = "c.status IN ('pledged', 'paid', 'blocked')";
}

// Rectangle filter (A, B, C, D, E, F, G)
if ($filter_rectangle !== 'all' && preg_match('/^[A-G]$/', $filter_rectangle)) {
    $where_conditions[] = 'c.rectangle_id = ?';
    $params[] = $filter_rectangle;
    $types .= 's';
}

// Search filter (cell_id, donor_name from cells table)
// We'll search in cell-level data first, then the JOINs will handle donor table matching
if ($search) {
    $where_conditions[] = '(c.cell_id LIKE ? OR c.donor_name LIKE ?)';
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'ss';
}

// Donor filter (by donor ID) - use subquery to avoid JOIN issues in WHERE
if ($filter_donor && is_numeric($filter_donor)) {
    $where_conditions[] = '(
        c.pledge_id IN (SELECT id FROM pledges WHERE donor_id = ?)
        OR c.payment_id IN (SELECT id FROM payments WHERE donor_id = ?)
    )';
    $donorId = (int)$filter_donor;
    $params[] = $donorId;
    $params[] = $donorId;
    $types .= 'ii';
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// CSV Export (must be before HTML output)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Rebuild query for export
    $export_sql = "
        SELECT 
            c.cell_id,
            c.rectangle_id,
            c.cell_type,
            c.area_size,
            c.status,
        COALESCE(d_pledge.name, d_payment.name, d_name.name, c.donor_name, p.donor_name, pay.donor_name) as donor_name,
        COALESCE(d_pledge.id, d_payment.id, d_name.id) as donor_id,
        COALESCE(d_pledge.phone, d_payment.phone, d_name.phone, p.donor_phone, pay.donor_phone) as donor_phone,
        COALESCE(d_pledge.email, d_payment.email, d_name.email, p.donor_email, pay.donor_email) as donor_email,
            c.amount as cell_amount,
            c.pledge_id,
            p.amount as pledge_amount,
            c.payment_id,
            pay.amount as payment_amount,
            c.allocation_batch_id,
            b.batch_type,
            c.assigned_date
        FROM floor_grid_cells c
        LEFT JOIN pledges p ON c.pledge_id = p.id
        LEFT JOIN payments pay ON c.payment_id = pay.id
        LEFT JOIN donors d_pledge ON p.donor_id = d_pledge.id
        LEFT JOIN donors d_payment ON pay.donor_id = d_payment.id
        LEFT JOIN donors d_name ON c.donor_name = d_name.name
        LEFT JOIN grid_allocation_batches b ON c.allocation_batch_id = b.id
        $where_clause
        ORDER BY c.rectangle_id, c.cell_id
    ";
    
    $export_stmt = $db->prepare($export_sql);
    if (!$export_stmt) {
        error_log("Grid Allocation Export: SQL Prepare Error: " . $db->error);
        die("Database error. Please try again.");
    }
    
    if (!empty($params)) {
        if (!$export_stmt->bind_param($types, ...$params)) {
            error_log("Grid Allocation Export: Bind Param Error: " . $export_stmt->error);
            error_log("Types: $types, Params count: " . count($params));
            $export_stmt->close();
            die("Database error. Please try again.");
        }
    }
    
    if (!$export_stmt->execute()) {
        error_log("Grid Allocation Export: Execute Error: " . $export_stmt->error);
        error_log("SQL: " . $export_sql);
        $export_stmt->close();
        die("Database error. Please try again.");
    }
    
    $export_results = $export_stmt->get_result();
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="grid_allocations_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    
    // CSV Headers
    fputcsv($out, [
        'Cell ID', 'Rectangle', 'Type', 'Area (m²)', 'Status', 
        'Donor Name', 'Donor ID', 'Phone', 'Email',
        'Cell Amount', 'Pledge ID', 'Pledge Amount', 'Payment ID', 'Payment Amount',
        'Batch ID', 'Batch Type', 'Assigned Date'
    ]);
    
    // CSV Data
    while ($alloc = $export_results->fetch_assoc()) {
        fputcsv($out, [
            $alloc['cell_id'],
            $alloc['rectangle_id'],
            $alloc['cell_type'],
            number_format((float)$alloc['area_size'], 2),
            $alloc['status'],
            $alloc['donor_name'] ?? '',
            $alloc['donor_id'] ?? '',
            $alloc['donor_phone'] ?? '',
            $alloc['donor_email'] ?? '',
            number_format((float)$alloc['cell_amount'], 2),
            $alloc['pledge_id'] ?? '',
            $alloc['pledge_id'] ? number_format((float)$alloc['pledge_amount'], 2) : '',
            $alloc['payment_id'] ?? '',
            $alloc['payment_id'] ? number_format((float)$alloc['payment_amount'], 2) : '',
            $alloc['allocation_batch_id'] ?? '',
            $alloc['batch_type'] ?? '',
            $alloc['assigned_date'] ?? ''
        ]);
    }
    
    fclose($out);
    $export_stmt->close();
    exit;
}

// Get grid allocation data with donor information
$sql = "
    SELECT 
        c.id,
        c.cell_id,
        c.rectangle_id,
        c.cell_type,
        c.area_size,
        c.status,
        c.pledge_id,
        c.payment_id,
        c.allocation_batch_id,
        c.donor_name as cell_donor_name,
        c.amount as cell_amount,
        c.assigned_date,
        
        -- Donor information from donors table (via pledge or payment)
        COALESCE(d_pledge.id, d_payment.id, d_name.id) as donor_id,
        COALESCE(d_pledge.name, d_payment.name, d_name.name, c.donor_name, p.donor_name, pay.donor_name) as donor_name,
        COALESCE(d_pledge.phone, d_payment.phone, d_name.phone, p.donor_phone, pay.donor_phone) as donor_phone,
        COALESCE(d_pledge.email, d_payment.email, d_name.email, p.donor_email, pay.donor_email) as donor_email,
        COALESCE(d_pledge.total_pledged, d_payment.total_pledged, d_name.total_pledged) as total_pledged,
        COALESCE(d_pledge.total_paid, d_payment.total_paid, d_name.total_paid) as total_paid,
        COALESCE(d_pledge.balance, d_payment.balance, d_name.balance) as balance,
        
        -- Pledge information
        p.amount as pledge_amount,
        p.type as pledge_type,
        p.status as pledge_status,
        p.created_at as pledge_created_at,
        p.approved_at as pledge_approved_at,
        p.donor_id as pledge_donor_id,
        
        -- Payment information
        pay.amount as payment_amount,
        pay.method as payment_method,
        pay.status as payment_status,
        pay.received_at as payment_received_at,
        pay.donor_id as payment_donor_id,
        
        -- Batch information
        b.batch_type,
        b.approval_status as batch_status,
        b.original_amount,
        b.additional_amount,
        b.total_amount,
        b.allocated_cell_count,
        b.allocated_area
        
    FROM floor_grid_cells c
    LEFT JOIN pledges p ON c.pledge_id = p.id
    LEFT JOIN payments pay ON c.payment_id = pay.id
    LEFT JOIN donors d_pledge ON p.donor_id = d_pledge.id
    LEFT JOIN donors d_payment ON pay.donor_id = d_payment.id
    LEFT JOIN donors d_name ON c.donor_name = d_name.name
    LEFT JOIN grid_allocation_batches b ON c.allocation_batch_id = b.id
    $where_clause
    ORDER BY c.rectangle_id, c.cell_id
";

$stmt = $db->prepare($sql);
if (!$stmt) {
    $error_msg = "SQL Prepare Error: " . $db->error . "\nSQL: " . substr($sql, 0, 500);
    error_log("Grid Allocation: " . $error_msg);
    // Display error on page for debugging
    $page_error = "Database error: " . htmlspecialchars($db->error);
    $allocations = [];
} else {
    if (!empty($params)) {
        if (!$stmt->bind_param($types, ...$params)) {
            $error_msg = "Bind Param Error: " . $stmt->error . "\nTypes: $types, Params count: " . count($params);
            error_log("Grid Allocation: " . $error_msg);
            $page_error = "Database binding error: " . htmlspecialchars($stmt->error);
            $stmt->close();
            $allocations = [];
        } else {
            if (!$stmt->execute()) {
                $error_msg = "Execute Error: " . $stmt->error . "\nSQL: " . substr($sql, 0, 500);
                error_log("Grid Allocation: " . $error_msg);
                $page_error = "Database execution error: " . htmlspecialchars($stmt->error);
                $stmt->close();
                $allocations = [];
            } else {
                $allocations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $page_error = null;
            }
        }
    } else {
        if (!$stmt->execute()) {
            $error_msg = "Execute Error: " . $stmt->error . "\nSQL: " . substr($sql, 0, 500);
            error_log("Grid Allocation: " . $error_msg);
            $page_error = "Database execution error: " . htmlspecialchars($stmt->error);
            $stmt->close();
            $allocations = [];
        } else {
            $allocations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $page_error = null;
        }
    }
}

// Get statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_cells,
        SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_cells,
        SUM(CASE WHEN status = 'pledged' THEN 1 ELSE 0 END) as pledged_cells,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_cells,
        SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) as blocked_cells,
        COUNT(DISTINCT donor_name) as unique_donors,
        SUM(COALESCE(amount, 0)) as total_amount
    FROM floor_grid_cells
    WHERE status IN ('pledged', 'paid', 'blocked')
";
$stats_result = $db->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
    $stats_result->free();
} else {
    // Fallback if query fails
    $stats = [
        'total_cells' => 0,
        'available_cells' => 0,
        'pledged_cells' => 0,
        'paid_cells' => 0,
        'blocked_cells' => 0,
        'unique_donors' => 0,
        'total_amount' => 0
    ];
}

// Get unique rectangles for filter
$rectangles_sql = "SELECT DISTINCT rectangle_id FROM floor_grid_cells ORDER BY rectangle_id";
$rectangles_result = $db->query($rectangles_sql);
$rectangles = [];
while ($row = $rectangles_result->fetch_assoc()) {
    $rectangles[] = $row['rectangle_id'];
}
$rectangles_result->free();
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
  <link rel="stylesheet" href="../assets/admin.css">
  <link rel="stylesheet" href="assets/grid-allocation.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include '../includes/sidebar.php'; ?>
  
  <div class="admin-content">
    <?php include '../includes/topbar.php'; ?>
    
    <main class="main-content">
      <div class="container-fluid">
        <?php include '../includes/db_error_banner.php'; ?>
        
        <?php if (isset($page_error) && $page_error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert" style="margin-top: 1rem;">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Error:</strong> <?php echo $page_error; ?>
            <br><small>Please check the server logs for more details.</small>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
        
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h1 class="h3 mb-1 text-primary">
              <i class="fas fa-th me-2"></i>Grid Allocation Viewer
            </h1>
            <p class="text-muted mb-0">View all floor grid cell allocations with donor information</p>
          </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
          <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-primary">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <i class="fas fa-th fa-2x text-primary"></i>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <h6 class="text-muted mb-0">Total Allocated</h6>
                    <h4 class="mb-0"><?php echo number_format((int)($stats['pledged_cells'] + $stats['paid_cells'] + $stats['blocked_cells'])); ?></h4>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-success">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <i class="fas fa-hand-holding-usd fa-2x text-success"></i>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <h6 class="text-muted mb-0">Pledged Cells</h6>
                    <h4 class="mb-0"><?php echo number_format((int)$stats['pledged_cells']); ?></h4>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-info">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <i class="fas fa-check-circle fa-2x text-info"></i>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <h6 class="text-muted mb-0">Paid Cells</h6>
                    <h4 class="mb-0"><?php echo number_format((int)$stats['paid_cells']); ?></h4>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-warning">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <i class="fas fa-users fa-2x text-warning"></i>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <h6 class="text-muted mb-0">Unique Donors</h6>
                    <h4 class="mb-0"><?php echo number_format((int)$stats['unique_donors']); ?></h4>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
          <div class="card-header bg-light">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5>
          </div>
          <div class="card-body">
            <form method="GET" action="" class="row g-3">
              <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" 
                       placeholder="Cell ID, Donor Name, Phone..." 
                       value="<?php echo htmlspecialchars($search); ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                  <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                  <option value="pledged" <?php echo $filter_status === 'pledged' ? 'selected' : ''; ?>>Pledged</option>
                  <option value="paid" <?php echo $filter_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                  <option value="blocked" <?php echo $filter_status === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                  <option value="available" <?php echo $filter_status === 'available' ? 'selected' : ''; ?>>Available</option>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Rectangle</label>
                <select name="rectangle" class="form-select">
                  <option value="all" <?php echo $filter_rectangle === 'all' ? 'selected' : ''; ?>>All</option>
                  <?php foreach ($rectangles as $rect): ?>
                    <option value="<?php echo htmlspecialchars($rect); ?>" 
                            <?php echo $filter_rectangle === $rect ? 'selected' : ''; ?>>
                      <?php echo htmlspecialchars($rect); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Donor ID</label>
                <input type="number" name="donor" class="form-control" 
                       placeholder="Donor ID" 
                       value="<?php echo htmlspecialchars($filter_donor); ?>">
              </div>
              <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                  <i class="fas fa-search me-1"></i>Filter
                </button>
                <a href="?" class="btn btn-secondary">
                  <i class="fas fa-times me-1"></i>Clear
                </a>
              </div>
            </form>
          </div>
        </div>

        <!-- Allocation Table -->
        <div class="card">
          <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
              <i class="fas fa-list me-2"></i>Allocations
              <span class="badge bg-primary ms-2"><?php echo count($allocations); ?></span>
            </h5>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" 
               class="btn btn-sm btn-success">
              <i class="fas fa-download me-1"></i>Export CSV
            </a>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover table-striped mb-0" id="allocationsTable">
                <thead class="table-light">
                  <tr>
                    <th>Cell ID</th>
                    <th>Rectangle</th>
                    <th>Type</th>
                    <th>Area (m²)</th>
                    <th>Status</th>
                    <th>Donor</th>
                    <th>Phone</th>
                    <th>Amount</th>
                    <th>Pledge/Payment</th>
                    <th>Batch</th>
                    <th>Assigned Date</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($allocations)): ?>
                    <tr>
                      <td colspan="11" class="text-center py-4 text-muted">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                        No allocations found
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($allocations as $alloc): ?>
                      <tr>
                        <td>
                          <strong><?php echo htmlspecialchars($alloc['cell_id']); ?></strong>
                        </td>
                        <td>
                          <span class="badge bg-secondary"><?php echo htmlspecialchars($alloc['rectangle_id']); ?></span>
                        </td>
                        <td>
                          <small><?php echo htmlspecialchars($alloc['cell_type']); ?></small>
                        </td>
                        <td><?php echo number_format((float)$alloc['area_size'], 2); ?></td>
                        <td>
                          <?php
                          $status_class = [
                              'pledged' => 'warning',
                              'paid' => 'success',
                              'blocked' => 'danger',
                              'available' => 'secondary'
                          ][$alloc['status']] ?? 'secondary';
                          ?>
                          <span class="badge bg-<?php echo $status_class; ?>">
                            <?php echo htmlspecialchars(ucfirst($alloc['status'])); ?>
                          </span>
                        </td>
                        <td>
                          <?php if ($alloc['donor_id']): ?>
                            <a href="../donor-management/index.php?donor_id=<?php echo (int)$alloc['donor_id']; ?>" 
                               class="text-decoration-none">
                              <strong><?php echo htmlspecialchars($alloc['donor_name'] ?? $alloc['cell_donor_name'] ?? 'N/A'); ?></strong>
                              <br>
                              <small class="text-muted">ID: <?php echo (int)$alloc['donor_id']; ?></small>
                            </a>
                          <?php else: ?>
                            <?php echo htmlspecialchars($alloc['cell_donor_name'] ?? 'N/A'); ?>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($alloc['donor_phone']): ?>
                            <a href="tel:<?php echo htmlspecialchars($alloc['donor_phone']); ?>" 
                               class="text-decoration-none">
                              <?php echo htmlspecialchars($alloc['donor_phone']); ?>
                            </a>
                          <?php else: ?>
                            <span class="text-muted">-</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <strong>£<?php echo number_format((float)$alloc['cell_amount'], 2); ?></strong>
                        </td>
                        <td>
                          <?php if ($alloc['pledge_id']): ?>
                            <a href="../pledges/index.php?pledge_id=<?php echo (int)$alloc['pledge_id']; ?>" 
                               class="text-decoration-none">
                              <span class="badge bg-info">Pledge #<?php echo (int)$alloc['pledge_id']; ?></span>
                              <br>
                              <small>£<?php echo number_format((float)$alloc['pledge_amount'], 2); ?></small>
                            </a>
                          <?php elseif ($alloc['payment_id']): ?>
                            <a href="../payments/index.php?payment_id=<?php echo (int)$alloc['payment_id']; ?>" 
                               class="text-decoration-none">
                              <span class="badge bg-success">Payment #<?php echo (int)$alloc['payment_id']; ?></span>
                              <br>
                              <small>£<?php echo number_format((float)$alloc['payment_amount'], 2); ?></small>
                            </a>
                          <?php else: ?>
                            <span class="text-muted">-</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($alloc['allocation_batch_id']): ?>
                            <span class="badge bg-primary" title="Batch ID: <?php echo (int)$alloc['allocation_batch_id']; ?>">
                              #<?php echo (int)$alloc['allocation_batch_id']; ?>
                            </span>
                            <?php if ($alloc['batch_type']): ?>
                              <br><small class="text-muted"><?php echo htmlspecialchars($alloc['batch_type']); ?></small>
                            <?php endif; ?>
                          <?php else: ?>
                            <span class="text-muted">-</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php if ($alloc['assigned_date']): ?>
                            <?php echo date('Y-m-d H:i', strtotime($alloc['assigned_date'])); ?>
                          <?php else: ?>
                            <span class="text-muted">-</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div>
    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/grid-allocation.js"></script>
</body>
</html>

