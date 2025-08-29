<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/url.php';
logout();
header('Location: ' . url_for('admin/login.php'));
exit;
