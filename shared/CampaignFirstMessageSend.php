<?php

declare(strict_types=1);

require_once __DIR__ . '/CampaignGroupSettings.php';
require_once __DIR__ . '/DonorCampaignGroups.php';
require_once __DIR__ . '/../services/UltraMsgService.php';

/**
 * Send the saved still-paying first WhatsApp message to chosen donors.
 */
final class CampaignFirstMessageSend
{
    public const BATCH_LIMIT = 8;

    /**
     * @return list<array{id:int,has_phone:bool}>
     */
    public static function listPayingMeta(mysqli $db): array
    {
        $tables = $db->query("SHOW TABLES LIKE 'donors'");
        if (!$tables || $tables->num_rows === 0) {
            return [];
        }

        $groupExpr = DonorCampaignGroups::sqlCase('d');
        $group = DonorCampaignGroups::PLEDGE_PAYING;
        $stmt = $db->prepare("SELECT d.id, d.phone FROM donors d WHERE ({$groupExpr}) = ? ORDER BY d.id ASC");
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('s', $group);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'has_phone' => trim((string) ($row['phone'] ?? '')) !== '',
            ];
        }
        $stmt->close();

        return $rows;
    }

    /**
     * @param list<int> $donorIds
     * @return array{
     *     ok:bool,
     *     error?:string,
     *     results:list<array{donor_id:int,name:string,status:string,error:?string}>
     * }
     */
    public static function sendBatch(mysqli $db, array $donorIds, int $userId): array
    {
        $settings = CampaignGroupSettings::get($db, CampaignGroupSettings::GROUP_PAYING);
        $template = trim((string) $settings['first_message']);
        if ($template === '') {
            return ['ok' => false, 'error' => 'Write and save a first message before sending.', 'results' => []];
        }

        $service = UltraMsgService::fromDatabase($db);
        if ($service === null) {
            return ['ok' => false, 'error' => 'WhatsApp is not configured. Set up UltraMsg first.', 'results' => []];
        }

        $validIds = CampaignGroupSettings::payingDonorIds($db, $donorIds);
        $validIds = array_slice($validIds, 0, self::BATCH_LIMIT);
        if ($validIds === []) {
            return ['ok' => true, 'results' => []];
        }

        $donors = self::loadDonors($db, $validIds);
        $results = [];
        foreach ($donors as $donor) {
            $results[] = self::sendOne($db, $service, $donor, $template, $userId);
        }

        return ['ok' => true, 'results' => $results];
    }

    /**
     * @param list<int> $ids
     * @return list<array{id:int,name:string,phone:string,pledged:float,paid:float,balance:float}>
     */
    private static function loadDonors(mysqli $db, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $db->prepare(
            "SELECT id, name, phone, total_pledged, total_paid, balance
             FROM donors
             WHERE id IN ({$placeholders})"
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $byId = [];
        while ($row = $result->fetch_assoc()) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $byId[$id] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? ''),
                'phone' => trim((string) ($row['phone'] ?? '')),
                'pledged' => (float) ($row['total_pledged'] ?? 0),
                'paid' => (float) ($row['total_paid'] ?? 0),
                'balance' => (float) ($row['balance'] ?? 0),
            ];
        }
        $stmt->close();

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * @param array{id:int,name:string,phone:string,pledged:float,paid:float,balance:float} $donor
     * @return array{donor_id:int,name:string,status:string,error:?string}
     */
    private static function sendOne(
        mysqli $db,
        UltraMsgService $service,
        array $donor,
        string $template,
        int $userId
    ): array {
        $name = trim($donor['name']) !== '' ? $donor['name'] : 'Unknown';
        $base = [
            'donor_id' => $donor['id'],
            'name' => $name,
            'error' => null,
        ];

        if ($donor['phone'] === '') {
            return array_merge($base, ['status' => 'skipped', 'error' => 'No phone number']);
        }

        $body = CampaignGroupSettings::preview($template, [
            'name' => $name,
            'pledged' => $donor['pledged'],
            'paid' => $donor['paid'],
            'balance' => $donor['balance'],
        ]);

        $result = $service->send($donor['phone'], $body, [
            'donor_id' => $donor['id'],
            'source_type' => 'campaign_first_message',
            'log' => true,
        ]);

        if (empty($result['success'])) {
            return array_merge($base, ['status' => 'failed', 'error' => 'Could not send']);
        }

        self::rememberOutgoing(
            $db,
            $donor['id'],
            (string) ($result['phone_number'] ?? $donor['phone']),
            $body,
            isset($result['message_id']) ? (string) $result['message_id'] : null,
            $userId
        );

        return array_merge($base, ['status' => 'sent']);
    }

    private static function rememberOutgoing(
        mysqli $db,
        int $donorId,
        string $phone,
        string $message,
        ?string $ultramsgId,
        int $userId
    ): void {
        try {
            $check = $db->query("SHOW TABLES LIKE 'whatsapp_conversations'");
            if (!$check || $check->num_rows === 0) {
                return;
            }

            $stmt = $db->prepare('SELECT id FROM whatsapp_conversations WHERE donor_id = ? LIMIT 1');
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('i', $donorId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $conversationId = (int) ($row['id'] ?? 0);
            if ($conversationId <= 0) {
                $isUnknown = 0;
                $insert = $db->prepare(
                    'INSERT INTO whatsapp_conversations (phone_number, donor_id, is_unknown, created_at)
                     VALUES (?, ?, ?, NOW())'
                );
                if ($insert === false) {
                    return;
                }
                $insert->bind_param('sii', $phone, $donorId, $isUnknown);
                $insert->execute();
                $conversationId = (int) $db->insert_id;
                $insert->close();
            }
            if ($conversationId <= 0) {
                return;
            }

            $ultramsgId = $ultramsgId ?? '';
            $status = 'sent';
            $msg = $db->prepare(
                "INSERT INTO whatsapp_messages
                    (conversation_id, ultramsg_id, direction, message_type, body, status, sender_id, is_from_donor, sent_at, created_at)
                 VALUES (?, ?, 'outgoing', 'text', ?, ?, ?, 0, NOW(), NOW())"
            );
            if ($msg !== false) {
                $msg->bind_param('isssi', $conversationId, $ultramsgId, $message, $status, $userId);
                $msg->execute();
                $msg->close();
            }

            $preview = function_exists('mb_substr') ? mb_substr($message, 0, 255) : substr($message, 0, 255);
            $upd = $db->prepare(
                "UPDATE whatsapp_conversations
                 SET last_message_at = NOW(),
                     last_message_preview = ?,
                     last_message_direction = 'outgoing',
                     updated_at = NOW()
                 WHERE id = ?"
            );
            if ($upd !== false) {
                $upd->bind_param('si', $preview, $conversationId);
                $upd->execute();
                $upd->close();
            }
        } catch (Throwable $e) {
            error_log('Campaign first message inbox log failed: ' . $e->getMessage());
        }
    }
}
