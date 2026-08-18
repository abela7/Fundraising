<?php

declare(strict_types=1);

require_once __DIR__ . '/DonorCampaignGroups.php';

/**
 * Detect campaign WhatsApp replies and identify the donor's group.
 */
final class CampaignInboundIdentifier
{
    public const INTENT_OK = 'ok';
    public const INTENT_OTHER = 'other';
    public const LINK_PENDING = 'pending';
    public const LINK_UNSUPPORTED = 'unsupported';
    public const LINK_SENT = 'sent';
    public const LINK_ALREADY = 'already_sent';

    /**
     * Groups that receive a unique confirmation link after OK.
     */
    public static function supportsLink(?string $group): bool
    {
        return $group === DonorCampaignGroups::PLEDGE_PAYING
            || $group === DonorCampaignGroups::PLEDGE_NOT_STARTED;
    }

    /**
     * True when the whole message is OK / okay, any casing.
     */
    public static function isOkReply(string $body): bool
    {
        $normalized = self::normalizeReply($body);

        return $normalized === 'ok' || $normalized === 'okay';
    }

    public static function normalizeReply(string $body): string
    {
        $text = trim($body);
        $text = preg_replace('/^["\'«»]+|["\'«»]+$/u', '', $text) ?? $text;
        $text = trim($text);
        $text = preg_replace('/[.!?።]+$/u', '', $text) ?? $text;
        $text = trim($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($text, 'UTF-8');
        }

        return strtolower($text);
    }

    /**
     * @param array{
     *     body:string,
     *     phone:string,
     *     donor_id?:int|null,
     *     conversation_id?:int,
     *     whatsapp_message_id?:int,
     *     ultramsg_id?:string
     * } $input
     * @return array{
     *     intent:string,
     *     matched:bool,
     *     identified:bool,
     *     donor_id:?int,
     *     donor_name:?string,
     *     campaign_group:?string,
     *     link_status:string,
     *     recorded:bool,
     *     reply_id:?int
     * }|null
     */
    public static function handle(mysqli $db, array $input): ?array
    {
        $body = (string) ($input['body'] ?? '');
        if (!self::isOkReply($body)) {
            return null;
        }

        try {
            self::ensureTable($db);
        } catch (Throwable $e) {
            error_log('Campaign inbound table failed: ' . $e->getMessage());
        }

        $existing = self::existingByUltramsg($db, (string) ($input['ultramsg_id'] ?? ''));
        if ($existing !== null) {
            return $existing;
        }

        $donor = self::loadDonor($db, $input);
        $group = $donor !== null ? DonorCampaignGroups::fromDonor($donor) : null;
        $linkStatus = self::supportsLink($group)
            ? self::LINK_PENDING
            : self::LINK_UNSUPPORTED;

        $result = [
            'intent' => self::INTENT_OK,
            'matched' => true,
            'identified' => $donor !== null,
            'donor_id' => $donor !== null ? (int) $donor['id'] : null,
            'donor_name' => $donor !== null ? (string) $donor['name'] : null,
            'campaign_group' => $group,
            'link_status' => $linkStatus,
            'recorded' => false,
            'reply_id' => null,
        ];

        $saved = self::record($db, $input, $result);
        if ($saved !== null) {
            $result['recorded'] = true;
            $result['reply_id'] = $saved;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    private static function loadDonor(mysqli $db, array $input): ?array
    {
        try {
            $tables = $db->query("SHOW TABLES LIKE 'donors'");
            if (!$tables || $tables->num_rows === 0) {
                return null;
            }
        } catch (Throwable $e) {
            return null;
        }

        $donorId = (int) ($input['donor_id'] ?? 0);
        if ($donorId > 0) {
            return self::donorById($db, $donorId);
        }

        return self::donorByPhone($db, (string) ($input['phone'] ?? ''));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function donorById(mysqli $db, int $donorId): ?array
    {
        $stmt = $db->prepare(
            'SELECT id, name, phone, donor_type, total_pledged, total_paid, balance
             FROM donors
             WHERE id = ?
             LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $donorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function donorByPhone(mysqli $db, string $phone): ?array
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $variants = array_values(array_unique(array_filter([
            $phone,
            ltrim($phone, '+'),
            $digits,
            $digits !== '' && str_starts_with($digits, '44') ? '0' . substr($digits, 2) : '',
            $digits !== '' && str_starts_with($digits, '44') ? substr($digits, 2) : '',
        ])));
        if ($variants === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($variants), '?'));
        $stmt = $db->prepare(
            "SELECT id, name, phone, donor_type, total_pledged, total_paid, balance
             FROM donors
             WHERE phone IN ({$placeholders})
             LIMIT 1"
        );
        if ($stmt === false) {
            return null;
        }
        $types = str_repeat('s', count($variants));
        $stmt->bind_param($types, ...$variants);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $result
     */
    private static function record(mysqli $db, array $input, array $result): ?int
    {
        try {
            self::ensureTable($db);
            $ultramsgId = trim((string) ($input['ultramsg_id'] ?? ''));
            $stmt = $db->prepare(
                'INSERT INTO campaign_inbound_replies
                    (donor_id, conversation_id, whatsapp_message_id, ultramsg_id, phone,
                     raw_body, normalized_body, intent, campaign_group, identified, link_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                return null;
            }

            $donorId = $result['donor_id'];
            $conversationId = (int) ($input['conversation_id'] ?? 0) ?: null;
            $messageId = (int) ($input['whatsapp_message_id'] ?? 0) ?: null;
            $phone = (string) ($input['phone'] ?? '');
            $rawBody = (string) ($input['body'] ?? '');
            $normalized = self::normalizeReply($rawBody);
            $intent = (string) $result['intent'];
            $group = $result['campaign_group'];
            $identified = !empty($result['identified']) ? 1 : 0;
            $linkStatus = (string) $result['link_status'];
            $ultramsgParam = $ultramsgId !== '' ? $ultramsgId : null;

            $stmt->bind_param(
                'iiissssssis',
                $donorId,
                $conversationId,
                $messageId,
                $ultramsgParam,
                $phone,
                $rawBody,
                $normalized,
                $intent,
                $group,
                $identified,
                $linkStatus
            );
            if (!$stmt->execute()) {
                $stmt->close();
                return null;
            }
            $id = (int) $db->insert_id;
            $stmt->close();

            return $id > 0 ? $id : null;
        } catch (Throwable $e) {
            error_log('Campaign inbound reply record failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @return array{
     *     intent:string,
     *     matched:bool,
     *     identified:bool,
     *     donor_id:?int,
     *     donor_name:?string,
     *     campaign_group:?string,
     *     link_status:string,
     *     recorded:bool,
     *     reply_id:?int
     * }|null
     */
    private static function existingByUltramsg(mysqli $db, string $ultramsgId): ?array
    {
        $ultramsgId = trim($ultramsgId);
        if ($ultramsgId === '') {
            return null;
        }
        try {
            $stmt = $db->prepare(
                'SELECT id, donor_id, campaign_group, identified, link_status, intent
                 FROM campaign_inbound_replies
                 WHERE ultramsg_id = ?
                 LIMIT 1'
            );
            if ($stmt === false) {
                return null;
            }
            $stmt->bind_param('s', $ultramsgId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!is_array($row)) {
                return null;
            }
            $donorId = isset($row['donor_id']) ? (int) $row['donor_id'] : 0;
            $identified = ((int) ($row['identified'] ?? 0)) === 1 && $donorId > 0;
            $donorName = null;
            if ($identified) {
                $loaded = self::donorById($db, $donorId);
                $donorName = $loaded !== null ? (string) ($loaded['name'] ?? '') : null;
            }

            return [
                'intent' => (string) ($row['intent'] ?? self::INTENT_OK),
                'matched' => true,
                'identified' => $identified,
                'donor_id' => $donorId > 0 ? $donorId : null,
                'donor_name' => $donorName,
                'campaign_group' => isset($row['campaign_group']) ? (string) $row['campaign_group'] : null,
                'link_status' => (string) ($row['link_status'] ?? self::LINK_PENDING),
                'recorded' => true,
                'reply_id' => (int) ($row['id'] ?? 0) ?: null,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function markLinkStatus(mysqli $db, ?int $replyId, string $status): void
    {
        if ($replyId === null || $replyId <= 0) {
            return;
        }
        try {
            $stmt = $db->prepare('UPDATE campaign_inbound_replies SET link_status = ? WHERE id = ?');
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('si', $status, $replyId);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('Campaign inbound link status update failed: ' . $e->getMessage());
        }
    }

    private static function ensureTable(mysqli $db): void
    {
        $db->query(
            "CREATE TABLE IF NOT EXISTS campaign_inbound_replies (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                donor_id INT NULL,
                conversation_id INT NULL,
                whatsapp_message_id INT NULL,
                ultramsg_id VARCHAR(80) NULL,
                phone VARCHAR(40) NOT NULL,
                raw_body TEXT NOT NULL,
                normalized_body VARCHAR(80) NOT NULL,
                intent VARCHAR(20) NOT NULL,
                campaign_group VARCHAR(40) NULL,
                identified TINYINT(1) NOT NULL DEFAULT 0,
                link_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_campaign_inbound_ultramsg (ultramsg_id),
                KEY idx_campaign_inbound_donor (donor_id),
                KEY idx_campaign_inbound_group (campaign_group, intent)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}
