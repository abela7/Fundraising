<?php
/**
 * Donor Portal Login - Token-based Access
 * Donors access via secure token link (sent via SMS/email)
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../admin/includes/resilient_db_loader.php';

// Check if already logged in
function current_donor(): ?array {
    if (isset($_SESSION['donor'])) {
        return $_SESSION['donor'];
    }
    return null;
}

if (current_donor()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// Handle token-based login
if (isset($_GET['token'])) {
    $token = trim($_GET['token']);
    
    if ($token && $db_connection_ok) {
        // Verify token
        $stmt = $db->prepare("
            SELECT id, name, phone, total_pledged, total_paid, balance, 
                   has_active_plan, active_payment_plan_id, plan_monthly_amount,
                   plan_duration_months, plan_start_date, plan_next_due_date,
                   payment_status, preferred_payment_method, preferred_language
            FROM donors 
            WHERE portal_token = ? 
              AND token_expires_at > NOW()
              AND portal_token IS NOT NULL
            LIMIT 1
        ");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $donor = $result->fetch_assoc();
        
        if ($donor) {
            // Set donor session
            $_SESSION['donor'] = $donor;
            
            // Update login tracking
            $update_stmt = $db->prepare("
                UPDATE donors 
                SET last_login_at = NOW(), 
                    login_count = login_count + 1 
                WHERE id = ?
            ");
            $update_stmt->bind_param('i', $donor['id']);
            $update_stmt->execute();
            
            // Redirect to dashboard
            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid or expired access token. Please contact the church office for a new access link.';
        }
    } else {
        $error = 'Invalid token format.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donor Portal Access - Church Fundraising</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css">
    <link rel="stylesheet" href="assets/auth.css">
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-container">
            <div class="auth-card">
                <div class="auth-header">
                    <div class="auth-logo">
                        <i class="fas fa-user-heart"></i>
                    </div>
                    <h1 class="auth-title">Donor Portal</h1>
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

                <div class="auth-info-card">
                    <div class="text-center mb-4">
                        <i class="fas fa-lock fa-3x text-primary mb-3"></i>
                        <h5>Secure Access</h5>
                        <p class="text-muted mb-0">
                            Access your donor portal using the secure link sent to your phone or email.
                        </p>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>How to access:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Check your SMS messages for the access link</li>
                            <li>Click the link to automatically log in</li>
                            <li>If your link expired, contact the church office</li>
                        </ul>
                    </div>
                </div>

                <div class="auth-footer">
                    <p class="mb-2">
                        <a href="../" class="text-decoration-none">
                            <i class="fas fa-arrow-left me-1"></i>Back to Home
                        </a>
                    </p>
                    <p class="text-muted small mb-0">
                        Need help? Contact the church office at 
                        <a href="tel:+44" class="text-decoration-none">+44 XXX XXX XXXX</a>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

