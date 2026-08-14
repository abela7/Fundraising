<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/CampaignPayingProgress.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$raw = (string) file_get_contents('php://input');
if (strlen($raw) > CampaignPayingProgress::MAX_JSON_BYTES) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'Payload too large.']);
    exit;
}
if ($raw === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON.']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON.']);
    exit;
}

$token = CampaignPayingProgress::normalizeToken((string) ($payload['token'] ?? ''));
$sign = (string) ($payload['sign'] ?? '');
if ($token === null || !CampaignPayingProgress::verifySign($token, $sign)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden.']);
    exit;
}

try {
    $db = db();
    if (!CampaignPayingProgress::tokenExists($db, $token)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Not found.']);
        exit;
    }

    $saved = CampaignPayingProgress::save(
        $db,
        $token,
        (string) ($payload['step'] ?? CampaignPayingProgress::STEP_WELCOME),
        is_array($payload['answers'] ?? null) ? $payload['answers'] : []
    );
    if ($saved === null) {
        throw new RuntimeException('Save returned empty.');
    }

    echo json_encode([
        'success' => true,
        'step' => $saved['step'],
        'answers' => $saved['answers'],
        'revision' => $saved['revision'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Paying progress API failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save.']);
}
