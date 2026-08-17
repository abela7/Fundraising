<?php

declare(strict_types=1);

require_once '../../../shared/auth.php';
require_once '../../../shared/csrf.php';
require_once '../../../config/db.php';
require_once '../../../shared/CampaignPayingReport.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

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

$donorId = (int) ($_POST['donor_id'] ?? 0);
$status = (string) ($_POST['call_status'] ?? '');

try {
    $saved = CampaignPayingReport::setCallStatus(db(), $donorId, $status);
    if ($saved === null) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Set a call status only after the donor has booked a time.',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'donor_id' => $saved['donor_id'],
        'call_status' => $saved['call_status'],
        'call_status_label' => $saved['call_status_label'],
    ]);
} catch (Throwable $e) {
    error_log('Paying call status API failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save the call status.']);
}
