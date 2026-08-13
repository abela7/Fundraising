<?php
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../shared/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}

function normalize_uk_mobile(string $raw): string
{
    $digits = preg_replace('/[^0-9+]/', '', $raw) ?? '';
    if (strpos($digits, '+44') === 0) {
        $digits = '0' . substr($digits, 3);
    }
    return $digits;
}

try {
    $postData = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($postData)) {
        $postData = $_POST;
    }
    $phone = normalize_uk_mobile(trim((string) ($postData['phone'] ?? '')));

    $result = [
        'success' => true,
        'valid_uk' => (bool) preg_match('/^07\d{9}$/', $phone),
        'exists' => false,
    ];

    if ($phone === '') {
        echo json_encode($result);
        exit;
    }

    $db = db();
    $stmt = $db->prepare("
        SELECT
            (SELECT COUNT(*) FROM pledges WHERE donor_phone = ?) +
            (SELECT COUNT(*) FROM payments WHERE donor_phone = ?) AS cnt
    ");
    $stmt->bind_param('ss', $phone, $phone);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $result['exists'] = ((int) ($row['cnt'] ?? 0)) > 0;

    echo json_encode($result);
} catch (Throwable $e) {
    error_log('check_donor.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false]);
}
