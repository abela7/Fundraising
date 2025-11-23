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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/registrar.js"></script>
</body>
</html>
