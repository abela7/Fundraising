<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1'); // Enable for debugging
ini_set('max_execution_time', '30');

echo "<!-- TEST PAGE STARTED -->\n";

try {
    echo "<!-- Loading auth.php -->\n";
    require_once __DIR__ . '/../shared/auth.php';
    echo "<!-- auth.php loaded -->\n";
    
    echo "<!-- Calling require_login() -->\n";
    require_login();
    echo "<!-- require_login() passed -->\n";
    
    echo "<!-- Getting current_user() -->\n";
    $current_user = current_user();
    echo "<!-- current_user() obtained -->\n";
    
    if (!$current_user) {
        die('NOT LOGGED IN');
    }
    
    echo "<!-- User ID: " . ($current_user['id'] ?? 'none') . " -->\n";
    echo "<!-- User Role: " . ($current_user['role'] ?? 'none') . " -->\n";
    
    if (!in_array($current_user['role'] ?? '', ['registrar', 'admin'], true)) {
        die('NOT REGISTRAR OR ADMIN');
    }
    
    echo "<!-- Loading db.php -->\n";
    require_once __DIR__ . '/../config/db.php';
    echo "<!-- db.php loaded -->\n";
    
    echo "<!-- Getting database connection -->\n";
    $db = db();
    echo "<!-- Database connection obtained -->\n";
    
    if (!$db) {
        die('DATABASE CONNECTION FAILED');
    }
    
    $user_id = (int)$current_user['id'];
    
    echo "<!-- Preparing SELECT query -->\n";
    $stmt = $db->prepare('SELECT id, name, phone, email, role FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        die('PREPARE FAILED: ' . $db->error);
    }
    
    echo "<!-- Binding parameters -->\n";
    $stmt->bind_param('i', $user_id);
    
    echo "<!-- Executing query -->\n";
    if (!$stmt->execute()) {
        die('EXECUTE FAILED: ' . $stmt->error);
    }
    
    echo "<!-- Getting result -->\n";
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        die('USER NOT FOUND');
    }
    
    echo "<!-- USER DATA LOADED SUCCESSFULLY -->\n";
    
} catch (Throwable $e) {
    die('ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test Edit Profile</title>
</head>
<body>
    <h1>Test Page - Edit Profile</h1>
    <p>If you see this, the page is working!</p>
    <p>User: <?php echo htmlspecialchars($user['name'] ?? 'Unknown'); ?></p>
    <p>Phone: <?php echo htmlspecialchars($user['phone'] ?? 'Unknown'); ?></p>
    <p>Email: <?php echo htmlspecialchars($user['email'] ?? 'Not set'); ?></p>
    <hr>
    <p><a href="edit-profile.php">Go to actual edit page</a></p>
</body>
</html>

