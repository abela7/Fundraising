<?php
/**
 * Donor Portal Logout
 */

session_start();

// Audit log donor logout
if (isset($_SESSION['donor']['id'])) {
    try {
        require_once __DIR__ . '/../shared/audit_helper.php';
        require_once __DIR__ . '/../config/db.php';
        $db = db();
        log_audit(
            $db,
            'logout',
            'donor',
            (int)$_SESSION['donor']['id'],
            ['name' => $_SESSION['donor']['name'] ?? '', 'phone' => $_SESSION['donor']['phone'] ?? ''],
            null,
            'donor_portal',
            0
        );
    } catch (Exception $e) {
        error_log("Audit logging failed on donor logout: " . $e->getMessage());
    }
}

// Clear donor session
unset($_SESSION['donor']);

// Destroy session if no other sessions exist
if (empty($_SESSION)) {
    session_destroy();
}

// Redirect to login
header('Location: login.php?logout=success');
exit;

