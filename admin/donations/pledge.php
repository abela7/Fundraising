<?php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$db = db();
$id = max(1, (int)($_GET['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	verify_csrf();
	$action = (string)($_POST['action'] ?? 'update');
	if ($action === 'convert_to_payment') {
		$id = max(1, (int)($_POST['id'] ?? 0));
		$method = (string)($_POST['method'] ?? 'cash');
		$reference = trim((string)($_POST['reference'] ?? ''));

		// Load pledge to copy fields
		$ps = $db->prepare('SELECT donor_name, donor_phone, donor_email, amount, package_id, status FROM pledges WHERE id=?');
		$ps->bind_param('i', $id);
		$ps->execute();
		$pledgeRow = $ps->get_result()->fetch_assoc();
		$ps->close();
		if (!$pledgeRow) {
			$error = 'Pledge not found for conversion.';
		}

		if (empty($error)) {
			// Map pledge status to payment status
			$map = ['approved' => 'approved', 'pending' => 'pending', 'rejected' => 'voided', 'cancelled' => 'voided'];
			$payStatus = $map[$pledgeRow['status']] ?? 'pending';
			$uid = (int)(current_user()['id'] ?? 0);
			$refText = $reference !== '' ? $reference : ('Converted from pledge #' . $id);

			// Insert payment
			if (empty($pledgeRow['package_id'])) {
				$sql = 'INSERT INTO payments (donor_name, donor_phone, donor_email, amount, method, package_id, reference, status, received_by_user_id, received_at) VALUES (?,?,?,?,?, NULL, ?, ?, ?, NOW())';
				$ins = $db->prepare($sql);
				$ins->bind_param('sssdsisi', $pledgeRow['donor_name'], $pledgeRow['donor_phone'], $pledgeRow['donor_email'], $pledgeRow['amount'], $method, $refText, $payStatus, $uid);
			} else {
				$sql = 'INSERT INTO payments (donor_name, donor_phone, donor_email, amount, method, package_id, reference, status, received_by_user_id, received_at) VALUES (?,?,?,?,?,?,?,?,?, NOW())';
				$ins = $db->prepare($sql);
				$ins->bind_param('sssdsissii', $pledgeRow['donor_name'], $pledgeRow['donor_phone'], $pledgeRow['donor_email'], $pledgeRow['amount'], $method, $pledgeRow['package_id'], $refText, $payStatus, $uid);
			}
			$ins->execute();
			$newPaymentId = (int)$db->insert_id;

			// Mark original pledge as cancelled and note conversion
			$db->query("UPDATE pledges SET status='cancelled', notes=CONCAT(COALESCE(notes,''),' | Converted to payment #".$newPaymentId."') WHERE id=".$id);

			header('Location: payment.php?id=' . $newPaymentId . '&ok=1');
			exit;
		}
	} else {
		$id = max(1, (int)($_POST['id'] ?? 0));
		$donor_name = trim((string)($_POST['donor_name'] ?? ''));
		$donor_phone = trim((string)($_POST['donor_phone'] ?? ''));
		$donor_email = trim((string)($_POST['donor_email'] ?? ''));
		$amount = (float)($_POST['amount'] ?? 0);
		$package_id = $_POST['package_id'] !== '' ? (int)$_POST['package_id'] : null;
		$status = (string)($_POST['status'] ?? 'pending');
		$type = (string)($_POST['type'] ?? 'pledge');
		$anonymous = isset($_POST['anonymous']) ? 1 : 0;
		$notes = trim((string)($_POST['notes'] ?? ''));
		if ($amount <= 0) { $error = 'Amount must be greater than 0.'; }
		if (empty($error)) {
			$sql = 'UPDATE pledges SET donor_name=?, donor_phone=?, donor_email=?, amount=?, package_id=?, status=?, type=?, anonymous=?, notes=? WHERE id=?';
			$stmt = $db->prepare($sql);
			if ($package_id === null) {
				$null = null;
				$stmt->bind_param('sssdsisssi', $donor_name, $donor_phone, $donor_email, $amount, $null, $status, $type, $anonymous, $notes, $id);
			} else {
				$stmt->bind_param('sssdsisssi', $donor_name, $donor_phone, $donor_email, $amount, $package_id, $status, $type, $anonymous, $notes, $id);
			}
			$stmt->execute();
			header('Location: pledge.php?id=' . $id . '&ok=1'); exit;
		}
	}
}

$stmt = $db->prepare('SELECT p.*, u.name AS creator_name, a.name AS approver_name FROM pledges p LEFT JOIN users u ON u.id=p.created_by_user_id LEFT JOIN users a ON a.id=p.approved_by_user_id WHERE p.id=?');
$stmt->bind_param('i', $id); $stmt->execute(); $pledge = $stmt->get_result()->fetch_assoc();
if (!$pledge) { http_response_code(404); echo 'Pledge not found'; exit; }
$packages = $db->query('SELECT id, label, price FROM donation_packages ORDER BY price')->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Manage Pledge #<?php echo (int)$pledge['id']; ?> - Admin</title>
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
          <h4 class="mb-0"><i class="fas fa-file-signature me-2 text-info"></i>Manage Pledge #<?php echo (int)$pledge['id']; ?></h4>
          <a href="../pledges/" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Pledges</a>
        </div>

        <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?></div>
        <?php elseif (isset($_GET['ok'])): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Saved.</div>
        <?php endif; ?>

        <form method="post" class="card p-3">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="id" value="<?php echo (int)$pledge['id']; ?>">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Donor Name</label>
              <input type="text" name="donor_name" class="form-control" value="<?php echo htmlspecialchars($pledge['donor_name']); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Phone</label>
              <input type="text" name="donor_phone" class="form-control" value="<?php echo htmlspecialchars($pledge['donor_phone']); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Email</label>
              <input type="email" name="donor_email" class="form-control" value="<?php echo htmlspecialchars($pledge['donor_email']); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Amount</label>
              <input type="number" name="amount" step="0.01" min="0.01" class="form-control" value="<?php echo number_format((float)$pledge['amount'], 2, '.', ''); ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Type</label>
              <select name="type" class="form-select">
                <?php foreach (['pledge','paid'] as $t): ?>
                <option value="<?php echo $t; ?>" <?php echo $pledge['type']===$t?'selected':''; ?>><?php echo ucfirst($t); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Package</label>
              <select name="package_id" class="form-select">
                <option value="">Custom</option>
                <?php foreach ($packages as $pkg): ?>
                <option value="<?php echo (int)$pkg['id']; ?>" <?php echo ((int)$pledge['package_id']===(int)$pkg['id'])?'selected':''; ?>><?php echo htmlspecialchars($pkg['label']); ?> - £<?php echo number_format((float)$pkg['price'],2); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach (['pending','approved','rejected','cancelled'] as $st): ?>
                <option value="<?php echo $st; ?>" <?php echo $pledge['status']===$st?'selected':''; ?>><?php echo ucfirst($st); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="anon" name="anonymous" <?php echo ((int)$pledge['anonymous'])===1?'checked':''; ?>>
                <label for="anon" class="form-check-label">Anonymous</label>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" rows="3" class="form-control"><?php echo htmlspecialchars($pledge['notes']); ?></textarea>
            </div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <input type="hidden" name="action" value="update">
            <button class="btn btn-primary" type="submit"><i class="fas fa-save me-1"></i>Save Changes</button>
          </div>
        </form>

        <div class="card mt-3">
          <div class="card-header bg-light"><i class="fas fa-right-left me-2"></i>Convert to Payment</div>
          <div class="card-body">
            <form method="post" class="row g-3">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="convert_to_payment">
              <input type="hidden" name="id" value="<?php echo (int)$pledge['id']; ?>">
              <div class="col-md-3">
                <label class="form-label">Method</label>
                <select name="method" class="form-select">
                  <?php foreach (['cash','bank','card','other'] as $m): ?>
                  <option value="<?php echo $m; ?>"><?php echo ucfirst($m); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Reference (optional)</label>
                <input type="text" name="reference" class="form-control" placeholder="Receipt #, Transaction ID, etc.">
              </div>
              <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary w-100" onclick="return confirm('Convert this pledge to a payment? The pledge will be marked as cancelled.');">
                  <i class="fas fa-arrow-right-arrow-left me-1"></i>Convert
                </button>
              </div>
            </form>
          </div>
        </div>

      </div>
    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>


