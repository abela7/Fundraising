<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../config/db.php';

$db = db();
$totalUsers = (int)$db->query('SELECT COUNT(*) AS c FROM users')->fetch_assoc()['c'];

// Access control: first user open; otherwise admin only
if ($totalUsers > 0) {
    require_admin();
}

$msg = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $totalUsers === 0 ? 'admin' : ($_POST['role'] ?? 'registrar');
    $code = trim($_POST['code'] ?? '');

    if ($name === '' || $phone === '' || $email === '' || $code === '') {
        $error = 'All fields are required';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = 'Code must be 6 digits';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address';
    } else {
        // Uniqueness checks
        $stmt = $db->prepare('SELECT 1 FROM users WHERE phone = ? OR email = ? LIMIT 1');
        $stmt->bind_param('ss', $phone, $email);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()) {
            $error = 'Phone or email already exists';
        } else {
            $hash = password_hash($code, PASSWORD_DEFAULT);
            $stmt2 = $db->prepare('INSERT INTO users (name, phone, email, role, password_hash, active, created_at) VALUES (?,?,?,?,?,1,NOW())');
            $stmt2->bind_param('sssss', $name, $phone, $email, $role, $hash);
            if ($stmt2->execute()) {
                $msg = 'User created successfully';
                if ($totalUsers === 0) {
                    $script = $_SERVER['SCRIPT_NAME'] ?? '';
                    $pos = strpos($script, '/admin');
                    $base = $pos !== false ? substr($script, 0, $pos) : '';
                    header('Location: ' . $base . '/admin/login.php');
                    exit;
                }
            } else {
                $error = 'Failed to create user';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $totalUsers === 0 ? 'Create First Admin' : 'Register User'; ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/auth.css">
</head>
<body>
<div class="auth-wrap">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-sm-10 col-md-7 col-lg-5">
        <div class="card auth-card">
          <div class="card-body">
            <div class="brand">
              <span class="brand-badge">Fundraising</span>
              <span class="subtle"><?php echo $totalUsers === 0 ? 'Setup' : 'Admin'; ?></span>
            </div>
            <h4 class="mb-3"><?php echo $totalUsers === 0 ? 'Create First Admin' : 'Register User'; ?></h4>
          <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
          <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
          <form method="post">
            <?php echo csrf_input(); ?>
            <div class="mb-3">
              <label class="form-label">Full Name</label>
              <input type="text" name="name" class="form-control" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Phone</label>
              <input type="text" name="phone" class="form-control" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" required>
            </div>
            <?php if ($totalUsers > 0): ?>
            <div class="mb-3">
              <label class="form-label">Role</label>
              <select name="role" class="form-select">
                <option value="registrar">Registrar</option>
                <option value="admin">Admin</option>
              </select>
            </div>
            <?php else: ?>
            <input type="hidden" name="role" value="admin">
            <?php endif; ?>
            <div class="mb-3">
              <label class="form-label">6-digit Code</label>
              <input type="password" name="code" pattern="\d{6}" maxlength="6" class="form-control" required>
              <div class="form-text">This will be your login code.</div>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-primary">Save</button>
              <a class="btn btn-outline-light" href="login.php">Back to login</a>
            </div>
          </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
