<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/IntelligentGridAllocator.php';
require_login();
require_admin();

$page_title = 'Pending Approvals';
$db = db();
$actionMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // htmx posts will include HX-Request header; still support normal POST
    verify_csrf();
    $pledgeId = (int)($_POST['pledge_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($pledgeId && in_array($action, ['approve','reject','update'], true)) {
        $db->begin_transaction();
        try {
            $stmt = $db->prepare('SELECT id, amount, type, status FROM pledges WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $pledgeId);
            $stmt->execute();
            $pledge = $stmt->get_result()->fetch_assoc();
            if (!$pledge || $pledge['status'] !== 'pending') { throw new RuntimeException('Invalid state'); }

                if ($action === 'approve') {
                $upd = $db->prepare("UPDATE pledges SET status='approved', approved_by_user_id=?, approved_at=NOW() WHERE id=?");
                $uid = (int)current_user()['id'];
                $upd->bind_param('ii', $uid, $pledgeId);
                $upd->execute();

                // Robust counters update: only and exactly on admin approval
                // Determine deltas based on pledge type
                $deltaPaid = 0.0;
                $deltaPledged = 0.0;
                if ((string)$pledge['type'] === 'paid') {
                    $deltaPaid = (float)$pledge['amount'];
                } else {
                    $deltaPledged = (float)$pledge['amount'];
                }

                // Ensure counters row exists and atomically increment fields
                // grand_total is computed from the post-update values
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

                // Allocate floor grid cells with the new intelligent allocator
                $gridAllocator = new IntelligentGridAllocator($db);
                $donorName = (string)($pledge['donor_name'] ?? 'Anonymous');
                $packageId = isset($pledge['package_id']) ? (int)$pledge['package_id'] : null;
                $status = ($pledge['type'] === 'paid') ? 'paid' : 'pledged';
                
                $allocationResult = $gridAllocator->allocate(
                    $pledgeId,
                    null, // No payment ID for a pledge
                    (float)$pledge['amount'],
                    $packageId,
                    $donorName,
                    $status
                );
                
                // Audit log
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $ipBin = $ip ? @inet_pton($ip) : null;
                $before = json_encode(['status' => 'pending', 'type' => $pledge['type'], 'amount' => (float)$pledge['amount']], JSON_UNESCAPED_SLASHES);
                $after  = json_encode([
                    'status' => 'approved',
                    'grid_allocation' => $allocationResult
                ], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, ip_address, source) VALUES(?, 'pledge', ?, 'approve', ?, ?, ?, 'admin')");
                $log->bind_param('iisss', $uid, $pledgeId, $before, $after, $ipBin);
                // Workaround: mysqli doesn't support 'b' bind natively well; fallback to send as NULL/escaped string
                // So we re-prepare without ip if bind fails
                if (!$log->execute()) {
                    $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'approve', ?, ?, 'admin')");
                    $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
                    $log->execute();
                }
                
                $actionMsg = $allocationResult['success'] 
                    ? "Approved & {$allocationResult['message']} (Grid allocation failed: {$allocationResult['error']})" 
                    : "Approved (Grid allocation failed: {$allocationResult['error']})";
            } elseif ($action === 'reject') {
                $uid = (int)current_user()['id'];
                $rej = $db->prepare("UPDATE pledges SET status='rejected' WHERE id = ?");
                $rej->bind_param('i', $pledgeId);
                $rej->execute();

                // Audit log
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $ipBin = $ip ? @inet_pton($ip) : null;
                $before = json_encode(['status' => 'pending'], JSON_UNESCAPED_SLASHES);
                $after  = json_encode(['status' => 'rejected'], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, ip_address, source) VALUES(?, 'pledge', ?, 'reject', ?, ?, ?, 'admin')");
                $log->bind_param('iisss', $uid, $pledgeId, $before, $after, $ipBin);
                if (!$log->execute()) {
                    $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'reject', ?, ?, 'admin')");
                    $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
                    $log->execute();
                }
                $actionMsg = 'Rejected';
            } elseif ($action === 'update') {
                // Update pledge details
                $donorName = trim($_POST['donor_name'] ?? '');
                $donorPhone = trim($_POST['donor_phone'] ?? '');
                $donorEmail = trim($_POST['donor_email'] ?? '');
                $amount = (float)($_POST['amount'] ?? 0);
                $notes = trim($_POST['notes'] ?? '');
                $packageId = isset($_POST['package_id']) ? (int)$_POST['package_id'] : null;
                // Normalize and validate UK mobile (07XXXXXXXXX)
                if ($donorPhone !== '') {
                    $digits = preg_replace('/[^0-9+]/', '', $donorPhone);
                    if (strpos($digits, '+44') === 0) { $digits = '0' . substr($digits, 3); }
                    if (!preg_match('/^07\d{9}$/', $digits)) {
                        throw new RuntimeException('Phone must be a valid UK mobile (start with 07)');
                    }
                    $donorPhone = $digits;
                }
                // Prevent duplicate pending/approved pledges or payments for same phone (excluding this record)
                if ($donorPhone !== '') {
                    $chk = $db->prepare("SELECT id FROM pledges WHERE donor_phone=? AND status IN ('pending','approved') AND id<>? LIMIT 1");
                    $chk->bind_param('si', $donorPhone, $pledgeId);
                    $chk->execute();
                    if ($chk->get_result()->fetch_assoc()) {
                        throw new RuntimeException('Another pledge exists with this phone');
                    }
                    $chk->close();
                    $chk2 = $db->prepare("SELECT id FROM payments WHERE donor_phone=? AND status IN ('pending','approved') LIMIT 1");
                    $chk2->bind_param('s', $donorPhone);
                    $chk2->execute();
                    if ($chk2->get_result()->fetch_assoc()) {
                        throw new RuntimeException('A payment exists with this phone');
                    }
                }
                
                if ($donorName && $donorPhone && $amount > 0) {
                    // Update pledge to new model (package_id driven)
                    if ($packageId && $packageId > 0) {
                        $upd = $db->prepare("UPDATE pledges SET donor_name=?, donor_phone=?, donor_email=?, amount=?, package_id=?, notes=? WHERE id=?");
                        $upd->bind_param('sssdisi', $donorName, $donorPhone, $donorEmail, $amount, $packageId, $notes, $pledgeId);
                    } else {
                        $upd = $db->prepare("UPDATE pledges SET donor_name=?, donor_phone=?, donor_email=?, amount=?, notes=? WHERE id=?");
                        $upd->bind_param('sssdsi', $donorName, $donorPhone, $donorEmail, $amount, $notes, $pledgeId);
                    }
                    $upd->execute();
                    
                    // Audit log
                    $uid = (int)current_user()['id'];
                    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                    $ipBin = $ip ? @inet_pton($ip) : null;
                    $before = json_encode(['status' => 'pending'], JSON_UNESCAPED_SLASHES);
                    $after = json_encode(['donor_name' => $donorName, 'amount' => $amount, 'package_id' => $packageId, 'updated' => true], JSON_UNESCAPED_SLASHES);
                    $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'update', ?, ?, 'admin')");
                    $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
                    $log->execute();
                    
                    $actionMsg = 'Updated successfully';
                } else {
                    throw new RuntimeException('Invalid data provided');
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            $actionMsg = 'Error: ' . $e->getMessage();
        }
    }

    // Payments workflow (standalone payments with status lifecycle)
    if (in_array($action, ['approve_payment','reject_payment','update_payment'], true)) {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        if ($paymentId > 0) {
            $db->begin_transaction();
            try {
                // Lock payment
                $sel = $db->prepare("SELECT id, amount, status FROM payments WHERE id=? FOR UPDATE");
                $sel->bind_param('i', $paymentId);
                $sel->execute();
                $pay = $sel->get_result()->fetch_assoc();
                if (!$pay) { throw new RuntimeException('Payment not found'); }

                if ($action === 'approve_payment') {
                    if ((string)$pay['status'] !== 'pending') { throw new RuntimeException('Payment not pending'); }
                    $upd = $db->prepare("UPDATE payments SET status='approved' WHERE id=?");
                    $upd->bind_param('i', $paymentId);
                    $upd->execute();

                    // Counters: increment paid_total
                    $amt = (float)$pay['amount'];
                    $ctr = $db->prepare(
                        "INSERT INTO counters (id, paid_total, pledged_total, grand_total, version, recalc_needed)
                         VALUES (1, ?, 0, ?, 1, 0)
                         ON DUPLICATE KEY UPDATE
                           paid_total = paid_total + VALUES(paid_total),
                           grand_total = grand_total + VALUES(grand_total),
                           version = version + 1,
                           recalc_needed = 0"
                    );
                    $grandDelta = $amt;
                    $ctr->bind_param('dd', $amt, $grandDelta);
                    $ctr->execute();

                    // Allocate floor grid cells for payment with the new intelligent allocator
                    $gridAllocator = new IntelligentGridAllocator($db);
                    
                    // Get payment details for allocation
                    $paymentDetails = $db->prepare("SELECT donor_name, amount, package_id FROM payments WHERE id = ?");
                    $paymentDetails->bind_param('i', $paymentId);
                    $paymentDetails->execute();
                    $paymentData = $paymentDetails->get_result()->fetch_assoc();

                    if ($paymentData) {
                        $allocationResult = $gridAllocator->allocate(
                            null, // No pledge ID for a direct payment
                            $paymentId,
                            (float)$paymentData['amount'],
                            isset($paymentData['package_id']) ? (int)$paymentData['package_id'] : null,
                            (string)$paymentData['donor_name'],
                            'paid'
                        );
                        $actionMsg .= " Grid allocation: " . ($allocationResult['success'] ? $allocationResult['message'] : $allocationResult['error']);
                    }
                    // No need to update audit log here as it's handled separately for payments
                } else if ($action === 'reject_payment') {
                    // Mark as voided. No counter change.
                    if ((string)$pay['status'] !== 'pending') { throw new RuntimeException('Payment not pending'); }
                    $upd = $db->prepare("UPDATE payments SET status='voided' WHERE id=?");
                    $upd->bind_param('i', $paymentId);
                    $upd->execute();

                    $uid = (int)current_user()['id'];
                    $before = json_encode(['status'=>'pending'], JSON_UNESCAPED_SLASHES);
                    $after  = json_encode(['status'=>'voided'], JSON_UNESCAPED_SLASHES);
                    $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment', ?, 'reject', ?, ?, 'admin')");
                    $log->bind_param('iiss', $uid, $paymentId, $before, $after);
                    $log->execute();
                    $actionMsg = 'Payment rejected';
                } else if ($action === 'update_payment') {
                    // Update standalone payment fields while keeping status pending
                    if ((string)$pay['status'] !== 'pending') { throw new RuntimeException('Payment not pending'); }
                    $donorName = trim($_POST['donor_name'] ?? '');
                    $donorPhone = trim($_POST['donor_phone'] ?? '');
                    $donorEmail = trim($_POST['donor_email'] ?? '');
                    $amount = (float)($_POST['amount'] ?? 0);
                    $notes = trim($_POST['notes'] ?? '');
                    $packageId = isset($_POST['package_id']) ? (int)$_POST['package_id'] : null;
                    $method = trim((string)($_POST['method'] ?? 'cash'));

                    if ($donorName && $amount > 0) {
                        if ($packageId && $packageId > 0) {
                            $upd = $db->prepare("UPDATE payments SET donor_name=?, donor_phone=?, donor_email=?, amount=?, method=?, package_id=?, reference=? WHERE id=?");
                            $upd->bind_param('sssdsisi', $donorName, $donorPhone, $donorEmail, $amount, $method, $packageId, $notes, $paymentId);
                        } else {
                            $upd = $db->prepare("UPDATE payments SET donor_name=?, donor_phone=?, donor_email=?, amount=?, method=?, reference=? WHERE id=?");
                            $upd->bind_param('sssds si', $donorName, $donorPhone, $donorEmail, $amount, $method, $notes, $paymentId);
                        }
                        $upd->execute();

                        // Audit
                        $uid = (int)current_user()['id'];
                        $before = json_encode(['status'=>'pending'], JSON_UNESCAPED_SLASHES);
                        $after  = json_encode(['amount'=>$amount,'method'=>$method,'package_id'=>$packageId,'updated'=>true], JSON_UNESCAPED_SLASHES);
                        $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment', ?, 'update', ?, ?, 'admin')");
                        $log->bind_param('iiss', $uid, $paymentId, $before, $after);
                        $log->execute();

                        $actionMsg = 'Payment updated successfully';
                    } else {
                        throw new RuntimeException('Invalid data provided');
                    }
                }

                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                $actionMsg = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: index.php?msg=' . urlencode($actionMsg));
        exit;
    }
    // PRG: redirect to avoid resubmission and show flash message
    header('Location: index.php?msg=' . urlencode($actionMsg));
    exit;
}

// Combined pending items (pledges + payments), newest first
$combinedSql = "
SELECT 'pledge' AS item_type, p.id AS item_id, p.amount, NULL AS method, p.notes, p.created_at,
       NULL AS sqm_meters, p.anonymous, p.donor_name, p.donor_phone, p.donor_email,
       u.name AS registrar_name, NULL AS sqm_unit, NULL AS sqm_quantity, NULL AS price_per_sqm,
       dp.label AS package_label, dp.price AS package_price, dp.sqm_meters AS package_sqm, p.package_id AS package_id
FROM pledges p
LEFT JOIN users u ON p.created_by_user_id = u.id
LEFT JOIN donation_packages dp ON dp.id = p.package_id
WHERE p.status = 'pending'
UNION ALL
SELECT 'payment' AS item_type, pay.id AS item_id, pay.amount, pay.method, pay.reference AS notes, pay.created_at,
       NULL AS sqm_meters, 0 AS anonymous, pay.donor_name, pay.donor_phone, pay.donor_email,
       u2.name AS registrar_name, NULL AS sqm_unit, NULL AS sqm_quantity, NULL AS price_per_sqm,
       dp2.label AS package_label, dp2.price AS package_price, dp2.sqm_meters AS package_sqm, pay.package_id AS package_id
FROM payments pay
LEFT JOIN users u2 ON u2.id = pay.received_by_user_id
LEFT JOIN donation_packages dp2 ON dp2.id = pay.package_id
WHERE pay.status = 'pending'
ORDER BY created_at DESC
";
$pending_items = $db->query($combinedSql)->fetch_all(MYSQLI_ASSOC);

// Diagnostics: counts by type to verify data presence
$counts = ['pledge' => 0, 'paid' => 0];
$cntRes = $db->query("SELECT type, COUNT(*) as c FROM pledges WHERE status='pending' GROUP BY type");
if ($cntRes) {
    while ($row = $cntRes->fetch_assoc()) {
        $t = strtolower((string)$row['type']);
        if (isset($counts[$t])) { $counts[$t] = (int)$row['c']; }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1%">
  <title>Pending Approvals - Fundraising Admin</title>
  <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.css'); ?>">
  <link rel="stylesheet" href="assets/approvals.css?v=<?php echo @filemtime(__DIR__ . '/assets/approvals.css'); ?>">
</head>
<body>
<div class="admin-wrapper">
  <?php include '../includes/sidebar.php'; ?>
  
  <div class="admin-content">
    <?php include '../includes/topbar.php'; ?>
    
    <main class="main-content">
      <div class="row">
        <div class="col-12">
          <?php if ($actionMsg): ?>
            <div class="alert alert-info alert-dismissible fade show animate-fade-in" role="alert">
              <i class="fas fa-info-circle me-2"></i>
              <?php echo htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8'); ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
          <?php endif; ?>
          
          <div class="card animate-fade-in">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                  <i class="fas fa-clock text-warning me-2"></i>
                  Pending Approvals
                </h5>
                <div class="d-flex gap-2">
                  <a href="../approved/" class="btn btn-sm btn-outline-success">
                    <i class="fas fa-check-circle"></i> View Approved
                  </a>
                  <button class="btn btn-sm btn-outline-primary" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> Manual Refresh
                  </button>
                  <button class="btn btn-sm btn-outline-secondary" id="autoRefreshBtn" onclick="toggleAutoRefresh()">
                    <i class="fas fa-play"></i> <span id="autoRefreshText">Auto Refresh</span>
                  </button>
                </div>
              </div>
              <div class="card-body">
                <?php include __DIR__ . '/partial_list.php'; ?>
              </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>

<!-- Details Modal (modern layout) -->
<div class="modal fade details-modal" id="detailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header details-header">
        <div class="details-header-main">
          <div class="amount-large" id="dAmountLarge">£ 0.00</div>
          <div class="chips">
            <span class="chip chip-type" id="dTypeBadge">PAID</span>
            <span class="chip chip-anon d-none" id="dAnonChip"><i class="fas fa-user-secret me-1"></i>Anonymous</span>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="detail-grid">
          <div class="detail-card">
            <div class="detail-title"><i class="fas fa-user me-2"></i>Donor</div>
            <div class="detail-row"><span>Name</span><span id="dDonorName">—</span></div>
            <div class="detail-row"><span>Phone</span><span id="dPhone">—</span></div>
            <div class="detail-row"><span>Email</span><span id="dEmail">—</span></div>
          </div>
          <div class="detail-card">
            <div class="detail-title"><i class="fas fa-ruler-combined me-2"></i>Pledge</div>
            <div class="detail-row"><span>Square meters</span><span id="dSqm">—</span></div>
            <div class="detail-row"><span>Created</span><span id="dCreated">—</span></div>
            <div class="detail-row"><span>Registrar</span><span id="dRegistrar">—</span></div>
          </div>
          <div class="detail-card detail-notes">
            <div class="detail-title"><i class="fas fa-sticky-note me-2"></i>Notes</div>
            <div class="notes-box" id="dNotes">—</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade edit-modal" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editModalLabel">
          <i class="fas fa-edit me-2"></i>Edit Pledge Details
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" id="editForm" action="index.php">
        <div class="modal-body">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" id="editAction" value="update">
          <input type="hidden" name="pledge_id" id="editPledgeId">
          <input type="hidden" name="payment_id" id="editPaymentId">
          <input type="hidden" name="method" id="editMethodHidden">
          
          <div class="row">
            <div class="col-md-6">
              <div class="mb-3">
                <label for="editDonorName" class="form-label">Donor Name</label>
                <input type="text" class="form-control" id="editDonorName" name="donor_name" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="mb-3">
                <label for="editDonorPhone" class="form-label">Phone Number</label>
                <input type="tel" class="form-control" id="editDonorPhone" name="donor_phone" required>
              </div>
            </div>
          </div>
          
          <div class="mb-3">
            <label for="editDonorEmail" class="form-label">Email (Optional)</label>
            <input type="email" class="form-control" id="editDonorEmail" name="donor_email">
          </div>
          
          <div class="row">
            <div class="col-md-6">
              <div class="mb-3">
                <label for="editAmount" class="form-label">Amount (£)</label>
                <input type="number" class="form-control" id="editAmount" name="amount" step="0.01" min="0" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="mb-3">
                <label for="editPackage" class="form-label">Package (optional)</label>
                <select id="editPackage" name="package_id" class="form-select">
                  <option value="">— Select package —</option>
                  <?php
                  $pkgs = $db->query("SELECT id,label FROM donation_packages WHERE active=1 ORDER BY sort_order, id")->fetch_all(MYSQLI_ASSOC);
                  foreach ($pkgs as $pkg) {
                      echo '<option value="'.(int)$pkg['id'].'">'.htmlspecialchars($pkg['label']).'</option>';
                  }
                  ?>
                </select>
              </div>
            </div>
          </div>
          <div class="mb-3" id="editMethodWrap" style="display:none">
            <label for="editMethod" class="form-label">Payment Method</label>
            <select id="editMethod" class="form-select" onchange="document.getElementById('editMethodHidden').value=this.value;">
              <option value="cash">Cash</option>
              <option value="card">Card</option>
              <option value="bank">Bank</option>
              <option value="other">Other</option>
            </select>
          </div>
          
          <div class="mb-3">
            <label for="editNotes" class="form-label">Notes</label>
            <textarea class="form-control" id="editNotes" name="notes" rows="3"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-1"></i>Update Pledge
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.js'); ?>"></script>
<script src="assets/approvals.js?v=<?php echo @filemtime(__DIR__ . '/assets/approvals.js'); ?>"></script>

<script>
function openEditModal(id, name, phone, email, amount, sqm, notes, kind) {
    // Reset ids
    document.getElementById('editPledgeId').value = '';
    document.getElementById('editPaymentId').value = '';
    document.getElementById('editMethodHidden').value = '';

    document.getElementById('editDonorName').value = name || '';
    document.getElementById('editDonorPhone').value = phone || '';
    document.getElementById('editDonorEmail').value = email || '';
    document.getElementById('editAmount').value = amount || 0;
    document.getElementById('editNotes').value = notes || '';

    const methodWrap = document.getElementById('editMethodWrap');
    const actionField = document.getElementById('editAction');

    if ((kind || '').toLowerCase() === 'payment') {
        // Payment edit
        actionField.value = 'update_payment';
        document.getElementById('editPaymentId').value = id;
        methodWrap.style.display = '';
    } else {
        // Pledge edit
        actionField.value = 'update';
        document.getElementById('editPledgeId').value = id;
        methodWrap.style.display = 'none';
    }
}

// Click-to-open details modal for approval cards
document.addEventListener('click', function(e){
  const card = e.target.closest('.approval-item');
  if (!card) return;
  // ignore if clicking on action buttons/forms
  if (e.target.closest('.approval-actions')) return;
  const fmt = (n) => new Intl.NumberFormat('en-GB', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(Number(n)||0);
  const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
  const type = (card.dataset.type||'—').toUpperCase();
  const amount = '£ ' + fmt(card.dataset.amount||0);
  const anon = Number(card.dataset.anonymous)===1;
  document.getElementById('dAmountLarge').textContent = amount;
  const typeBadge = document.getElementById('dTypeBadge');
  typeBadge.textContent = type;
  typeBadge.classList.toggle('is-paid', type==='PAID');
  typeBadge.classList.toggle('is-pledge', type==='PLEDGE');
  document.getElementById('dAnonChip').classList.toggle('d-none', !anon);
  document.getElementById('dDonorName').textContent = card.dataset.donorName||'—';
  document.getElementById('dPhone').textContent = card.dataset.donorPhone||'—';
  document.getElementById('dEmail').textContent = card.dataset.donorEmail||'—';
  if ((card.dataset.type||'').toLowerCase()==='payment') {
    // For payments, show package if available, otherwise method
    const pkg = card.dataset.packageLabel || '';
    document.getElementById('dSqm').textContent = pkg ? pkg : '—';
  } else {
    document.getElementById('dSqm').textContent = fmt(card.dataset.sqmMeters||0) + ' m²';
  }
  document.getElementById('dCreated').textContent = card.dataset.createdAt||'—';
  document.getElementById('dRegistrar').textContent = card.dataset.registrar||'—';
  document.getElementById('dNotes').textContent = card.dataset.notes||'—';
  modal.show();
});

// Nothing else needed: actions use forms and the PRG pattern
</script>

<!-- Auto-Refresh Functionality -->
<script>
// Auto-refresh state management
let autoRefreshInterval = null;
let isAutoRefreshActive = false;
const REFRESH_INTERVAL = 2000; // 2 seconds

// Toggle auto-refresh functionality
function toggleAutoRefresh() {
    const btn = document.getElementById('autoRefreshBtn');
    const text = document.getElementById('autoRefreshText');
    const icon = btn.querySelector('i');
    
    if (isAutoRefreshActive) {
        // Stop auto-refresh
        stopAutoRefresh();
        btn.classList.remove('btn-success');
        btn.classList.add('btn-outline-secondary');
        icon.className = 'fas fa-play';
        text.textContent = 'Auto Refresh';
    } else {
        // Start auto-refresh
        startAutoRefresh();
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-success');
        icon.className = 'fas fa-pause';
        text.textContent = 'Auto ON';
    }
}

// Start auto-refresh
function startAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
    
    isAutoRefreshActive = true;
    autoRefreshInterval = setInterval(() => {
        // Check if any modals are open - don't refresh if user is interacting
        const openModals = document.querySelectorAll('.modal.show');
        if (openModals.length === 0) {
            refreshPage();
        }
    }, REFRESH_INTERVAL);
    
    console.log('Auto-refresh started: Every 2 seconds');
}

// Stop auto-refresh
function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
    isAutoRefreshActive = false;
    console.log('Auto-refresh stopped');
}

// Refresh the page
function refreshPage() {
    // Add a subtle loading indicator
    const btn = document.getElementById('autoRefreshBtn');
    const originalHTML = btn.innerHTML;
    
    // Show loading state briefly
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Refreshing...</span>';
    
    // Refresh the page
    setTimeout(() => {
        location.reload();
    }, 300);
}

// Initialize auto-refresh state from localStorage
document.addEventListener('DOMContentLoaded', function() {
    const savedState = localStorage.getItem('approvalsAutoRefresh');
    if (savedState === 'true') {
        toggleAutoRefresh(); // This will start auto-refresh
    }
});

// Save auto-refresh state to localStorage
function saveAutoRefreshState() {
    localStorage.setItem('approvalsAutoRefresh', isAutoRefreshActive.toString());
}

// Update the toggle function to save state
const originalToggle = toggleAutoRefresh;
toggleAutoRefresh = function() {
    originalToggle();
    saveAutoRefreshState();
};

// Stop auto-refresh when page visibility changes (user switches tabs)
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        if (isAutoRefreshActive) {
            stopAutoRefresh();
            // Will restart when page becomes visible
        }
    } else {
        // Page became visible again
        const savedState = localStorage.getItem('approvalsAutoRefresh');
        if (savedState === 'true' && !isAutoRefreshActive) {
            startAutoRefresh();
        }
    }
});

// Stop auto-refresh when user is about to leave the page
window.addEventListener('beforeunload', function() {
    stopAutoRefresh();
});

// Keyboard shortcut: Ctrl+R or F5 to toggle auto-refresh
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey && e.key === 'r') || e.key === 'F5') {
        e.preventDefault();
        if (isAutoRefreshActive) {
            // If auto-refresh is on, just do a manual refresh
            location.reload();
        } else {
            // If auto-refresh is off, start it
            toggleAutoRefresh();
        }
    }
});
</script>

<script>
// Fallback for sidebar toggle if admin.js failed to attach for any reason
if (typeof window.toggleSidebar !== 'function') {
  window.toggleSidebar = function() {
    var body = document.body;
    var sidebar = document.getElementById('sidebar');
    var overlay = document.querySelector('.sidebar-overlay');
    if (window.innerWidth <= 991.98) {
      if (sidebar) sidebar.classList.toggle('active');
      if (overlay) overlay.classList.toggle('active');
      body.style.overflow = (sidebar && sidebar.classList.contains('active')) ? 'hidden' : '';
    } else {
      body.classList.toggle('sidebar-collapsed');
    }
  };
}
</script>
</body>
</html>
