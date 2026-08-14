<?php

declare(strict_types=1);

require_once '../../../shared/auth.php';
require_once '../../../config/db.php';
require_once '../../../shared/CampaignFirstMessageSend.php';

header('Content-Type: application/json');

require_login();
require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $donors = CampaignFirstMessageSend::listPayingMeta(db());
    echo json_encode([
        'success' => true,
        'donors' => $donors,
        'total' => count($donors),
    ]);
} catch (Throwable $e) {
    error_log('Campaign paying ids failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load still-paying donors.']);
}
