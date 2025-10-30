<?php
/**
 * Donor Portal Login - Phone-based Access
 * Simple phone-only login (can be enhanced later with PIN/password)
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
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

// Handle logout message
if (isset($_GET['logout']) && $_GET['logout'] === 'success') {
    $success = 'You have been logged out successfully.';
}

// Handle phone-based login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    $phone = trim($_POST['phone'] ?? '');
    
    if ($phone === '') {
        $error = 'Please enter your phone number.';
    } else {
        // Normalize phone number (remove spaces, dashes, etc.)
        $normalized_phone = preg_replace('/\D+/', '', $phone);
        
        // Handle +44 format
        if (strpos($normalized_phone, '44') === 0 && strlen($normalized_phone) === 12) {
            $normalized_phone = '0' . substr($normalized_phone, 2);
        }
        
        if (strlen($normalized_phone) < 10) {
            $error = 'Please enter a valid UK mobile number.';
        } else {
            // Look up donor by phone
            if ($db_connection_ok) {
                try {
                    // Try exact match first
                    $stmt = $db->prepare("
                        SELECT id, name, phone, total_pledged, total_paid, balance, 
                               has_active_plan, active_payment_plan_id, plan_monthly_amount,
                               plan_duration_months, plan_start_date, plan_next_due_date,
                               payment_status, preferred_payment_method, preferred_language
                        FROM donors 
                        WHERE phone = ? 
                        LIMIT 1
                    ");
                    $stmt->bind_param('s', $phone);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $donor = $result->fetch_assoc();
                    
                    // If no exact match, try normalized match
                    if (!$donor && $normalized_phone) {
                        $stmt2 = $db->prepare("
                            SELECT id, name, phone, total_pledged, total_paid, balance, 
                                   has_active_plan, active_payment_plan_id, plan_monthly_amount,
                                   plan_duration_months, plan_start_date, plan_next_due_date,
                                   payment_status, preferred_payment_method, preferred_language
                            FROM donors 
                            WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') = ?
                            LIMIT 1
                        ");
                        $stmt2->bind_param('s', $normalized_phone);
                        $stmt2->execute();
                        $result2 = $stmt2->get_result();
                        $donor = $result2->fetch_assoc();
                        $stmt2->close();
                    }
                    $stmt->close();
                    
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
                        $update_stmt->close();
                        
                        // Regenerate session ID for security
                        session_regenerate_id(true);
                        
                        // Redirect to dashboard
                        header('Location: index.php');
                        exit;
                    } else {
                        $error = 'No donor account found with this phone number. Please contact the church office if you believe this is an error.';
                    }
                } catch (Exception $e) {
                    $error = 'An error occurred. Please try again or contact support.';
                }
            } else {
                $error = 'Database connection error. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donor Portal Login - Church Fundraising</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/auth.css?v=<?php echo @filemtime(__DIR__ . '/assets/auth.css'); ?>">
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

                <form method="POST" class="auth-form">
                    <?php echo csrf_input(); ?>
                    
                    <div class="form-floating mb-4">
                        <input type="tel" 
                               class="form-control" 
                               id="phone" 
                               name="phone" 
                               placeholder="Phone number" 
                               pattern="[0-9+\s\-\(\)]+"
                               required 
                               autofocus>
                        <label for="phone">
                            <i class="fas fa-phone me-2"></i>Phone Number
                        </label>
                        <div class="form-text">
                            Enter your UK mobile number (e.g., 07123456789)
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-login w-100">
                        <i class="fas fa-sign-in-alt me-2"></i>Login
                    </button>
                </form>

                <div class="auth-footer">
                    <p class="mb-2">
                        <a href="../" class="text-decoration-none">
                            <i class="fas fa-arrow-left me-1"></i>Back to Home
                        </a>
                    </p>
                    <p class="text-muted small mb-0">
                        Need help? Contact the church office for assistance.
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
    <script>
    // Phone number formatting (optional enhancement)
    document.getElementById('phone').addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        
        // Handle +44 format
        if (value.startsWith('44') && value.length === 12) {
            value = '0' + value.slice(2);
        }
        
        // Format as UK mobile (07123456789 or 07123 456789)
        if (value.length > 0) {
            if (value.length <= 5) {
                value = value;
            } else if (value.length <= 10) {
                value = value.slice(0, 5) + ' ' + value.slice(5);
            } else {
                value = value.slice(0, 5) + ' ' + value.slice(5, 10);
            }
        }
        
        e.target.value = value;
    });
    </script>
</body>
</html>
