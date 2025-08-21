<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/IntelligentGridDeallocator.php';
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

                // Note: payments are standalone; no pledge_id linkage anymore

                // Deallocate floor grid cells using the intelligent deallocator
                $gridDeallocator = new IntelligentGridDeallocator($db);
                $deallocationResult = $gridDeallocator->deallocatePledge($pledgeId);
                
                if (!$deallocationResult['success']) {
                    throw new RuntimeException('Floor deallocation failed: ' . $deallocationResult['error']);
                }

                // Audit log
                $uid = (int)(current_user()['id'] ?? 0);
                $before = json_encode([
                    'status' => 'approved',
                    'floor_cells' => $deallocationResult['deallocated_cells'] ?? []
                ], JSON_UNESCAPED_SLASHES);
                $after = json_encode([
                    'status' => 'pending',
                    'deallocation_result' => $deallocationResult
                ], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'undo_approve', ?, ?, 'admin')");
                $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
                $log->execute();

                $db->commit();
                $actionMsg = 'Approval undone' . ($deallocationResult['deallocated_count'] > 0 ? " - {$deallocationResult['deallocated_count']} floor cell(s) freed" : '');
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

                // Deallocate floor grid cells for the old amount
                $gridDeallocator = new IntelligentGridDeallocator($db);
                $deallocationResult = $gridDeallocator->deallocatePledge($pledgeId);
                
                if (!$deallocationResult['success']) {
                    throw new RuntimeException('Floor deallocation failed: ' . $deallocationResult['error']);
                }

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

                // Deallocate floor grid cells for the old payment amount
                $gridDeallocator = new IntelligentGridDeallocator($db);
                $deallocationResult = $gridDeallocator->deallocatePayment($paymentId);
                
                if (!$deallocationResult['success']) {
                    throw new RuntimeException('Floor deallocation failed: ' . $deallocationResult['error']);
                }

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
                $gridDeallocator = new IntelligentGridDeallocator($db);
                $deallocationResult = $gridDeallocator->deallocatePayment($paymentId);
                
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
            } catch (Throwable $e) {
                $db->rollback();
                $actionMsg = 'Error: ' . $e->getMessage();
            }
        }
    }

    header('Location: index.php?msg=' . urlencode($actionMsg));
    exit;
}

// List approved pledges and approved standalone payments in a single list
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
  WHERE p.status = 'approved')
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
  WHERE pay.status = 'approved')
ORDER BY approved_at DESC, created_at DESC";

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
              <?php include __DIR__ . '/partial_list.php'; ?>
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
</script>
</body>
</html>


