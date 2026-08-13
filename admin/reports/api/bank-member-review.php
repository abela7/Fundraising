<?php

declare(strict_types=1);

require_once '../../../shared/auth.php';
require_once '../../../shared/csrf.php';
require_once '../../../config/db.php';
require_once '../../../shared/BankStatementReviews.php';

header('Content-Type: application/json');

require_login();
require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$token = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Refresh and try again.']);
    exit;
}

$rowKey = strtolower(trim((string) ($_POST['row_key'] ?? '')));
$status = trim((string) ($_POST['review_status'] ?? ''));
$excelRow = (int) ($_POST['excel_row'] ?? 0);
$name = trim((string) ($_POST['excel_name'] ?? ''));
$ref = trim((string) ($_POST['excel_ref'] ?? ''));
$paid = (float) ($_POST['excel_paid'] ?? 0);
$userId = (int) ($_SESSION['user']['id'] ?? 0);

if (!BankStatementReviews::isValid($status) || !preg_match('/^[a-f0-9]{64}$/', $rowKey)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid review status.']);
    exit;
}

try {
    $ok = BankStatementReviews::set(db(), $rowKey, $status, $excelRow, $name, $ref, $paid, $userId);
    if (!$ok) {
        throw new RuntimeException('Could not save review status.');
    }
    echo json_encode(['success' => true, 'review_status' => $status]);
} catch (Throwable $e) {
    error_log('Bank member review save failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save review status.']);
}
