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

$token = CampaignPayingProgress::normalizeToken((string) ($_POST['token'] ?? ''));
$sign = (string) ($_POST['sign'] ?? '');
if ($token === null || !CampaignPayingProgress::verifySign($token, $sign)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden.']);
    exit;
}

$file = $_FILES['file'] ?? null;
if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Choose a screenshot.']);
    exit;
}

$tmp = (string) ($file['tmp_name'] ?? '');
$size = (int) ($file['size'] ?? 0);
if ($tmp === '' || !is_uploaded_file($tmp)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid upload.']);
    exit;
}
if ($size <= 0 || $size > CampaignPayingProgress::MAX_PROOF_BYTES) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File too large (max 5MB).']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($tmp);
$ext = CampaignPayingProgress::proofExtensionForMime($mime);
if ($ext === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Send a photo (JPG, PNG, WEBP, or GIF).']);
    exit;
}

try {
    $db = db();
    if (!CampaignPayingProgress::tokenExists($db, $token)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Not found.']);
        exit;
    }
} catch (Throwable $e) {
    error_log('Paying proof token check failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save.']);
    exit;
}

$dir = dirname(__DIR__, 2) . '/uploads/paying_proofs';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save.']);
    exit;
}

try {
    $random = bin2hex(random_bytes(16));
} catch (Throwable $e) {
    $random = str_replace('.', '', uniqid('', true));
    $random = substr(hash('sha256', $random), 0, 32);
}
$filename = $token . '_' . $random . '.' . $ext;
$absolute = $dir . '/' . $filename;
$relative = 'uploads/paying_proofs/' . $filename;
if (CampaignPayingProgress::normalizeProofPath($relative) === null || !move_uploaded_file($tmp, $absolute)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save.']);
    exit;
}

try {
    $saved = CampaignPayingProgress::save($db, $token, CampaignPayingProgress::STEP_BANK_PROOF, [
        'send_proof' => 'yes',
        'proof_file' => $relative,
    ]);
    if ($saved === null) {
        throw new RuntimeException('Save returned empty.');
    }

    echo json_encode([
        'success' => true,
        'proof_file' => $relative,
        'step' => $saved['step'],
        'answers' => CampaignPayingProgress::answersForClient($saved['answers']),
        'revision' => $saved['revision'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Paying proof upload failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save.']);
}
