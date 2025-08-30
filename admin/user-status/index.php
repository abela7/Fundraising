<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$db = db();
$msg = '';
$error = '';

// Check if last_login_at column exists (resilient to schema variations)
$hasLastLogin = false;
try {
    $col = $db->query("SHOW COLUMNS FROM users LIKE 'last_login_at'");
    if ($col && $col->num_rows > 0) { $hasLastLogin = true; }
} catch (Throwable $e) {
    $hasLastLogin = false;
}

$sql = 'SELECT id, name, phone, email, role, active, created_at' .
        ($hasLastLogin ? ', last_login_at' : ', NULL as last_login_at') .
        ' FROM users ORDER BY (role = "admin") DESC, name ASC';
$rows = [];
try {
    $res = $db->query($sql);
    if ($res) { $rows = $res->fetch_all(MYSQLI_ASSOC); }
} catch (Throwable $e) {
    $error = 'Failed to load user status: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>User Login Status - Fundraising Admin</title>
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
      <?php if ($msg): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
          <i class="fas fa-info-circle me-2"></i>
          <?php echo htmlspecialchars($msg); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fas fa-exclamation-triangle me-2"></i>
          <?php echo htmlspecialchars($error); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h1 class="h3 mb-1">User Login Status</h1>
          <p class="text-muted mb-0">See which registrars and admins have logged in and when</p>
        </div>
      </div>

      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Contact</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Joined</th>
                  <th>Last Login</th>
                  <th>Login State</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $u): ?>
                <tr>
                  <td>
                    <div class="d-flex align-items-center">
                      <div class="avatar-circle me-3">
                        <?php echo strtoupper(substr($u['name'] ?? 'U', 0, 1)); ?>
                      </div>
                      <div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($u['name'] ?? ''); ?></div>
                        <small class="text-muted">ID: #<?php echo str_pad((string)($u['id'] ?? 0), 4, '0', STR_PAD_LEFT); ?></small>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div>
                      <div><i class="fas fa-phone text-muted me-1"></i> <?php echo htmlspecialchars($u['phone'] ?? ''); ?></div>
                      <small class="text-muted"><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($u['email'] ?? ''); ?></small>
                    </div>
                  </td>
                  <td>
                    <span class="badge rounded-pill bg-<?php echo ($u['role'] ?? '') === 'admin' ? 'primary' : 'info'; ?>">
                      <i class="fas fa-<?php echo ($u['role'] ?? '') === 'admin' ? 'crown' : 'user'; ?> me-1"></i>
                      <?php echo ucfirst($u['role'] ?? ''); ?>
                    </span>
                  </td>
                  <td>
                    <?php if ((int)($u['active'] ?? 0) === 1): ?>
                      <span class="badge rounded-pill bg-success-subtle text-success">
                        <i class="fas fa-check-circle me-1"></i>Active
                      </span>
                    <?php else: ?>
                      <span class="badge rounded-pill bg-secondary">
                        <i class="fas fa-times-circle me-1"></i>Inactive
                      </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <small class="text-muted"><?php echo isset($u['created_at']) ? date('M d, Y', strtotime((string)$u['created_at'])) : '—'; ?></small>
                  </td>
                  <td>
                    <?php if (!empty($u['last_login_at'])): ?>
                      <small class="text-muted"><?php echo date('M d, Y g:i A', strtotime((string)$u['last_login_at'])); ?></small>
                    <?php else: ?>
                      <span class="badge bg-warning-subtle text-warning">Never</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($u['last_login_at'])): ?>
                      <span class="badge bg-success"><i class="fas fa-user-check me-1"></i>Logged In</span>
                    <?php else: ?>
                      <span class="badge bg-warning"><i class="fas fa-user-clock me-1"></i>Never Logged In</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


