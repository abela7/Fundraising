<?php
require_once '../../config/db.php';
require_once '../../shared/csrf.php';
require_once '../../shared/auth.php';
require_login();
require_admin();

// Resiliently load settings and check for DB errors
require_once __DIR__ . '/../includes/resilient_db_loader.php';

$current_user = current_user();
$db = db();

// AJAX: Get payment details for modal (mirror main payments page)
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

// Handle bulk status updates for cash payments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_update') {
    verify_csrf();
    $targetStatus = (string)($_POST['target_status'] ?? '');
    $allowed = ['approved','voided','pending'];
    if (!in_array($targetStatus, $allowed, true)) {
        header('Location: ./cash.php?err=' . urlencode('Invalid status.'));
        exit;
    }
    $ids = $_POST['selected_ids'] ?? [];
    if (!is_array($ids) || count($ids) === 0) {
        header('Location: ./cash.php?err=' . urlencode('No payments selected.'));
        exit;
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $db->begin_transaction();
    try {
        // Constrain to method='cash' to prevent cross-method updates
        $sql = "UPDATE payments SET status=? WHERE method='cash' AND id IN ($placeholders)";
        $stmt = $db->prepare($sql);
        $bindTypes = 's' . $types;
        $params = array_merge([$targetStatus], $ids);
        $stmt->bind_param($bindTypes, ...$params);
        $stmt->execute();
        $affected = $stmt->affected_rows;

        // Audit log (one entry summarizing bulk)
        $uid = (int)($current_user['id'] ?? 0);
        $summaryAfter = json_encode(['method'=>'cash','status'=>$targetStatus,'count'=>$affected]);
        $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment', 0, 'bulk_status_update', NULL, ?, 'admin')");
        $log->bind_param('is', $uid, $summaryAfter);
        $log->execute();

        $db->commit();
        header('Location: ./cash.php?ok=1');
        exit;
    } catch (Throwable $e) {
        $db->rollback();
        header('Location: ./cash.php?err=' . urlencode($e->getMessage()));
        exit;
    }
}

// Handle record cash payment (forces method=cash)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_cash_payment') {
    verify_csrf();

    $donorName  = trim((string)($_POST['donor_name'] ?? ''));
    $donorPhone = trim((string)($_POST['donor_phone'] ?? ''));
    $donorEmail = trim((string)($_POST['donor_email'] ?? ''));
    $packageId  = isset($_POST['package_id']) && $_POST['package_id'] !== '' ? (int)$_POST['package_id'] : null;
    $amount     = (float)($_POST['amount'] ?? 0);
    $reference  = trim((string)($_POST['reference'] ?? ''));

    // If a package is selected, override amount from donation_packages
    if ($packageId !== null) {
        $pkgStmt = $db->prepare('SELECT price FROM donation_packages WHERE id = ?');
        $pkgStmt->bind_param('i', $packageId);
        $pkgStmt->execute();
        $pkg = $pkgStmt->get_result()->fetch_assoc();
        $pkgStmt->close();
        if (!$pkg) {
            header('Location: ./cash.php?err=' . urlencode('Selected package was not found.'));
            exit;
        }
        $amount = (float)$pkg['price'];
    }

    if ($amount <= 0)  { header('Location: ./cash.php?err=' . urlencode('Amount must be greater than zero.')); exit; }

    $db->begin_transaction();
    try {
        $uid = (int)$current_user['id'];
        $status = 'pending';
        $method = 'cash';

        if ($packageId === null) {
            $sql = 'INSERT INTO payments (donor_name, donor_phone, donor_email, amount, method, package_id, reference, status, received_by_user_id, received_at) 
                    VALUES (?,?,?,?,?, NULL, ?, ?, ?, NOW())';
            $ins = $db->prepare($sql);
            $ins->bind_param('sssdsssi', $donorName, $donorPhone, $donorEmail, $amount, $method, $reference, $status, $uid);
        } else {
            $sql = 'INSERT INTO payments (donor_name, donor_phone, donor_email, amount, method, package_id, reference, status, received_by_user_id, received_at) 
                    VALUES (?,?,?,?,?,?,?,?,?, NOW())';
            $ins = $db->prepare($sql);
            $ins->bind_param('sssdsissi', $donorName, $donorPhone, $donorEmail, $amount, $method, $packageId, $reference, $status, $uid);
        }
        $ins->execute();

        // Audit log
        $after  = json_encode(['amount'=>$amount,'method'=>'cash','reference'=>$reference,'status'=>'pending','package_id'=>$packageId], JSON_UNESCAPED_SLASHES);
        $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment', ?, 'create_pending', NULL, ?, 'admin')");
        $paymentId = $db->insert_id;
        $log->bind_param('iis', $uid, $paymentId, $after);
        $log->execute();

        $db->commit();
        header('Location: ./cash.php?ok=1');
        exit;
    } catch (Throwable $e) {
        $db->rollback();
        header('Location: ./cash.php?err=' . urlencode($e->getMessage()));
        exit;
    }
}

// Filters (method is fixed to cash)
$allowedStatuses = ['all','pending','approved','voided'];
$statusFilter = (string)($_GET['status'] ?? 'all');
if (!in_array($statusFilter, $allowedStatuses, true)) { $statusFilter = 'all'; }

$search = trim((string)($_GET['search'] ?? ''));

// Registrar filter
$registrarParam = (string)($_GET['registrar'] ?? 'all');
$registrarId = ($registrarParam !== 'all') ? max(0, (int)$registrarParam) : 0;

// Load registrars for dropdown
$registrars = $db->query("SELECT id, name FROM users WHERE role='registrar' AND active=1 ORDER BY name")?->fetch_all(MYSQLI_ASSOC) ?? [];

// Stats (cash only)
$settings = $db->query('SELECT target_amount, currency_code FROM settings WHERE id=1')->fetch_assoc() ?: ['target_amount'=>0,'currency_code'=>'GBP'];
$statPaid = $db->query("SELECT COALESCE(SUM(amount),0) AS total_paid FROM payments WHERE method='cash' AND status='approved'")->fetch_assoc();
$totalPaid = (float)($statPaid['total_paid'] ?? 0);
$pendingRow = $db->query("SELECT COALESCE(SUM(amount),0) AS pending FROM payments WHERE method='cash' AND status='pending'")->fetch_assoc();
$pendingAmt = (float)($pendingRow['pending'] ?? 0);
$todayRow = $db->query("SELECT COUNT(*) AS cnt FROM payments WHERE method='cash' AND DATE(created_at)=CURDATE()")
               ->fetch_assoc();
$todayCount = (int)($todayRow['cnt'] ?? 0);

// List query (cash only)
$where = ["p.method = 'cash'"]; $bind=[]; $types='';
if ($statusFilter !== 'all') { $where[] = 'p.status = ?'; $bind[] = $statusFilter; $types.='s'; }
if ($registrarId > 0) { $where[] = 'p.received_by_user_id = ?'; $bind[] = $registrarId; $types.='i'; }
if ($search !== '') {
    $where[] = '(p.donor_name LIKE ? OR p.donor_phone LIKE ? OR p.reference LIKE ?)';
    $like = '%' . $search . '%';
    $bind[] = $like; $types.='s';
    $bind[] = $like; $types.='s';
    $bind[] = $like; $types.='s';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

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

// Registrar summary when filtered
$registrarSummary = null; $registrarName = '';
if ($registrarId > 0) {
    foreach ($registrars as $reg) { if ((int)$reg['id'] === $registrarId) { $registrarName = $reg['name']; break; } }
    if ($registrarName === '') {
        $nmStmt = $db->prepare('SELECT name FROM users WHERE id = ?');
        $nmStmt->bind_param('i', $registrarId);
        $nmStmt->execute();
        $registrarName = (string)($nmStmt->get_result()->fetch_assoc()['name'] ?? 'Registrar #'.$registrarId);
        $nmStmt->close();
    }
    $sumSql = "SELECT 
                  COUNT(*) AS cnt,
                  COALESCE(SUM(CASE WHEN status='approved' THEN amount ELSE 0 END),0) AS approved_total,
                  COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) AS pending_total
               FROM payments WHERE method='cash' AND received_by_user_id = ?";
    $s = $db->prepare($sumSql);
    $s->bind_param('i', $registrarId);
    $s->execute();
    $registrarSummary = $s->get_result()->fetch_assoc();
    $s->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Payments - Fundraising Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/payments.css">
    <link rel="icon" href="../../assets/favicon.svg" type="image/svg+xml">
</head>
<body>
    <div class="admin-wrapper">
        <?php include '../includes/sidebar.php'; ?>
        <div class="admin-content">
            <?php include '../includes/topbar.php'; ?>
            <main class="main-content">
                <div class="container-fluid">
                    <?php include '../includes/db_error_banner.php'; ?>

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0"><i class="fas fa-money-bill-wave text-success me-2"></i>Cash Payments</h4>
                        <div class="d-flex gap-2">
                            <a href="./" class="btn btn-outline-secondary"><i class="fas fa-list me-1"></i>All Payments</a>
                            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#recordCashPaymentModal">
                                <i class="fas fa-plus-circle me-2"></i>Record Cash Payment
                            </button>
                        </div>
                    </div>

                    <!-- Stats -->
                    <div class="row mb-4">
                        <div class="col-md-4 mb-3">
                            <div class="stat-mini-card">
                                <div class="stat-icon bg-success"><i class="fas fa-check-circle"></i></div>
                                <div class="stat-content">
                                    <div class="stat-value">£<?php echo number_format($totalPaid, 2); ?></div>
                                    <div class="stat-label">Approved Cash</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="stat-mini-card">
                                <div class="stat-icon bg-warning"><i class="fas fa-clock"></i></div>
                                <div class="stat-content">
                                    <div class="stat-value">£<?php echo number_format($pendingAmt, 2); ?></div>
                                    <div class="stat-label">Pending Cash</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="stat-mini-card">
                                <div class="stat-icon bg-info"><i class="fas fa-calendar-day"></i></div>
                                <div class="stat-content">
                                    <div class="stat-value"><?php echo (int)$todayCount; ?></div>
                                    <div class="stat-label">Today's Cash Payments</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="card mb-3">
                        <div class="card-header bg-light"><i class="fas fa-filter me-2"></i>Filters</div>
                        <div class="card-body">
                            <form method="get" id="filterForm">
                                <div class="row g-3">
                                    <div class="col-lg-3 col-md-6">
                                        <label class="form-label">Registrar</label>
                                        <select name="registrar" class="form-select" onchange="document.getElementById('filterForm').submit();">
                                            <option value="all" <?php echo $registrarId===0?'selected':''; ?>>All Registrars</option>
                                            <?php foreach ($registrars as $reg): ?>
                                            <option value="<?php echo (int)$reg['id']; ?>" <?php echo $registrarId===(int)$reg['id']?'selected':''; ?>>
                                                <?php echo htmlspecialchars($reg['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-lg-3 col-md-6">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select" onchange="document.getElementById('filterForm').submit();">
                                            <option value="all" <?php echo $statusFilter==='all'?'selected':''; ?>>All</option>
                                            <option value="pending" <?php echo $statusFilter==='pending'?'selected':''; ?>>Pending</option>
                                            <option value="approved" <?php echo $statusFilter==='approved'?'selected':''; ?>>Approved</option>
                                            <option value="voided" <?php echo $statusFilter==='voided'?'selected':''; ?>>Voided</option>
                                        </select>
                                    </div>
                                    <div class="col-lg-4 col-md-8">
                                        <label class="form-label">Search</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                                            <input name="search" type="text" class="form-control" placeholder="Name, phone, or reference..." value="<?php echo htmlspecialchars($search); ?>">
                                            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                                        </div>
                                    </div>
                                    <div class="col-lg-2 col-md-4">
                                        <label class="form-label">Method</label>
                                        <div class="form-control-plaintext"><span class="badge bg-success"><i class="fas fa-money-bill-wave me-1"></i>Cash Only</span></div>
                                    </div>
                                </div>
                            </form>
                            <?php if ($registrarId > 0 && $registrarSummary): ?>
                            <div class="mt-3 alert alert-secondary">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div>
                                        <i class="fas fa-user me-2"></i>
                                        <strong><?php echo htmlspecialchars($registrarName); ?></strong> summary
                                    </div>
                                    <div class="small">
                                        <span class="me-3"><i class="fas fa-money-bill-wave text-success me-1"></i>Approved: £<?php echo number_format((float)$registrarSummary['approved_total'], 2); ?></span>
                                        <span class="me-3"><i class="fas fa-clock text-warning me-1"></i>Pending: £<?php echo number_format((float)$registrarSummary['pending_total'], 2); ?></span>
                                        <span><i class="fas fa-receipt text-muted me-1"></i>Payments: <?php echo (int)$registrarSummary['cnt']; ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Bulk Actions -->
                    <div class="card">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h6 class="card-title mb-0"><i class="fas fa-money-bill-wave me-2"></i>Cash Payment Records</h6>
                            <form method="post" class="d-flex align-items-center gap-2" onsubmit="return confirm('Apply to selected payments?');">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="action" value="bulk_update">
                                <select class="form-select form-select-sm" name="target_status" required>
                                    <option value="" selected disabled>Bulk Action</option>
                                    <option value="approved">Mark as Approved</option>
                                    <option value="pending">Mark as Pending</option>
                                    <option value="voided">Mark as Voided</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-check me-1"></i>Apply</button>
                            </form>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($rows)): ?>
                            <div class="empty-state text-center py-5">
                                <i class="fas fa-inbox text-muted mb-3" style="font-size: 3rem;"></i>
                                <h5 class="text-muted">No cash payments found</h5>
                                <p class="text-muted">Try changing filters or record a new cash payment.</p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="border-0" style="width:36px"><input type="checkbox" onclick="document.querySelectorAll('.sel').forEach(cb=>cb.checked=this.checked)"></th>
                                            <th class="border-0">Date & Time</th>
                                            <th class="border-0">Donor</th>
                                            <th class="border-0">Package</th>
                                            <th class="border-0">Amount</th>
                                            <th class="border-0">Status</th>
                                            <th class="border-0">Recorded By</th>
                                            <th class="border-0 text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rows as $r): ?>
                                        <tr>
                                            <td><input class="sel" type="checkbox" formmethod="post" form="bulkForm" name="selected_ids[]" value="<?php echo (int)$r['id']; ?>"></td>
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
                                                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($r['package_label']); ?></span>
                                                <?php else: ?>
                                                <span class="text-muted">Custom</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="fw-bold text-success">£<?php echo number_format((float)$r['amount'], 2); ?></span></td>
                                            <td>
                                                <?php
                                                $statusColors = ['pending'=>'warning','approved'=>'success','voided'=>'danger'];
                                                $status = $r['status'] ?? 'pending';
                                                $color = $statusColors[$status] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $color; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                                            </td>
                                            <td><small class="text-muted"><?php echo htmlspecialchars($r['received_by_name'] ?? 'System'); ?></small></td>
                                            <td class="text-center">
                                                <button class="btn btn-sm btn-outline-primary" onclick="viewPaymentDetails(<?php echo (int)$r['id']; ?>)"><i class="fas fa-eye"></i></button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <?php // Pagination
                                $queryBase = $_GET; unset($queryBase['page']);
                                $queryStringBase = http_build_query($queryBase);
                                $from = $totalRows > 0 ? ($offset + 1) : 0;
                                $to   = min($offset + $perPage, $totalRows);
                            ?>
                            <nav aria-label="Cash payments pagination" class="mt-4">
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

                    <!-- Hidden form for bulk checkboxes submission -->
                    <form id="bulkForm" method="post" class="d-none">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="bulk_update">
                        <input type="hidden" name="target_status" value="approved">
                    </form>
                </div>
            </main>
        </div>
    </div>

    <!-- Record Cash Payment Modal -->
    <div class="modal fade" id="recordCashPaymentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i>Record Cash Payment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="recordCashForm" method="post">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="record_cash_payment">

                        <div class="section-header mb-3">
                            <h6 class="text-success"><i class="fas fa-user me-2"></i>Donor Information</h6>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Donor Name <span class="text-danger">*</span></label>
                                <input type="text" name="donor_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" name="donor_phone" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="donor_email" class="form-control">
                            </div>
                        </div>

                        <div class="section-header mb-3">
                            <h6 class="text-success"><i class="fas fa-credit-card me-2"></i>Payment Details</h6>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Donation Package</label>
                                <select name="package_id" id="cashPackageSelect" class="form-select" onchange="updateAmountFromPackage()">
                                    <option value="">Select Package (Optional)</option>
                                    <?php
                                    $packages = $db->query("SELECT id, label, price, sqm_meters FROM donation_packages ORDER BY price")->fetch_all(MYSQLI_ASSOC);
                                    foreach ($packages as $pkg):
                                    ?>
                                    <option value="<?php echo $pkg['id']; ?>" data-price="<?php echo $pkg['price']; ?>">
                                        <?php echo htmlspecialchars($pkg['label']); ?> - £<?php echo number_format($pkg['price'], 2); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Amount <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">£</span>
                                    <input type="number" name="amount" id="amountInput" class="form-control" step="0.01" min="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Method</label>
                                <div class="form-control-plaintext"><span class="badge bg-success"><i class="fas fa-money-bill-wave me-1"></i>Cash</span></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Reference</label>
                                <input type="text" name="reference" class="form-control" placeholder="Receipt #, etc.">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="recordCashForm" class="btn btn-success"><i class="fas fa-save me-2"></i>Record</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/admin.js"></script>
    <script src="assets/payments.js"></script>

    <!-- View Payment Details Modal (needed by payments.js) -->
    <div class="modal fade" id="paymentDetailsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Payment Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Filled by payments.js -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>


