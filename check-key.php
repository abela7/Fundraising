<?php
// Temporary script to check your cron key
// DELETE THIS FILE AFTER USE!

require_once __DIR__ . '/shared/auth.php';
require_login();

$current_user = current_user();
if ($current_user['role'] !== 'admin') {
    die('Access denied - Admin only');
}

echo "<h2>Your Cron Key:</h2>";
echo "<pre style='background: #f0f0f0; padding: 20px; font-size: 16px;'>";
echo getenv('FUNDRAISING_CRON_KEY') ?: 'NOT SET - Check .env file';
echo "</pre>";
echo "<p><strong>⚠️ DELETE THIS FILE AFTER VIEWING!</strong></p>";
?>
