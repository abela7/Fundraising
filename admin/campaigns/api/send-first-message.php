<?php

declare(strict_types=1);

require_once '../../../shared/auth.php';
require_once '../../../shared/csrf.php';
require_once '../../../config/db.php';
require_once '../../../shared/CampaignFirstMessageSend.php';

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

$decoded = json_decode((string) ($_POST['donor_ids'] ?? '[]'), true);
$ids = [];
if (is_array($decoded)) {
    foreach ($decoded as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
}
$ids = array_values($ids);

$userId = (int) ($_SESSION['user']['id'] ?? 0);

try {
    @set_time_limit(60);
    $sent = CampaignFirstMessageSend::sendBatch(db(), $ids, $userId);
    if (!$sent['ok']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $sent['error'] ?? 'Could not send messages.',
            'results' => [],
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'results' => $sent['results'],
    ]);
} catch (Throwable $e) {
    error_log('Campaign first message send failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not send WhatsApp messages.']);
}
