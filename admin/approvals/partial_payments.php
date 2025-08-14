<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_admin();
$db = db();

if (!isset($pending_payments)) {
    $pending_payments = [];
}

if (empty($pending_payments)) {
    echo '<div class="text-center p-5 text-muted">
            <i class="fas fa-inbox fa-3x mb-3"></i>
            <h4>No pending payments</h4>
            <p>Payments submitted will appear here for approval.</p>
          </div>';
    return;
}
?>

<div class="approval-list">
<?php foreach ($pending_payments as $p): ?>
  <?php
    $pid = (int)$p['id'];
    $amt = (float)$p['amount'];
    $method = (string)$p['method'];
    $name = (string)($p['donor_name'] ?? '');
    $phone= (string)($p['donor_phone'] ?? '');
    $email= (string)($p['donor_email'] ?? '');
    $created = (string)($p['created_at'] ?? '');
  ?>
  <div class="approval-item">
    <div class="approval-content">
      <div class="amount-section">
        <div class="amount">£<?php echo number_format($amt, 0); ?></div>
        <div class="type-badge"><span class="badge bg-success">Payment</span></div>
      </div>
      <div class="donor-section">
        <div class="donor-name"><?php echo htmlspecialchars($name ?: 'N/A'); ?></div>
        <div class="donor-phone"><?php echo htmlspecialchars($phone ?: ''); ?></div>
      </div>
      <div class="details-section">
        <div class="sqm">Method: <?php echo htmlspecialchars(ucfirst($method)); ?></div>
        <div class="time"><?php echo $created ? date('H:i', strtotime($created)) : '--:--'; ?></div>
      </div>
    </div>
    <div class="approval-actions">
      <form method="post" class="action-form" action="index.php">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="reject_payment">
        <input type="hidden" name="payment_id" value="<?php echo $pid; ?>">
        <button type="submit" class="btn btn-reject"><i class="fas fa-times"></i></button>
      </form>
      <form method="post" class="action-form" action="index.php">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="approve_payment">
        <input type="hidden" name="payment_id" value="<?php echo $pid; ?>">
        <button type="submit" class="btn btn-approve"><i class="fas fa-check"></i></button>
      </form>
    </div>
  </div>
<?php endforeach; ?>
</div>


