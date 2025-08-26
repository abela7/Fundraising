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

<div class="approved-list">
<?php foreach ($approved as $row): ?>
  <?php
    $pledge_id = (int)$row['id'];
    $amount = (float)$row['amount'];
    $type = (string)$row['type'];
    $notes = (string)($row['notes'] ?? '');
    $sqm_meters = (float)($row['sqm_meters'] ?? 0);
    $donor_name = (string)($row['donor_name'] ?? '');
    $donor_phone = (string)($row['donor_phone'] ?? '');
    $donor_email = (string)($row['donor_email'] ?? '');
    $registrar = (string)($row['registrar_name'] ?? '');
    $payment_id = isset($row['payment_id']) ? (int)$row['payment_id'] : 0;
    $payment_amount = isset($row['payment_amount']) ? (float)$row['payment_amount'] : null;
    $payment_method = (string)($row['payment_method'] ?? '');
    $payment_reference = (string)($row['payment_reference'] ?? '');

  ?>
  <div class="approved-item">
    <div class="approved-content">
      <div class="amount-section">
        <div class="amount">£<?php echo number_format($amount, 0); ?></div>
        <span class="badge bg-<?php echo $type === 'paid' ? 'success' : 'primary'; ?>"><?php echo ucfirst($type); ?></span>
      </div>
      <div class="donor-section">
        <div class="donor-name"><?php echo htmlspecialchars($donor_name ?: 'N/A'); ?></div>
        <div class="donor-phone"><?php echo htmlspecialchars($donor_phone ?: ''); ?></div>
      </div>
      <div class="details-section">
        <div class="sqm"><?php echo htmlspecialchars(format_sqm_fraction($sqm_meters)); ?> m²</div>
        <div class="registrar"><?php echo htmlspecialchars($registrar ?: ''); ?></div>
      </div>
    </div>
    <div class="approved-actions">
      <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editPledgeModal"
              onclick="openEditPledgeModal(<?php echo $pledge_id; ?>, '<?php echo htmlspecialchars($donor_name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($donor_phone, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($donor_email ?? '', ENT_QUOTES); ?>', <?php echo $amount; ?>, <?php echo $sqm_meters; ?>, '<?php echo htmlspecialchars($notes ?? '', ENT_QUOTES); ?>')">
        <i class="fas fa-edit"></i>
      </button>
      <?php if ($type === 'paid' && $payment_id): ?>
      <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editPaymentModal"
              onclick="openEditPaymentModal(<?php echo $payment_id; ?>, <?php echo (float)$payment_amount; ?>, '<?php echo htmlspecialchars($payment_method ?: 'cash', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($payment_reference ?? '', ENT_QUOTES); ?>')">
        <i class="fas fa-credit-card"></i>
      </button>
      <?php endif; ?>
      <?php if ($type !== 'paid'): ?>
        <form method="post" class="d-inline" action="index.php" onsubmit="return confirm('Undo approval for this item?');">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="undo">
          <input type="hidden" name="pledge_id" value="<?php echo $pledge_id; ?>">
          
          <?php 
          // Preserve current filter and pagination parameters
          $preserveParams = ['filter_type', 'filter_amount_min', 'filter_amount_max', 'filter_donor', 'filter_registrar', 'filter_date_from', 'filter_date_to', 'sort_by', 'sort_order', 'page', 'per_page'];
          foreach ($preserveParams as $param) {
              if (isset($_GET[$param]) && $_GET[$param] !== '') {
                  echo '<input type="hidden" name="' . htmlspecialchars($param) . '" value="' . htmlspecialchars($_GET[$param]) . '">';
              }
          }
          ?>
          <button class="btn btn-sm btn-danger" title="Undo approval"><i class="fas fa-undo"></i></button>
        </form>
      <?php else: ?>
        <form method="post" class="d-inline" action="index.php" onsubmit="return confirm('Undo approval for this payment and subtract from totals?');">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="undo_payment">
          <input type="hidden" name="payment_id" value="<?php echo (int)($payment_id ?? 0); ?>">
          <input type="hidden" name="payment_amount" value="<?php echo (float)($payment_amount ?? 0); ?>">
          
          <?php 
          // Preserve current filter and pagination parameters
          $preserveParams = ['filter_type', 'filter_amount_min', 'filter_amount_max', 'filter_donor', 'filter_registrar', 'filter_date_from', 'filter_date_to', 'sort_by', 'sort_order', 'page', 'per_page'];
          foreach ($preserveParams as $param) {
              if (isset($_GET[$param]) && $_GET[$param] !== '') {
                  echo '<input type="hidden" name="' . htmlspecialchars($param) . '" value="' . htmlspecialchars($_GET[$param]) . '">';
              }
          }
          ?>
          <button class="btn btn-sm btn-warning" title="Undo payment approval"><i class="fas fa-rotate-left"></i></button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>


