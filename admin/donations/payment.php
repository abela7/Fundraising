<?php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$db = db();
$id = max(1, (int)($_GET['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = max(1, (int)($_POST['id'] ?? 0));
    $donor_name = trim((string)($_POST['donor_name'] ?? ''));
    $donor_phone = trim((string)($_POST['donor_phone'] ?? ''));
    $donor_email = trim((string)($_POST['donor_email'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $method = (string)($_POST['method'] ?? 'cash');
    $package_id = $_POST['package_id'] !== '' ? (int)$_POST['package_id'] : null;
    $reference = trim((string)($_POST['reference'] ?? ''));
    $status = (string)($_POST['status'] ?? 'pending');
    if ($amount <= 0) { $error = 'Amount must be greater than 0.'; }
    if (empty($error)) {
        $sql = 'UPDATE payments SET donor_name=?, donor_phone=?, donor_email=?, amount=?, method=?, package_id=?, reference=?, status=? WHERE id=?';
        $stmt = $db->prepare($sql);
        if ($package_id === null) {
            $null = null;
            $stmt->bind_param('sssdsissi', $donor_name, $donor_phone, $donor_email, $amount, $method, $null, $reference, $status, $id);
        } else {
            $stmt->bind_param('sssdsissi', $donor_name, $donor_phone, $donor_email, $amount, $method, $package_id, $reference, $status, $id);
        }
        $stmt->execute();
        header('Location: payment.php?id=' . $id . '&ok=1'); exit;
    }
}

$stmt = $db->prepare('SELECT p.*, u.name AS received_by_name FROM payments p LEFT JOIN users u ON u.id=p.received_by_user_id WHERE p.id=?');
$stmt->bind_param('i', $id); $stmt->execute(); $payment = $stmt->get_result()->fetch_assoc();
if (!$payment) { http_response_code(404); echo 'Payment not found'; exit; }
$packages = $db->query('SELECT id, label, price FROM donation_packages ORDER BY price')->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Manage Payment #<?php echo (int)$payment['id']; ?> - Admin</title>
  <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="admin-content">
    <?php include '../includes/topbar.php'; ?>
    <main class="main-content">
      <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h4 class="mb-0"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Manage Payment #<?php echo (int)$payment['id']; ?></h4>
          <a href="../payments/" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Payments</a>
        </div>

        <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?></div>
        <?php elseif (isset($_GET['ok'])): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Saved.</div>
        <?php endif; ?>

        <form method="post" class="card p-3">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="id" value="<?php echo (int)$payment['id']; ?>">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Donor Name</label>
              <input type="text" name="donor_name" class="form-control" value="<?php echo htmlspecialchars($payment['donor_name']); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Phone</label>
              <input type="text" name="donor_phone" class="form-control" value="<?php echo htmlspecialchars($payment['donor_phone']); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Email</label>
              <input type="email" name="donor_email" class="form-control" value="<?php echo htmlspecialchars($payment['donor_email']); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Amount</label>
              <input type="number" name="amount" step="0.01" min="0.01" class="form-control" value="<?php echo number_format((float)$payment['amount'], 2, '.', ''); ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Method</label>
              <select name="method" class="form-select">
                <?php foreach (['cash','bank','card','other'] as $m): ?>
                <option value="<?php echo $m; ?>" <?php echo $payment['method']===$m?'selected':''; ?>><?php echo ucfirst($m); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Package</label>
              <select name="package_id" class="form-select">
                <option value="">Custom</option>
                <?php foreach ($packages as $pkg): ?>
                <option value="<?php echo (int)$pkg['id']; ?>" <?php echo ((int)$payment['package_id']===(int)$pkg['id'])?'selected':''; ?>><?php echo htmlspecialchars($pkg['label']); ?> - £<?php echo number_format((float)$pkg['price'],2); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Reference</label>
              <input type="text" name="reference" class="form-control" value="<?php echo htmlspecialchars($payment['reference']); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach (['pending','approved','voided'] as $st): ?>
                <option value="<?php echo $st; ?>" <?php echo $payment['status']===$st?'selected':''; ?>><?php echo ucfirst($st); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary" type="submit"><i class="fas fa-save me-1"></i>Save Changes</button>
          </div>
        </form>

      </div>
    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>


