<?php
declare(strict_types=1);
header('Content-Type: application/json');

// Suppress PHP warnings/notices that could break JSON output
error_reporting(0);
ini_set('display_errors', '0');

// Simple rate limiting: max 30 requests per IP per minute (footer refreshes less often)
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$cacheDir = sys_get_temp_dir() . '/projector_rl';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
$cacheFile = $cacheDir . '/' . md5($ip) . '_footer.json';
$now = time();
$window = 60;
$maxRequests = 30;
$data = file_exists($cacheFile) ? (json_decode(file_get_contents($cacheFile), true) ?? []) : [];
$data = array_filter($data, fn($t) => $t > $now - $window);
if (count($data) >= $maxRequests) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests', 'is_visible' => false]);
    exit();
}
$data[] = $now;
file_put_contents($cacheFile, json_encode(array_values($data)));

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
