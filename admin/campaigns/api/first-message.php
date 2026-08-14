<?php

declare(strict_types=1);

require_once '../../../shared/auth.php';
require_once '../../../shared/csrf.php';
require_once '../../../config/db.php';
require_once '../../../shared/CampaignGroupSettings.php';

header('Content-Type: application/json');

require_login();
require_admin();

$group = CampaignGroupSettings::GROUP_PAYING;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    try {
        $settings = CampaignGroupSettings::get(db(), $group);
        echo json_encode([
            'success' => true,
            'group' => $group,
            'first_message' => $settings['first_message'],
            'default_message' => $settings['default_message'],
            'welcome_message' => $settings['welcome_message'],
            'default_welcome' => $settings['default_welcome'],
            'recipient_mode' => $settings['recipient_mode'],
            'donor_ids' => $settings['donor_ids'],
            'variables' => CampaignGroupSettings::variables(),
            'welcome_variables' => CampaignGroupSettings::welcomeVariables(),
        ]);
    } catch (Throwable $e) {
        error_log('Campaign first message load failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not load campaign settings.']);
    }
    exit;
}

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

$action = trim((string) ($_POST['action'] ?? ''));
$userId = (int) ($_SESSION['user']['id'] ?? 0);

try {
    $db = db();
    if ($action === 'save_message') {
        $message = (string) ($_POST['first_message'] ?? '');
        if (trim($message) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Write a first message before saving.']);
            exit;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        if ($length > CampaignGroupSettings::MAX_MESSAGE_LENGTH) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Message is too long.']);
            exit;
        }
        $ok = CampaignGroupSettings::saveMessage($db, $group, $message, $userId);
        if (!$ok) {
            throw new RuntimeException('Could not save first message.');
        }
        echo json_encode([
            'success' => true,
            'first_message' => trim($message),
            'preview' => CampaignGroupSettings::preview(trim($message), []),
        ]);
        exit;
    }

    if ($action === 'save_welcome') {
        $message = (string) ($_POST['welcome_message'] ?? '');
        if (trim($message) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Write a welcome message before saving.']);
            exit;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        if ($length > CampaignGroupSettings::MAX_MESSAGE_LENGTH) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Message is too long.']);
            exit;
        }
        $ok = CampaignGroupSettings::saveWelcomeMessage($db, $group, $message, $userId);
        if (!$ok) {
            throw new RuntimeException('Could not save welcome message.');
        }
        echo json_encode([
            'success' => true,
            'welcome_message' => trim($message),
            'preview' => CampaignGroupSettings::preview(trim($message), []),
        ]);
        exit;
    }

    if ($action === 'save_recipients') {
        $mode = trim((string) ($_POST['recipient_mode'] ?? CampaignGroupSettings::MODE_ALL));
        $idsRaw = (string) ($_POST['donor_ids'] ?? '[]');
        $decoded = json_decode($idsRaw, true);
        $ids = [];
        if (is_array($decoded)) {
            foreach ($decoded as $id) {
                $ids[] = (int) $id;
            }
        }
        $ok = CampaignGroupSettings::saveRecipients($db, $group, $mode, $ids, $userId);
        if (!$ok) {
            throw new RuntimeException('Could not save recipients.');
        }
        $saved = CampaignGroupSettings::get($db, $group);
        echo json_encode([
            'success' => true,
            'recipient_mode' => $saved['recipient_mode'],
            'donor_ids' => $saved['donor_ids'],
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('Campaign first message save failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save campaign settings.']);
}
