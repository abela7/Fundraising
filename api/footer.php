<?php
declare(strict_types=1);
header('Content-Type: application/json');

// Suppress PHP warnings/notices that could break JSON output
error_reporting(0);
ini_set('display_errors', '0');

try {
    require_once __DIR__ . '/../config/db.php';
    
    $db = db();
    
    // Get footer message and visibility
    $result = $db->query("SELECT message, is_visible FROM projector_footer WHERE id = 1 LIMIT 1");
    
    if ($result && $row = $result->fetch_assoc()) {
        echo json_encode([
            'success' => true,
            'message' => $row['message'],
            'is_visible' => (bool)$row['is_visible']
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Every contribution makes a difference. Thank you for your generosity!',
            'is_visible' => true
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Every contribution makes a difference. Thank you for your generosity!',
        'is_visible' => true,
        'error' => 'Database error'
    ]);
}
?>
