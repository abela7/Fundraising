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

    } elseif ($action === 'send_batch') {
        $ids_json = $_POST['ids'] ?? '[]';
        $ids = json_decode($ids_json, true);
        $message_raw = $_POST['message'] ?? '';
        $channel = $_POST['channel'] ?? 'whatsapp';
        $template_id = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null;

        if (empty($ids)) {
            echo json_encode(['success' => true, 'results' => []]);
            exit;
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
                        $sendRes = $msgHelper->sendFromTemplate(
                            $templateKey,
                            (int)$donor['id'],
                            $variables,
                            $channel,
                            'bulk_manual',
                            false
                        );
                        
                        if (isset($sendRes['success']) && $sendRes['success']) {
                            $resultEntry['status'] = 'sent';
                            if ($channel === 'whatsapp' && ($sendRes['channel'] ?? '') === 'sms') {
                                $resultEntry['fallback'] = true;
                            }
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

                    if ($channel === 'whatsapp') {
                        if ($msgHelper->isWhatsAppAvailable()) {
                            $whatsapp = UltraMsgService::fromDatabase($db);
                            if ($whatsapp) {
                                $waRes = $whatsapp->send($donor['phone'], $processedMessage, ['donor_id' => $donor['id'], 'source_type' => 'bulk_custom']);
                                
                                if ($waRes['success']) {
                                    $resultEntry['status'] = 'sent';
                                } else {
                                    $resultEntry['error'] = 'WhatsApp failed: ' . ($waRes['error'] ?? '');
                                    
                                    // Fallback to SMS
                                    $sms = VoodooSMSService::fromDatabase($db);
                                    if ($sms) {
                                        $smsRes = $sms->send($donor['phone'], $processedMessage, ['donor_id' => $donor['id'], 'source_type' => 'bulk_custom_fallback']);
                                        if ($smsRes['success']) {
                                            $resultEntry['status'] = 'sent';
                                            $resultEntry['fallback'] = true;
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
                                $smsRes = $sms->send($donor['phone'], $processedMessage, ['donor_id' => $donor['id'], 'source_type' => 'bulk_custom']);
                                if ($smsRes['success']) {
                                    $resultEntry['status'] = 'sent';
                                    $resultEntry['fallback'] = true; 
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
                            $smsRes = $sms->send($donor['phone'], $processedMessage, ['donor_id' => $donor['id'], 'source_type' => 'bulk_custom']);
                            if ($smsRes['success']) {
                                $resultEntry['status'] = 'sent';
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

            $results[] = $resultEntry;
        }
        
        echo json_encode(['success' => true, 'results' => $results]);

    } else {
        throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
