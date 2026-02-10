<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../services/MessagingHelper.php';
require_once __DIR__ . '/../../../services/UltraMsgService.php';
require_once __DIR__ . '/../../../services/VoodooSMSService.php';

header('Content-Type: application/json');

try {
    // Basic auth check
    if (!is_logged_in() || !is_admin()) {
        throw new Exception('Unauthorized');
    }

    $db = db();
    $action = $_REQUEST['action'] ?? '';

    /**
     * Ensure bulk history tables exist (idempotent).
     */
    function ensureBulkTables(mysqli $db): void
    {
        $db->query("
            CREATE TABLE IF NOT EXISTS bulk_message_runs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                run_id VARCHAR(64) NOT NULL,
                created_by_user_id INT NULL,
                created_by_name VARCHAR(255) NULL,
                template_id INT UNSIGNED NULL,
                channel_preference VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
                message_text TEXT NOT NULL,
                filters_json TEXT NULL,
                total_recipients INT NOT NULL DEFAULT 0,
                sent_count INT NOT NULL DEFAULT 0,
                failed_count INT NOT NULL DEFAULT 0,
                whatsapp_sent INT NOT NULL DEFAULT 0,
                sms_sent INT NOT NULL DEFAULT 0,
                sms_fallback_count INT NOT NULL DEFAULT 0,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_run_id (run_id),
                KEY idx_created_at (created_at),
                KEY idx_template_id (template_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS bulk_message_recipients (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                run_id VARCHAR(64) NOT NULL,
                donor_id INT NULL,
                phone VARCHAR(20) NOT NULL,
                channel_used VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL,
                is_fallback TINYINT(1) NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                provider_message_id VARCHAR(100) NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_run_donor (run_id, donor_id),
                KEY idx_run (run_id),
                KEY idx_status (status),
                KEY idx_channel (channel_used),
                KEY idx_sent_at (sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Recalculate aggregate stats for a run.
     */
    function recalcRunStats(mysqli $db, string $runId): void
    {
        $stmt = $db->prepare("
            UPDATE bulk_message_runs r
            JOIN (
                SELECT
                    run_id,
                    COUNT(*) AS total_recipients,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                    SUM(CASE WHEN channel_used = 'whatsapp' AND status = 'sent' THEN 1 ELSE 0 END) AS whatsapp_sent,
                    SUM(CASE WHEN channel_used = 'sms' AND status = 'sent' THEN 1 ELSE 0 END) AS sms_sent,
                    SUM(CASE WHEN is_fallback = 1 AND status = 'sent' THEN 1 ELSE 0 END) AS sms_fallback_count
                FROM bulk_message_recipients
                WHERE run_id = ?
                GROUP BY run_id
            ) agg ON agg.run_id = r.run_id
            SET
                r.total_recipients = agg.total_recipients,
                r.sent_count = agg.sent_count,
                r.failed_count = agg.failed_count,
                r.whatsapp_sent = agg.whatsapp_sent,
                r.sms_sent = agg.sms_sent,
                r.sms_fallback_count = agg.sms_fallback_count
            WHERE r.run_id = ?
        ");
        if ($stmt) {
            $stmt->bind_param('ss', $runId, $runId);
            $stmt->execute();
        }
    }

    // --- Helper: Build Query from Filters ---
    function buildDonorQuery($db) {
        $conditions = ["1=1"];
        $params = [];
        $types = "";

        // Filter: Agent
        if (!empty($_REQUEST['agent_id'])) {
            $conditions[] = "d.agent_id = ?";
            $params[] = $_REQUEST['agent_id'];
            $types .= "i";
        }

        // Filter: Registrar
        if (!empty($_REQUEST['registrar_id'])) {
            $conditions[] = "d.registered_by_user_id = ?";
            $params[] = $_REQUEST['registrar_id'];
            $types .= "i";
        }
        
        // Filter: Representative
        if (!empty($_REQUEST['representative_id'])) {
            $conditions[] = "d.representative_id = ?";
            $params[] = $_REQUEST['representative_id'];
            $types .= "i";
        }

        // Filter: City
        if (!empty($_REQUEST['city'])) {
            $conditions[] = "d.city = ?";
            $params[] = $_REQUEST['city'];
            $types .= "s";
        }

        // Filter: Payment Method (Preferred)
        if (!empty($_REQUEST['payment_method'])) {
            $conditions[] = "d.preferred_payment_method = ?";
            $params[] = $_REQUEST['payment_method'];
            $types .= "s";
        }

        // Filter: Language
        if (!empty($_REQUEST['language'])) {
            $conditions[] = "d.preferred_language = ?";
            $params[] = $_REQUEST['language'];
            $types .= "s";
        }

        // Filter: Payment Status
        if (!empty($_REQUEST['payment_status'])) {
            if ($_REQUEST['payment_status'] === 'active') {
                $conditions[] = "d.has_active_plan = 1";
            } else {
                $conditions[] = "d.payment_status = ?";
                $params[] = $_REQUEST['payment_status'];
                $types .= "s";
            }
        }

        // Filter: Amount (Min/Max) - Check against Active Plan Monthly Amount
        if (!empty($_REQUEST['min_amount']) || !empty($_REQUEST['max_amount'])) {
            $conditions[] = "d.has_active_plan = 1";
            
            if (!empty($_REQUEST['min_amount'])) {
                $conditions[] = "pp.monthly_amount >= ?";
                $params[] = $_REQUEST['min_amount'];
                $types .= "d";
            }
            if (!empty($_REQUEST['max_amount'])) {
                $conditions[] = "pp.monthly_amount <= ?";
                $params[] = $_REQUEST['max_amount'];
                $types .= "d";
            }
        }

        // Only donors with phones!
        $conditions[] = "d.phone IS NOT NULL AND d.phone != ''";

        $sql = "SELECT d.id, d.phone, d.name, d.preferred_language FROM donors d ";
        
        // Joins if needed
        if (!empty($_REQUEST['min_amount']) || !empty($_REQUEST['max_amount'])) {
            $sql .= "JOIN donor_payment_plans pp ON d.active_payment_plan_id = pp.id ";
        }

        $sql .= "WHERE " . implode(" AND ", $conditions);
        
        return [$sql, $params, $types];
    }

    if ($action === 'count') {
        list($sql, $params, $types) = buildDonorQuery($db);
        $sql = preg_replace('/SELECT .* FROM/', 'SELECT COUNT(*) as cnt FROM', $sql);
        
        $stmt = $db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        echo json_encode(['success' => true, 'count' => $result['cnt']]);

    } elseif ($action === 'get_ids') {
        list($sql, $params, $types) = buildDonorQuery($db);
        $sql = preg_replace('/SELECT .* FROM/', 'SELECT d.id FROM', $sql);
        
        $stmt = $db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = $row['id'];
        }
        
        echo json_encode(['success' => true, 'ids' => $ids]);

    } elseif ($action === 'create_run') {
        ensureBulkTables($db);

        $bulk_run_id = isset($_POST['bulk_run_id']) ? trim((string)$_POST['bulk_run_id']) : '';
        $bulk_run_id = preg_replace('/[^a-zA-Z0-9_\\-]/', '', $bulk_run_id);
        if ($bulk_run_id === '') {
            throw new Exception('Missing bulk_run_id');
        }

        $template_id = isset($_POST['template_id']) && $_POST['template_id'] !== '' ? (int)$_POST['template_id'] : null;
        $channel = isset($_POST['channel']) ? (string)$_POST['channel'] : 'whatsapp';
        $message_text = trim((string)($_POST['message'] ?? ''));
        $filters_json = isset($_POST['filters_json']) ? (string)$_POST['filters_json'] : null;

        $total_recipients = isset($_POST['total_recipients']) ? (int)$_POST['total_recipients'] : 0;
        if ($total_recipients < 0) $total_recipients = 0;

        $user = function_exists('current_user') ? current_user() : null;
        $userId = $user['id'] ?? null;
        $userName = $user['name'] ?? null;

        // Upsert run
        $stmt = $db->prepare("
            INSERT INTO bulk_message_runs
                (run_id, created_by_user_id, created_by_name, template_id, channel_preference, message_text, filters_json, total_recipients, started_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                template_id = VALUES(template_id),
                channel_preference = VALUES(channel_preference),
                message_text = VALUES(message_text),
                filters_json = VALUES(filters_json),
                total_recipients = GREATEST(total_recipients, VALUES(total_recipients)),
                updated_at = NOW()
        ");
        if (!$stmt) {
            throw new Exception('Failed to create run');
        }

        // Bind nullable ints carefully
        $tpl = $template_id;
        $uid = $userId;
        $stmt->bind_param(
            'sisssssi',
            $bulk_run_id,
            $uid,
            $userName,
            $tpl,
            $channel,
            $message_text,
            $filters_json,
            $total_recipients
        );
        $stmt->execute();

        echo json_encode(['success' => true]);

    } elseif ($action === 'send_batch') {
        $ids_json = $_POST['ids'] ?? '[]';
        $ids = json_decode($ids_json, true);
        $message_raw = $_POST['message'] ?? '';
        $channel = $_POST['channel'] ?? 'whatsapp';
        $template_id = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null;
        $bulk_run_id = isset($_POST['bulk_run_id']) ? trim((string)$_POST['bulk_run_id']) : '';
        $bulk_run_id = preg_replace('/[^a-zA-Z0-9_\\-]/', '', $bulk_run_id);

        if (empty($ids)) {
            echo json_encode(['success' => true, 'results' => []]);
            exit;
        }

        if ($bulk_run_id !== '') {
            ensureBulkTables($db);
        }

        $msgHelper = new MessagingHelper($db);
        
        // Fetch donor details for replacement
        $ids_str = implode(',', array_map('intval', $ids));
        
        // Get details needed for variables + language preference
        $query = "
            SELECT d.id, d.name, d.phone, d.preferred_language, d.active_payment_plan_id,
                   pp.monthly_amount, pp.start_date, pp.next_payment_due, pp.payment_method, pp.plan_frequency_unit, pp.plan_frequency_number
            FROM donors d
            LEFT JOIN donor_payment_plans pp ON d.active_payment_plan_id = pp.id
            WHERE d.id IN ($ids_str)
        ";
        
        $donors = $db->query($query)->fetch_all(MYSQLI_ASSOC);
        
        $results = [];
        
        foreach ($donors as $donor) {
            $resultEntry = [
                'donor_id' => $donor['id'],
                'name' => $donor['name'],
                'phone' => $donor['phone'],
                'status' => 'failed',
                'fallback' => false,
                'channel_used' => 'sms',
                'error' => ''
            ];

            try {
                // Prepare variables
                $frequency_sms = '';
                if (!empty($donor['plan_frequency_unit'])) {
                    $unit = $donor['plan_frequency_unit'];
                    $num = (int)($donor['plan_frequency_number'] ?? 1);
                    if ($unit === 'day') $frequency_sms = $num === 1 ? 'per day' : "every {$num} days";
                    elseif ($unit === 'week') $frequency_sms = $num === 1 ? 'per week' : "every {$num} weeks";
                    elseif ($unit === 'month') $frequency_sms = $num === 1 ? 'per month' : "every {$num} months";
                    elseif ($unit === 'year') $frequency_sms = $num === 1 ? 'per year' : "every {$num} years";
                }

                $variables = [
                    'name' => explode(' ', trim($donor['name']))[0],
                    'amount' => $donor['monthly_amount'] ? '£' . number_format((float)$donor['monthly_amount'], 2) : '',
                    'frequency' => $frequency_sms,
                    'start_date' => $donor['start_date'] ? date('M j, Y', strtotime($donor['start_date'])) : '',
                    'next_payment_due' => $donor['next_payment_due'] ? date('M j, Y', strtotime($donor['next_payment_due'])) : '',
                    'payment_method' => $donor['payment_method'] ? ucwords(str_replace('_', ' ', $donor['payment_method'])) : '',
                    'portal_link' => 'https://bit.ly/4p0J1gf'
                ];

                if ($template_id) {
                    // Template path (using MessagingHelper logic)
                    $tStmt = $db->prepare("SELECT template_key FROM sms_templates WHERE id = ?");
                    $tStmt->bind_param('i', $template_id);
                    $tStmt->execute();
                    $tRes = $tStmt->get_result()->fetch_assoc();
                    $templateKey = $tRes['template_key'] ?? null;

                    if ($templateKey) {
                        $requestedChannel = in_array($channel, ['auto', 'sms', 'whatsapp', 'both'], true)
                            ? $channel
                            : MessagingHelper::CHANNEL_AUTO;
                        $sourceType = $bulk_run_id ? ('bulk_manual:' . $bulk_run_id) : 'bulk_manual';
                        $sendRes = $msgHelper->sendFromTemplate(
                            $templateKey,
                            (int)$donor['id'],
                            $variables,
                            $requestedChannel,
                            $sourceType,
                            false
                        );
                        
                        if (isset($sendRes['success']) && $sendRes['success']) {
                            $resultEntry['status'] = 'sent';
                            $resultEntry['fallback'] = !empty($sendRes['is_fallback']);
                            $resultEntry['channel_used'] = $sendRes['channel'] ?? ($resultEntry['fallback'] ? 'sms' : $requestedChannel);
                        } else {
                            $resultEntry['error'] = $sendRes['error'] ?? 'Unknown error';
                        }
                    } else {
                        $resultEntry['error'] = 'Template not found';
                    }

                } else {
                    // Custom Message path
                    // Manually replace variables
                    $processedMessage = $message_raw;
                    foreach ($variables as $key => $value) {
                        $processedMessage = str_replace('{' . $key . '}', (string)$value, $processedMessage);
                    }
                    // Clean unused vars
                    $processedMessage = preg_replace('/\{[a-z_]+\}/', '', $processedMessage);
                    $processedMessage = trim($processedMessage);

                    if ($channel === 'whatsapp' || $channel === 'auto') {
                        if ($msgHelper->isWhatsAppAvailable()) {
                            $whatsapp = UltraMsgService::fromDatabase($db);
                            if ($whatsapp) {
                                $waSource = $bulk_run_id ? ('bulk_custom:' . $bulk_run_id) : 'bulk_custom';
                                $waRes = $whatsapp->send($donor['phone'], $processedMessage, ['donor_id' => $donor['id'], 'source_type' => $waSource]);
                                
                                if ($waRes['success']) {
                                    $resultEntry['status'] = 'sent';
                                    $resultEntry['channel_used'] = 'whatsapp';
                                } else {
                                    $resultEntry['error'] = 'WhatsApp failed: ' . ($waRes['error'] ?? '');
                                    
                                    // Fallback to SMS
                                    $sms = VoodooSMSService::fromDatabase($db);
                                    if ($sms) {
                                        $smsSource = $bulk_run_id ? ('bulk_custom_fallback:' . $bulk_run_id) : 'bulk_custom_fallback';
                                        $smsRes = $sms->send($donor['phone'], $processedMessage, ['donor_id' => $donor['id'], 'source_type' => $smsSource]);
                                        if ($smsRes['success']) {
                                            $resultEntry['status'] = 'sent';
                                            $resultEntry['fallback'] = true;
                                            $resultEntry['channel_used'] = 'sms';
                                            $resultEntry['error'] = ''; 
                                        } else {
                                            $resultEntry['error'] .= ' | SMS Failed: ' . ($smsRes['error'] ?? '');
                                        }
                                    }
                                }
                            } else {
                                $resultEntry['error'] = 'WhatsApp config missing';
                            }
                        } else {
                            // SMS Fallback immediately if WhatsApp not configured
                            $sms = VoodooSMSService::fromDatabase($db);
                            if ($sms) {
                                $smsSource = $bulk_run_id ? ('bulk_custom:' . $bulk_run_id) : 'bulk_custom';
                                $smsRes = $sms->send($donor['phone'], $processedMessage, ['donor_id' => $donor['id'], 'source_type' => $smsSource]);
                                if ($smsRes['success']) {
                                    $resultEntry['status'] = 'sent';
                                    $resultEntry['fallback'] = true; 
                                    $resultEntry['channel_used'] = 'sms';
                                } else {
                                    $resultEntry['error'] = 'SMS Failed: ' . ($smsRes['error'] ?? '');
                                }
                            } else {
                                $resultEntry['error'] = 'No SMS service configured';
                            }
                        }
                    } else {
                        // SMS Only
                        $sms = VoodooSMSService::fromDatabase($db);
                        if ($sms) {
                            $smsSource = $bulk_run_id ? ('bulk_custom:' . $bulk_run_id) : 'bulk_custom';
                            $smsRes = $sms->send($donor['phone'], $processedMessage, ['donor_id' => $donor['id'], 'source_type' => $smsSource]);
                            if ($smsRes['success']) {
                                $resultEntry['status'] = 'sent';
                                $resultEntry['channel_used'] = 'sms';
                            } else {
                                $resultEntry['error'] = 'SMS Failed: ' . ($smsRes['error'] ?? '');
                            }
                        } else {
                            $resultEntry['error'] = 'No SMS service configured';
                        }
                    }
                }
            } catch (Exception $e) {
                $resultEntry['error'] = $e->getMessage();
            }

            // Persist recipient row for bulk history
            if ($bulk_run_id !== '') {
                $channelUsed = $resultEntry['channel_used'] ?? 'sms';

                $statusVal = $resultEntry['status'] === 'sent' ? 'sent' : 'failed';
                $isFallback = $resultEntry['fallback'] ? 1 : 0;
                $errMsg = $resultEntry['error'] !== '' ? $resultEntry['error'] : null;

                $stmt = $db->prepare("
                    INSERT INTO bulk_message_recipients
                        (run_id, donor_id, phone, channel_used, status, is_fallback, error_message, sent_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        phone = VALUES(phone),
                        channel_used = VALUES(channel_used),
                        status = VALUES(status),
                        is_fallback = VALUES(is_fallback),
                        error_message = VALUES(error_message),
                        updated_at = NOW()
                ");
                if ($stmt) {
                    $donorId = (int)$resultEntry['donor_id'];
                    $phone = (string)$resultEntry['phone'];
                    $stmt->bind_param('sisssis', $bulk_run_id, $donorId, $phone, $channelUsed, $statusVal, $isFallback, $errMsg);
                    $stmt->execute();
                }
            }

            $results[] = $resultEntry;
        }

        if ($bulk_run_id !== '') {
            recalcRunStats($db, $bulk_run_id);
        }
        
        echo json_encode(['success' => true, 'results' => $results]);

    } elseif ($action === 'finalize_run') {
        ensureBulkTables($db);

        $bulk_run_id = isset($_POST['bulk_run_id']) ? trim((string)$_POST['bulk_run_id']) : '';
        $bulk_run_id = preg_replace('/[^a-zA-Z0-9_\\-]/', '', $bulk_run_id);
        if ($bulk_run_id === '') {
            throw new Exception('Missing bulk_run_id');
        }

        recalcRunStats($db, $bulk_run_id);
        $stmt = $db->prepare("UPDATE bulk_message_runs SET finished_at = NOW(), updated_at = NOW() WHERE run_id = ?");
        if ($stmt) {
            $stmt->bind_param('s', $bulk_run_id);
            $stmt->execute();
        }

        echo json_encode(['success' => true]);

    } else {
        throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
