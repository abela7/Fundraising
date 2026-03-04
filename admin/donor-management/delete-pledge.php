<?php
declare(strict_types=1);
/**
 * Delete Pledge - Multi-Page Wizard (Option A)
 * Safe, comprehensive deletion with step-by-step verification.
 * Only REJECTED pledges can be deleted.
 */

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

date_default_timezone_set('Europe/London');

$conn = db();

// === VERIFICATION 1/5: Validate and sanitize inputs ===
$pledge_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
$step_raw = (int)($_GET['step'] ?? 1);
$step = max(1, min(6, $step_raw));

if ($pledge_id <= 0 || $donor_id <= 0) {
    header('Location: view-donor.php?id=' . $donor_id);
    exit;
}

// === VERIFICATION 2/5: Check table/column existence before any queries ===
$has_pledge_payments = false;
$pp_check = $conn->query("SHOW TABLES LIKE 'pledge_payments'");
if ($pp_check && $pp_check->num_rows > 0) {
    $has_pledge_payments = true;
}

$has_grid_batches = false;
$gb_check = $conn->query("SHOW TABLES LIKE 'grid_allocation_batches'");
if ($gb_check && $gb_check->num_rows > 0) {
    $has_grid_batches = true;
}

$has_allocation_batch_col = false;
$fc_check = $conn->query("SHOW TABLES LIKE 'floor_grid_cells'");
if ($fc_check && $fc_check->num_rows > 0) {
    $abc_check = $conn->query("SHOW COLUMNS FROM floor_grid_cells LIKE 'allocation_batch_id'");
    $has_allocation_batch_col = ($abc_check && $abc_check->num_rows > 0);
}

$has_last_pledge_id_col = false;
$lpi_check = $conn->query("SHOW COLUMNS FROM donors LIKE 'last_pledge_id'");
if ($lpi_check && $lpi_check->num_rows > 0) {
    $has_last_pledge_id_col = true;
}

// === VERIFICATION 3/5: Fetch pledge and dependencies (all use prepared statements) ===
try {
    $confirmed_pp_count = 0;
    if ($has_pledge_payments) {
        $cp = $conn->prepare("SELECT COUNT(*) as cnt FROM pledge_payments WHERE pledge_id = ? AND status = 'confirmed'");
        $cp->bind_param('i', $pledge_id);
        $cp->execute();
        $confirmed_pp_count = (int)($cp->get_result()->fetch_assoc()['cnt'] ?? 0);
        $cp->close();
    }

    $query = "
        SELECT p.*, d.name as donor_name, d.phone as donor_phone, d.total_pledged, d.balance,
            (SELECT COUNT(*) FROM donor_payment_plans WHERE pledge_id = p.id) as linked_plans,
            (SELECT COUNT(*) FROM payments WHERE pledge_id = p.id) as linked_payments,
            (SELECT COUNT(*) FROM floor_grid_cells WHERE pledge_id = p.id) as allocated_cells
        FROM pledges p
        JOIN donors d ON p.donor_id = d.id
        WHERE p.id = ? AND p.donor_id = ?
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $pledge_id, $donor_id);
    $stmt->execute();
    $pledge = $stmt->get_result()->fetch_object();
    $stmt->close();

    if (!$pledge) {
        header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Pledge not found'));
        exit;
    }

    $cells = [];
    if ($pledge->allocated_cells > 0) {
        $cells_cols = $has_allocation_batch_col
            ? "cell_id, area_size, allocation_batch_id"
            : "cell_id, area_size";
        $cq = $conn->prepare("SELECT {$cells_cols} FROM floor_grid_cells WHERE pledge_id = ? ORDER BY cell_id");
        $cq->bind_param('i', $pledge_id);
        $cq->execute();
        $cells = $cq->get_result()->fetch_all(MYSQLI_ASSOC);
        $cq->close();
    }

    $pledge_payments = [];
    if ($has_pledge_payments) {
        $ppq = $conn->prepare("SELECT id, amount, payment_method, payment_date, reference_number, status FROM pledge_payments WHERE pledge_id = ? ORDER BY payment_date DESC");
        $ppq->bind_param('i', $pledge_id);
        $ppq->execute();
        $pledge_payments = $ppq->get_result()->fetch_all(MYSQLI_ASSOC);
        $ppq->close();
    }

    $linked_payments = [];
    if ($pledge->linked_payments > 0) {
        $pq = $conn->prepare("SELECT id, amount, payment_method, status FROM payments WHERE pledge_id = ? ORDER BY id");
        $pq->bind_param('i', $pledge_id);
        $pq->execute();
        $linked_payments = $pq->get_result()->fetch_all(MYSQLI_ASSOC);
        $pq->close();
    }

    $batches = [];
    if ($has_grid_batches) {
        $bq = $conn->prepare("SELECT id, batch_type, approval_status, original_pledge_id, new_pledge_id FROM grid_allocation_batches WHERE original_pledge_id = ? OR new_pledge_id = ?");
        $bq->bind_param('ii', $pledge_id, $pledge_id);
        $bq->execute();
        $batches = $bq->get_result()->fetch_all(MYSQLI_ASSOC);
        $bq->close();
    }
} catch (Exception $e) {
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Database error: ' . $e->getMessage()));
    exit;
}

// === VERIFICATION 4/5: Eligibility checks (must pass before any step) ===
$can_delete = true;
$block_reason = '';

if ($pledge->status !== 'rejected') {
    $can_delete = false;
    $block_reason = 'Only rejected pledges can be deleted. This pledge has status: "' . ucfirst($pledge->status) . '". Please reject the pledge first.';
}
if ($pledge->linked_plans > 0) {
    $can_delete = false;
    $block_reason = 'This pledge has ' . $pledge->linked_plans . ' active payment plan(s). Delete the payment plan(s) first.';
}
if ($confirmed_pp_count > 0) {
    $can_delete = false;
    $block_reason = 'This pledge has ' . $confirmed_pp_count . ' confirmed pledge payment(s). Void them first before deleting.';
}

// === STEP 6: Execute deletion (only when POST + step=6 + can_delete) ===
if ($step === 6 && $_SERVER['REQUEST_METHOD'] === 'POST' && $can_delete) {
    verify_csrf();

    try {
        $conn->begin_transaction();

        // 1. Deallocate floor_grid_cells (including allocation_batch_id if column exists)
        $cells_deallocated = 0;
        if ($pledge->allocated_cells > 0) {
            $dealloc_sql = "UPDATE floor_grid_cells SET status = 'available', pledge_id = NULL, donor_name = NULL, amount = NULL, assigned_date = NULL";
            if ($has_allocation_batch_col) {
                $dealloc_sql .= ", allocation_batch_id = NULL";
            }
            $dealloc_sql .= " WHERE pledge_id = ?";
            $ds = $conn->prepare($dealloc_sql);
            $ds->bind_param('i', $pledge_id);
            $ds->execute();
            $cells_deallocated = $ds->affected_rows;
            $ds->close();
        }

        // 2. Unlink pledge_payments (only if table exists)
        $unlinked_pp = 0;
        if ($has_pledge_payments) {
            $up = $conn->prepare("UPDATE pledge_payments SET pledge_id = NULL WHERE pledge_id = ?");
            $up->bind_param('i', $pledge_id);
            $up->execute();
            $unlinked_pp = $up->affected_rows;
            $up->close();
        }

        // 3. Unlink payments
        $unlinked_payments = 0;
        if ($pledge->linked_payments > 0) {
            $up2 = $conn->prepare("UPDATE payments SET pledge_id = NULL WHERE pledge_id = ?");
            $up2->bind_param('i', $pledge_id);
            $up2->execute();
            $unlinked_payments = $up2->affected_rows;
            $up2->close();
        }

        // 4. Clear grid_allocation_batches references (prepared statements for safety)
        if ($has_grid_batches && count($batches) > 0) {
            $bo = $conn->prepare("UPDATE grid_allocation_batches SET original_pledge_id = NULL WHERE original_pledge_id = ?");
            $bo->bind_param('i', $pledge_id);
            $bo->execute();
            $bo->close();
            $bn = $conn->prepare("UPDATE grid_allocation_batches SET new_pledge_id = NULL WHERE new_pledge_id = ?");
            $bn->bind_param('i', $pledge_id);
            $bn->execute();
            $bn->close();
        }

        // 5. Audit log (before delete)
        $pledgeData = [
            'id' => $pledge_id,
            'donor_id' => $donor_id,
            'donor_name' => $pledge->donor_name,
            'donor_phone' => $pledge->donor_phone,
            'amount' => (float)$pledge->amount,
            'status' => $pledge->status,
            'cells_deallocated' => $cells_deallocated,
            'unlinked_pledge_payments' => $unlinked_pp,
            'unlinked_payments' => $unlinked_payments,
        ];
        log_audit(
            $conn,
            'delete',
            'pledge',
            $pledge_id,
            $pledgeData,
            ['deleted' => true, 'status_at_deletion' => 'rejected'],
            'admin_portal',
            (int)($_SESSION['user']['id'] ?? 0)
        );

        // 6. Delete the pledge
        $del = $conn->prepare("DELETE FROM pledges WHERE id = ?");
        $del->bind_param('i', $pledge_id);
        $del->execute();
        $del->close();

        // 7. Update donor: pledge_count and last_pledge_id
        $new_last_pledge_id = null;
        if ($has_last_pledge_id_col) {
            $lp = $conn->prepare("SELECT id FROM pledges WHERE donor_id = ? ORDER BY created_at DESC LIMIT 1");
            $lp->bind_param('i', $donor_id);
            $lp->execute();
            $lp_row = $lp->get_result()->fetch_assoc();
            $lp->close();
            if ($lp_row) {
                $new_last_pledge_id = (int)$lp_row['id'];
            }
        }

        $uc = $conn->prepare("UPDATE donors SET pledge_count = (SELECT COUNT(*) FROM pledges WHERE donor_id = ?), updated_at = NOW() WHERE id = ?");
        $uc->bind_param('ii', $donor_id, $donor_id);
        $uc->execute();
        $uc->close();

        if ($has_last_pledge_id_col) {
            if ($new_last_pledge_id !== null) {
                $ul = $conn->prepare("UPDATE donors SET last_pledge_id = ? WHERE id = ?");
                $ul->bind_param('ii', $new_last_pledge_id, $donor_id);
                $ul->execute();
                $ul->close();
            } else {
                $uln = $conn->prepare("UPDATE donors SET last_pledge_id = NULL WHERE id = ?");
                $uln->bind_param('i', $donor_id);
                $uln->execute();
                $uln->close();
            }
        }

        $conn->commit();

        $msg = 'Rejected pledge deleted successfully.';
        if ($cells_deallocated > 0) {
            $msg .= ' ' . $cells_deallocated . ' cell(s) deallocated.';
        }
        if ($unlinked_pp + $unlinked_payments > 0) {
            $msg .= ' ' . ($unlinked_pp + $unlinked_payments) . ' payment(s) unlinked.';
        }
        header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode($msg));
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Delete failed: ' . $e->getMessage()));
        exit;
    }
}

// === VERIFICATION 5/5: Build wizard URLs (sanitized) ===
$base_url = 'delete-pledge.php?id=' . (int)$pledge_id . '&donor_id=' . (int)$donor_id;
$step_titles = [
    1 => 'Overview & Eligibility',
    2 => 'Cell Allocation',
    3 => 'Linked Payments',
    4 => 'Batch References',
    5 => 'Donor Updates',
    6 => 'Confirm & Delete',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Pledge – Step <?php echo (int)$step; ?> of 6</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .wizard-card { background: white; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); max-width: 700px; width: 100%; }
        .wizard-header { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; padding: 1.5rem 2rem; border-radius: 16px 16px 0 0; }
        .wizard-progress { display: flex; justify-content: space-between; margin-top: 1rem; gap: 4px; }
        .wizard-progress .step-dot { width: 28px; height: 28px; border-radius: 50%; background: rgba(255,255,255,0.3); display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 600; }
        .wizard-progress .step-dot.done { background: rgba(255,255,255,0.8); }
        .wizard-progress .step-dot.active { background: white; color: #c82333; }
        .wizard-body { padding: 2rem; }
        .info-box { background: #f8f9fa; border-left: 4px solid #1e3c72; padding: 1rem; margin: 1rem 0; border-radius: 4px; }
        .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 1rem; margin: 1rem 0; border-radius: 4px; }
        .danger-box { background: #f8d7da; border-left: 4px solid #dc3545; padding: 1rem; margin: 1rem 0; border-radius: 4px; }
        .blocked-box { background: #f8d7da; border-left: 4px solid #dc3545; padding: 1.5rem; text-align: center; border-radius: 4px; }
        .detail-row { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #eee; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { font-weight: 600; color: #6c757d; }
        .cell-tag { background: #e9ecef; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem; display: inline-block; margin: 2px; }
        .wizard-actions { display: flex; gap: 1rem; margin-top: 2rem; justify-content: space-between; }
        .wizard-actions .btn { padding: 0.75rem 1.5rem; font-weight: 600; }
    </style>
</head>
<body>
<div class="wizard-card">
    <div class="wizard-header">
        <h4 class="mb-0"><i class="fas fa-trash-alt me-2"></i>Delete Pledge – Step <?php echo (int)$step; ?> of 6</h4>
        <p class="mb-0 mt-1 opacity-75 small"><?php echo htmlspecialchars($step_titles[$step]); ?></p>
        <div class="wizard-progress">
            <?php for ($i = 1; $i <= 6; $i++): ?>
            <div class="step-dot <?php echo $i < $step ? 'done' : ($i === $step ? 'active' : ''); ?>"><?php echo $i; ?></div>
            <?php endfor; ?>
        </div>
    </div>
    <div class="wizard-body">
        <?php if (!$can_delete && $step < 6): ?>
        <div class="blocked-box">
            <i class="fas fa-ban fa-2x text-danger mb-2"></i>
            <h5 class="text-danger">Cannot Proceed</h5>
            <p class="mb-0"><?php echo htmlspecialchars($block_reason); ?></p>
            <a href="view-donor.php?id=<?php echo (int)$donor_id; ?>" class="btn btn-primary mt-3"><i class="fas fa-arrow-left me-2"></i>Back to Donor Profile</a>
        </div>
        <?php elseif ($can_delete || $step === 1): ?>

        <?php if ($step === 1): ?>
        <div class="info-box">
            <h6 class="mb-3"><strong>Pledge Details</strong></h6>
            <div class="detail-row"><span class="detail-label">Donor</span><span><?php echo htmlspecialchars($pledge->donor_name ?? ''); ?></span></div>
            <div class="detail-row"><span class="detail-label">Phone</span><span><?php echo htmlspecialchars($pledge->donor_phone ?? ''); ?></span></div>
            <div class="detail-row"><span class="detail-label">Amount</span><span class="fw-bold">£<?php echo number_format((float)($pledge->amount ?? 0), 2); ?></span></div>
            <div class="detail-row"><span class="detail-label">Status</span><span class="badge bg-warning"><?php echo htmlspecialchars(ucfirst($pledge->status ?? 'unknown')); ?></span></div>
            <div class="detail-row"><span class="detail-label">Date</span><span><?php echo $pledge->created_at ? date('M d, Y', strtotime($pledge->created_at)) : '—'; ?></span></div>
        </div>
        <div class="alert alert-success py-2">
            <i class="fas fa-check-circle me-2"></i>Eligibility checks passed. This rejected pledge can be safely deleted.
        </div>
        <p class="text-muted small">The wizard will guide you through each affected area before deletion.</p>
        <?php endif; ?>

        <?php if ($step === 2): ?>
        <h6 class="mb-3"><i class="fas fa-th-large me-2 text-primary"></i>Cell Allocation</h6>
        <?php if (count($cells) > 0): ?>
        <p>This pledge has <strong><?php echo count($cells); ?></strong> allocated cell(s). They will be deallocated and set to available.</p>
        <div class="mb-3">
            <?php foreach ($cells as $c): ?>
            <span class="cell-tag"><?php echo htmlspecialchars($c['cell_id'] ?? ''); ?> (<?php echo htmlspecialchars($c['area_size'] ?? ''); ?>m²)</span>
            <?php endforeach; ?>
        </div>
        <?php if ($has_allocation_batch_col): ?>
        <p class="small text-muted">Cells will also have their batch reference cleared.</p>
        <?php endif; ?>
        <?php else: ?>
        <p class="text-muted">No cells allocated to this pledge.</p>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($step === 3): ?>
        <h6 class="mb-3"><i class="fas fa-link me-2 text-primary"></i>Linked Payments</h6>
        <?php if (count($pledge_payments) > 0 || count($linked_payments) > 0): ?>
        <p>These payments will be <strong>unlinked</strong> (not deleted):</p>
        <?php if (count($pledge_payments) > 0): ?>
        <div class="mb-2"><strong>Pledge payments:</strong> <?php echo count($pledge_payments); ?> record(s)</div>
        <ul class="small">
            <?php foreach (array_slice($pledge_payments, 0, 5) as $pp): ?>
            <li>£<?php echo number_format((float)($pp['amount'] ?? 0), 2); ?> – <?php echo htmlspecialchars($pp['payment_date'] ?? ''); ?> (<?php echo htmlspecialchars($pp['status'] ?? ''); ?>)</li>
            <?php endforeach; ?>
            <?php if (count($pledge_payments) > 5): ?><li>… and <?php echo count($pledge_payments) - 5; ?> more</li><?php endif; ?>
        </ul>
        <?php endif; ?>
        <?php if (count($linked_payments) > 0): ?>
        <div><strong>Direct payments:</strong> <?php echo count($linked_payments); ?> record(s) unlinked</div>
        <?php endif; ?>
        <?php else: ?>
        <p class="text-muted">No linked payments.</p>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($step === 4): ?>
        <h6 class="mb-3"><i class="fas fa-layer-group me-2 text-primary"></i>Batch References</h6>
        <?php if (count($batches) > 0): ?>
        <p><strong><?php echo count($batches); ?></strong> allocation batch(es) reference this pledge. References will be cleared.</p>
        <ul class="small">
            <?php foreach ($batches as $b): ?>
            <li>Batch #<?php echo (int)($b['id'] ?? 0); ?> – <?php echo htmlspecialchars($b['batch_type'] ?? ''); ?> (<?php echo htmlspecialchars($b['approval_status'] ?? ''); ?>)</li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="text-muted">No batch references.</p>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($step === 5): ?>
        <h6 class="mb-3"><i class="fas fa-user me-2 text-primary"></i>Donor Updates</h6>
        <p>The donor record will be updated:</p>
        <ul>
            <li><strong>pledge_count</strong> – recalculated from remaining pledges</li>
            <?php if ($has_last_pledge_id_col): ?>
            <li><strong>last_pledge_id</strong> – set to the most recent remaining pledge (or cleared if none)</li>
            <?php endif; ?>
        </ul>
        <p class="small text-muted">Financial totals (total_pledged, total_paid, balance) are unchanged because rejected pledges don't count.</p>
        <?php endif; ?>

        <?php if ($step === 6): ?>
        <div class="danger-box">
            <strong><i class="fas fa-exclamation-triangle me-2"></i>Final confirmation</strong>
            <p class="mb-0 mt-2">You are about to permanently delete this rejected pledge. This cannot be undone.</p>
        </div>
        <div class="info-box">
            <h6 class="mb-2">Summary of actions</h6>
            <ul class="mb-0">
                <li>Delete pledge #<?php echo (int)$pledge_id; ?></li>
                <?php if (count($cells) > 0): ?><li>Deallocate <?php echo count($cells); ?> cell(s)</li><?php endif; ?>
                <?php if (count($pledge_payments) + count($linked_payments) > 0): ?><li>Unlink <?php echo count($pledge_payments) + count($linked_payments); ?> payment(s)</li><?php endif; ?>
                <?php if (count($batches) > 0): ?><li>Clear <?php echo count($batches); ?> batch reference(s)</li><?php endif; ?>
                <li>Update donor pledge_count<?php if ($has_last_pledge_id_col): ?> and last_pledge_id<?php endif; ?></li>
            </ul>
        </div>
        <?php endif; ?>

        <div class="wizard-actions">
            <div>
                <?php if ($step > 1): ?>
                <a href="<?php echo $base_url; ?>&step=<?php echo (int)($step - 1); ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Back</a>
                <?php else: ?>
                <a href="view-donor.php?id=<?php echo (int)$donor_id; ?>" class="btn btn-outline-secondary"><i class="fas fa-times me-2"></i>Cancel</a>
                <?php endif; ?>
            </div>
            <div>
                <?php if ($step < 6): ?>
                <a href="<?php echo $base_url; ?>&step=<?php echo (int)($step + 1); ?>" class="btn btn-primary">Continue <i class="fas fa-arrow-right ms-2"></i></a>
                <?php else: ?>
                <form method="POST" action="<?php echo $base_url; ?>&step=6" class="d-inline" onsubmit="return confirm('Permanently delete this pledge?');">
                    <?php echo csrf_input(); ?>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt me-2"></i>Delete Pledge</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>
</body>
</html>
