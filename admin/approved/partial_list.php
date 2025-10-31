<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_admin();
$db = db();

// Helper function to count total donations for a donor (pledges + payments, any status)
function countDonorDonations(mysqli $db, string $donorPhone): int {
    if (!$donorPhone) return 0;
    
    $stmt = $db->prepare("
        (SELECT COUNT(*) as cnt FROM pledges WHERE donor_phone = ?)
        UNION ALL
        (SELECT COUNT(*) as cnt FROM payments WHERE donor_phone = ?)
    ");
    $stmt->bind_param('ss', $donorPhone, $donorPhone);
    $stmt->execute();
    $result = $stmt->get_result();
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $total += (int)$row['cnt'];
    }
    $stmt->close();
    return $total;
}

// Format helper
function format_sqm_fraction(float $value): string {
    if ($value <= 0) return '0';
    $whole = (int)floor($value);
    $fractionPart = $value - $whole;

    if ($fractionPart > 0) {
        if (abs($fractionPart - 0.25) < 0.01) return ($whole > 0 ? $whole . ' ' : '') . '1/4';
        if (abs($fractionPart - 0.5) < 0.01) return ($whole > 0 ? $whole . ' ' : '') . '1/2';
        if (abs($fractionPart - 0.75) < 0.01) return ($whole > 0 ? $whole . ' ' : '') . '3/4';
    }
    
    return $whole > 0 ? (string)$whole : number_format($value, 2);
}

// Expect $approved from parent include
if (empty($approved)) {
    echo '<div class="text-center p-5 text-muted">
            <i class="fas fa-check-circle fa-3x mb-3"></i>
            <h4>All approved items</h4>
            <p>Approved donations appear here for management.</p>
          </div>';
    return;
}
?>

<div class="approval-list">
<?php foreach ($approved as $row): ?>
    <?php
        // Parse data with same logic as approvals
        $pledge_id = (int)$row['id'];
        $pledge_amount = (float)$row['amount'];
        $pledge_type = strtolower((string)$row['type']);
        $pledge_notes = (string)($row['notes'] ?? '');
        $pledge_created = (string)($row['created_at'] ?? '');
        $pledge_sqm = (float)($row['sqm_meters'] ?? 0);
        $pledge_anonymous = 0; // Approved items don't use anonymous flag like pending
        $pledge_donor_name = (string)($row['donor_name'] ?? '');
        $pledge_donor_phone = (string)($row['donor_phone'] ?? '');
        $pledge_donor_email = (string)($row['donor_email'] ?? '');
        $pledge_registrar = (string)($row['registrar_name'] ?? '');
        
        // Check if pledge was requested from portal or has updates
        $pledge_source = (string)($row['pledge_source'] ?? 'volunteer');
        $has_updates = (int)($row['has_updates'] ?? 0);
        $isFromPortal = ($pledge_source === 'self');
        $isAdditional = ($has_updates > 0);

        // Check if this is a batch (update)
        $isBatch = ($pledge_type === 'pledge_update' || $pledge_type === 'payment_update' || $pledge_type === 'batch');
        $batch_id = $isBatch ? (int)($row['batch_id'] ?? 0) : (($has_updates > 0 && isset($row['batch_id'])) ? (int)($row['batch_id'] ?? 0) : null);
        $original_pledge_id = $isBatch ? (int)($row['original_pledge_id'] ?? 0) : (($has_updates > 0 && isset($row['original_pledge_id'])) ? (int)($row['original_pledge_id'] ?? 0) : null);
        $additional_amount = $isBatch ? (float)($row['additional_amount'] ?? 0) : (($has_updates > 0 && isset($row['additional_amount'])) ? (float)($row['additional_amount'] ?? 0) : 0);
        $original_amount = $isBatch ? (float)($row['original_amount'] ?? 0) : (($has_updates > 0 && isset($row['original_amount'])) ? (float)($row['original_amount'] ?? 0) : 0);
        
        // Display logic (same as approvals)
        $isPayment = ($pledge_type === 'payment' || $pledge_type === 'paid');
        $isPaid = $isPayment;
        $isPledge = ($pledge_type === 'pledge' || $pledge_type === 'pledge_update');
        $displayName = $pledge_donor_name ?: 'N/A';
        $displayPhone = $pledge_donor_phone ?: '';

        // Compute meters
        $meters = 0.0;
        if ($isPledge && isset($row['sqm_meters'])) {
            $meters = (float)$row['sqm_meters'];
        }
    ?>
<div class="approval-item <?php echo $isBatch ? 'batch-item' : ''; ?>" 
     id="<?php echo $isBatch ? 'batch-' : 'pledge-'; ?><?php echo $pledge_id; ?>"
     role="button" tabindex="0"
     data-pledge-id="<?php echo $pledge_id; ?>"
     data-type="<?php echo htmlspecialchars($pledge_type, ENT_QUOTES); ?>"
     data-amount="<?php echo $pledge_amount; ?>"
     data-anonymous="<?php echo $pledge_anonymous; ?>"
     data-donor-name="<?php echo htmlspecialchars($pledge_donor_name, ENT_QUOTES); ?>"
     data-donor-phone="<?php echo htmlspecialchars($pledge_donor_phone, ENT_QUOTES); ?>"
     data-donor-phone-for-history="<?php echo htmlspecialchars($pledge_donor_phone, ENT_QUOTES); ?>"
     data-donor-email="<?php echo htmlspecialchars($pledge_donor_email ?? '', ENT_QUOTES); ?>"
     data-notes="<?php echo htmlspecialchars($pledge_notes ?? '', ENT_QUOTES); ?>"
     data-package-label="<?php echo htmlspecialchars((string)($row['package_label'] ?? ''), ENT_QUOTES); ?>"
     data-sqm-meters="<?php echo $meters; ?>"
     data-created-at="<?php echo htmlspecialchars($pledge_created, ENT_QUOTES); ?>"
     data-registrar="<?php echo htmlspecialchars($pledge_registrar, ENT_QUOTES); ?>"
     <?php if ($isBatch && $batch_id): ?>
     data-batch-id="<?php echo $batch_id; ?>"
     data-batch-type="<?php echo htmlspecialchars($row['batch_type'] ?? '', ENT_QUOTES); ?>"
     data-original-pledge-id="<?php echo $original_pledge_id; ?>"
     data-additional-amount="<?php echo $additional_amount; ?>"
     data-original-amount="<?php echo $original_amount; ?>"
     data-approved-at="<?php echo htmlspecialchars($row['approved_at'] ?? '', ENT_QUOTES); ?>"
     <?php elseif ($has_updates > 0 && $batch_id): ?>
     data-batch-id="<?php echo $batch_id; ?>"
     data-batch-type="<?php echo htmlspecialchars($row['batch_type'] ?? '', ENT_QUOTES); ?>"
     data-original-pledge-id="<?php echo $original_pledge_id; ?>"
     data-additional-amount="<?php echo $additional_amount; ?>"
     data-original-amount="<?php echo $original_amount; ?>"
     data-has-updates="1"
     <?php endif; ?>
>
    <div class="approval-content" style="<?php echo $isBatch ? 'border-left: 4px solid #0d6efd;' : ''; ?>">
        <div class="amount-section">
            <div class="amount">
                <?php if ($isBatch): ?>
                    +£<?php echo number_format($additional_amount, 0); ?>
                    <small class="text-muted d-block" style="font-size: 0.7rem;">Update to Pledge #<?php echo $original_pledge_id; ?></small>
                <?php else: ?>
                    £<?php echo number_format($pledge_amount, 0); ?>
                <?php endif; ?>
            </div>
            <?php
                $badgeClass = 'secondary';
                if ($isBatch) { $badgeClass = 'info'; }
                elseif ($isPaid) { $badgeClass = 'success'; }
                elseif ($isPledge) { $badgeClass = 'warning'; }
                $label = $isBatch ? 'Update' : ($isPaid ? 'Payment' : ($isPledge ? 'Pledge' : ucfirst($pledge_type)));
            ?>
            <div class="type-badge">
                <span class="badge bg-<?php echo $badgeClass; ?>"><?php echo $label; ?></span>
                <?php if ($isBatch): ?>
                    <span class="badge bg-primary ms-1" title="This update was requested from the donor portal">
                        <i class="fas fa-globe me-1"></i>Requested from Portal
                    </span>
                <?php elseif ($isFromPortal && !$isBatch): ?>
                    <span class="badge bg-primary ms-1" title="This donation was requested from the donor portal">
                        <i class="fas fa-globe me-1"></i>Requested from Portal
                    </span>
                <?php endif; ?>
                <?php if ($isAdditional && !$isBatch && $additional_amount > 0): ?>
                    <span class="badge bg-success ms-1" title="This pledge was updated with an additional £<?php echo number_format($additional_amount, 2); ?>">
                        <i class="fas fa-plus-circle me-1"></i>+£<?php echo number_format($additional_amount, 0); ?> Added
                    </span>
                <?php elseif ($isAdditional && !$isBatch): ?>
                    <span class="badge bg-info ms-1" title="This pledge has been updated with additional donations">
                        <i class="fas fa-plus-circle me-1"></i>Additional
                    </span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="donor-section">
            <div class="donor-name">
                <?php echo htmlspecialchars($displayName); ?>
                <?php
                    // Check if this is an update request from donor portal (same logic as approvals page)
                    $is_donor_update = ($pledge_source === 'self' || $pledge_source === 'donor_portal');
                    if ($is_donor_update && !$isBatch):
                ?>
                    <span class="badge bg-warning ms-2" title="This donor updated their pledge amount via the donor portal">
                        <i class="fas fa-edit me-1"></i>Update Request
                    </span>
                <?php endif; ?>
                <?php
                    // Check if this is a repeat donor (has multiple donations total) - same as approvals
                    // Helper function to count approved donations for a donor
                    if (!function_exists('countDonorDonationsApproved')) {
                        function countDonorDonationsApproved(mysqli $db, string $donorPhone): int {
                            if (!$donorPhone) return 0;
                            $stmt = $db->prepare("
                                (SELECT COUNT(*) as cnt FROM pledges WHERE donor_phone = ? AND status = 'approved')
                                UNION ALL
                                (SELECT COUNT(*) as cnt FROM payments WHERE donor_phone = ? AND status = 'approved')
                            ");
                            $stmt->bind_param('ss', $donorPhone, $donorPhone);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $total = 0;
                            while ($row = $result->fetch_assoc()) {
                                $total += (int)$row['cnt'];
                            }
                            $stmt->close();
                            return $total;
                        }
                    }
                    $donationCount = countDonorDonationsApproved($db, $pledge_donor_phone);
                    if ($donationCount > 1 && !$isBatch):
                ?>
                    <span class="badge bg-info ms-2" title="This donor has <?php echo $donationCount; ?> total approved donations">
                        <i class="fas fa-redo-alt me-1"></i>Repeat Donor
                    </span>
                <?php endif; ?>
            </div>
            <div class="donor-phone">
                <?php echo htmlspecialchars($displayPhone); ?>
            </div>
        </div>
        
        <div class="details-section">
            <?php if ($isPledge): ?>
              <?php if (!empty($row['package_label'])): ?>
                <div class="sqm"><?php echo htmlspecialchars($row['package_label']); ?></div>
              <?php else: ?>
                <div class="sqm"><?php echo htmlspecialchars(format_sqm_fraction($meters)); ?> m²</div>
              <?php endif; ?>
            <?php else: ?>
              <div class="sqm">
                <?php if (!empty($row['package_label'])): ?>
                  <?php echo htmlspecialchars($row['package_label']); ?>
                <?php else: ?>
                  Method: <?php echo htmlspecialchars(ucfirst((string)($row['method'] ?? ''))); ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="time"><?php echo $pledge_created ? date('H:i', strtotime($pledge_created)) : '--:--'; ?></div>
            <div class="registrar">
                <?php if (empty(trim($pledge_registrar))): ?>
                    <span class="badge bg-info" style="font-size: 0.7rem;">Self Pledged</span>
                <?php else: ?>
                    <?php echo htmlspecialchars(substr($pledge_registrar, 0, 15)); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="approval-actions">
        <?php if (!$isBatch): ?>
            <button type="button" class="btn btn-edit" data-bs-toggle="modal" data-bs-target="#editPledgeModal" 
                    onclick="openEditPledgeModal(<?php echo $pledge_id; ?>, '<?php echo htmlspecialchars($pledge_donor_name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($pledge_donor_phone, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($pledge_donor_email ?? '', ENT_QUOTES); ?>', <?php echo $pledge_amount; ?>, <?php echo $meters; ?>, '<?php echo htmlspecialchars($pledge_notes ?? '', ENT_QUOTES); ?>')">
                <i class="fas fa-edit"></i>
            </button>
        <?php endif; ?>
        <form method="post" class="action-form" action="index.php" onclick="event.stopPropagation();">
            <?php echo csrf_input(); ?>
            <?php if ($isBatch && $batch_id): ?>
                <input type="hidden" name="action" value="undo_batch">
                <input type="hidden" name="batch_id" value="<?php echo $batch_id; ?>">
                <button type="submit" class="btn btn-reject" title="Undo this update batch">
                    <i class="fas fa-undo"></i>
                </button>
            <?php else: ?>
                <input type="hidden" name="action" value="undo">
                <input type="hidden" name="pledge_id" value="<?php echo $pledge_id; ?>">
                <button type="submit" class="btn btn-reject">
                    <i class="fas fa-undo"></i>
                </button>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>
