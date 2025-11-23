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
$success_message = '';
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success_message = 'Profile updated successfully!';
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
                
                <div class="row justify-content-center">
                    <div class="col-12 col-md-8 col-lg-6">
                        <div class="card shadow-sm">
                            <div class="card-body p-4">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <h5 class="card-title mb-0">My Profile</h5>
                                    <a href="edit-profile.php" class="btn btn-primary btn-sm">
                                        <i class="fas fa-edit me-1"></i>Edit
                                    </a>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="text-muted small mb-1 d-block">Name</label>
                                    <p class="mb-0 fw-semibold"><?php echo h($user['name']); ?></p>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="text-muted small mb-1 d-block">Phone</label>
                                    <p class="mb-0"><?php echo h($user['phone'] ?? 'Not set'); ?></p>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="text-muted small mb-1 d-block">Email</label>
                                    <p class="mb-0"><?php echo h($user['email'] ?? 'Not set'); ?></p>
                                </div>
                                
                                <div class="mb-0">
                                    <label class="text-muted small mb-1 d-block">Role</label>
                                    <p class="mb-0">
                                        <span class="badge bg-primary"><?php echo ucfirst(h($user['role'])); ?></span>
                                    </p>
                                </div>
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
