<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_admin();
$db = db();

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
            <i class="fas fa-inbox fa-3x mb-3"></i>
            <h4>No approved items yet</h4>
            <p>Once items are approved, they appear here for management.</p>
          </div>';
    return;
}
?>

<?php
// If combined items are provided, render those; otherwise fallback to pledges-only list
$items = isset($approved_items) ? $approved_items : [];
?>
<div class="approval-list">
    <?php if (empty($items)): ?>
        <div class="text-center p-5 text-muted">
            <i class="fas fa-check-circle fa-3x mb-3"></i>
            <h4>No Approved Items</h4>
            <p>There are no approved items to display.</p>
        </div>
    <?php endif; ?>
    <?php foreach ($items as $item): ?>
    <?php
        // Guard against nulls and unexpected values
        $item_id = (int)($item['id'] ?? 0);
        $item_amount = (float)($item['amount'] ?? 0);
        $item_type = strtolower((string)($item['type'] ?? 'pledge'));
        $item_notes = (string)($item['notes'] ?? '');
        $meters = (float)($item['sqm_meters'] ?? 0);
        $item_donor_name = (string)($item['donor_name'] ?? '');
        $item_donor_phone = (string)($item['donor_phone'] ?? '');
        $item_donor_email = (string)($item['donor_email'] ?? '');
        $item_registrar = (string)($item['registrar_name'] ?? 'System');
        $item_package_id = isset($item['package_id']) ? (int)$item['package_id'] : null;
        $item_anonymous = (int)($item['anonymous'] ?? 0);
        $item_created = (string)($item['created_at'] ?? '');
        $item_approved_at = (string)($item['approved_at'] ?? '');

        // Display logic
        $isPayment = ($item_type === 'payment');
        $isPledge = ($item_type === 'pledge');
        $showAnonChip = $item_anonymous === 1;
        $displayName = $showAnonChip ? 'Anonymous' : ($item_donor_name ?: 'N/A');
        $displayPhone = $showAnonChip ? '' : ($item_donor_phone ?: '');

        // Compute meters from package when available
    ?>
    <div class="approval-item" id="item-<?php echo $item_id; ?>"
         role="button" tabindex="0"
         data-item-id="<?php echo $item_id; ?>"
         data-type="<?php echo htmlspecialchars($item_type, ENT_QUOTES); ?>"
         data-amount="<?php echo $item_amount; ?>"
         data-anonymous="<?php echo $item_anonymous; ?>"
         data-donor-name="<?php echo htmlspecialchars($item_donor_name, ENT_QUOTES); ?>"
         data-donor-phone="<?php echo htmlspecialchars($item_donor_phone, ENT_QUOTES); ?>"
         data-donor-email="<?php echo htmlspecialchars($item_donor_email ?? '', ENT_QUOTES); ?>"
         data-notes="<?php echo htmlspecialchars($item_notes ?? '', ENT_QUOTES); ?>"
         data-package-label="<?php echo htmlspecialchars((string)($item['package_label'] ?? ''), ENT_QUOTES); ?>"
         data-package-sqm="<?php echo isset($item['package_sqm']) ? (float)$item['package_sqm'] : 0; ?>"
         data-package-price="<?php echo isset($item['package_price']) ? (float)$item['package_price'] : 0; ?>"
         data-method="<?php echo htmlspecialchars((string)($item['method'] ?? ''), ENT_QUOTES); ?>"
         data-sqm-meters="<?php echo $meters; ?>"
         data-created-at="<?php echo htmlspecialchars($item_created, ENT_QUOTES); ?>"
         data-approved-at="<?php echo htmlspecialchars($item_approved_at, ENT_QUOTES); ?>"
         data-registrar="<?php echo htmlspecialchars($item_registrar, ENT_QUOTES); ?>"
    >
        <div class="approval-content">
            <div class="amount-section">
                <div class="amount">£<?php echo number_format($item_amount, 0); ?></div>
                <?php
                    if ($isPledge) { $badgeClass = 'warning'; }
                    elseif ($item_type === 'paid') { $badgeClass = 'success'; }
                    else { $badgeClass = 'primary'; }
                    $label = $isPledge ? 'Pledge' : ($item_type === 'paid' ? 'Payment' : ucfirst($item_type));
                ?>
                <span class="badge bg-<?php echo $badgeClass; ?>"><?php echo $label; ?></span>
            </div>
            <div class="donor-section">
                <div class="donor-name">
                    <?php echo htmlspecialchars($displayName); ?>
                    <?php if ($showAnonChip): ?>
                        <span class="anon-chip ms-2"><i class="fas fa-user-secret"></i> Anonymous</span>
                    <?php endif; ?>
                </div>
                <div class="donor-phone">
                    <?php echo htmlspecialchars($displayPhone); ?>
                </div>
            </div>
            
            <div class="details-section">
                <?php if ($isPledge): ?>
                  <div class="sqm"><?php echo htmlspecialchars(format_sqm_fraction($meters)); ?> m²</div>
                  <div class="registrar"><?php echo htmlspecialchars($item_registrar); ?></div>
                  <?php if ($item_package_id): ?>
                    <div class="package-details">
                      <strong>Package:</strong> <?php echo htmlspecialchars($item['package_label']); ?>
                      <br>
                      <strong>Price:</strong> £<?php echo number_format($item['package_price'], 0); ?>
                      <br>
                      <strong>SQM:</strong> <?php echo htmlspecialchars(format_sqm_fraction($item['package_sqm'])); ?> m²
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <div class="sqm"><?php echo htmlspecialchars(format_sqm_fraction($meters)); ?> m²</div>
                  <div class="registrar"><?php echo htmlspecialchars($item_registrar); ?></div>
                  <div class="method">Method: <?php echo htmlspecialchars(ucfirst((string)($item['method'] ?? ''))); ?></div>
                <?php endif; ?>
              </div>
            </div>
        
        <div class="approval-actions">
            <button type="button" class="btn btn-edit" data-bs-toggle="modal" data-bs-target="#editModal"
                    onclick="openEditModal(<?php echo $item_id; ?>, '<?php echo htmlspecialchars($item_donor_name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($item_donor_phone, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($item_donor_email ?? '', ENT_QUOTES); ?>', <?php echo $item_amount; ?>, <?php echo $meters; ?>, '<?php echo htmlspecialchars($item_notes ?? '', ENT_QUOTES); ?>', '<?php echo $isPayment ? 'payment' : 'pledge'; ?>', '<?php echo $item_package_id; ?>', '<?php echo htmlspecialchars((string)($item['method'] ?? ''), ENT_QUOTES); ?>')">
                <i class="fas fa-edit"></i>
            </button>
            <form method="post" class="action-form" action="index.php?page=<?php echo $page ?? 1; ?>" onclick="event.stopPropagation();">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="<?php echo $isPledge ? 'undo' : 'undo_payment'; ?>">
                <input type="hidden" name="<?php echo $isPledge ? 'pledge_id' : 'payment_id'; ?>" value="<?php echo $item_id; ?>">
                <button type="submit" class="btn btn-undo">
                    <i class="fas fa-undo"></i>
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>


