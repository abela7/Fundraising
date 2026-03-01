<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../shared/login_security.php';
require_once __DIR__ . '/../config/db.php';

$error = '';
$db = db();
$totalUsers = (int)$db->query('SELECT COUNT(*) AS c FROM users')->fetch_assoc()['c'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $clientIp = login_security_get_ip();
    $phone = trim((string)($_POST['phone'] ?? ''));
    $code = trim((string)($_POST['code'] ?? ''));
    $identifier = login_security_normalize_identifier($phone);

    $rate = login_security_check($db, 'admin_login', $identifier, $clientIp);
    if ($rate['blocked']) {
        $error = login_security_retry_message((int)($rate['retry_after'] ?? 0));
    } elseif ($phone === '' || $code === '') {
        login_security_record($db, 'admin_login', $identifier, $clientIp, false);
        $error = 'Phone and 6-digit code are required';
    } elseif (mb_strlen($phone) > 32 || mb_strlen($code) > 16) {
        login_security_record($db, 'admin_login', $identifier, $clientIp, false);
        usleep(random_int(120000, 280000));
        $error = 'Invalid phone or code';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        login_security_record($db, 'admin_login', $identifier, $clientIp, false);
        usleep(random_int(120000, 280000));
        $error = 'Code must be 6 digits';
    } elseif (login_with_phone_password($phone, $code)) {
        login_security_record($db, 'admin_login', $identifier, $clientIp, true);
        header('Location: ./');
        exit;
    } else {
        login_security_record($db, 'admin_login', $identifier, $clientIp, false);
        usleep(random_int(120000, 280000));
        $error = 'Invalid phone or code';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php include __DIR__ . '/../shared/noindex.php'; ?>
  <title>Admin Login</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../assets/theme.css">
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
                <span class="subtle">Admin Access</span>
              </div>
              <h4 class="mb-3">Welcome back</h4>
            <?php if ($error): ?>
              <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <form method="post">
              <?php echo csrf_input(); ?>
              <div class="mb-3">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" required>
              </div>
              <div class="mb-3">
                <label class="form-label">6-digit Code</label>
                <input type="password" name="code" pattern="\d{6}" maxlength="6" class="form-control" required>
                <div class="form-text">Enter your 6-digit access code.</div>
              </div>
              <button class="btn btn-primary w-100">Login</button>
            </form>
            <?php if ($totalUsers === 0): ?>
            <hr>
            <a href="register.php" class="btn btn-outline-light w-100">Create first admin</a>
            <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
