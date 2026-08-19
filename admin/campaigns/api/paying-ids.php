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
    $group = CampaignGroupSettings::sanitizeGroup(
        (string) ($_GET['group'] ?? CampaignGroupSettings::GROUP_PAYING)
    );
    $donors = CampaignFirstMessageSend::listPayingMeta(db(), $group);
    echo json_encode([
        'success' => true,
        'group' => $group,
        'donors' => $donors,
        'total' => count($donors),
    ]);
} catch (Throwable $e) {
    error_log('Campaign paying ids failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load campaign donors.']);
}
