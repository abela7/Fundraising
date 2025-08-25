<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

require_login();
require_admin();

try {
    $db = db();
    
    // Simple test - just return success
    echo json_encode([
        'success' => true,
        'message' => 'AJAX endpoint is working correctly',
        'timestamp' => date('Y-m-d H:i:s'),
        'user' => current_user()['name'] ?? 'Unknown'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
