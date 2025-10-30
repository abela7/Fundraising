<?php
/**
 * Donor Portal Logout
 */

session_start();

// Clear donor session
unset($_SESSION['donor']);

// Destroy session if no other sessions exist
if (empty($_SESSION)) {
    session_destroy();
}

// Redirect to login
header('Location: login.php?logout=success');
exit;

