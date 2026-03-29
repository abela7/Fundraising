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
    CREATE TABLE IF NOT EXISTS community_members (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) DEFAULT NULL,
        phone VARCHAR(50) DEFAULT NULL,
        preference ENUM('phone','email','both') NOT NULL DEFAULT 'phone',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_phone (phone),
        INDEX idx_email (email),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Parse input
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) {
    $data = $_POST;
}

$fullName   = trim($data['full_name'] ?? '');
$phone      = trim($data['phone'] ?? '');
$email      = trim($data['email'] ?? '');
$preference = $data['preference'] ?? 'phone';

// Validate
if ($fullName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Full name is required']);
    exit;
}

if (!in_array($preference, ['phone', 'email', 'both'])) {
    $preference = 'phone';
}

if (($preference === 'phone' || $preference === 'both') && $phone === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Phone number is required']);
    exit;
}

if (($preference === 'email' || $preference === 'both') && $email === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email address is required']);
    exit;
}

$phoneVal = $phone !== '' ? $phone : null;
$emailVal = $email !== '' ? $email : null;

// Insert
$stmt = $db->prepare("
    INSERT INTO community_members (full_name, email, phone, preference, created_at)
    VALUES (?, ?, ?, ?, NOW())
");
$stmt->bind_param('ssss', $fullName, $emailVal, $phoneVal, $preference);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Registration successful',
        'member_id' => $stmt->insert_id
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save. Please try again.']);
}
