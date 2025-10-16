<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php'; // <-- FIX: Added missing include
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

$sql = "
    SELECT 
        p.id, p.amount, p.type, p.notes, p.created_at, p.anonymous,
        p.donor_name, p.donor_phone, p.donor_email,
        u.name as registrar_name,
        dp.label AS package_label, dp.price AS package_price, dp.sqm_meters AS package_sqm
    FROM pledges p
    LEFT JOIN users u ON p.created_by_user_id = u.id
    LEFT JOIN donation_packages dp ON dp.id = p.package_id
    WHERE p.status = 'pending'
    ORDER BY p.created_at DESC
";
$res = $db->query($sql);
$pending_pledges = [];
if ($res && $res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) { $pending_pledges[] = $row; }
}

$totalPending = isset($pending_items) ? count($pending_items) : count($pending_pledges);
if ($totalPending === 0) {
    echo '<div class="text-center p-5 text-muted">
            <i class="fas fa-check-circle fa-3x mb-3"></i>
            <h4>All Caught Up!</h4>
            <p>There are no pending approvals.</p>
          </div>';
    // Do not exit; allow the rest of the page (scripts, modals) to load
}
?>

<?php
// Format decimal square meters to mixed fraction in quarters (e.g., 1 1/2, 3/4)
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
?>

<div class="approval-list">
<?php
// If combined items are provided, render those; otherwise fallback to pledges-only list
$items = isset($pending_items) ? $pending_items : $pending_pledges;
?>
<?php foreach ($items as $pledge): ?>
    <?php
        // Guard against nulls and unexpected values
        $isCombined = isset($pledge['item_type']);
        $pledge_id = (int)($isCombined ? ($pledge['item_id'] ?? 0) : ($pledge['id'] ?? 0));
        $pledge_amount = (float)($pledge['amount'] ?? 0);
        $pledge_type = strtolower((string)($isCombined ? ($pledge['item_type'] ?? 'pledge') : ($pledge['type'] ?? 'pledge')));
        $pledge_notes = (string)($pledge['notes'] ?? '');
        $pledge_created = (string)($pledge['created_at'] ?? '');
        $pledge_sqm = (float)($pledge['sqm_meters'] ?? 0);
        $pledge_anonymous = (int)($pledge['anonymous'] ?? 0);
        $pledge_donor_name = (string)($pledge['donor_name'] ?? '');
        $pledge_donor_phone = (string)($pledge['donor_phone'] ?? '');
        $pledge_donor_email = (string)($pledge['donor_email'] ?? '');
        $pledge_registrar = (string)($pledge['registrar_name'] ?? 'System');

        // Display logic
        $isPayment = ($pledge_type === 'payment');
        $isPaid = ($pledge_type === 'paid') || $isPayment;
        $isPledge = ($pledge_type === 'pledge');
        $isAnonPaid = ($isPaid && $pledge_anonymous === 1);
        $isAnonPledge = ($isPledge && $pledge_anonymous === 1);
        $showAnonChip = $isAnonPaid || $isAnonPledge;
        $displayName = $isAnonPaid ? 'Anonymous' : ($pledge_donor_name ?: 'N/A');
        $displayPhone = $isAnonPaid ? '' : ($pledge_donor_phone ?: '');

        // Compute meters from package when available, guard against division by zero
        $meters = 0.0;
        if ($isPledge) {
            if (isset($pledge['package_sqm'])) {
                $meters = (float)$pledge['package_sqm'];
            }

            // Fallback: derive meters from amount and package price only if price is a positive number
            $packagePrice = isset($pledge['package_price']) ? (float)$pledge['package_price'] : 0.0;
            if ($meters <= 0 && $pledge_amount > 0 && $packagePrice > 0.0) {
                $meters = (float)$pledge_amount / $packagePrice;
            }
        }
    ?>
<div class="approval-item" id="pledge-<?php echo $pledge_id; ?>"
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
     data-package-label="<?php echo htmlspecialchars((string)($pledge['package_label'] ?? ''), ENT_QUOTES); ?>"
     data-package-sqm="<?php echo isset($pledge['package_sqm']) ? (float)$pledge['package_sqm'] : 0; ?>"
     data-package-price="<?php echo isset($pledge['package_price']) ? (float)$pledge['package_price'] : 0; ?>"
     data-method="<?php echo htmlspecialchars((string)($pledge['method'] ?? ''), ENT_QUOTES); ?>"
     data-sqm-meters="<?php echo $meters; ?>"
     data-created-at="<?php echo htmlspecialchars($pledge_created, ENT_QUOTES); ?>"
     data-registrar="<?php echo htmlspecialchars($pledge_registrar, ENT_QUOTES); ?>"
>
    <div class="approval-content">
        <div class="amount-section">
            <div class="amount">£<?php echo number_format($pledge_amount, 0); ?></div>
            <?php
                $badgeClass = 'secondary';
                if ($isPayment || $pledge_type === 'paid') { $badgeClass = 'success'; }
                elseif ($isPledge) { $badgeClass = 'warning'; }
                $label = $isPayment ? 'Payment' : ($isPledge ? 'Pledge' : ucfirst($pledge_type));
            ?>
            <div class="type-badge">
                <span class="badge bg-<?php echo $badgeClass; ?>"><?php echo $label; ?></span>
            </div>
        </div>
        
        <div class="donor-section">
            <div class="donor-name">
                <?php echo htmlspecialchars($displayName); ?>
                <?php if ($showAnonChip): ?>
                    <span class="anon-chip ms-2"><i class="fas fa-user-secret"></i> Anonymous</span>
                <?php endif; ?>
                <?php
                    // Check if this is a repeat donor (has multiple donations total)
                    $donationCount = countDonorDonations($db, $pledge_donor_phone);
                    if ($donationCount > 1):
                ?>
                    <span class="badge bg-info ms-2" title="This donor has <?php echo $donationCount; ?> total donations">
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
              <?php if (!empty($pledge['package_label'])): ?>
                <div class="sqm"><?php echo htmlspecialchars($pledge['package_label']); ?></div>
              <?php else: ?>
                <div class="sqm"><?php echo htmlspecialchars(format_sqm_fraction($meters)); ?> m²</div>
              <?php endif; ?>
            <?php else: ?>
              <div class="sqm">
                <?php if (!empty($pledge['package_label'])): ?>
                  <?php echo htmlspecialchars($pledge['package_label']); ?>
                <?php else: ?>
                  Method: <?php echo htmlspecialchars(ucfirst((string)($pledge['method'] ?? ''))); ?>
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
        <button type="button" class="btn btn-edit" data-bs-toggle="modal" data-bs-target="#editModal" 
                onclick="openEditModal(<?php echo $pledge_id; ?>, '<?php echo htmlspecialchars($pledge_donor_name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($pledge_donor_phone, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($pledge_donor_email ?? '', ENT_QUOTES); ?>', <?php echo $pledge_amount; ?>, <?php echo $pledge_sqm; ?>, '<?php echo htmlspecialchars($pledge_notes ?? '', ENT_QUOTES); ?>', '<?php echo $isPledge ? 'pledge' : 'payment'; ?>')">
            <i class="fas fa-edit"></i>
        </button>
        <form method="post" class="action-form" action="index.php" onclick="event.stopPropagation();">
            <?php echo csrf_input(); ?>
            <?php if ($isPledge): ?>
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="pledge_id" value="<?php echo $pledge_id; ?>">
            <?php else: ?>
              <input type="hidden" name="action" value="reject_payment">
              <input type="hidden" name="payment_id" value="<?php echo $pledge_id; ?>">
            <?php endif; ?>
            
            <?php 
            // Preserve current filter and pagination parameters
            $preserveParams = ['filter_type', 'filter_amount_min', 'filter_amount_max', 'filter_donor', 'filter_registrar', 'filter_date_from', 'filter_date_to', 'sort_by', 'sort_order', 'page', 'per_page'];
            foreach ($preserveParams as $param) {
                if (isset($_GET[$param]) && $_GET[$param] !== '') {
                    echo '<input type="hidden" name="' . htmlspecialchars($param) . '" value="' . htmlspecialchars($_GET[$param]) . '">';
                }
            }
            ?>
            <button type="submit" class="btn btn-reject">
                <i class="fas fa-times"></i>
            </button>
        </form>
        <form method="post" class="action-form" action="index.php" onclick="event.stopPropagation();">
            <?php echo csrf_input(); ?>
            <?php if ($isPledge): ?>
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="pledge_id" value="<?php echo $pledge_id; ?>">
            <?php else: ?>
              <input type="hidden" name="action" value="approve_payment">
              <input type="hidden" name="payment_id" value="<?php echo $pledge_id; ?>">
            <?php endif; ?>
            
            <?php 
            // Preserve current filter and pagination parameters
            $preserveParams = ['filter_type', 'filter_amount_min', 'filter_amount_max', 'filter_donor', 'filter_registrar', 'filter_date_from', 'filter_date_to', 'sort_by', 'sort_order', 'page', 'per_page'];
            foreach ($preserveParams as $param) {
                if (isset($_GET[$param]) && $_GET[$param] !== '') {
                    echo '<input type="hidden" name="' . htmlspecialchars($param) . '" value="' . htmlspecialchars($_GET[$param]) . '">';
                }
            }
            ?>
            <button type="submit" class="btn btn-approve">
                <i class="fas fa-check"></i>
            </button>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>
