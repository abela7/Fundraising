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

// Load user data from database
$stmt = $db->prepare('SELECT id, name, phone, email, role, created_at, last_login_at FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    die('User not found');
}

$success_message = '';
$error_message = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    verify_csrf();
    
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    // Validation
    if (empty($name)) {
        $error_message = 'Name is required';
    } elseif (empty($phone)) {
        $error_message = 'Phone number is required';
    } else {
        try {
            $db->begin_transaction();
            
            // Check if phone is already used by another user
            $check_stmt = $db->prepare('SELECT id FROM users WHERE phone = ? AND id != ? LIMIT 1');
            $check_stmt->bind_param('si', $phone, $user_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                throw new Exception('This phone number is already in use by another user');
            }
            $check_stmt->close();
            
            // Update user profile
            $update_stmt = $db->prepare('UPDATE users SET name = ?, phone = ?, email = ?, updated_at = NOW() WHERE id = ?');
            $update_stmt->bind_param('sssi', $name, $phone, $email, $user_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            // Update session
            $_SESSION['user']['name'] = $name;
            $_SESSION['user']['phone'] = $phone;
            $_SESSION['user']['email'] = $email;
            
            $db->commit();
            
            // Reload user data
            $stmt = $db->prepare('SELECT id, name, phone, email, role, created_at, last_login_at FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $success_message = 'Profile updated successfully!';
        } catch (Exception $e) {
            $db->rollback();
            $error_message = $e->getMessage();
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    verify_csrf();
    
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($current_password)) {
        $error_message = 'Current password is required';
    } elseif (empty($new_password)) {
        $error_message = 'New password is required';
    } elseif (strlen($new_password) < 6) {
        $error_message = 'New password must be at least 6 characters';
    } elseif ($new_password !== $confirm_password) {
        $error_message = 'New passwords do not match';
    } else {
        try {
            // Verify current password
            $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!password_verify($current_password, $result['password_hash'])) {
                throw new Exception('Current password is incorrect');
            }
            
            // Update password
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $db->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?');
            $update_stmt->bind_param('si', $new_hash, $user_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            $success_message = 'Password changed successfully!';
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
}

$page_title = 'My Profile';
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
    <style>
        .profile-header {
            background: linear-gradient(135deg, #0a6286 0%, #084d68 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0 auto 1rem;
            border: 4px solid rgba(255, 255, 255, 0.3);
        }
        
        .profile-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .profile-card h5 {
            color: #0a6286;
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #f1f5f9;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #64748b;
        }
        
        .info-value {
            color: #1e293b;
        }
        
        @media (max-width: 768px) {
            .profile-header {
                padding: 1.5rem 1rem;
            }
            
            .profile-avatar {
                width: 80px;
                height: 80px;
                font-size: 2rem;
            }
            
            .profile-card {
                padding: 1rem;
            }
            
            .info-row {
                flex-direction: column;
                gap: 0.25rem;
            }
        }
    </style>
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
                
                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="text-center">
                        <div class="profile-avatar">
                            <?php
                            $names = explode(' ', trim($user['name']));
                            $initials = '';
                            foreach ($names as $name) {
                                if ($name) $initials .= strtoupper(substr($name, 0, 1));
                            }
                            echo h(substr($initials ?: 'U', 0, 2));
                            ?>
                        </div>
                        <h3 class="mb-1"><?php echo h($user['name']); ?></h3>
                        <p class="mb-0 opacity-75">
                            <i class="fas fa-user-tag me-1"></i><?php echo ucfirst(h($user['role'])); ?>
                        </p>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Account Information -->
                    <div class="col-12 col-lg-6">
                        <div class="profile-card">
                            <h5>
                                <i class="fas fa-info-circle me-2"></i>Account Information
                            </h5>
                            
                            <div class="info-row">
                                <span class="info-label">Name</span>
                                <span class="info-value"><?php echo h($user['name']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Phone</span>
                                <span class="info-value"><?php echo h($user['phone']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Email</span>
                                <span class="info-value"><?php echo h($user['email'] ?: 'Not set'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Role</span>
                                <span class="info-value">
                                    <span class="badge bg-primary"><?php echo ucfirst(h($user['role'])); ?></span>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Member Since</span>
                                <span class="info-value">
                                    <?php 
                                    if ($user['created_at']) {
                                        echo date('F j, Y', strtotime($user['created_at']));
                                    } else {
                                        echo 'Unknown';
                                    }
                                    ?>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Last Login</span>
                                <span class="info-value">
                                    <?php 
                                    if ($user['last_login_at']) {
                                        echo date('M j, Y g:i A', strtotime($user['last_login_at']));
                                    } else {
                                        echo 'Never';
                                    }
                                    ?>
                                </span>
                            </div>
                            
                            <div class="mt-3">
                                <button type="button" class="btn btn-primary w-100" id="editProfileBtn" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                                    <i class="fas fa-edit me-2"></i>Edit Profile
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Security Settings -->
                    <div class="col-12 col-lg-6">
                        <div class="profile-card">
                            <h5>
                                <i class="fas fa-shield-alt me-2"></i>Security Settings
                            </h5>
                            
                            <div class="info-row">
                                <span class="info-label">Password</span>
                                <span class="info-value">••••••••</span>
                            </div>
                            
                            <div class="mt-3">
                                <button type="button" class="btn btn-warning w-100" id="changePasswordBtn" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                                    <i class="fas fa-key me-2"></i>Change Password
                                </button>
                            </div>
                            
                            <div class="alert alert-info mt-3 mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                <small>Use a strong password with at least 6 characters</small>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
        </main>
    </div>
</div>

<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" value="<?php echo h($user['name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                        <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo h($user['phone']); ?>" required>
                        <small class="text-muted">Used for login</small>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?php echo h($user['email']); ?>">
                        <small class="text-muted">Optional</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_profile" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-key me-2"></i>Change Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6">
                        <small class="text-muted">Minimum 6 characters</small>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="change_password" class="btn btn-warning">
                        <i class="fas fa-key me-2"></i>Change Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Simple JavaScript for profile page
document.addEventListener('DOMContentLoaded', function() {
    // Handle sidebar toggle
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    if (sidebarToggle && sidebar && sidebarOverlay) {
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            sidebar.classList.add('show');
            sidebarOverlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        });

        // Close sidebar when clicking overlay
        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('show');
            sidebarOverlay.classList.remove('show');
            document.body.style.overflow = '';
        });
    }

    // Handle desktop sidebar toggle
    const desktopSidebarToggle = document.getElementById('desktopSidebarToggle');
    const appContent = document.querySelector('.app-content');

    if (desktopSidebarToggle && sidebar && appContent) {
        // Start collapsed on desktop
        if (window.innerWidth >= 768) {
            sidebar.classList.add('collapsed');
            appContent.classList.add('collapsed');
        }

        desktopSidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            sidebar.classList.toggle('collapsed');
            appContent.classList.toggle('collapsed');
        });
    }

    // Handle dropdowns
    const dropdownToggles = document.querySelectorAll('[data-bs-toggle="dropdown"]');
    dropdownToggles.forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            const dropdown = toggle.closest('.dropdown');
            if (dropdown) {
                const menu = dropdown.querySelector('.dropdown-menu');
                if (menu) {
                    // Close other dropdowns
                    document.querySelectorAll('.dropdown-menu.show').forEach(function(otherMenu) {
                        otherMenu.classList.remove('show');
                    });
                    // Toggle this dropdown
                    menu.classList.toggle('show');
                }
            }
        });
    });

    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown-menu.show').forEach(function(menu) {
                menu.classList.remove('show');
            });
        }
    });

    // Handle modals
    const editProfileBtn = document.getElementById('editProfileBtn');
    if (editProfileBtn) {
        editProfileBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const modal = document.getElementById('editProfileModal');
            if (modal && typeof bootstrap !== 'undefined') {
                const bsModal = new bootstrap.Modal(modal);
                bsModal.show();
            }
        });
    }

    const changePasswordBtn = document.getElementById('changePasswordBtn');
    if (changePasswordBtn) {
        changePasswordBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const modal = document.getElementById('changePasswordModal');
            if (modal && typeof bootstrap !== 'undefined') {
                const bsModal = new bootstrap.Modal(modal);
                bsModal.show();
            }
        });
    }
});
</script>
</body>
</html>

