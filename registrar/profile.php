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
try {
    $stmt = $db->prepare('SELECT id, name, phone, email, role, created_at, last_login_at FROM users WHERE id = ? LIMIT 1');
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

$success_message = '';
$error_message = '';

// Handle profile update
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
            throw new Exception('Phone is required');
        }
        
        // Normalize email
        $email_value = empty($email) ? null : $email;
        
        // Simple UPDATE query
        $stmt = $db->prepare('UPDATE users SET name = ?, phone = ?, email = ? WHERE id = ?');
        if (!$stmt) {
            throw new Exception('Failed to prepare update');
        }
        
        $stmt->bind_param('sssi', $name, $phone, $email_value, $user_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            
            // Update session
            $_SESSION['user']['name'] = $name;
            $_SESSION['user']['phone'] = $phone;
            $_SESSION['user']['email'] = $email_value;
            
            // Reload user data
            $user['name'] = $name;
            $user['phone'] = $phone;
            $user['email'] = $email_value;
            
            $success_message = 'Profile updated successfully!';
        } else {
            $stmt->close();
            throw new Exception('Failed to update profile');
        }
        
    } catch (Exception $e) {
        error_log('Profile update error: ' . $e->getMessage());
        $error_message = $e->getMessage();
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/registrar.css?v=<?php echo @filemtime(__DIR__ . '/assets/registrar.css'); ?>">
    <style>
        .profile-header {
            background: linear-gradient(135deg, #0a6286 0%, #084d68 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .profile-header h3 {
            color: white !important;
        }
        
        .profile-header p {
            color: rgba(255, 255, 255, 0.9) !important;
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
                        <button class="btn btn-light btn-sm mt-3" type="button" data-bs-toggle="offcanvas" data-bs-target="#editProfileOffcanvas">
                            <i class="fas fa-edit me-1"></i>Edit Profile
                        </button>
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
                                <span class="info-value"><?php echo h($user['phone'] ?? 'Not set'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Email</span>
                                <span class="info-value"><?php echo h($user['email'] ?? 'Not set'); ?></span>
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
                        </div>
                    </div>
                </div>
                
            </div>
        </main>
    </div>
</div>

<!-- Edit Profile Offcanvas (slides from right) -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="editProfileOffcanvas">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title">
            <i class="fas fa-edit me-2"></i>Edit Profile
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
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
            
            <div class="d-grid gap-2">
                <button type="submit" name="update_profile" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i>Save Changes
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="offcanvas">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/registrar.js?v=<?php echo @filemtime(__DIR__ . '/assets/registrar.js'); ?>"></script>
</body>
</html>
