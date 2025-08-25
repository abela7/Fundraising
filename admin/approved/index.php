<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/IntelligentGridAllocator.php';
require_login();
require_admin();

$db = db();
$page_title = 'Approved Items';
$actionMsg = '';

// Load active donation packages for edit modal
$pkgRows = $db->query("SELECT id, label, sqm_meters, price FROM donation_packages WHERE active=1 ORDER BY sort_order, id")->fetch_all(MYSQLI_ASSOC);

// No helpers needed currently

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'undo') {
        $pledgeId = (int)($_POST['pledge_id'] ?? 0);
        if ($pledgeId > 0) {
            $db->begin_transaction();
            try {
                // Lock pledge and verify approved
                $sel = $db->prepare("SELECT id, amount, type, status FROM pledges WHERE id=? FOR UPDATE");
                $sel->bind_param('i', $pledgeId);
                $sel->execute();
                $pledge = $sel->get_result()->fetch_assoc();
                if (!$pledge || $pledge['status'] !== 'approved') {
                    throw new RuntimeException('Invalid state');
                }

                // Revert pledge to pending
                $upd = $db->prepare("UPDATE pledges SET status='pending' WHERE id=?");
                $upd->bind_param('i', $pledgeId);
                $upd->execute();

                // Decrement counters by the amount previously added
                $deltaPaid = 0.0; $deltaPledged = 0.0;
                if ((string)$pledge['type'] === 'paid') { $deltaPaid = -1 * (float)$pledge['amount']; }
                else { $deltaPledged = -1 * (float)$pledge['amount']; }
                $ctr = $db->prepare(
                    "INSERT INTO counters (id, paid_total, pledged_total, grand_total, version, recalc_needed)
                     VALUES (1, ?, ?, ?, 1, 0)
                     ON DUPLICATE KEY UPDATE
                       paid_total = paid_total + VALUES(paid_total),
                       pledged_total = pledged_total + VALUES(pledged_total),
                       grand_total = grand_total + VALUES(grand_total),
                       version = version + 1,
                       recalc_needed = 0"
                );
                $grandDelta = $deltaPaid + $deltaPledged;
                $ctr->bind_param('ddd', $deltaPaid, $deltaPledged, $grandDelta);
                $ctr->execute();

                // Deallocate floor grid cells for this pledge
                $gridAllocator = new IntelligentGridAllocator($db);
                $deallocationResult = $gridAllocator->deallocate($pledgeId, null);
                if (!$deallocationResult['success']) {
                    throw new RuntimeException('Floor deallocation failed: ' . $deallocationResult['error']);
                }

                // Note: payments are standalone; no pledge_id linkage anymore

                // Audit log
                $uid = (int)(current_user()['id'] ?? 0);
                $before = json_encode(['status' => 'approved'], JSON_UNESCAPED_SLASHES);
                $after = json_encode(['status' => 'pending'], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'undo_approve', ?, ?, 'admin')");
                $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
                $log->execute();

                $db->commit();
                $actionMsg = 'Approval undone';
                
                // Set a flag to trigger floor map refresh on page load
                $_SESSION['trigger_floor_refresh'] = true;
            } catch (Throwable $e) {
                $db->rollback();
                $actionMsg = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_pledge') {
        $pledgeId = (int)($_POST['pledge_id'] ?? 0);
        $donorName = trim((string)($_POST['donor_name'] ?? ''));
        $donorPhone = trim((string)($_POST['donor_phone'] ?? ''));
        $donorEmail = trim((string)($_POST['donor_email'] ?? ''));
        $amountNew = (float)($_POST['amount'] ?? 0);
        $sqmMeters = isset($_POST['sqm_meters']) ? (float)$_POST['sqm_meters'] : 0.0; // optional
        $packageId = isset($_POST['package_id']) && $_POST['package_id'] !== '' ? (int)$_POST['package_id'] : null;
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($pledgeId > 0 && $donorName && $donorPhone && $amountNew > 0) {
            $db->begin_transaction();
            try {
                // Lock and load current pledge
                $sel = $db->prepare("SELECT id, amount, type, status FROM pledges WHERE id=? FOR UPDATE");
                $sel->bind_param('i', $pledgeId);
                $sel->execute();
                $pledge = $sel->get_result()->fetch_assoc();
                if (!$pledge || $pledge['status'] !== 'approved') { throw new RuntimeException('Invalid state'); }

                $amountOld = (float)$pledge['amount'];

                // First: move pledge back to pending and subtract old amount from counters
                $updStatus = $db->prepare("UPDATE pledges SET status='pending' WHERE id=?");
                $updStatus->bind_param('i', $pledgeId);
                $updStatus->execute();

                $deltaPaid = 0.0; $deltaPledged = 0.0;
                if ((string)$pledge['type'] === 'paid') { $deltaPaid = -1 * $amountOld; } else { $deltaPledged = -1 * $amountOld; }
                $grandDelta = $deltaPaid + $deltaPledged;
                $ctr = $db->prepare(
                    "INSERT INTO counters (id, paid_total, pledged_total, grand_total, version, recalc_needed)
                     VALUES (1, ?, ?, ?, 1, 0)
                     ON DUPLICATE KEY UPDATE
                       paid_total = paid_total + VALUES(paid_total),
                       pledged_total = pledged_total + VALUES(pledged_total),
                       grand_total = grand_total + VALUES(grand_total),
                       version = version + 1,
                       recalc_needed = 0"
                );
                $ctr->bind_param('ddd', $deltaPaid, $deltaPledged, $grandDelta);
                $ctr->execute();

                // Prefer explicit package selection; fallback to sqm-meters match
                $pkgId = $packageId;
                if ($pkgId === null && $sqmMeters > 0) {
                    $pkgSel = $db->prepare('SELECT id FROM donation_packages WHERE ABS(sqm_meters - ?) < 0.00001 LIMIT 1');
                    $pkgSel->bind_param('d', $sqmMeters);
                    $pkgSel->execute();
                    $pkgRow = $pkgSel->get_result()->fetch_assoc();
                    if ($pkgRow) { $pkgId = (int)$pkgRow['id']; }
                }

                if ($pkgId !== null) {
                    $upd = $db->prepare("UPDATE pledges SET donor_name=?, donor_phone=?, donor_email=?, amount=?, notes=?, package_id=? WHERE id=?");
                    $upd->bind_param('ssssiii', $donorName, $donorPhone, $donorEmail, $amountNew, $notes, $pkgId, $pledgeId);
                } else {
                    $upd = $db->prepare("UPDATE pledges SET donor_name=?, donor_phone=?, donor_email=?, amount=?, notes=? WHERE id=?");
                    $upd->bind_param('sssssi', $donorName, $donorPhone, $donorEmail, $amountNew, $notes, $pledgeId);
                }
                $upd->execute();

                // Audit log
                $uid = (int)(current_user()['id'] ?? 0);
                $before = json_encode(['status' => 'approved', 'amount' => $amountOld], JSON_UNESCAPED_SLASHES);
                $after = json_encode(['status' => 'pending', 'amount' => $amountNew, 'updated' => true], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'update_to_pending', ?, ?, 'admin')");
                $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
                $log->execute();

                $db->commit();
                $actionMsg = 'Pledge updated and set to pending for re-approval';
            } catch (Throwable $e) {
                $db->rollback();
                $actionMsg = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_payment') {
        // Edit an approved payment (standalone); adjust counters by delta
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $amountNew = (float)($_POST['payment_amount'] ?? 0);
        $method = (string)($_POST['payment_method'] ?? 'cash');
        $reference = trim((string)($_POST['payment_reference'] ?? ''));
        $allowed = ['cash','card','bank','other']; if (!in_array($method, $allowed, true)) { $method = 'cash'; }
        if ($paymentId > 0 && $amountNew > 0) {
            $db->begin_transaction();
            try {
                // Lock payment and verify approved
                $sel = $db->prepare("SELECT id, amount, status FROM payments WHERE id=? FOR UPDATE");
                $sel->bind_param('i', $paymentId);
                $sel->execute();
                $row = $sel->get_result()->fetch_assoc();
                if (!$row || (string)$row['status'] !== 'approved') { throw new RuntimeException('Invalid payment state'); }
                $amountOld = (float)$row['amount'];

                // Move payment back to pending and subtract its previously approved amount
                $updStatus = $db->prepare("UPDATE payments SET status='pending', amount=?, method=?, reference=? WHERE id=?");
                $updStatus->bind_param('dssi', $amountNew, $method, $reference, $paymentId);
                $updStatus->execute();

                $delta = -1 * $amountOld;
                $ctr = $db->prepare(
                    "INSERT INTO counters (id, paid_total, pledged_total, grand_total, version, recalc_needed)
                     VALUES (1, ?, 0, ?, 1, 0)
                     ON DUPLICATE KEY UPDATE
                       paid_total = paid_total + VALUES(paid_total),
                       grand_total = grand_total + VALUES(grand_total),
                       version = version + 1,
                       recalc_needed = 0"
                );
                $grandDelta = $delta;
                $ctr->bind_param('dd', $delta, $grandDelta);
                $ctr->execute();

                // Audit log
                $uid = (int)(current_user()['id'] ?? 0);
                $before = json_encode(['status' => 'approved', 'amount' => $amountOld], JSON_UNESCAPED_SLASHES);
                $after = json_encode(['status' => 'pending', 'amount' => $amountNew, 'method' => $method, 'updated' => true], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment', ?, 'update_to_pending', ?, ?, 'admin')");
                $log->bind_param('iiss', $uid, $paymentId, $before, $after);
                $log->execute();

                $db->commit();
                $actionMsg = 'Payment updated and set to pending for re-approval';
            } catch (Throwable $e) {
                $db->rollback();
                $actionMsg = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'undo_payment') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $amount = (float)($_POST['payment_amount'] ?? 0);
        if ($paymentId > 0 && $amount > 0) {
            $db->begin_transaction();
            try {
                // Lock payment and ensure currently approved
                $sel = $db->prepare("SELECT id, amount, status FROM payments WHERE id=? FOR UPDATE");
                $sel->bind_param('i', $paymentId);
                $sel->execute();
                $pay = $sel->get_result()->fetch_assoc();
                if (!$pay || (string)$pay['status'] !== 'approved') { throw new RuntimeException('Payment is not approved'); }

                // Set back to pending
                $upd = $db->prepare("UPDATE payments SET status='pending' WHERE id=?");
                $upd->bind_param('i', $paymentId);
                $upd->execute();

                // Subtract from counters
                $delta = -1 * (float)$pay['amount'];
                $ctr = $db->prepare(
                    "INSERT INTO counters (id, paid_total, pledged_total, grand_total, version, recalc_needed)
                     VALUES (1, ?, 0, ?, 1, 0)
                     ON DUPLICATE KEY UPDATE
                       paid_total = paid_total + VALUES(paid_total),
                       grand_total = grand_total + VALUES(grand_total),
                       version = version + 1,
                       recalc_needed = 0"
                );
                $grandDelta = $delta;
                $ctr->bind_param('dd', $delta, $grandDelta);
                $ctr->execute();

                // Deallocate floor grid cells for this payment
                $gridAllocator = new IntelligentGridAllocator($db);
                $deallocationResult = $gridAllocator->deallocate(null, $paymentId);
                if (!$deallocationResult['success']) {
                    throw new RuntimeException('Floor deallocation failed: ' . $deallocationResult['error']);
                }

                // Audit
                $uid = (int)(current_user()['id'] ?? 0);
                $before = json_encode(['status'=>'approved'], JSON_UNESCAPED_SLASHES);
                $after  = json_encode(['status'=>'pending'], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment', ?, 'undo_approve', ?, ?, 'admin')");
                $log->bind_param('iiss', $uid, $paymentId, $before, $after);
                $log->execute();

                $db->commit();
                $actionMsg = 'Payment approval undone';
                
                // Set a flag to trigger floor map refresh on page load
                $_SESSION['trigger_floor_refresh'] = true;
            } catch (Throwable $e) {
                $db->rollback();
                $actionMsg = 'Error: ' . $e->getMessage();
            }
        }
    }

    // Preserve filter parameters in redirect
    $query_params = array_filter([
        'page' => $_GET['page'] ?? '',
        'search' => $_GET['search'] ?? '',
        'type' => $_GET['type'] ?? '',
        'amount_min' => $_GET['amount_min'] ?? '',
        'amount_max' => $_GET['amount_max'] ?? '',
        'sort' => $_GET['sort'] ?? ''
    ]);
    $redirect_url = 'index.php?msg=' . urlencode($actionMsg);
    if (!empty($query_params)) {
        $redirect_url .= '&' . http_build_query($query_params);
    }
    header('Location: ' . $redirect_url);
    exit;
}

// Pagination and filtering parameters
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filter parameters
$search = trim($_GET['search'] ?? '');
$type_filter = $_GET['type'] ?? '';
$amount_min = $_GET['amount_min'] !== '' ? (float)$_GET['amount_min'] : null;
$amount_max = $_GET['amount_max'] !== '' ? (float)$_GET['amount_max'] : null;
$sort = $_GET['sort'] ?? 'approved_desc';

// Build dynamic WHERE conditions for filtering
$where_conditions_pledge = ["p.status = 'approved'"];
$where_conditions_payment = ["pay.status = 'approved'"];
$having_conditions = [];

if ($search !== '') {
    $search_condition_pledge = "(p.donor_name LIKE '%$search%' OR p.donor_phone LIKE '%$search%' OR p.donor_email LIKE '%$search%' OR p.notes LIKE '%$search%')";
    $search_condition_payment = "(pay.donor_name LIKE '%$search%' OR pay.donor_phone LIKE '%$search%' OR pay.donor_email LIKE '%$search%' OR pay.reference LIKE '%$search%')";
    $where_conditions_pledge[] = $search_condition_pledge;
    $where_conditions_payment[] = $search_condition_payment;
}

if ($type_filter !== '') {
    if ($type_filter === 'pledge') {
        $where_conditions_payment[] = "FALSE"; // Exclude payments
    } elseif ($type_filter === 'paid') {
        $where_conditions_pledge[] = "FALSE"; // Exclude pledges
    }
}

if ($amount_min !== null) {
    $where_conditions_pledge[] = "p.amount >= $amount_min";
    $where_conditions_payment[] = "pay.amount >= $amount_min";
}

if ($amount_max !== null) {
    $where_conditions_pledge[] = "p.amount <= $amount_max";
    $where_conditions_payment[] = "pay.amount <= $amount_max";
}

// Build ORDER BY clause
$order_by = 'approved_at DESC, created_at DESC';
switch ($sort) {
    case 'approved_asc': $order_by = 'approved_at ASC, created_at ASC'; break;
    case 'approved_desc': $order_by = 'approved_at DESC, created_at DESC'; break;
    case 'created_asc': $order_by = 'created_at ASC'; break;
    case 'created_desc': $order_by = 'created_at DESC'; break;
    case 'amount_asc': $order_by = 'amount ASC'; break;
    case 'amount_desc': $order_by = 'amount DESC'; break;
    case 'name_asc': $order_by = 'donor_name ASC'; break;
    case 'name_desc': $order_by = 'donor_name DESC'; break;
    case 'type_asc': $order_by = 'type ASC'; break;
    case 'type_desc': $order_by = 'type DESC'; break;
}

// Get total count for pagination
$count_sql = "
SELECT COUNT(*) as total FROM (
    (SELECT p.id
      FROM pledges p
      LEFT JOIN donation_packages dp ON dp.id = p.package_id
      LEFT JOIN users u ON p.created_by_user_id = u.id
      WHERE " . implode(' AND ', $where_conditions_pledge) . ")
    UNION ALL
    (SELECT pay.id
      FROM payments pay
      LEFT JOIN donation_packages dp ON dp.id = pay.package_id
      LEFT JOIN users u ON pay.received_by_user_id = u.id
      WHERE " . implode(' AND ', $where_conditions_payment) . ")
) as combined";

$total_count = $db->query($count_sql)->fetch_assoc()['total'];

// List approved pledges and approved standalone payments in a single list with filtering
$sql = "
(SELECT 
    p.id,
    p.amount,
    'pledge' AS type,
    p.notes,
    p.created_at,
    p.approved_at,
    dp.sqm_meters AS sqm_meters,
    p.anonymous,
    p.donor_name,
    p.donor_phone,
    p.donor_email,
    u.name AS registrar_name,
    NULL AS payment_id,
    NULL AS payment_amount,
    NULL AS payment_method,
    NULL AS payment_reference
  FROM pledges p
  LEFT JOIN donation_packages dp ON dp.id = p.package_id
  LEFT JOIN users u ON p.created_by_user_id = u.id
  WHERE " . implode(' AND ', $where_conditions_pledge) . ")
UNION ALL
(SELECT 
    pay.id AS id,
    pay.amount,
    'paid' AS type,
    pay.reference AS notes,
    pay.created_at,
    pay.received_at AS approved_at,
    dp.sqm_meters AS sqm_meters,
    0 AS anonymous,
    pay.donor_name,
    pay.donor_phone,
    pay.donor_email,
    u.name AS registrar_name,
    pay.id AS payment_id,
    pay.amount AS payment_amount,
    pay.method AS payment_method,
    pay.reference AS payment_reference
  FROM payments pay
  LEFT JOIN donation_packages dp ON dp.id = pay.package_id
  LEFT JOIN users u ON pay.received_by_user_id = u.id
  WHERE " . implode(' AND ', $where_conditions_payment) . ")
ORDER BY $order_by
LIMIT $per_page OFFSET $offset";

$approved = $db->query($sql)->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Approved Items - Fundraising Admin</title>
  <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.css'); ?>">
  <link rel="stylesheet" href="assets/approved.css?v=<?php echo @filemtime(__DIR__ . '/assets/approved.css'); ?>">
</head>
<body>
<div class="admin-wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="admin-content">
    <?php include '../includes/topbar.php'; ?>
    <main class="main-content">
      <div class="row">
        <div class="col-12">
          <?php if (!empty($_GET['msg'])): ?>
          <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle me-2"></i><?php echo htmlspecialchars($_GET['msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
          <?php endif; ?>

          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="mb-0">
                <i class="fas fa-check-circle text-success me-2"></i>Approved Items
              </h5>
              <button class="btn btn-sm btn-outline-primary" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>
            <div class="card-body">
              <!-- Filtering and Search Controls -->
              <div class="row mb-4">
                <div class="col-12">
                  <form method="GET" class="filtering-controls">
                    <div class="row g-3">
                      <div class="col-md-4">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Name, phone, email, notes...">
                      </div>
                      <div class="col-md-2">
                        <label for="type" class="form-label">Type</label>
                        <select class="form-select" id="type" name="type">
                          <option value="">All Types</option>
                          <option value="pledge" <?php echo $type_filter === 'pledge' ? 'selected' : ''; ?>>Pledge</option>
                          <option value="paid" <?php echo $type_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        </select>
                      </div>
                      <div class="col-md-2">
                        <label for="amount_min" class="form-label">Min Amount</label>
                        <input type="number" class="form-control" id="amount_min" name="amount_min" 
                               value="<?php echo $amount_min !== null ? $amount_min : ''; ?>" 
                               min="0" step="0.01" placeholder="£0">
                      </div>
                      <div class="col-md-2">
                        <label for="amount_max" class="form-label">Max Amount</label>
                        <input type="number" class="form-control" id="amount_max" name="amount_max" 
                               value="<?php echo $amount_max !== null ? $amount_max : ''; ?>" 
                               min="0" step="0.01" placeholder="£999999">
                      </div>
                      <div class="col-md-2">
                        <label for="sort" class="form-label">Sort By</label>
                        <select class="form-select" id="sort" name="sort">
                          <option value="approved_desc" <?php echo $sort === 'approved_desc' ? 'selected' : ''; ?>>Recently Approved</option>
                          <option value="approved_asc" <?php echo $sort === 'approved_asc' ? 'selected' : ''; ?>>Oldest Approved</option>
                          <option value="created_desc" <?php echo $sort === 'created_desc' ? 'selected' : ''; ?>>Newest Created</option>
                          <option value="created_asc" <?php echo $sort === 'created_asc' ? 'selected' : ''; ?>>Oldest Created</option>
                          <option value="amount_desc" <?php echo $sort === 'amount_desc' ? 'selected' : ''; ?>>Highest Amount</option>
                          <option value="amount_asc" <?php echo $sort === 'amount_asc' ? 'selected' : ''; ?>>Lowest Amount</option>
                          <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                          <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                          <option value="type_asc" <?php echo $sort === 'type_asc' ? 'selected' : ''; ?>>Type A-Z</option>
                          <option value="type_desc" <?php echo $sort === 'type_desc' ? 'selected' : ''; ?>>Type Z-A</option>
                        </select>
                      </div>
                    </div>
                    <div class="row mt-3">
                      <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                          <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary ms-2">
                          <i class="fas fa-times"></i> Clear Filters
                        </a>
                        <input type="hidden" name="page" value="1">
                      </div>
                    </div>
                  </form>
                </div>
              </div>

              <!-- Results Info -->
              <div class="row mb-3">
                <div class="col-12">
                  <div class="d-flex justify-content-between align-items-center">
                    <div class="text-muted">
                      Showing <?php echo count($approved); ?> of <?php echo $total_count; ?> items
                      <?php if ($search || $type_filter || $amount_min !== null || $amount_max !== null): ?>
                        (filtered)
                      <?php endif; ?>
                    </div>
                    <div class="text-muted">
                      Page <?php echo $page; ?> of <?php echo max(1, ceil($total_count / $per_page)); ?>
                    </div>
                  </div>
                </div>
              </div>

              <?php include __DIR__ . '/partial_list.php'; ?>

              <!-- Pagination -->
              <?php if ($total_count > $per_page): ?>
              <div class="row mt-4">
                <div class="col-12">
                  <nav aria-label="Approved items pagination">
                    <?php
                    $total_pages = ceil($total_count / $per_page);
                    $current_page = $page;
                    
                    // Build query string for pagination links
                    $query_params = array_filter([
                        'search' => $search,
                        'type' => $type_filter,
                        'amount_min' => $amount_min,
                        'amount_max' => $amount_max,
                        'sort' => $sort
                    ]);
                    ?>
                    <ul class="pagination justify-content-center">
                      <!-- Previous Page -->
                      <?php if ($current_page > 1): ?>
                      <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($query_params, ['page' => $current_page - 1])); ?>">
                          <i class="fas fa-chevron-left"></i> Previous
                        </a>
                      </li>
                      <?php else: ?>
                      <li class="page-item disabled">
                        <span class="page-link"><i class="fas fa-chevron-left"></i> Previous</span>
                      </li>
                      <?php endif; ?>

                      <!-- Page Numbers -->
                      <?php
                      $start_page = max(1, $current_page - 2);
                      $end_page = min($total_pages, $current_page + 2);
                      
                      if ($start_page > 1): ?>
                      <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($query_params, ['page' => 1])); ?>">1</a>
                      </li>
                      <?php if ($start_page > 2): ?>
                      <li class="page-item disabled"><span class="page-link">...</span></li>
                      <?php endif; ?>
                      <?php endif; ?>

                      <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                      <li class="page-item <?php echo $i === $current_page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($query_params, ['page' => $i])); ?>"><?php echo $i; ?></a>
                      </li>
                      <?php endfor; ?>

                      <?php if ($end_page < $total_pages): ?>
                      <?php if ($end_page < $total_pages - 1): ?>
                      <li class="page-item disabled"><span class="page-link">...</span></li>
                      <?php endif; ?>
                      <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($query_params, ['page' => $total_pages])); ?>"><?php echo $total_pages; ?></a>
                      </li>
                      <?php endif; ?>

                      <!-- Next Page -->
                      <?php if ($current_page < $total_pages): ?>
                      <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($query_params, ['page' => $current_page + 1])); ?>">
                          Next <i class="fas fa-chevron-right"></i>
                        </a>
                      </li>
                      <?php else: ?>
                      <li class="page-item disabled">
                        <span class="page-link">Next <i class="fas fa-chevron-right"></i></span>
                      </li>
                      <?php endif; ?>
                    </ul>
                  </nav>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>

<!-- Edit Pledge Modal -->
<div class="modal fade" id="editPledgeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Approved Pledge</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="index.php">
        <div class="modal-body">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="update_pledge">
          <input type="hidden" name="pledge_id" id="editPledgeId">
          <div class="row">
            <div class="col-md-6">
              <div class="mb-3">
                <label class="form-label">Donor Name</label>
                <input type="text" class="form-control" id="editDonorName" name="donor_name" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="mb-3">
                <label class="form-label">Phone Number</label>
                <input type="tel" class="form-control" id="editDonorPhone" name="donor_phone" required>
              </div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Email (Optional)</label>
            <input type="email" class="form-control" id="editDonorEmail" name="donor_email">
          </div>
          <div class="row">
            <div class="col-md-6">
              <div class="mb-3">
                <label class="form-label">Amount (£)</label>
                <input type="number" class="form-control" id="editAmount" name="amount" step="0.01" min="0" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="mb-3">
                <label class="form-label">Package (optional)</label>
                <select class="form-select" id="editPackageId" name="package_id">
                  <option value="">— None —</option>
                  <?php foreach ($pkgRows as $pkg): ?>
                  <option value="<?php echo (int)$pkg['id']; ?>" data-sqm="<?php echo htmlspecialchars($pkg['sqm_meters']); ?>" data-price="<?php echo htmlspecialchars($pkg['price']); ?>">
                    <?php echo htmlspecialchars($pkg['label']); ?> (<?php echo number_format((float)$pkg['sqm_meters'], 2); ?> m² · £<?php echo number_format((float)$pkg['price'], 2); ?>)
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Notes</label>
            <textarea class="form-control" id="editNotes" name="notes" rows="3"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Payment Modal -->
<div class="modal fade" id="editPaymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-credit-card me-2"></i>Edit Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="index.php">
        <div class="modal-body">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="update_payment">
          <input type="hidden" name="payment_id" id="editPaymentId">
          <div class="mb-3">
            <label class="form-label">Amount (£)</label>
            <input type="number" class="form-control" id="editPaymentAmount" name="payment_amount" step="0.01" min="0" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Method</label>
            <select class="form-select" id="editPaymentMethod" name="payment_method">
              <option value="cash">Cash</option>
              <option value="card">Card</option>
              <option value="bank">Bank</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Reference</label>
            <input type="text" class="form-control" id="editPaymentReference" name="payment_reference">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.js'); ?>"></script>
<script src="assets/approved.js?v=<?php echo @filemtime(__DIR__ . '/assets/approved.js'); ?>"></script>
<script>
function openEditPledgeModal(id, name, phone, email, amount, sqm, notes) {
  document.getElementById('editPledgeId').value = id;
  document.getElementById('editDonorName').value = name;
  document.getElementById('editDonorPhone').value = phone;
  document.getElementById('editDonorEmail').value = email || '';
  document.getElementById('editAmount').value = amount;
  document.getElementById('editNotes').value = notes || '';

  // Preselect package if sqm matches one
  var pkgSelect = document.getElementById('editPackageId');
  if (pkgSelect) {
    var matched = false;
    for (var i = 0; i < pkgSelect.options.length; i++) {
      var opt = pkgSelect.options[i];
      var sqmAttr = parseFloat(opt.getAttribute('data-sqm'));
      if (!isNaN(sqmAttr) && Math.abs(sqmAttr - parseFloat(sqm || 0)) < 0.00001) {
        pkgSelect.selectedIndex = i;
        matched = true;
        // If package has a price, set amount accordingly
        var priceAttr = parseFloat(opt.getAttribute('data-price'));
        if (!isNaN(priceAttr) && priceAttr > 0) {
          document.getElementById('editAmount').value = priceAttr;
        }
        break;
      }
    }
    if (!matched) {
      pkgSelect.selectedIndex = 0; // None
    }
  }
}
function openEditPaymentModal(paymentId, amount, method, reference) {
  document.getElementById('editPaymentId').value = paymentId;
  document.getElementById('editPaymentAmount').value = amount;
  document.getElementById('editPaymentMethod').value = method || 'cash';
  document.getElementById('editPaymentReference').value = reference || '';
}

// When package changes, auto-fill amount from the selected package's price
document.addEventListener('DOMContentLoaded', function() {
  var pkg = document.getElementById('editPackageId');
  var amt = document.getElementById('editAmount');
  if (pkg && amt) {
    pkg.addEventListener('change', function() {
      var sel = pkg.options[pkg.selectedIndex];
      if (!sel || !sel.getAttribute) return;
      var price = parseFloat(sel.getAttribute('data-price'));
      if (!isNaN(price) && price > 0) {
        amt.value = price.toFixed(2);
      }
    });
  }
});

// Check for floor map refresh trigger
<?php if (isset($_SESSION['trigger_floor_refresh']) && $_SESSION['trigger_floor_refresh']): ?>
// Clear the flag
<?php unset($_SESSION['trigger_floor_refresh']); ?>

// Trigger immediate floor map refresh
console.log('Admin action completed - triggering floor map refresh');
localStorage.setItem('floorMapRefresh', Date.now());

// Also try to call refresh function directly on any open floor map windows
try {
    // Check all open windows/tabs
    for (let i = 0; i < window.length; i++) {
        try {
            if (window[i] && window[i].refreshFloorMap) {
                window[i].refreshFloorMap();
            }
        } catch(e) { /* Cross-origin restrictions */ }
    }
} catch(e) { /* No access to other windows */ }

// Show user feedback
setTimeout(() => {
    const alert = document.createElement('div');
    alert.className = 'alert alert-success alert-dismissible fade show position-fixed';
    alert.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    alert.innerHTML = `
        <i class="fas fa-sync-alt me-2"></i>Floor map refresh signal sent!
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alert);
    setTimeout(() => alert.remove(), 3000);
}, 100);
<?php endif; ?>
</script>
</body>
</html>


