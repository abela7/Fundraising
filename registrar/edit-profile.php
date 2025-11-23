<?php
declare(strict_types=1);
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

$db = db();
$user_id = (int)$current_user['id'];

// Helper function
function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
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
        
        $db->begin_transaction();
        
        // Check if phone is already used by another user
        $check_stmt = $db->prepare('SELECT id FROM users WHERE phone = ? AND id != ? LIMIT 1');
        if (!$check_stmt) {
            throw new Exception('Database prepare failed: ' . $db->error);
        }
        $check_stmt->bind_param('si', $phone, $user_id);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            $check_stmt->close();
            throw new Exception('This phone number is already in use by another user');
        }
        $check_stmt->close();
        
        // Update user profile
        $update_stmt = $db->prepare('UPDATE users SET name = ?, phone = ?, email = ?, updated_at = NOW() WHERE id = ?');
        if (!$update_stmt) {
            throw new Exception('Database prepare failed: ' . $db->error);
        }
        $update_stmt->bind_param('sssi', $name, $phone, $email, $user_id);
        if (!$update_stmt->execute()) {
            throw new Exception('Update failed: ' . $update_stmt->error);
        }
        $update_stmt->close();
        
        // Update session
        $_SESSION['user']['name'] = $name;
        $_SESSION['user']['phone'] = $phone;
        $_SESSION['user']['email'] = $email;
        
        $db->commit();
        
        // Redirect to profile page with success message
        header('Location: profile.php?success=1');
        exit;
        
    } catch (Exception $e) {
        if ($db->in_transaction) {
            $db->rollback();
        }
        $error_message = $e->getMessage();
    }
}

// Load user data from database
try {
    $stmt = $db->prepare('SELECT id, name, phone, email, role FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new Exception('Database prepare failed: ' . $db->error);
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        die('User not found');
    }
} catch (Exception $e) {
    die('Error loading user: ' . h($e->getMessage()));
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
                                    
                                    <div class="d-flex gap-2 justify-content-end">
                                        <a href="profile.php" class="btn btn-secondary">
                                            <i class="fas fa-times me-1"></i>Cancel
                                        </a>
                                        <button type="submit" name="update_profile" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i>Update Profile
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

