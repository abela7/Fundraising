<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$db = db();
$memberId = max(1, (int)($_GET['id'] ?? 0));

// Load member
$stmt = $db->prepare('SELECT id, name, phone, email, role, active, created_at FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $memberId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$member) {
    http_response_code(404);
    echo 'Member not found';
    exit;
}

// Load pledges created/approved by this member
$pledges = $db->query("SELECT p.id, p.donor_name, p.amount, p.status, p.created_at, p.approved_at, dp.label AS package_label
                       FROM pledges p
                       LEFT JOIN donation_packages dp ON dp.id=p.package_id
                       WHERE p.created_by_user_id = {$member['id']} OR p.approved_by_user_id = {$member['id']}
                       ORDER BY p.created_at DESC LIMIT 200")?->fetch_all(MYSQLI_ASSOC) ?? [];

// Load payments received by this member
$payments = $db->query("SELECT p.id, p.donor_name, p.amount, p.status, p.method, p.reference, p.received_at, dp.label AS package_label
                        FROM payments p
                        LEFT JOIN donation_packages dp ON dp.id=p.package_id
                        WHERE p.received_by_user_id = {$member['id']}
                        ORDER BY p.received_at DESC LIMIT 200")?->fetch_all(MYSQLI_ASSOC) ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Member Details - Fundraising Admin</title>
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
          <h4 class="mb-0"><i class="fas fa-user me-2 text-primary"></i><?php echo htmlspecialchars($member['name']); ?></h4>
          <a href="./" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Members</a>
        </div>

        <!-- Member Card -->
        <div class="card mb-4">
          <div class="card-body">
            <div class="row g-3 align-items-center">
              <div class="col-md-8">
                <div class="d-flex align-items-center">
                  <div class="avatar-circle me-3 bg-primary text-white" style="width:60px;height:60px;font-size:1.5rem;">
                    <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                  </div>
                  <div>
                    <div class="fw-semibold fs-5"><?php echo htmlspecialchars($member['name']); ?></div>
                    <div class="text-muted small">
                      <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($member['phone']); ?> ·
                      <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($member['email']); ?>
                    </div>
                    <div class="mt-1">
                      <span class="badge bg-<?php echo $member['role']==='admin'?'primary':'info'; ?>"><?php echo ucfirst($member['role']); ?></span>
                      <?php if ((int)$member['active'] === 1): ?>
                      <span class="badge bg-success-subtle text-success">Active</span>
                      <?php else: ?>
                      <span class="badge bg-secondary">Inactive</span>
                      <?php endif; ?>
                      <span class="badge bg-light text-dark">Joined: <?php echo date('M d, Y', strtotime($member['created_at'])); ?></span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Payments by member -->
        <div class="card mb-4">
          <div class="card-header bg-success text-white">
            <h6 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Payments received by member</h6>
          </div>
          <div class="card-body p-0">
            <?php if (empty($payments)): ?>
            <div class="p-4 text-center text-muted">No payments found</div>
            <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Date</th>
                    <th>Donor</th>
                    <th>Package</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th class="text-end">Manage</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($payments as $p): ?>
                  <tr>
                    <td><?php echo date('d M Y, h:i A', strtotime($p['received_at'] ?? $p['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars(($p['donor_name'] ?? '') !== '' ? $p['donor_name'] : 'Anonymous'); ?></td>
                    <td><?php echo htmlspecialchars($p['package_label'] ?? 'Custom'); ?></td>
                    <td>£<?php echo number_format((float)$p['amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst((string)$p['method'])); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst((string)$p['status'])); ?></td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-primary" href="../donations/payment.php?id=<?php echo (int)$p['id']; ?>">
                        <i class="fas fa-pen-to-square me-1"></i>Open
                      </a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Pledges by member -->
        <div class="card mb-4">
          <div class="card-header bg-info text-white">
            <h6 class="mb-0"><i class="fas fa-hand-holding-heart me-2"></i>Pledges created/approved by member</h6>
          </div>
          <div class="card-body p-0">
            <?php if (empty($pledges)): ?>
            <div class="p-4 text-center text-muted">No pledges found</div>
            <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Date</th>
                    <th>Donor</th>
                    <th>Package</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th class="text-end">Manage</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($pledges as $pl): ?>
                  <tr>
                    <td><?php echo date('d M Y, h:i A', strtotime($pl['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars(($pl['donor_name'] ?? '') !== '' ? $pl['donor_name'] : 'Anonymous'); ?></td>
                    <td><?php echo htmlspecialchars($pl['package_label'] ?? 'Custom'); ?></td>
                    <td>£<?php echo number_format((float)$pl['amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst((string)$pl['status'])); ?></td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-primary" href="../donations/pledge.php?id=<?php echo (int)$pl['id']; ?>">
                        <i class="fas fa-pen-to-square me-1"></i>Open
                      </a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
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


