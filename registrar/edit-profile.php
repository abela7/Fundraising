<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1'); // Enable error display for debugging
ini_set('max_execution_time', '30');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php-errors.log');

// Start output buffering to catch any early output
ob_start();

// Debug function
function debug_log($message, $data = null) {
    $log_msg = date('Y-m-d H:i:s') . ' [EDIT-PROFILE] ' . $message;
    if ($data !== null) {
        $log_msg .= ' | Data: ' . print_r($data, true);
    }
    error_log($log_msg);
    // Also output to screen for debugging
    echo "<!-- DEBUG: " . htmlspecialchars($log_msg) . " -->\n";
}

debug_log('Page started');

try {
    debug_log('Loading auth.php');
    require_once __DIR__ . '/../shared/auth.php';
    debug_log('auth.php loaded');
    
    debug_log('Loading csrf.php');
    require_once __DIR__ . '/../shared/csrf.php';
    debug_log('csrf.php loaded');
    
    debug_log('Loading db.php');
    require_once __DIR__ . '/../config/db.php';
    debug_log('db.php loaded');
    
    debug_log('Calling require_login()');
    require_login();
    debug_log('require_login() completed');
    
    debug_log('Getting current_user()');
    $current_user = current_user();
    debug_log('current_user() completed', ['id' => $current_user['id'] ?? 'none', 'role' => $current_user['role'] ?? 'none']);
    
    // Ensure only registrars can access
    if (!in_array($current_user['role'] ?? '', ['registrar', 'admin'], true)) {
        debug_log('Access denied - not registrar/admin');
        header('Location: ../admin/error/403.php');
        exit;
    }
    
    debug_log('Getting database connection');
    $db = db();
    if (!$db) {
        throw new Exception('Database connection returned null');
    }
    debug_log('Database connection obtained');
    
    $user_id = (int)$current_user['id'];
    debug_log('User ID set', ['user_id' => $user_id]);
    
} catch (Throwable $e) {
    debug_log('FATAL ERROR in initialization', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    ob_end_clean();
    die('FATAL ERROR: ' . htmlspecialchars($e->getMessage()) . ' in ' . $e->getFile() . ':' . $e->getLine());
}

// Helper function
function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Load user data from database FIRST (needed for form processing)
$user = null;
try {
    debug_log('Starting user data load');
    $stmt = $db->prepare('SELECT id, name, phone, email, role FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new Exception('Database prepare failed: ' . $db->error);
    }
    debug_log('Query prepared');
    
    $stmt->bind_param('i', $user_id);
    debug_log('Parameters bound');
    
    if (!$stmt->execute()) {
        throw new Exception('Query execution failed: ' . $stmt->error);
    }
    debug_log('Query executed');
    
    $result = $stmt->get_result();
    if (!$result) {
        throw new Exception('Failed to get result: ' . $db->error);
    }
    debug_log('Result obtained');
    
    $user = $result->fetch_assoc();
    debug_log('User data fetched', ['user' => $user ? 'found' : 'not found']);
    
    $stmt->close();
    debug_log('Statement closed');
    
    if (!$user) {
        ob_end_clean();
        die('User not found. User ID: ' . $user_id);
    }
    
} catch (Throwable $e) {
    debug_log('ERROR loading user', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    ob_end_clean();
    die('Error loading user data: ' . htmlspecialchars($e->getMessage()));
}

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    debug_log('Form submission detected');
    try {
        debug_log('Verifying CSRF token');
        verify_csrf();
        debug_log('CSRF verified');
        
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        debug_log('Form data extracted', ['name' => $name, 'phone' => $phone, 'email' => $email]);
        
        // Validation
        if (empty($name)) {
            throw new Exception('Name is required');
        }
        if (empty($phone)) {
            throw new Exception('Phone number is required');
        }
        debug_log('Validation passed');
        
        // Ensure registrar can only update their own record
        if ($current_user['id'] != $user_id) {
            throw new Exception('You can only update your own profile');
        }
        debug_log('Ownership check passed');
        
        // Check if phone is already used by another user (only if phone changed)
        if ($phone !== ($user['phone'] ?? '')) {
            debug_log('Phone changed, checking for duplicates');
            $check_stmt = $db->prepare('SELECT id FROM users WHERE phone = ? AND id != ? LIMIT 1');
            if (!$check_stmt) {
                throw new Exception('Database prepare failed: ' . $db->error);
            }
            debug_log('Duplicate check query prepared');
            
            $check_stmt->bind_param('si', $phone, $user_id);
            debug_log('Duplicate check parameters bound');
            
            if (!$check_stmt->execute()) {
                $check_stmt->close();
                throw new Exception('Query execution failed: ' . $check_stmt->error);
            }
            debug_log('Duplicate check query executed');
            
            $check_result = $check_stmt->get_result();
            if ($check_result && $check_result->num_rows > 0) {
                $check_stmt->close();
                throw new Exception('This phone number is already in use by another user');
            }
            $check_stmt->close();
            debug_log('Duplicate check passed');
        }
        
        // Try to update user profile
        debug_log('Preparing UPDATE query');
        $update_stmt = $db->prepare('UPDATE users SET name = ?, phone = ?, email = ? WHERE id = ?');
        if (!$update_stmt) {
            throw new Exception('Database prepare failed: ' . $db->error);
        }
        debug_log('UPDATE query prepared');
        
        $email_value = empty($email) ? null : $email;
        debug_log('Binding parameters');
        $update_stmt->bind_param('sssi', $name, $phone, $email_value, $user_id);
        debug_log('Parameters bound, executing UPDATE');
        
        if (!$update_stmt->execute()) {
            $update_stmt->close();
            $error_msg = $db->error;
            debug_log('UPDATE failed', ['error' => $error_msg]);
            if (strpos($error_msg, 'Access denied') !== false || 
                strpos($error_msg, 'permission') !== false ||
                strpos($error_msg, 'denied') !== false) {
                throw new Exception('You do not have permission to update your profile. Please contact an administrator.');
            }
            throw new Exception('Update failed: ' . $error_msg);
        }
        debug_log('UPDATE executed successfully');
        $update_stmt->close();
        debug_log('UPDATE statement closed');
        
        // Update session
        debug_log('Updating session');
        $_SESSION['user']['name'] = $name;
        $_SESSION['user']['phone'] = $phone;
        $_SESSION['user']['email'] = $email;
        debug_log('Session updated');
        
        // Redirect to profile page with success message
        debug_log('Redirecting to profile.php');
        ob_end_clean();
        header('Location: profile.php?success=1');
        exit;
        
    } catch (Throwable $e) {
        debug_log('ERROR in form submission', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()]);
        error_log('Error updating profile in edit-profile.php: ' . $e->getMessage());
        $error_message = 'Failed to update profile: ' . $e->getMessage();
    }
}

debug_log('Form processing completed, loading includes');

// Check for success message from redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success_message = 'Profile updated successfully!';
}

$page_title = 'Edit Profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($page_title); ?> - Registrar Portal</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css">
    <link rel="stylesheet" href="assets/registrar.css">
</head>
<body>
<?php debug_log('HTML head rendered'); ?>
<div class="app-wrapper">
    <?php 
    debug_log('Including sidebar.php');
    try {
        include 'includes/sidebar.php';
        debug_log('sidebar.php included successfully');
    } catch (Throwable $e) {
        debug_log('ERROR including sidebar.php', ['error' => $e->getMessage()]);
        echo '<!-- ERROR: sidebar.php failed: ' . htmlspecialchars($e->getMessage()) . ' -->';
    }
    ?>
    
    <div class="app-content">
        <?php 
        debug_log('Including topbar.php');
        try {
            include 'includes/topbar.php';
            debug_log('topbar.php included successfully');
        } catch (Throwable $e) {
            debug_log('ERROR including topbar.php', ['error' => $e->getMessage()]);
            echo '<!-- ERROR: topbar.php failed: ' . htmlspecialchars($e->getMessage()) . ' -->';
        }
        ?>
        
        <main class="main-content">
            <?php debug_log('Main content started'); ?>
            <div class="container-fluid p-3 p-md-4">
                
                <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo h($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo h($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <div class="row justify-content-center">
                    <div class="col-12 col-md-8 col-lg-6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-edit me-2"></i>Edit Profile
                                </h5>
                            </div>
                            <div class="card-body p-4">
                                <?php debug_log('Rendering form'); ?>
                                <form method="POST" action="">
                                    <?php echo csrf_field(); ?>
                                    
                                    <div class="mb-3">
                                        <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="name" name="name" value="<?php echo h($user['name']); ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                        <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo h($user['phone'] ?? ''); ?>" required>
                                        <small class="text-muted">Used for login</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email Address</label>
                                        <input type="email" class="form-control" id="email" name="email" value="<?php echo h($user['email'] ?? ''); ?>">
                                        <small class="text-muted">Optional</small>
                                    </div>
                                    
                                    <div class="d-flex gap-2 justify-content-end mt-4">
                                        <a href="profile.php" class="btn btn-secondary">
                                            <i class="fas fa-times me-1"></i>Cancel Edit
                                        </a>
                                        <button type="submit" name="update_profile" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i>Update
                                        </button>
                                    </div>
                                </form>
                                <?php debug_log('Form rendered'); ?>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
            <?php debug_log('Main content completed'); ?>
        </main>
    </div>
</div>

<?php debug_log('Including JavaScript files'); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/registrar.js"></script>
<?php debug_log('Page rendering completed'); ?>
</body>
</html>
<?php
ob_end_flush();
debug_log('Output flushed, page complete');
?>
