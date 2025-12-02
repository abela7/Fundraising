<?php
require_once __DIR__ . '/../shared/auth.php';

logout();

// Redirect to login with success message
header('Location: login.php?logout=success');
exit;
