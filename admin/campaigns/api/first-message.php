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
            'status_message' => $settings['status_message'],
            'default_status' => $settings['default_status'],
            'status_title' => $settings['status_title'],
            'default_status_title' => $settings['default_status_title'],
            'status_labels' => $settings['status_labels'],
            'default_status_labels' => $settings['default_status_labels'],
            'status_variables' => CampaignGroupSettings::statusVariables(),
            'contact_message' => $settings['contact_message'],
            'default_contact_message' => $settings['default_contact_message'],
            'contact_ask' => $settings['contact_ask'],
            'default_contact_ask' => $settings['default_contact_ask'],
            'contact_labels' => $settings['contact_labels'],
            'default_contact_labels' => $settings['default_contact_labels'],
            'contact_variables' => CampaignGroupSettings::contactVariables(),
            'correction_message' => $settings['correction_message'],
            'default_correction_message' => $settings['default_correction_message'],
            'correction_ask' => $settings['correction_ask'],
            'default_correction_ask' => $settings['default_correction_ask'],
            'correction_amount_label' => $settings['correction_amount_label'],
            'default_correction_amount_label' => $settings['default_correction_amount_label'],
            'correction_variables' => CampaignGroupSettings::correctionVariables(),
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

    if ($action === 'save_status') {
        $message = (string) ($_POST['status_message'] ?? '');
        $title = (string) ($_POST['status_title'] ?? '');
        if (trim($message) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Write footer text before saving.']);
            exit;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        $titleLength = function_exists('mb_strlen') ? mb_strlen(trim($title)) : strlen(trim($title));
        if ($length > CampaignGroupSettings::MAX_MESSAGE_LENGTH) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Message is too long.']);
            exit;
        }
        if ($titleLength > CampaignGroupSettings::MAX_TITLE_LENGTH) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Title is too long.']);
            exit;
        }
        foreach (['status_pledge_label', 'status_paid_label', 'status_remain_label'] as $field) {
            $label = trim((string) ($_POST[$field] ?? ''));
            $labelLength = function_exists('mb_strlen') ? mb_strlen($label) : strlen($label);
            if ($labelLength > CampaignGroupSettings::MAX_TITLE_LENGTH) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'A label is too long.']);
                exit;
            }
        }
        $ok = CampaignGroupSettings::saveStatusMessage(
            $db,
            $group,
            $message,
            $userId,
            $title,
            [
                'pledge' => (string) ($_POST['status_pledge_label'] ?? ''),
                'paid' => (string) ($_POST['status_paid_label'] ?? ''),
                'remain' => (string) ($_POST['status_remain_label'] ?? ''),
            ]
        );
        if (!$ok) {
            throw new RuntimeException('Could not save status message.');
        }
        $card = CampaignGroupSettings::statusCardCopy($message, $title);
        $labels = CampaignGroupSettings::statusLabels(null, [
            'pledge' => (string) ($_POST['status_pledge_label'] ?? ''),
            'paid' => (string) ($_POST['status_paid_label'] ?? ''),
            'remain' => (string) ($_POST['status_remain_label'] ?? ''),
        ]);
        echo json_encode([
            'success' => true,
            'status_message' => $card['footer'],
            'status_title' => $card['title'],
            'status_labels' => $labels,
            'preview' => CampaignGroupSettings::preview($card['footer'], []),
        ]);
        exit;
    }

    if ($action === 'save_contact') {
        $message = (string) ($_POST['contact_message'] ?? '');
        $ask = (string) ($_POST['contact_ask'] ?? '');
        if (trim($message) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Write the after-yes message before saving.']);
            exit;
        }
        if (trim($ask) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Write the date and time prompt before saving.']);
            exit;
        }
        $messageLength = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        $askLength = function_exists('mb_strlen') ? mb_strlen($ask) : strlen($ask);
        if ($messageLength > CampaignGroupSettings::MAX_MESSAGE_LENGTH || $askLength > CampaignGroupSettings::MAX_MESSAGE_LENGTH) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Message is too long.']);
            exit;
        }
        foreach (['contact_date_label', 'contact_time_label', 'contact_method_label', 'contact_whatsapp_label', 'contact_phone_label'] as $field) {
            $label = trim((string) ($_POST[$field] ?? ''));
            $labelLength = function_exists('mb_strlen') ? mb_strlen($label) : strlen($label);
            if ($labelLength > CampaignGroupSettings::MAX_TITLE_LENGTH) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'A label is too long.']);
                exit;
            }
        }
        $labels = [
            'date' => (string) ($_POST['contact_date_label'] ?? ''),
            'time' => (string) ($_POST['contact_time_label'] ?? ''),
            'method' => (string) ($_POST['contact_method_label'] ?? ''),
            'whatsapp' => (string) ($_POST['contact_whatsapp_label'] ?? ''),
            'phone' => (string) ($_POST['contact_phone_label'] ?? ''),
        ];
        $ok = CampaignGroupSettings::saveContactCopy($db, $group, $message, $ask, $userId, $labels);
        if (!$ok) {
            throw new RuntimeException('Could not save contact page.');
        }
        echo json_encode([
            'success' => true,
            'contact_message' => CampaignGroupSettings::contactMessageText($message),
            'contact_ask' => CampaignGroupSettings::contactAskText($ask),
            'contact_labels' => CampaignGroupSettings::contactLabels(null, $labels),
        ]);
        exit;
    }

    if ($action === 'save_correction') {
        $message = (string) ($_POST['correction_message'] ?? '');
        $ask = (string) ($_POST['correction_ask'] ?? '');
        $amountLabel = (string) ($_POST['correction_amount_label'] ?? '');
        if (trim($message) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Write the after-no message before saving.']);
            exit;
        }
        if (trim($ask) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Write the paid-so-far prompt before saving.']);
            exit;
        }
        if (trim($amountLabel) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Write the amount field label before saving.']);
            exit;
        }
        $messageLength = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        $askLength = function_exists('mb_strlen') ? mb_strlen($ask) : strlen($ask);
        $labelLength = function_exists('mb_strlen') ? mb_strlen(trim($amountLabel)) : strlen(trim($amountLabel));
        if ($messageLength > CampaignGroupSettings::MAX_MESSAGE_LENGTH || $askLength > CampaignGroupSettings::MAX_MESSAGE_LENGTH) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Message is too long.']);
            exit;
        }
        if ($labelLength > CampaignGroupSettings::MAX_TITLE_LENGTH) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'A label is too long.']);
            exit;
        }
        $ok = CampaignGroupSettings::saveCorrectionCopy($db, $group, $message, $ask, $amountLabel, $userId);
        if (!$ok) {
            throw new RuntimeException('Could not save after-no page.');
        }
        echo json_encode([
            'success' => true,
            'correction_message' => CampaignGroupSettings::correctionMessageText($message),
            'correction_ask' => CampaignGroupSettings::correctionAskText($ask),
            'correction_amount_label' => CampaignGroupSettings::correctionAmountLabelText($amountLabel),
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
