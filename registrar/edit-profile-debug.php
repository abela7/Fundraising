<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('max_execution_time', '30');

// Flush output immediately
ob_start();
echo "<!-- Step 1: Starting -->\n";
ob_flush();
flush();

try {
    echo "<!-- Step 2: Loading auth.php -->\n";
    ob_flush();
    flush();
    require_once __DIR__ . '/../shared/auth.php';
    
    echo "<!-- Step 3: Loading csrf.php -->\n";
    ob_flush();
    flush();
    require_once __DIR__ . '/../shared/csrf.php';
    
    echo "<!-- Step 4: Loading db.php -->\n";
    ob_flush();
    flush();
    require_once __DIR__ . '/../config/db.php';
    
    echo "<!-- Step 5: Calling require_login() -->\n";
    ob_flush();
    flush();
    require_login();
    
    echo "<!-- Step 6: Getting current_user() -->\n";
    ob_flush();
    flush();
    $current_user = current_user();
    
    if (!$current_user) {
        die('NOT LOGGED IN');
    }
    
    echo "<!-- Step 7: Checking role -->\n";
    ob_flush();
    flush();
    if (!in_array($current_user['role'] ?? '', ['registrar', 'admin'], true)) {
        die('NOT REGISTRAR OR ADMIN');
    }
    
    echo "<!-- Step 8: Getting database connection -->\n";
    ob_flush();
    flush();
    $db = db();
    if (!$db) {
        die('DATABASE CONNECTION FAILED');
    }
    
    echo "<!-- Step 9: Loading user data -->\n";
    ob_flush();
    flush();
    $user_id = (int)$current_user['id'];
    $stmt = $db->prepare('SELECT id, name, phone, email, role FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        die('USER NOT FOUND');
    }
    
    echo "<!-- Step 10: All checks passed, rendering page -->\n";
    ob_flush();
    flush();
    
} catch (Throwable $e) {
    die('ERROR: ' . htmlspecialchars($e->getMessage()) . ' in ' . $e->getFile() . ':' . $e->getLine());
}

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
        
        if (empty($name)) {
            throw new Exception('Name is required');
        }
        if (empty($phone)) {
            throw new Exception('Phone number is required');
        }
        
        if ($current_user['id'] != $user_id) {
            throw new Exception('You can only update your own profile');
        }
        
        if ($phone !== ($user['phone'] ?? '')) {
            $check_stmt = $db->prepare('SELECT id FROM users WHERE phone = ? AND id != ? LIMIT 1');
            $check_stmt->bind_param('si', $phone, $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result && $check_result->num_rows > 0) {
                $check_stmt->close();
                throw new Exception('This phone number is already in use by another user');
            }
            $check_stmt->close();
        }
        
        $update_stmt = $db->prepare('UPDATE users SET name = ?, phone = ?, email = ? WHERE id = ?');
        $email_value = empty($email) ? null : $email;
        $update_stmt->bind_param('sssi', $name, $phone, $email_value, $user_id);
        if (!$update_stmt->execute()) {
            throw new Exception('Update failed: ' . $db->error);
        }
        $update_stmt->close();
        
        $_SESSION['user']['name'] = $name;
        $_SESSION['user']['phone'] = $phone;
        $_SESSION['user']['email'] = $email;
        
        header('Location: profile.php?success=1');
        exit;
        
    } catch (Throwable $e) {
        $error_message = 'Failed to update profile: ' . $e->getMessage();
    }
}

if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success_message = 'Profile updated successfully!';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Profile (Debug) - Registrar Portal</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css">
    <link rel="stylesheet" href="assets/registrar.css">
</head>
<body>
<div class="app-wrapper">
    <!-- Simplified header without includes -->
    <header class="topbar">
        <div class="topbar-inner">
            <h1 class="topbar-title">Edit Profile (Debug Mode)</h1>
        </div>
    </header>
    
    <div class="app-content">
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
                                <form method="POST" action="" id="editProfileForm">
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
                                            <i class="fas fa-times me-1"></i>Cancel
                                        </a>
                                        <button type="submit" name="update_profile" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i>Update Profile
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <a href="edit-profile.php" class="btn btn-outline-secondary btn-sm">Try Original Page</a>
                        </div>
                    </div>
                </div>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

