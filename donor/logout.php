<?php
/**
 * Donor Portal Logout
 * 
 * Modes:
 * - Normal logout: Clears session but keeps device trusted
 * - Full logout (?forget=1): Clears session AND removes trusted device
 */

session_start();

$forget_device = isset($_GET['forget']) && $_GET['forget'] === '1';

// Audit log and handle trusted device
if (isset($_SESSION['donor']['id'])) {
    try {
        require_once __DIR__ . '/../shared/audit_helper.php';
        require_once __DIR__ . '/../config/db.php';
        $db = db();
        
        // If forget device requested, remove the trusted device token
        if ($forget_device && isset($_COOKIE['donor_device_token'])) {
            $token = $_COOKIE['donor_device_token'];
            
            // Deactivate in database
            $stmt = $db->prepare("UPDATE donor_trusted_devices SET is_active = 0 WHERE device_token = ?");
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $stmt->close();
            
            // Clear cookie
            $cookie_options = [
                'expires' => time() - 3600,
                'path' => '/donor/',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'httponly' => true,
                'samesite' => 'Lax'
            ];
            setcookie('donor_device_token', '', $cookie_options);
        }
        
        // Audit log
        log_audit(
            $db,
            'logout',
            'donor',
            (int)$_SESSION['donor']['id'],
            ['name' => $_SESSION['donor']['name'] ?? '', 'phone' => $_SESSION['donor']['phone'] ?? ''],
            ['forget_device' => $forget_device],
            'donor_portal',
            0
        );
    } catch (Exception $e) {
        error_log("Audit logging failed on donor logout: " . $e->getMessage());
    }
}

// Clear donor session
unset($_SESSION['donor']);
unset($_SESSION['otp_phone']);

// Destroy session if no other sessions exist
if (empty($_SESSION)) {
    session_destroy();
}

// Redirect to login
header('Location: login.php?logout=success');
exit;

