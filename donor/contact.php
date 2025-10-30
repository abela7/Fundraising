<?php
/**
 * Donor Portal - Contact Admin / Support
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../admin/includes/resilient_db_loader.php';

function current_donor(): ?array {
    if (isset($_SESSION['donor'])) {
        return $_SESSION['donor'];
    }
    return null;
}

function require_donor_login(): void {
    if (!current_donor()) {
        header('Location: login.php');
        exit;
    }
}

require_donor_login();
$donor = current_donor();
$page_title = 'Contact Support';
$current_donor = $donor;

$success_message = '';
$error_message = '';

// Handle contact form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_contact') {
    verify_csrf();
    
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $category = trim($_POST['category'] ?? '');
    
    if (empty($subject) || empty($message)) {
        $error_message = 'Please fill in all required fields.';
    } else {
        // Store message in messages table (if it exists) or create audit log
        if ($db_connection_ok) {
            try {
                // Try to insert into messages table if it exists
                $messages_table_exists = $db->query("SHOW TABLES LIKE 'messages'")->num_rows > 0;
                
                if ($messages_table_exists) {
                    // Insert message (assuming messages table structure)
                    $insert_stmt = $db->prepare("
                        INSERT INTO messages (
                            from_user_id, to_user_id, subject, message, 
                            created_at, read_at, is_system
                        ) VALUES (?, 0, ?, ?, NOW(), NULL, 1)
                    ");
                    // Use donor ID or 0 for system
                    $from_id = 0; // System/donor portal
                    $insert_stmt->bind_param('iss', $from_id, $subject, $message);
                    $insert_stmt->execute();
                }
                
                // Always create audit log
                $audit_data = json_encode([
                    'donor_id' => $donor['id'],
                    'donor_name' => $donor['name'],
                    'donor_phone' => $donor['phone'],
                    'category' => $category,
                    'subject' => $subject,
                    'message' => $message
                ], JSON_UNESCAPED_SLASHES);
                $audit_stmt = $db->prepare("
                    INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) 
                    VALUES(?, 'donor', ?, 'contact_support', ?, 'donor_portal')
                ");
                $user_id = 0;
                $audit_stmt->bind_param('iis', $user_id, $donor['id'], $audit_data);
                $audit_stmt->execute();
                
                $success_message = 'Your message has been sent successfully! We will get back to you as soon as possible.';
                
                // Clear form
                $_POST = [];
            } catch (Exception $e) {
                $error_message = 'An error occurred while sending your message. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($donor['preferred_language'] ?? 'en'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Donor Portal</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/donor.css?v=<?php echo @filemtime(__DIR__ . '/assets/donor.css'); ?>">
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="app-content">
        <?php include 'includes/topbar.php'; ?>
        
        <main class="main-content">
                <div class="page-header">
                    <h1 class="page-title">Contact Support</h1>
                </div>

                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <!-- Contact Form -->
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fas fa-paper-plane text-primary"></i>Send us a Message
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="contactForm">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="submit_contact">
                                    
                                    <!-- Category -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-tag me-2"></i>Category <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" name="category" required>
                                            <option value="">Select a category...</option>
                                            <option value="payment">Payment Question</option>
                                            <option value="plan">Payment Plan Question</option>
                                            <option value="account">Account Issue</option>
                                            <option value="general">General Inquiry</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>

                                    <!-- Subject -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-heading me-2"></i>Subject <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" 
                                               class="form-control" 
                                               name="subject" 
                                               placeholder="Brief description of your question..."
                                               required>
                                    </div>

                                    <!-- Message -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-comment me-2"></i>Message <span class="text-danger">*</span>
                                        </label>
                                        <textarea class="form-control" 
                                                  name="message" 
                                                  rows="8" 
                                                  placeholder="Please provide details about your question or issue..."
                                                  required></textarea>
                                    </div>

                                    <!-- Donor Info Display -->
                                    <div class="alert alert-info">
                                        <small>
                                            <strong>Your Contact Information:</strong><br>
                                            Name: <?php echo htmlspecialchars($donor['name']); ?><br>
                                            Phone: <?php echo htmlspecialchars($donor['phone']); ?>
                                        </small>
                                    </div>

                                    <!-- Submit Button -->
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="fas fa-paper-plane me-2"></i>Send Message
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Help & Info -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fas fa-info-circle text-primary"></i>Quick Help
                                </h5>
                            </div>
                            <div class="card-body">
                                <h6 class="fw-bold">Common Questions:</h6>
                                <ul class="list-unstyled">
                                    <li class="mb-2">
                                        <i class="fas fa-question-circle text-primary me-2"></i>
                                        <strong>Payment Status:</strong> Check your payment history page
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-question-circle text-primary me-2"></i>
                                        <strong>Plan Updates:</strong> Contact us to modify your payment plan
                                    </li>
                                    <li class="mb-2">
                                        <i class="fas fa-question-circle text-primary me-2"></i>
                                        <strong>Account Issues:</strong> We'll help reset your access
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fas fa-phone text-primary"></i>Contact Details
                                </h5>
                            </div>
                            <div class="card-body">
                                <p class="mb-2">
                                    <i class="fas fa-church me-2 text-primary"></i>
                                    <strong>Church Office</strong>
                                </p>
                                <p class="mb-0 text-muted">
                                    Please contact the church office directly for urgent matters.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/donor.js"></script>
</body>
</html>

