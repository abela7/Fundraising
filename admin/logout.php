<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/auth.php';
logout();
header('Location: /admin/login.php');
exit;
