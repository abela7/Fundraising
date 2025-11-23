<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Don't display errors, but log them
ini_set('max_execution_time', '30'); // Prevent freezing

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../config/db.php';

require_login();

// Ensure only registrars can access
$current_user = current_user();
if (!in_array($current_user['role'] ?? '', ['registrar', 'admin'], true)) {
    header('Location: ../admin/error/403.php');
    exit;
}

try {
    $db = db();
    if (!$db) {
        die('Database connection failed');
    }
} catch (Exception $e) {
    die('Database error: ' . h($e->getMessage()));
}

$user_id = (int)$current_user['id'];

// Helper function
function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Load user data from database FIRST (needed for form processing)
$user = null;
try {
    $stmt = $db->prepare('SELECT id, name, phone, email, role FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new Exception('Database prepare failed: ' . $db->error);
    }
    $stmt->bind_param('i', $user_id);
    if (!$stmt->execute()) {
        throw new Exception('Query execution failed: ' . $stmt->error);
    }
    $result = $stmt->get_result();
    if (!$result) {
        throw new Exception('Failed to get result: ' . $db->error);
    }
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        die('User not found. User ID: ' . $user_id);
    }
} catch (Throwable $e) {
    error_log('Error loading user in edit-profile.php: ' . $e->getMessage());
    die('Error loading user data. Please contact support.');
}

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        verify_csrf();
        
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        // Validation
        if (empty($name)) {
            throw new Exception('Name is required');
        }
        if (empty($phone)) {
            throw new Exception('Phone number is required');
        }
        
        // Ensure registrar can only update their own record
        if ($current_user['id'] != $user_id) {
            throw new Exception('You can only update your own profile');
        }
        
        // Check if phone is already used by another user (only if phone changed)
        if ($phone !== ($user['phone'] ?? '')) {
            $check_stmt = $db->prepare('SELECT id FROM users WHERE phone = ? AND id != ? LIMIT 1');
            if (!$check_stmt) {
                throw new Exception('Database prepare failed: ' . $db->error);
            }
            $check_stmt->bind_param('si', $phone, $user_id);
            if (!$check_stmt->execute()) {
                $check_stmt->close();
                throw new Exception('Query execution failed: ' . $check_stmt->error);
            }
            $check_result = $check_stmt->get_result();
            if ($check_result && $check_result->num_rows > 0) {
                $check_stmt->close();
                throw new Exception('This phone number is already in use by another user');
            }
            $check_stmt->close();
        }
        
        // Try to update user profile - only update own record
        // If database permissions don't allow UPDATE, we'll catch the error
        $update_stmt = $db->prepare('UPDATE users SET name = ?, phone = ?, email = ? WHERE id = ?');
        if (!$update_stmt) {
            throw new Exception('Database prepare failed: ' . $db->error);
        }
        $email_value = empty($email) ? null : $email;
        $update_stmt->bind_param('sssi', $name, $phone, $email_value, $user_id);
        if (!$update_stmt->execute()) {
            $update_stmt->close();
            // Check if it's a permission error
            $error_msg = $db->error;
            if (strpos($error_msg, 'Access denied') !== false || 
                strpos($error_msg, 'permission') !== false ||
                strpos($error_msg, 'denied') !== false) {
                throw new Exception('You do not have permission to update your profile. Please contact an administrator.');
            }
            throw new Exception('Update failed: ' . $error_msg);
        }
        $update_stmt->close();
        
        // Update session
        $_SESSION['user']['name'] = $name;
        $_SESSION['user']['phone'] = $phone;
        $_SESSION['user']['email'] = $email;
        
        // Redirect to profile page with success message
        header('Location: profile.php?success=1');
        exit;
        
    } catch (Throwable $e) {
        error_log('Error updating profile in edit-profile.php: ' . $e->getMessage());
        $error_message = 'Failed to update profile: ' . $e->getMessage();
    }
}

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
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="app-content">
        <?php include 'includes/topbar.php'; ?>
        
        <main class="main-content">
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
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/registrar.js"></script>
</body>
</html>

