<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../config/db.php';

$current_user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Session Check - Registrar Portal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Session Diagnostic</h5>
                    </div>
                    <div class="card-body">
                        <h6>Session Status:</h6>
                        <pre class="bg-light p-3 rounded"><?php 
                            echo "Session ID: " . session_id() . "\n";
                            echo "Session Status: " . (session_status() === PHP_SESSION_ACTIVE ? "ACTIVE" : "INACTIVE") . "\n";
                            echo "Session Data:\n";
                            print_r($_SESSION);
                        ?></pre>
                        
                        <h6 class="mt-4">Current User:</h6>
                        <pre class="bg-light p-3 rounded"><?php 
                            if ($current_user) {
                                print_r($current_user);
                            } else {
                                echo "NOT LOGGED IN - current_user() returned null\n";
                            }
                        ?></pre>
                        
                        <h6 class="mt-4">is_logged_in() Result:</h6>
                        <p class="alert <?php echo is_logged_in() ? 'alert-success' : 'alert-danger'; ?>">
                            <?php echo is_logged_in() ? 'TRUE - User is logged in' : 'FALSE - User is NOT logged in'; ?>
                        </p>
                        
                        <?php if ($current_user): ?>
                        <h6 class="mt-4">User Role Check:</h6>
                        <p class="alert <?php echo in_array($current_user['role'] ?? '', ['registrar', 'admin'], true) ? 'alert-success' : 'alert-warning'; ?>">
                            Role: <?php echo htmlspecialchars($current_user['role'] ?? 'none'); ?><br>
                            <?php echo in_array($current_user['role'] ?? '', ['registrar', 'admin'], true) ? '✓ Has access to registrar pages' : '✗ Does NOT have access to registrar pages'; ?>
                        </p>
                        
                        <h6 class="mt-4">Database Verification:</h6>
                        <?php
                        try {
                            $db = db();
                            $user_id = (int)$current_user['id'];
                            $stmt = $db->prepare('SELECT id, name, phone, email, role, active FROM users WHERE id = ? LIMIT 1');
                            $stmt->bind_param('i', $user_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $db_user = $result->fetch_assoc();
                            $stmt->close();
                            
                            if ($db_user) {
                                echo '<pre class="bg-light p-3 rounded">';
                                print_r($db_user);
                                echo '</pre>';
                                
                                if ((int)$db_user['active'] !== 1) {
                                    echo '<div class="alert alert-warning">⚠️ User account is INACTIVE in database</div>';
                                }
                            } else {
                                echo '<div class="alert alert-danger">✗ User not found in database!</div>';
                            }
                        } catch (Exception $e) {
                            echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
                        }
                        ?>
                        <?php endif; ?>
                        
                        <div class="mt-4">
                            <a href="login.php" class="btn btn-primary">Go to Login</a>
                            <a href="edit-profile.php" class="btn btn-success">Try Edit Profile</a>
                            <a href="index.php" class="btn btn-secondary">Go to Dashboard</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

