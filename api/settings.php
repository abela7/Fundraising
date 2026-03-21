<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/db.php';

try {
    $db = db();
    $key = trim($_GET['key'] ?? '');

    if ($key === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing key']);
        exit;
    }

    $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        echo json_encode(['value' => $row['setting_value']]);
    } else {
        echo json_encode(['value' => null]);
    }
    $stmt->close();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
