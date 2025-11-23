<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('max_execution_time', '30');

// Enable output buffering and flushing for debugging
if (ob_get_level() === 0) {
    ob_start();
}

// Ensure logs directory exists (silently fail if can't create)
$logs_dir = __DIR__ . '/../logs';
if (!is_dir($logs_dir)) {
    @mkdir($logs_dir, 0755, true);
}

try {
    require_once __DIR__ . '/../shared/auth.php';
    require_once __DIR__ . '/../shared/csrf.php';
    require_once __DIR__ . '/../config/db.php';
    
    require_login();
    
    $current_user = current_user();
    if (!$current_user) {
        header('Location: login.php');
        exit;
    }
    
    if (!in_array($current_user['role'] ?? '', ['registrar', 'admin'], true)) {
        header('Location: ../admin/error/403.php');
        exit;
    }
    
    $db = db();
    if (!$db) {
        throw new Exception('Database connection failed');
    }
    
    $user_id = (int)$current_user['id'];
    
} catch (Throwable $e) {
    error_log('FATAL ERROR in edit-profile.php initialization: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    // Show error details in development, generic message in production
    if (ini_get('display_errors')) {
        die('Error loading page: ' . htmlspecialchars($e->getMessage()) . ' in ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine());
    } else {
        die('Error loading page. Please contact support.');
    }
}

// Helper function
function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Load user data from database
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
        
        // Update user profile
        $update_stmt = $db->prepare('UPDATE users SET name = ?, phone = ?, email = ? WHERE id = ?');
        if (!$update_stmt) {
            throw new Exception('Database prepare failed: ' . $db->error);
        }
        $email_value = empty($email) ? null : $email;
        $update_stmt->bind_param('sssi', $name, $phone, $email_value, $user_id);
        if (!$update_stmt->execute()) {
            $error_msg = $db->error;
            $update_stmt->close();
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
    <?php 
    // Safely include sidebar
    $sidebar_file = __DIR__ . '/includes/sidebar.php';
    if (file_exists($sidebar_file)) {
        try {
            ob_start();
            include $sidebar_file;
            $sidebar_output = ob_get_clean();
            echo $sidebar_output;
        } catch (Throwable $e) {
            ob_end_clean();
            error_log('Error including sidebar.php: ' . $e->getMessage());
            // Don't break the page if sidebar fails
        }
    }
    ?>
    
    <div class="app-content">
        <?php 
        // Safely include topbar
        $topbar_file = __DIR__ . '/includes/topbar.php';
        if (file_exists($topbar_file)) {
            try {
                ob_start();
                include $topbar_file;
                $topbar_output = ob_get_clean();
                echo $topbar_output;
            } catch (Throwable $e) {
                ob_end_clean();
                error_log('Error including topbar.php: ' . $e->getMessage());
                // Don't break the page if topbar fails
                // Show a simple header instead
                echo '<header class="topbar"><div class="topbar-inner"><h1 class="topbar-title">Edit Profile</h1></div></header>';
            }
        } else {
            echo '<header class="topbar"><div class="topbar-inner"><h1 class="topbar-title">Edit Profile</h1></div></header>';
        }
        ?>
        
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
                                            <i class="fas fa-times me-1"></i>Cancel Edit
                                        </a>
                                        <button type="submit" name="update_profile" class="btn btn-primary" id="submitBtn">
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
<script>
// Bootstrap fallback and minimal polyfills if CDN fails
(function() {
    'use strict';
    function loadAltCdn() {
        var alt = document.createElement('script');
        alt.src = 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js';
        alt.defer = true;
        alt.onload = initPolyfills;
        alt.onerror = initPolyfills; // even if alt fails, enable polyfills
        document.head.appendChild(alt);
    }
    function initPolyfills() {
        if (typeof window.bootstrap !== 'undefined') return; // Bootstrap loaded, nothing to polyfill
        // Polyfill: dismissible alerts
        document.querySelectorAll('.alert .btn-close').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var alert = btn.closest('.alert');
                if (alert) alert.remove();
            });
        });
        // Polyfill: simple dropdown toggle for user menu
        var toggle = document.querySelector('[data-bs-toggle="dropdown"]');
        var menu = toggle ? toggle.parentElement.querySelector('.dropdown-menu') : null;
        if (toggle && menu) {
            function closeMenu(e) {
                if (!menu.classList.contains('show')) return;
                if (!menu.contains(e.target) && !toggle.contains(e.target)) {
                    menu.classList.remove('show');
                    document.removeEventListener('click', closeMenu, true);
                }
            }
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                menu.classList.toggle('show');
                if (menu.classList.contains('show')) {
                    document.addEventListener('click', closeMenu, true);
                }
            });
        }
    }
    // After page load, if Bootstrap is missing, try alt CDN then polyfill
    window.addEventListener('load', function() {
        setTimeout(function() {
            if (typeof window.bootstrap === 'undefined') {
                loadAltCdn();
                // Ensure we eventually polyfill even if alt CDN is blocked
                setTimeout(initPolyfills, 1200);
            }
        }, 200);
    });
})();
</script>
<script>
// Prevent double submission and handle errors
(function() {
    'use strict';
    
    try {
        document.addEventListener('DOMContentLoaded', function() {
            try {
                const form = document.getElementById('editProfileForm');
                const submitBtn = document.getElementById('submitBtn');
                
                if (form && submitBtn) {
                    form.addEventListener('submit', function(e) {
                        try {
                            submitBtn.disabled = true;
                            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Updating...';
                        } catch (err) {
                            console.error('Error in form submit handler:', err);
                        }
                    });
                }
                
                // Check if Bootstrap is loaded
                if (typeof bootstrap === 'undefined') {
                    console.warn('Bootstrap is not loaded - attempting fallback');
                }
            } catch (err) {
                console.error('Error in DOMContentLoaded handler:', err);
            }
        });
    } catch (err) {
        console.error('Error initializing page scripts:', err);
    }
})();
</script>
<script>
// Safely load registrar.js
(function() {
    'use strict';
    try {
        const script = document.createElement('script');
        script.src = 'assets/registrar.js';
        script.onerror = function() {
            console.warn('registrar.js failed to load - page will still work');
        };
        document.head.appendChild(script);
    } catch (err) {
        console.error('Error loading registrar.js:', err);
    }
})();
</script>
</body>
</html>
