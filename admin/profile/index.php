<?php
require_once '../../config/db.php';
require_once '../../shared/auth.php';
require_once '../../shared/csrf.php';
require_login();

$sessionUser = current_user();
$db = db();

// Helper encode
function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

// Load latest user row from DB (ensures we have email, created_at)
$userRow = null;
$modal = $_GET['modal'] ?? '';
$showProfileModal = false;
$showPasswordModal = false;
if ($sessionUser && isset($sessionUser['id'])) {
    $stmt = $db->prepare('SELECT id, name, phone, email, role, created_at, last_login_at FROM users WHERE id = ? LIMIT 1');
    $uid = (int)$sessionUser['id'];
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $userRow = $stmt->get_result()->fetch_assoc();
}

if (!$userRow) {
    // Fallback to session if DB row missing
    $userRow = [
        'id' => $sessionUser['id'] ?? 0,
        'name' => $sessionUser['name'] ?? 'User',
        'phone' => $sessionUser['phone'] ?? '',
        'email' => $sessionUser['email'] ?? null,
        'role' => $sessionUser['role'] ?? 'registrar',
        'created_at' => null,
        'last_login_at' => null,
    ];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
    } catch (Throwable $e) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: ./');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($name === '' || $phone === '') {
            $_SESSION['error'] = 'Name and phone are required.';
            $_SESSION['form_data'] = ['name' => $name, 'email' => $email, 'phone' => $phone];
            header('Location: ./?modal=profile');
            exit;
        }

        // Ensure phone uniqueness (excluding self)
        $sql = 'SELECT id FROM users WHERE phone = ? AND id <> ? LIMIT 1';
        $stmt = $db->prepare($sql);
        $checkId = (int)$userRow['id'];
        $stmt->bind_param('si', $phone, $checkId);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $_SESSION['error'] = 'Phone number is already in use.';
            $_SESSION['form_data'] = ['name' => $name, 'email' => $email, 'phone' => $phone];
            header('Location: ./?modal=profile');
            exit;
        }

        // Optional: validate email format if provided
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Please enter a valid email address.';
            $_SESSION['form_data'] = ['name' => $name, 'email' => $email, 'phone' => $phone];
            header('Location: ./?modal=profile');
            exit;
        }

        $sql = 'UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?';
        $stmt = $db->prepare($sql);
        $emailParam = ($email === '' ? null : $email);
        $updateId = (int)$userRow['id'];
        $stmt->bind_param('sssi', $name, $emailParam, $phone, $updateId);
        if ($stmt->execute()) {
            // Refresh local user row and session for name/phone
            $userRow['name'] = $name;
            $userRow['email'] = ($email === '' ? null : $email);
            $userRow['phone'] = $phone;
            $_SESSION['user']['name'] = $name;
            $_SESSION['user']['phone'] = $phone;
            $_SESSION['success'] = 'Profile updated successfully.';
        } else {
            $_SESSION['error'] = 'Failed to update profile.';
        }

        header('Location: ./');
        exit;
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($new === '' || $confirm === '' || strlen($new) < 6) {
            $_SESSION['error'] = 'New password must be at least 6 characters.';
            header('Location: ./?modal=password');
            exit;
        }
        if ($new !== $confirm) {
            $_SESSION['error'] = 'New password and confirmation do not match.';
            header('Location: ./?modal=password');
            exit;
        }

        // Fetch hash
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $pwdId = (int)$userRow['id'];
        $stmt->bind_param('i', $pwdId);
        $stmt->execute();
        $hashRow = $stmt->get_result()->fetch_assoc();
        $hash = $hashRow['password_hash'] ?? '';

        if ($hash && !password_verify($current, $hash)) {
            $_SESSION['error'] = 'Current password is incorrect.';
            header('Location: ./?modal=password');
            exit;
        }

        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $updPwdId = (int)$userRow['id'];
        $stmt->bind_param('si', $newHash, $updPwdId);
        if ($stmt->execute()) {
            $_SESSION['success'] = 'Password updated successfully.';
        } else {
            $_SESSION['error'] = 'Failed to update password.';
        }
        header('Location: ./');
        exit;
    }
}

// Live stats for the profile
$stats = [
    'actions_today' => 0,
    'approvals_today' => 0,
    'success_rate' => 0,
];

// Actions today from audit_logs
$stmt = $db->prepare('SELECT COUNT(*) AS c FROM audit_logs WHERE user_id = ? AND DATE(created_at) = CURDATE()');
$auditId = (int)$userRow['id'];
$stmt->bind_param('i', $auditId);
$stmt->execute();
$stats['actions_today'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

// Approvals today from pledges approved by this user
$stmt = $db->prepare("SELECT COUNT(*) AS c FROM pledges WHERE approved_by_user_id = ? AND DATE(approved_at) = CURDATE()");
$apprId = (int)$userRow['id'];
$stmt->bind_param('i', $apprId);
$stmt->execute();
$stats['approvals_today'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

// Remove success rate calculation - not needed

// Login history (fallback to audit logs if available)
$loginHistory = [];
$stmt = $db->prepare('SELECT created_at, action, entity_type, entity_id FROM audit_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
$histId = (int)$userRow['id'];
$stmt->bind_param('i', $histId);
$stmt->execute();
$loginHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Modal flags (server-driven)
$showProfileModal = ($modal === 'profile');
$showPasswordModal = ($modal === 'password');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Fundraising System</title>
    
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/profile.css">
    
    <!-- Favicon -->
    <link rel="icon" href="../../assets/favicon.svg" type="image/svg+xml">
</head>
<body>
    <div class="admin-wrapper">
        <?php include '../includes/sidebar.php'; ?>
        <div class="admin-content">
            <?php include '../includes/topbar.php'; ?>
            <main class="main-content">
                <div class="container-fluid">
                    <!-- Page Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="page-title mb-1">My Profile</h1>
                            <p class="text-muted mb-0">Manage your account settings and preferences</p>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-primary" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt me-2"></i>Refresh
                            </button>
                        </div>
                    </div>
                    
                    <div class="row">
                        <!-- Profile Overview -->
                        <div class="col-lg-4 mb-4">
                            <div class="profile-card">
                                <div class="profile-header">
                                    <div class="profile-avatar">
                                        <i class="fas fa-user"></i>
                                        <div class="avatar-edit" onclick="alert('Avatar upload feature coming soon!')">
                                            <i class="fas fa-camera"></i>
                                        </div>
                                    </div>
                                    <h3 class="profile-name"><?php echo h($userRow['name'] ?? 'Unknown User'); ?></h3>
                                    <p class="profile-role">
                                        <span class="badge bg-<?php echo $userRow['role'] === 'admin' ? 'danger' : 'primary'; ?>">
                                            <i class="fas fa-<?php echo $userRow['role'] === 'admin' ? 'crown' : 'user-tie'; ?> me-1"></i>
                                            <?php echo h(ucfirst((string)($userRow['role'] ?? $sessionUser['role'] ?? ''))); ?>
                                        </span>
                                    </p>
                                    <div class="profile-status">
                                        <i class="fas fa-circle text-success me-1"></i>
                                        <span class="text-success">Active</span>
                                    </div>
                                </div>
                                <div class="profile-stats">
                                    <div class="stat">
                                        <div class="stat-icon">
                                            <i class="fas fa-tasks"></i>
                                        </div>
                                        <div class="stat-content">
                                            <div class="stat-value"><?php echo (int)$stats['actions_today']; ?></div>
                                            <div class="stat-label">Actions Today</div>
                                        </div>
                                    </div>
                                    <div class="stat">
                                        <div class="stat-icon">
                                            <i class="fas fa-check-circle"></i>
                                        </div>
                                        <div class="stat-content">
                                            <div class="stat-value"><?php echo (int)$stats['approvals_today']; ?></div>
                                            <div class="stat-label">Approvals Today</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="profile-info">
                                    <div class="info-item">
                                        <i class="fas fa-phone"></i>
                                        <span><?php echo h($userRow['phone']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-envelope"></i>
                                        <span><?php echo h($userRow['email'] ?? 'Not set'); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-calendar"></i>
                                        <span>Joined <?php echo $userRow['created_at'] ? date('M Y', strtotime($userRow['created_at'])) : '—'; ?></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-clock"></i>
                                        <span>Last login: <?php echo $userRow['last_login_at'] ? date('M j, g:i A', strtotime($userRow['last_login_at'])) : 'Never'; ?></span>
                                    </div>
                                </div>
                                
                                <!-- Quick Actions -->
                                <div class="profile-actions mt-3">
                                    <a href="?modal=profile" class="btn btn-primary btn-sm w-100 mb-2">
                                        <i class="fas fa-edit me-2"></i>Edit Profile
                                    </a>
                                    <a href="?modal=password" class="btn btn-outline-warning btn-sm w-100">
                                        <i class="fas fa-key me-2"></i>Change Password
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Profile Settings -->
                        <div class="col-lg-8">
                            <!-- Success/Error Messages -->
                            <?php if (isset($_SESSION['success'])): ?>
                                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                                    <i class="fas fa-check-circle me-2"></i><?php echo h($_SESSION['success']); unset($_SESSION['success']); ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>
                            <?php if (isset($_SESSION['error'])): ?>
                                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                                <?php unset($_SESSION['form_data']); ?>
                            <?php endif; ?>
                            
                            <!-- Personal Information -->
                            <div class="settings-section mb-4">
                                <div class="section-header">
                                    <h4><i class="fas fa-user me-2"></i>Personal Information</h4>
                                    <a class="btn btn-sm btn-outline-primary" href="?modal=profile">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                </div>

                                <form id="personalForm" method="POST" class="needs-validation">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="update_profile">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Full Name</label>
                                            <input type="text" name="name" class="form-control" value="<?php echo h($_SESSION['form_data']['name'] ?? $userRow['name']); ?>" disabled required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Email Address</label>
                                            <input type="email" name="email" class="form-control" value="<?php echo h($_SESSION['form_data']['email'] ?? $userRow['email']); ?>" placeholder="Enter email" disabled>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Phone Number</label>
                                            <input type="tel" name="phone" class="form-control" value="<?php echo h($_SESSION['form_data']['phone'] ?? $userRow['phone']); ?>" disabled required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Role</label>
                                            <input type="text" class="form-control" value="<?php echo ucfirst($userRow['role'] ?? $sessionUser['role']); ?>" readonly>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Security Settings -->
                            <div class="settings-section mb-4">
                                <div class="section-header">
                                    <h4><i class="fas fa-shield-alt me-2"></i>Security & Activity</h4>
                                </div>
                                <div class="security-options">
                                    <div class="security-item">
                                        <div class="security-info">
                                            <h5>Password Security</h5>
                                            <p class="text-muted mb-0">Keep your account secure with a strong password</p>
                                        </div>
                                        <a class="btn btn-outline-warning" href="?modal=password">
                                            <i class="fas fa-key me-2"></i>Change Password
                                        </a>
                                    </div>
                                    
                                    <div class="security-item">
                                        <div class="security-info">
                                            <h5>Account Activity</h5>
                                            <p class="text-muted mb-0">Monitor your recent actions and login history</p>
                                        </div>
                                        <button class="btn btn-outline-info" onclick="toggleActivity()">
                                            <i class="fas fa-history me-2"></i>View Activity
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Recent Activity (Hidden by default) -->
                                <div id="activitySection" class="activity-timeline mt-4" style="display: none;">
                                    <div class="activity-header">
                                        <div class="activity-title">
                                            <i class="fas fa-history me-2"></i>Recent Activity
                                        </div>
                                        <div class="activity-stats">
                                            <span class="activity-count"><?php echo count($loginHistory); ?></span>
                                            <span class="activity-label">actions</span>
                                        </div>
                                    </div>
                                    
                                    <?php if (empty($loginHistory)): ?>
                                        <div class="activity-empty">
                                            <div class="empty-icon">
                                                <i class="fas fa-clock"></i>
                                            </div>
                                            <h6>No Activity Yet</h6>
                                            <p>Your recent actions will appear here</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="activity-timeline-container">
                                            <?php 
                                            $activityIcons = [
                                                'approve' => ['icon' => 'check', 'color' => 'success', 'bg' => 'rgba(40, 167, 69, 0.1)'],
                                                'create' => ['icon' => 'plus', 'color' => 'primary', 'bg' => 'rgba(0, 123, 255, 0.1)'],
                                                'update' => ['icon' => 'edit', 'color' => 'warning', 'bg' => 'rgba(255, 193, 7, 0.1)'],
                                                'delete' => ['icon' => 'trash', 'color' => 'danger', 'bg' => 'rgba(220, 53, 69, 0.1)'],
                                                'login' => ['icon' => 'sign-in-alt', 'color' => 'info', 'bg' => 'rgba(23, 162, 184, 0.1)']
                                            ];
                                            
                                            foreach (array_slice($loginHistory, 0, 8) as $index => $row): 
                                                $actionType = strtolower($row['action']);
                                                $iconData = $actionIcons[$actionType] ?? ['icon' => 'cog', 'color' => 'secondary', 'bg' => 'rgba(108, 117, 125, 0.1)'];
                                                $timeAgo = time() - strtotime($row['created_at']);
                                                
                                                if ($timeAgo < 60) $timeText = 'Just now';
                                                elseif ($timeAgo < 3600) $timeText = floor($timeAgo / 60) . 'm ago';
                                                elseif ($timeAgo < 86400) $timeText = floor($timeAgo / 3600) . 'h ago';
                                                else $timeText = floor($timeAgo / 86400) . 'd ago';
                                            ?>
                                                <div class="timeline-item <?php echo $index === 0 ? 'latest' : ''; ?>">
                                                    <div class="timeline-marker" style="background: <?php echo $iconData['bg']; ?>">
                                                        <i class="fas fa-<?php echo $iconData['icon']; ?> text-<?php echo $iconData['color']; ?>"></i>
                                                    </div>
                                                    <div class="timeline-content">
                                                        <div class="timeline-header">
                                                            <h6 class="timeline-action">
                                                                <?php echo h(ucfirst($row['action'])); ?> 
                                                                <span class="timeline-entity"><?php echo h($row['entity_type']); ?></span>
                                                            </h6>
                                                            <span class="timeline-time"><?php echo $timeText; ?></span>
                                                        </div>
                                                        <div class="timeline-details">
                                                            <span class="timeline-id">#<?php echo h($row['entity_id']); ?></span>
                                                            <span class="timeline-date"><?php echo date('M j, g:i A', strtotime($row['created_at'])); ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            
                                            <?php if (count($loginHistory) > 8): ?>
                                                <div class="timeline-more">
                                                    <button class="btn btn-link btn-sm" onclick="loadMoreActivity()">
                                                        <i class="fas fa-chevron-down me-1"></i>
                                                        View <?php echo count($loginHistory) - 8; ?> more activities
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            
                            
                            <!-- Session Information -->
                            <div class="settings-section">
                                <div class="section-header">
                                    <h4><i class="fas fa-desktop me-2"></i>Session Information</h4>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="session-info-card">
                                            <div class="session-info-icon">
                                                <i class="fas fa-globe text-primary"></i>
                                            </div>
                                            <div class="session-info-content">
                                                <h6>IP Address</h6>
                                                <p class="mb-0"><?php echo h($_SERVER['REMOTE_ADDR'] ?? 'Unknown'); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="session-info-card">
                                            <div class="session-info-icon">
                                                <i class="fas fa-browser text-info"></i>
                                            </div>
                                            <div class="session-info-content">
                                                <h6>Browser</h6>
                                                <p class="mb-0"><?php echo h(substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 30) . '...'); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="session-info-card">
                                            <div class="session-info-icon">
                                                <i class="fas fa-clock text-warning"></i>
                                            </div>
                                            <div class="session-info-content">
                                                <h6>Session Started</h6>
                                                <p class="mb-0"><?php echo date('M j, Y g:i A'); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="session-info-card">
                                            <div class="session-info-icon">
                                                <i class="fas fa-shield-alt text-success"></i>
                                            </div>
                                            <div class="session-info-content">
                                                <h6>Security Status</h6>
                                                <p class="mb-0 text-success">Secure Connection</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Logout Options -->
                                <div class="mt-4 pt-3 border-top">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1">Session Management</h6>
                                            <small class="text-muted">Manage your active sessions and logout options</small>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-outline-secondary btn-sm" onclick="refreshSession()">
                                                <i class="fas fa-sync-alt me-1"></i>Refresh
                                            </button>
                                            <a href="../auth/logout.php" class="btn btn-outline-danger btn-sm" onclick="return confirm('Are you sure you want to logout?')">
                                                <i class="fas fa-sign-out-alt me-1"></i>Logout
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Profile Edit Modal (Server-controlled) -->
    <?php if ($showProfileModal): ?>
    <div class="modal fade show" id="profileModal" tabindex="-1" style="display:block; background: rgba(0,0,0,0.35)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Personal Information</h5>
                    <a href="." class="btn-close"></a>
                </div>
                <form method="POST">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" class="form-control" value="<?php echo h($_SESSION['form_data']['name'] ?? $userRow['name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" value="<?php echo h($_SESSION['form_data']['email'] ?? $userRow['email']); ?>" placeholder="Enter email">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-control" value="<?php echo h($_SESSION['form_data']['phone'] ?? $userRow['phone']); ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="." class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Change Password Modal (Server-controlled) -->
    <?php if ($showPasswordModal): ?>
    <div class="modal fade show" id="passwordModal" tabindex="-1" style="display:block; background: rgba(0,0,0,0.35)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Change Password</h5>
                    <a href="." class="btn-close"></a>
                </div>
                <form method="POST">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" minlength="6" required>
                            <div class="mt-2">
                                <small class="text-muted">Password strength: </small>
                                <span id="passwordStrength" class="badge bg-danger">Very Weak</span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="6" required>
                            <small class="text-muted">Must be at least 6 characters long</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="." class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/admin.js"></script>
    <script src="assets/profile.js"></script>
    <script>
        // Profile page specific functionality
        function toggleActivity() {
            const section = document.getElementById('activitySection');
            const button = event.target.closest('button');
            
            if (section.style.display === 'none') {
                section.style.display = 'block';
                button.innerHTML = '<i class="fas fa-eye-slash me-2"></i>Hide Activity';
                section.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                section.style.display = 'none';
                button.innerHTML = '<i class="fas fa-history me-2"></i>View Activity';
            }
        }
        
        function refreshSession() {
            const button = event.target.closest('button');
            const originalText = button.innerHTML;
            
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Refreshing...';
            
            // Simulate refresh delay
            setTimeout(() => {
                button.disabled = false;
                button.innerHTML = originalText;
                
                // Show success message
                const alert = document.createElement('div');
                alert.className = 'alert alert-success alert-dismissible fade show mt-3';
                alert.innerHTML = `
                    <i class="fas fa-check-circle me-2"></i>Session refreshed successfully!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                
                button.closest('.settings-section').appendChild(alert);
                
                // Auto-remove after 3 seconds
                setTimeout(() => {
                    if (alert.parentElement) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 3000);
            }, 1000);
        }
        
        // Form validation enhancement
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-focus first input in modals
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('shown.bs.modal', function() {
                    const firstInput = modal.querySelector('input:not([type="hidden"])');
                    if (firstInput) firstInput.focus();
                });
            });
            
            // Password strength indicator
            const newPasswordInput = document.querySelector('input[name="new_password"]');
            if (newPasswordInput) {
                newPasswordInput.addEventListener('input', function() {
                    const strength = checkPasswordStrength(this.value);
                    updatePasswordStrengthIndicator(strength);
                });
            }
        });
        
        function checkPasswordStrength(password) {
            let score = 0;
            if (password.length >= 8) score++;
            if (password.match(/[a-z]/)) score++;
            if (password.match(/[A-Z]/)) score++;
            if (password.match(/[0-9]/)) score++;
            if (password.match(/[^a-zA-Z0-9]/)) score++;
            
            return score;
        }
        
        function updatePasswordStrengthIndicator(score) {
            const indicator = document.getElementById('passwordStrength');
            if (!indicator) return;
            
            const levels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];
            const colors = ['danger', 'warning', 'warning', 'info', 'success'];
            
            indicator.className = `badge bg-${colors[score - 1] || 'danger'}`;
            indicator.textContent = levels[score - 1] || 'Very Weak';
        }
        
        function loadMoreActivity() {
            const button = event.target.closest('button');
            const originalText = button.innerHTML;
            
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Loading...';
            
            // Simulate loading more activities
            setTimeout(() => {
                button.style.display = 'none';
                
                // Show success message
                const message = document.createElement('div');
                message.className = 'text-center text-muted py-2';
                message.innerHTML = '<small><i class="fas fa-check me-1"></i>All activities loaded</small>';
                button.parentElement.appendChild(message);
            }, 800);
        }
    </script>
</body>
</html>
