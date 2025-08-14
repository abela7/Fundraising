<?php
require_once __DIR__ . '/../shared/auth.php';

// Log the logout action if user was logged in
if (current_user()) {
    $db = db();
    $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, entity_type) VALUES (?, 'Logged out', 'auth')");
    $uid = (int)current_user()['id'];
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $stmt->close();
}

logout();

// Redirect to login with success message
header('Location: login.php?logout=success');
exit;
