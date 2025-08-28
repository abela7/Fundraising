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
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-sm-10 col-md-7 col-lg-5">
                    <div class="card auth-card">
                        <div class="card-body">
                            <div class="brand">
                                <span class="brand-badge">Fundraising</span>
                                <span class="subtle">Registration</span>
                            </div>
                            
                            <?php if ($success): ?>
                                <div class="text-center">
                                    <i class="fas fa-check-circle text-success" style="font-size: 4rem; margin: 1rem 0;"></i>
                                    <h4 class="mb-3">Application Submitted!</h4>
                                    <div class="alert alert-success">
                                        <?php echo htmlspecialchars($msg); ?>
                                    </div>
                                    <div class="mt-4">
                                        <a href="login.php" class="btn btn-primary">
                                            <i class="fas fa-sign-in-alt me-2"></i>
                                            Go to Login
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <h4 class="mb-3">Apply to Become a Registrar</h4>
                                <p class="text-muted mb-4">
                                    Fill out the form below to apply for registrar access. Your application will be reviewed by an administrator.
                                </p>
                                
                                <?php if ($error): ?>
                                    <div class="alert alert-danger">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <?php echo htmlspecialchars($error); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <form method="post">
                                    <?php echo csrf_input(); ?>
                                    
                                    <div class="mb-3">
                                        <label for="name" class="form-label">
                                            <i class="fas fa-user me-2"></i>Full Name
                                        </label>
                                        <input 
                                            type="text" 
                                            id="name" 
                                            name="name" 
                                            class="form-control" 
                                            value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                            required 
                                            minlength="2"
                                            placeholder="Enter your full name"
                                        >
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="email" class="form-label">
                                            <i class="fas fa-envelope me-2"></i>Email Address
                                        </label>
                                        <input 
                                            type="email" 
                                            id="email" 
                                            name="email" 
                                            class="form-control" 
                                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                            required 
                                            placeholder="Enter your email address"
                                        >
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label for="phone" class="form-label">
                                            <i class="fas fa-phone me-2"></i>Phone Number
                                        </label>
                                        <input 
                                            type="tel" 
                                            id="phone" 
                                            name="phone" 
                                            class="form-control" 
                                            value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                            required 
                                            minlength="10"
                                            placeholder="Enter your phone number"
                                        >
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-paper-plane me-2"></i>
                                            Submit Application
                                        </button>
                                        
                                        <a href="login.php" class="btn btn-outline-light">
                                            <i class="fas fa-arrow-left me-2"></i>
                                            Back to Login
                                        </a>
                                    </div>
                                </form>
                                
                                <hr class="my-4">
                                
                                <div class="text-center">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Already have an account? <a href="login.php" class="text-decoration-none">Login here</a>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
