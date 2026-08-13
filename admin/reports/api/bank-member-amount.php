<?php

declare(strict_types=1);

require_once '../../../shared/auth.php';
require_once '../../../shared/csrf.php';
require_once '../../../config/db.php';
require_once '../../../shared/BankStatementAmounts.php';

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
$excelRow = (int) ($_POST['excel_row'] ?? 0);
$name = trim((string) ($_POST['excel_name'] ?? ''));
$ref = trim((string) ($_POST['excel_ref'] ?? ''));
$bankPaid = BankStatementAmounts::parsePaid((string) ($_POST['bank_paid'] ?? ''));
$originalPaid = BankStatementAmounts::parsePaid((string) ($_POST['original_paid'] ?? '0'));
$userId = (int) ($_SESSION['user']['id'] ?? 0);

if ($excelRow < 2 || ($name === '' && $ref === '') || $bankPaid === null || $originalPaid === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Enter a valid Bank paid amount.']);
    exit;
}

if (!preg_match('/^[a-f0-9]{64}$/', $rowKey) || !hash_equals(BankStatementAmounts::rowKey($excelRow, $name, $ref), $rowKey)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Could not match that bank row.']);
    exit;
}

try {
    $ok = BankStatementAmounts::set(
        db(),
        $rowKey,
        $excelRow,
        $name,
        $ref,
        $originalPaid,
        $bankPaid,
        $userId
    );
    if (!$ok) {
        throw new RuntimeException('Could not save Bank paid amount.');
    }
    echo json_encode([
        'success' => true,
        'bank_paid' => $bankPaid,
    ]);
} catch (Throwable $e) {
    error_log('Bank member amount save failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save Bank paid amount.']);
}
