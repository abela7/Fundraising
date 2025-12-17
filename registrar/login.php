<?php
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';

// If already logged in, redirect to registration page
if (current_user() && in_array(current_user()['role'], ['registrar', 'admin'], true)) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// Handle logout message
if (isset($_GET['logout']) && $_GET['logout'] === 'success') {
    $success = 'You have been logged out successfully.';
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Will exit with 400 if invalid
    verify_csrf();

    $phone = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($phone === '' || $password === '') {
        $error = 'Please enter both phone and password.';
    } else {
        if (login_with_phone_password($phone, $password)) {
            // Only allow registrar or admin into this area
            if (!in_array(current_user()['role'] ?? '', ['registrar', 'admin'], true)) {
                logout();
                $error = 'Access denied. This area is for registrars only.';
            } else {
                header('Location: index.php');
                exit;
            }
        } else {
            $error = 'Invalid phone or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/../shared/noindex.php'; ?>
    <title>Registrar Login - Church Fundraising</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css">
    <link rel="stylesheet" href="assets/auth.css">
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-container">
            <div class="auth-card">
                <div class="auth-header">
                    <div class="auth-logo">
                        <i class="fas fa-church"></i>
                    </div>
                    <h1 class="auth-title">Registrar Access</h1>
                    <p class="auth-subtitle">Church Fundraising System</p>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <form method="POST" class="auth-form">
                    <?php echo csrf_input(); ?>
                    
                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="username" name="username" 
                               placeholder="Phone number" required autofocus>
                        <label for="username">
                            <i class="fas fa-phone me-2"></i>Phone
                        </label>
                    </div>

                    <div class="form-floating mb-4">
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Password" required>
                        <label for="password">
                            <i class="fas fa-lock me-2"></i>Password
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary btn-login w-100">
                        <i class="fas fa-sign-in-alt me-2"></i>Login
                    </button>
                    
                    <div class="text-center mt-3">
                        <a href="forgot-password.php" class="text-decoration-none small">
                            <i class="fas fa-key me-1"></i>Forgot Password?
                        </a>
                    </div>
                </form>

                <div class="auth-footer">
                    <div class="text-center mb-3">
                        <hr class="my-3">
                        <p class="mb-2">
                            <small class="text-muted">Don't have a registrar account?</small>
                        </p>
                        <a href="register.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-user-plus me-1"></i>Apply to Become a Registrar
                        </a>
                    </div>
                    <p class="mb-2">
                        <a href="../" class="text-decoration-none">
                            <i class="fas fa-arrow-left me-1"></i>Back to Home
                        </a>
                    </p>
                    <p class="text-muted small mb-0">
                        © <?php echo date('Y'); ?> Church Fundraising. All rights reserved.
                    </p>
                </div>
            </div>
        </div>

        <!-- Mobile-first decorative elements -->
        <div class="auth-decoration">
            <div class="circle circle-1"></div>
            <div class="circle circle-2"></div>
            <div class="circle circle-3"></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
