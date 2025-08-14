<?php
require_once '../../config/db.php';
require_once '../../shared/csrf.php';
require_once '../../shared/auth.php';
require_login();
require_admin();

$current_user = current_user();
$db = db();

// AJAX: Get payment details for modal
if (($_GET['action'] ?? '') === 'get_payment_details') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid payment id']);
        exit;
    }
    $sql = "SELECT p.*, u.name AS received_by_name, dp.label AS package_label
            FROM payments p
            LEFT JOIN users u ON u.id = p.received_by_user_id
            LEFT JOIN donation_packages dp ON dp.id = p.package_id
            WHERE p.id = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Payment not found']);
        exit;
    }
    echo json_encode(['success' => true, 'payment' => $row]);
    exit;
}

// Handle record payment submission (standalone payments, pending until approval)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    verify_csrf();

    $donorName  = trim((string)($_POST['donor_name'] ?? ''));
    $donorPhone = trim((string)($_POST['donor_phone'] ?? ''));
    $donorEmail = trim((string)($_POST['donor_email'] ?? ''));
    $packageId  = isset($_POST['package_id']) && $_POST['package_id'] !== '' ? (int)$_POST['package_id'] : null;
    $amount     = (float)($_POST['amount'] ?? 0);
    $method     = (string)($_POST['method'] ?? 'cash');
    $reference  = trim((string)($_POST['reference'] ?? ''));

    $allowedMethods = ['cash','bank','card','other'];
    if (!in_array($method, $allowedMethods, true)) { $method = 'cash'; }

    // If a package is selected, override amount from donation_packages (server-side enforcement)
    if ($packageId !== null) {
        $pkgStmt = $db->prepare('SELECT price FROM donation_packages WHERE id = ?');
        $pkgStmt->bind_param('i', $packageId);
        $pkgStmt->execute();
        $pkg = $pkgStmt->get_result()->fetch_assoc();
        $pkgStmt->close();
        if (!$pkg) {
            header('Location: ./?err=' . urlencode('Selected package was not found.'));
            exit;
        }
        $amount = (float)$pkg['price'];
    }

    if ($amount <= 0)  { header('Location: ./?err=' . urlencode('Amount must be greater than zero.')); exit; }

    $db->begin_transaction();
    try {
        $uid = (int)$current_user['id'];
        $status = 'pending';

        if ($packageId === null) {
            // Insert with NULL package_id
            $sql = 'INSERT INTO payments (donor_name, donor_phone, donor_email, amount, method, package_id, reference, status, received_by_user_id, received_at) 
                    VALUES (?,?,?,?,?, NULL, ?, ?, ?, NOW())';
            $ins = $db->prepare($sql);
            // Types: s (name), s (phone), s (email), d (amount), s (method), s (reference), s (status), i (user)
            $ins->bind_param('sssdsssi', $donorName, $donorPhone, $donorEmail, $amount, $method, $reference, $status, $uid);
        } else {
            // Insert with package_id
            $sql = 'INSERT INTO payments (donor_name, donor_phone, donor_email, amount, method, package_id, reference, status, received_by_user_id, received_at) 
                    VALUES (?,?,?,?,?,?,?,?,?, NOW())';
            $ins = $db->prepare($sql);
            $ins->bind_param('sssdsissi', $donorName, $donorPhone, $donorEmail, $amount, $method, $packageId, $reference, $status, $uid);
        }

        $ins->execute();

        // Audit log
        $after  = json_encode(['amount'=>$amount,'method'=>$method,'reference'=>$reference,'status'=>'pending','package_id'=>$packageId], JSON_UNESCAPED_SLASHES);
        $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment', ?, 'create_pending', NULL, ?, 'admin')");
        $paymentId = $db->insert_id;
        $log->bind_param('iis', $uid, $paymentId, $after);
        $log->execute();

        $db->commit();
        header('Location: ./?ok=1');
        exit;
    } catch (Throwable $e) {
        $db->rollback();
        header('Location: ./?err=' . urlencode($e->getMessage()));
        exit;
    }
}

// Filters
$allowedMethods = ['all','cash','bank','card','other'];
$methodFilter = (string)($_GET['method'] ?? 'all');
if (!in_array($methodFilter, $allowedMethods, true)) { $methodFilter = 'all'; }

$allowedStatuses = ['all','pending','approved','voided'];
$statusFilter = (string)($_GET['status'] ?? 'all');
if (!in_array($statusFilter, $allowedStatuses, true)) { $statusFilter = 'all'; }

$search = trim((string)($_GET['search'] ?? ''));

// Stats (based on new schema)
$settings = $db->query('SELECT target_amount, currency_code FROM settings WHERE id=1')->fetch_assoc() ?: ['target_amount'=>0,'currency_code'=>'GBP'];
$statPaid = $db->query("SELECT COALESCE(SUM(amount),0) AS total_paid FROM payments WHERE status='approved'")->fetch_assoc();
$totalPaid = (float)($statPaid['total_paid'] ?? 0);
$pendingRow = $db->query("SELECT COALESCE(SUM(amount),0) AS pending FROM payments WHERE status='pending'")->fetch_assoc();
$pendingAmt = (float)($pendingRow['pending'] ?? 0);
$todayRow = $db->query("SELECT COUNT(*) AS cnt FROM payments WHERE DATE(created_at)=CURDATE()")->fetch_assoc();
$todayCount = (int)($todayRow['cnt'] ?? 0);
$collectionRate = ($settings['target_amount'] > 0) ? round(($totalPaid / (float)$settings['target_amount']) * 100) : 0;

// List query
$where = [];$bind=[];$types='';
if ($methodFilter !== 'all') { $where[] = 'p.method = ?'; $bind[] = $methodFilter; $types.='s'; }
if ($statusFilter !== 'all') { $where[] = 'p.status = ?'; $bind[] = $statusFilter; $types.='s'; }
if ($search !== '') {
    $where[] = '(p.donor_name LIKE ? OR p.donor_phone LIKE ? OR p.reference LIKE ?)';
    $like = '%' . $search . '%';
    $bind[] = $like; $types.='s';
    $bind[] = $like; $types.='s';
    $bind[] = $like; $types.='s';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$cntStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM payments p $whereSql");
if ($types) { $cntStmt->bind_param($types, ...$bind); }
$cntStmt->execute();
$totalRows = (int)($cntStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$totalPages = (int)ceil($totalRows / $perPage);

$sql = "SELECT p.*, u.name AS received_by_name, dp.label AS package_label, dp.sqm_meters AS package_sqm
        FROM payments p
        LEFT JOIN users u ON u.id = p.received_by_user_id
        LEFT JOIN donation_packages dp ON dp.id = p.package_id
        $whereSql
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
if ($types) {
    $types2 = $types . 'ii';
    $params2 = array_merge($bind, [$perPage, $offset]);
    $stmt->bind_param($types2, ...$params2);
} else {
    $stmt->bind_param('ii', $perPage, $offset);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments - Fundraising System</title>
    
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/payments.css">
    
    <!-- Favicon -->
    <link rel="icon" href="../../assets/favicon.svg" type="image/svg+xml">
</head>
<body>
    <div class="admin-wrapper">
        <?php include '../includes/sidebar.php'; ?>
        <div class="admin-content">
            <?php include '../includes/topbar.php'; ?>
            <main class="main-content">
                <div class="container-fluid">
                    <!-- Page Header (actions only) -->
                    <div class="page-header mb-4">
                        <div class="d-flex justify-content-end">
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
                                <i class="fas fa-plus-circle me-2"></i>Record Payment
                            </button>
                        </div>
                    </div>
                    
                    <!-- Payment Statistics -->
                            <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="stat-mini-card animate-fade-in" style="animation-delay: 0.1s;">
                                <div class="stat-icon bg-success">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="stat-content">
                                            <div class="stat-value">£<?php echo number_format($totalPaid, 2); ?></div>
                                    <div class="stat-label">Total Collected</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="stat-mini-card animate-fade-in" style="animation-delay: 0.2s;">
                                <div class="stat-icon bg-info">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="stat-content">
                                            <div class="stat-value">£<?php echo number_format($pendingAmt, 2); ?></div>
                                    <div class="stat-label">Pending</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="stat-mini-card animate-fade-in" style="animation-delay: 0.3s;">
                                <div class="stat-icon bg-warning">
                                    <i class="fas fa-sync"></i>
                                </div>
                                <div class="stat-content">
                                            <div class="stat-value"><?php echo (int)$todayCount; ?></div>
                                    <div class="stat-label">Today's Payments</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="stat-mini-card animate-fade-in" style="animation-delay: 0.4s;">
                                <div class="stat-icon bg-primary">
                                    <i class="fas fa-percentage"></i>
                                </div>
                                <div class="stat-content">
                                            <div class="stat-value"><?php echo $collectionRate; ?>%</div>
                                    <div class="stat-label">Collection Rate</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Modern Filters & Search -->
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <h6 class="card-title mb-0">
                                <i class="fas fa-filter me-2"></i>Filters & Search
                            </h6>
                        </div>
                        <div class="card-body">
                            <form method="get" class="filter-form" id="filterForm">
                                <div class="row g-3">
                                    <div class="col-lg-3 col-md-6">
                                        <label class="form-label">Payment Method</label>
                                        <select name="method" class="form-select" onchange="document.getElementById('filterForm').submit();">
                                            <option value="all" <?php echo $methodFilter==='all'?'selected':''; ?>>All Methods</option>
                                            <option value="cash" <?php echo $methodFilter==='cash'?'selected':''; ?>>Cash</option>
                                            <option value="bank" <?php echo $methodFilter==='bank'?'selected':''; ?>>Bank Transfer</option>
                                            <option value="card" <?php echo $methodFilter==='card'?'selected':''; ?>>Card</option>
                                            <option value="other" <?php echo $methodFilter==='other'?'selected':''; ?>>Other</option>
                                        </select>
                                    </div>
                                    <div class="col-lg-3 col-md-6">
                                        <label class="form-label">Status Filter</label>
                                        <select name="status" class="form-select" onchange="document.getElementById('filterForm').submit();">
                                            <option value="all" <?php echo $statusFilter==='all'?'selected':''; ?>>All Status</option>
                                            <option value="pending" <?php echo $statusFilter==='pending'?'selected':''; ?>>Pending</option>
                                            <option value="approved" <?php echo $statusFilter==='approved'?'selected':''; ?>>Approved</option>
                                            <option value="voided" <?php echo $statusFilter==='voided'?'selected':''; ?>>Voided</option>
                                        </select>
                                    </div>
                                    <div class="col-lg-4 col-md-8">
                                        <label class="form-label">Search</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                                            <input name="search" type="text" class="form-control" 
                                                   placeholder="Search by name, phone, or reference..." 
                                                   value="<?php echo htmlspecialchars($search); ?>">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-lg-2 col-md-4">
                                        <label class="form-label">&nbsp;</label>
                                        <div class="d-grid gap-2">
                                            <a href="?" class="btn btn-outline-secondary">
                                                <i class="fas fa-undo me-1"></i>Clear
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Payments Table -->
                    <div class="card">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h6 class="card-title mb-0">
                                <i class="fas fa-list me-2"></i>Payment Records
                            </h6>
                            <div class="text-muted small">
                                Total: <?php echo number_format($totalRows); ?> payments
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($rows)): ?>
                            <div class="empty-state text-center py-5">
                                <i class="fas fa-inbox text-muted mb-3" style="font-size: 3rem;"></i>
                                <h5 class="text-muted">No payments found</h5>
                                <p class="text-muted">No payments match your current filters.</p>
                                <a href="?" class="btn btn-outline-primary">Clear Filters</a>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="border-0">Date & Time</th>
                                            <th class="border-0">Donor</th>
                                            <th class="border-0">Package</th>
                                            <th class="border-0">Amount</th>
                                            <th class="border-0">Method</th>
                                            <th class="border-0">Status</th>
                                            <th class="border-0">Recorded By</th>
                                            <th class="border-0 text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rows as $r): ?>
                                        <tr class="payment-row">
                                            <td>
                                                <div class="timestamp">
                                                    <div class="fw-medium"><?php echo date('d M Y', strtotime($r['created_at'])); ?></div>
                                                    <small class="text-muted"><?php echo date('h:i A', strtotime($r['created_at'])); ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="donor-info">
                                                    <div class="fw-medium"><?php echo htmlspecialchars(($r['donor_name'] ?? '') !== '' ? $r['donor_name'] : 'Anonymous'); ?></div>
                                                    <?php if (!empty($r['donor_phone'])): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars($r['donor_phone']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (!empty($r['package_label'])): ?>
                                                <span class="badge bg-light text-dark border">
                                                    <?php echo htmlspecialchars($r['package_label']); ?>
                                                    <?php if (isset($r['package_sqm']) && $r['package_sqm'] > 0): ?>
                                                    <br><small>(<?php echo number_format((float)$r['package_sqm'],2); ?> m²)</small>
                                                    <?php endif; ?>
                                                </span>
                                                <?php else: ?>
                                                <span class="text-muted">Custom</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="fw-bold text-success">£<?php echo number_format((float)$r['amount'], 2); ?></span>
                                            </td>
                                            <td>
                                                <?php 
                                                $methodIcons = [
                                                    'cash' => 'fas fa-money-bill-wave',
                                                    'bank' => 'fas fa-university',
                                                    'card' => 'fas fa-credit-card',
                                                    'other' => 'fas fa-question-circle'
                                                ];
                                                $icon = $methodIcons[$r['method']] ?? 'fas fa-question-circle';
                                                ?>
                                                <span class="badge bg-secondary">
                                                    <i class="<?php echo $icon; ?> me-1"></i><?php echo htmlspecialchars(ucfirst($r['method'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                $statusColors = [
                                                    'pending' => 'warning',
                                                    'approved' => 'success', 
                                                    'voided' => 'danger'
                                                ];
                                                $statusIcons = [
                                                    'pending' => 'fas fa-clock',
                                                    'approved' => 'fas fa-check-circle',
                                                    'voided' => 'fas fa-times-circle'
                                                ];
                                                $status = $r['status'] ?? 'pending';
                                                $color = $statusColors[$status] ?? 'secondary';
                                                $icon = $statusIcons[$status] ?? 'fas fa-question-circle';
                                                ?>
                                                <span class="badge bg-<?php echo $color; ?>">
                                                    <i class="<?php echo $icon; ?> me-1"></i><?php echo htmlspecialchars(ucfirst($status)); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?php echo htmlspecialchars($r['received_by_name'] ?? 'System'); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group" role="group">
                                                    <button class="btn btn-sm btn-outline-primary" 
                                                            onclick="viewPaymentDetails(<?php echo (int)$r['id']; ?>)" 
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Pagination -->
                            <?php
                                // Build base query string for links
                                $queryBase = $_GET; unset($queryBase['page']);
                                $queryStringBase = http_build_query($queryBase);
                                $from = $totalRows > 0 ? ($offset + 1) : 0;
                                $to   = min($offset + $perPage, $totalRows);
                              ?>
                              <nav aria-label="Payments pagination" class="mt-4">
                                <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
                                  <div class="text-muted small">
                                    Showing <?php echo number_format($from); ?>–<?php echo number_format($to); ?> of <?php echo number_format($totalRows); ?>
                                  </div>
                                  <?php if ($totalPages > 1): ?>
                                  <ul class="pagination mb-0">
                                    <?php $prevDisabled = $page <= 1 ? ' disabled' : ''; ?>
                                    <li class="page-item<?php echo $prevDisabled; ?>">
                                      <a class="page-link" href="?<?php echo $queryStringBase ? $queryStringBase . '&' : ''; ?>page=<?php echo max(1, $page-1); ?>" aria-label="Previous">
                                        <span aria-hidden="true"><i class="fas fa-chevron-left"></i></span>
                                      </a>
                                    </li>
                                    <?php
                                      $renderedDotsLeft = false; $renderedDotsRight = false;
                                      for ($i = 1; $i <= $totalPages; $i++) {
                                        $isEdge = ($i === 1 || $i === $totalPages);
                                        $inWindow = ($i >= $page - 2 && $i <= $page + 2);
                                        if ($isEdge || $inWindow) {
                                          $active = $i === $page ? ' active' : '';
                                          echo '<li class="page-item'.$active.'"><a class="page-link" href="?'.($queryStringBase ? $queryStringBase.'&' : '').'page='.$i.'">'.$i.'</a></li>';
                                        } else {
                                          if ($i < $page && !$renderedDotsLeft) { echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; $renderedDotsLeft = true; }
                                          if ($i > $page && !$renderedDotsRight) { echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; $renderedDotsRight = true; }
                                        }
                                      }
                                    ?>
                                    <?php $nextDisabled = $page >= $totalPages ? ' disabled' : ''; ?>
                                    <li class="page-item<?php echo $nextDisabled; ?>">
                                      <a class="page-link" href="?<?php echo $queryStringBase ? $queryStringBase . '&' : ''; ?>page=<?php echo min($totalPages, $page+1); ?>" aria-label="Next">
                                        <span aria-hidden="true"><i class="fas fa-chevron-right"></i></span>
                                      </a>
                                    </li>
                                  </ul>
                                  <?php endif; ?>
                                </div>
                              </nav>
                          </div>
                      </div>
                  </div>
              </div>
          </main>
      </div>
  </div>
    
    <!-- Record Payment Modal -->
    <div class="modal fade" id="recordPaymentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-gradient-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle me-2"></i>
                        Record New Payment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="recordPaymentForm" method="post">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="record_payment">
                        
                        <!-- Donor Information -->
                        <div class="section-header mb-3">
                            <h6 class="text-primary"><i class="fas fa-user me-2"></i>Donor Information</h6>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Donor Name <span class="text-danger">*</span></label>
                                <input type="text" name="donor_name" class="form-control" placeholder="Enter donor name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" name="donor_phone" class="form-control" placeholder="Enter phone number">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="donor_email" class="form-control" placeholder="Enter email address">
                            </div>
                        </div>

                        <!-- Payment Details -->
                        <div class="section-header mb-3">
                            <h6 class="text-primary"><i class="fas fa-credit-card me-2"></i>Payment Details</h6>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Donation Package</label>
                                <select name="package_id" id="packageSelect" class="form-select" onchange="updateAmountFromPackage()">
                                    <option value="">Select Package (Optional)</option>
                                    <?php
                                    $packages = $db->query("SELECT id, label, price, sqm_meters FROM donation_packages ORDER BY price")->fetch_all(MYSQLI_ASSOC);
                                    foreach ($packages as $pkg):
                                    ?>
                                    <option value="<?php echo $pkg['id']; ?>" data-price="<?php echo $pkg['price']; ?>">
                                        <?php echo htmlspecialchars($pkg['label']); ?> - £<?php echo number_format($pkg['price'], 2); ?>
                                        <?php if ($pkg['sqm_meters'] > 0): ?>
                                        (<?php echo number_format($pkg['sqm_meters'], 2); ?> m²)
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Payment Amount <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">£</span>
                                    <input type="number" name="amount" id="amountInput" class="form-control" placeholder="0.00" step="0.01" min="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                                <select class="form-select" name="method" required>
                                    <option value="">Select Method</option>
                                    <option value="cash">Cash</option>
                                    <option value="bank">Bank Transfer</option>
                                    <option value="card">Card</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Transaction Reference</label>
                                <input type="text" name="reference" class="form-control" placeholder="Receipt #, Transaction ID, etc.">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="recordPaymentForm" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Record Payment
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- View Payment Details Modal -->
    <div class="modal fade" id="paymentDetailsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Payment Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="payment-detail-grid">
                        <div class="detail-item">
                            <label>Payment ID:</label>
                            <span>#PAY2025001</span>
                        </div>
                        <div class="detail-item">
                            <label>Date & Time:</label>
                            <span><?php echo date('d M Y h:i A'); ?></span>
                        </div>
                        <div class="detail-item">
                            <label>Donor:</label>
                            <span>John Doe</span>
                        </div>
                        <div class="detail-item">
                            <label>Amount:</label>
                            <span class="text-success fw-bold">£400.00</span>
                        </div>
                        <div class="detail-item">
                            <label>Method:</label>
                            <span>Cash</span>
                        </div>
                        <div class="detail-item">
                            <label>Status:</label>
                            <span class="badge bg-success">Completed</span>
                        </div>
                        <div class="detail-item full-width">
                            <label>Notes:</label>
                            <span>Payment received at church office</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/admin.js"></script>
    <script src="assets/payments.js"></script>
</body>
</html>
