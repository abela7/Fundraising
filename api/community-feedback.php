<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
$db = db();

// Create table if not exists
$db->query("
    CREATE TABLE IF NOT EXISTS community_feedback (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        contact VARCHAR(255) DEFAULT NULL,
        type ENUM('feedback','suggestion','complaint','praise') NOT NULL DEFAULT 'feedback',
        message TEXT NOT NULL,
        status ENUM('new','reviewed','resolved') NOT NULL DEFAULT 'new',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_type (type),
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Parse input
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) {
    $data = $_POST;
}

$name    = trim($data['name'] ?? '');
$contact = trim($data['contact'] ?? '');
$type    = $data['type'] ?? 'feedback';
$message = trim($data['message'] ?? '');

// Validate
if ($name === '') {
    $name = 'Anonymous';
}

if ($message === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Message is required']);
    exit;
}

if (!in_array($type, ['feedback', 'suggestion', 'complaint', 'praise'])) {
    $type = 'feedback';
}

$contactVal = $contact !== '' ? $contact : null;

$stmt = $db->prepare("
    INSERT INTO community_feedback (name, contact, type, message, created_at)
    VALUES (?, ?, ?, ?, NOW())
");
$stmt->bind_param('ssss', $name, $contactVal, $type, $message);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Feedback submitted successfully',
        'feedback_id' => $stmt->insert_id
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save. Please try again.']);
}
