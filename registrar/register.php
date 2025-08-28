<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../config/db.php';

$msg = '';
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        // Validation
        if ($name === '' || $email === '' || $phone === '') {
            $error = 'All fields are required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address';
        } elseif (strlen($name) < 2) {
            $error = 'Name must be at least 2 characters';
        } elseif (strlen($phone) < 10) {
            $error = 'Phone number must be at least 10 characters';
        } else {
            $db = db();
            
            // Check if email or phone already exists in applications or users table
            $stmt = $db->prepare('
                SELECT "application" as source FROM registrar_applications WHERE email = ? OR phone = ?
                UNION ALL
                SELECT "user" as source FROM users WHERE email = ? OR phone = ?
                LIMIT 1
            ');
            $stmt->bind_param('ssss', $email, $phone, $email, $phone);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($existing) {
                if ($existing['source'] === 'user') {
                    $error = 'This email or phone number is already registered as a user. Please contact an administrator if you need assistance.';
                } else {
                    $error = 'An application with this email or phone number already exists. Please check your email for updates or contact an administrator.';
                }
            } else {
                // Insert the application
                $stmt = $db->prepare('INSERT INTO registrar_applications (name, email, phone, status, created_at) VALUES (?, ?, ?, "pending", NOW())');
                $stmt->bind_param('sss', $name, $email, $phone);
                
                if ($stmt->execute()) {
                    $success = true;
                    $msg = 'Your registrar application has been submitted successfully! An administrator will review your application and contact you with login credentials if approved.';
                } else {
                    $error = 'Failed to submit application. Please try again later.';
                }
                $stmt->close();
            }
        }
    } catch (Exception $e) {
        $error = 'An error occurred. Please try again later.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Application - Church Fundraising</title>
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
                <?php if ($success): ?>
                    <!-- Success State -->
                    <div class="auth-header">
                        <div class="auth-logo success-state">
                            <i class="fas fa-check"></i>
                        </div>
                        <h1 class="auth-title">Application Submitted!</h1>
                        <p class="auth-subtitle">Your registrar application has been received</p>
                    </div>

                    <div class="success-content">
                        <div class="alert alert-success border-0 mb-4">
                            <i class="fas fa-info-circle me-2"></i>
                            <?php echo htmlspecialchars($msg); ?>
                        </div>

                        <div class="success-steps mb-4">
                            <div class="step-item">
                                <div class="step-icon">
                                    <i class="fas fa-paper-plane"></i>
                                </div>
                                <div class="step-content">
                                    <h6 class="step-title">Application Sent</h6>
                                    <p class="step-desc">Your application is being reviewed</p>
                                </div>
                            </div>
                            <div class="step-divider"></div>
                            <div class="step-item">
                                <div class="step-icon pending">
                                    <i class="fas fa-user-check"></i>
                                </div>
                                <div class="step-content">
                                    <h6 class="step-title">Admin Review</h6>
                                    <p class="step-desc">We'll review your application shortly</p>
                                </div>
                            </div>
                            <div class="step-divider"></div>
                            <div class="step-item">
                                <div class="step-icon pending">
                                    <i class="fas fa-key"></i>
                                </div>
                                <div class="step-content">
                                    <h6 class="step-title">Receive Access</h6>
                                    <p class="step-desc">Get your login credentials via email</p>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <a href="login.php" class="btn btn-primary btn-login">
                                <i class="fas fa-sign-in-alt me-2"></i>
                                Continue to Login
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Application Form -->
                    <div class="auth-header">
                        <div class="auth-logo">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <h1 class="auth-title">Join as Registrar</h1>
                        <p class="auth-subtitle">Apply for registrar access to help with fundraising</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger border-0" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="auth-form" novalidate>
                        <?php echo csrf_input(); ?>
                        
                        <div class="form-floating mb-3">
                            <input 
                                type="text" 
                                class="form-control" 
                                id="name" 
                                name="name" 
                                placeholder="Full Name"
                                value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                required 
                                minlength="2"
                                autofocus
                            >
                            <label for="name">
                                <i class="fas fa-user me-2"></i>Full Name
                            </label>
                            <div class="invalid-feedback">
                                Please provide your full name (at least 2 characters).
                            </div>
                        </div>
                        
                        <div class="form-floating mb-3">
                            <input 
                                type="email" 
                                class="form-control" 
                                id="email" 
                                name="email" 
                                placeholder="Email Address"
                                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                required
                            >
                            <label for="email">
                                <i class="fas fa-envelope me-2"></i>Email Address
                            </label>
                            <div class="invalid-feedback">
                                Please provide a valid email address.
                            </div>
                        </div>
                        
                        <div class="form-floating mb-4">
                            <input 
                                type="tel" 
                                class="form-control" 
                                id="phone" 
                                name="phone" 
                                placeholder="Phone Number"
                                value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                required 
                                minlength="10"
                                pattern="[0-9+\-\s\(\)]+"
                            >
                            <label for="phone">
                                <i class="fas fa-phone me-2"></i>Phone Number
                            </label>
                            <div class="invalid-feedback">
                                Please provide a valid phone number (at least 10 digits).
                            </div>
                        </div>

                        <!-- Application Info -->
                        <div class="application-info mb-4">
                            <h6 class="info-title">
                                <i class="fas fa-info-circle me-2"></i>
                                What happens next?
                            </h6>
                            <ul class="info-list style-none">
                                <li><i class="fas fa-check text-success me-2"></i>Your application will be reviewed by an administrator</li>
                                <li><i class="fas fa-check text-success me-2"></i>You'll receive login credentials via WhatsApp if approved</li>
                                <li><i class="fas fa-check text-success me-2"></i>You can then start registering donations and pledges</li>
                            </ul>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-login w-100 mb-3">
                            <i class="fas fa-paper-plane me-2"></i>
                            Submit Application
                        </button>
                    </form>
                <?php endif; ?>

                <div class="auth-footer">
                    <?php if (!$success): ?>
                        <div class="text-center mb-3">
                            <hr class="my-3">
                            <p class="mb-2">
                                <small class="text-muted">Already have registrar access?</small>
                            </p>
                            <a href="login.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-sign-in-alt me-1"></i>Login Here
                            </a>
                        </div>
                    <?php endif; ?>
                    
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
    <script>
        // Enhanced form validation and UX
        (function() {
            'use strict';
            
            // Initialize form validation
            const form = document.querySelector('.auth-form');
            if (form) {
                form.addEventListener('submit', function(event) {
                    if (!form.checkValidity()) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    form.classList.add('was-validated');
                });

                // Real-time validation feedback
                const inputs = form.querySelectorAll('input[required]');
                inputs.forEach(input => {
                    input.addEventListener('blur', function() {
                        if (this.checkValidity()) {
                            this.classList.remove('is-invalid');
                            this.classList.add('is-valid');
                        } else {
                            this.classList.remove('is-valid');
                            this.classList.add('is-invalid');
                        }
                    });

                    input.addEventListener('input', function() {
                        if (this.classList.contains('was-validated') || this.classList.contains('is-invalid')) {
                            if (this.checkValidity()) {
                                this.classList.remove('is-invalid');
                                this.classList.add('is-valid');
                            } else {
                                this.classList.remove('is-valid');
                                this.classList.add('is-invalid');
                            }
                        }
                    });
                });

                // Phone number formatting
                const phoneInput = document.getElementById('phone');
                if (phoneInput) {
                    phoneInput.addEventListener('input', function(e) {
                        // Allow only numbers, spaces, hyphens, parentheses, and plus
                        this.value = this.value.replace(/[^\d+\-\s\(\)]/g, '');
                    });
                }
            }

            // Smooth animations for success state
            if (document.querySelector('.success-state')) {
                const steps = document.querySelectorAll('.step-item');
                steps.forEach((step, index) => {
                    step.style.animationDelay = `${index * 0.2}s`;
                    step.classList.add('animate-in');
                });
            }
        })();
    </script>
</body>
</html>
