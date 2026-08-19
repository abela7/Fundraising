<?php

declare(strict_types=1);

require_once __DIR__ . '/DonorCampaignGroups.php';
require_once __DIR__ . '/CampaignInboundIdentifier.php';

/**
 * Unique still-paying confirmation links and WhatsApp delivery after OK.
 */
final class CampaignPayingLink
{
    public const SITE_HOME = 'https://donate.abuneteklehaymanot.org/';
    public const PUBLIC_HOST = 'https://donate.abuneteklehaymanot.org/paying';
    public const PUBLIC_HOST_NOT_STARTED = 'https://donate.abuneteklehaymanot.org/not-started';
    private const TOKEN_BYTES = 8;
    private const RESEND_MINUTES = 10;

    /**
     * Groups that receive a unique confirmation link after OK.
     */
    public static function isEligibleGroup(string $group): bool
    {
        return $group === DonorCampaignGroups::PLEDGE_PAYING
            || $group === DonorCampaignGroups::PLEDGE_NOT_STARTED;
    }

    /**
     * Public path segment for one campaign group.
     */
    public static function publicPath(string $group): string
    {
        return $group === DonorCampaignGroups::PLEDGE_NOT_STARTED ? 'not-started' : 'paying';
    }

    /**
     * Create or reuse a short token for this donor.
     */
    public static function issue(mysqli $db, int $donorId, string $group = DonorCampaignGroups::PLEDGE_PAYING): ?string
    {
        if ($donorId <= 0) {
            return null;
        }
        $group = self::isEligibleGroup($group) ? $group : DonorCampaignGroups::PLEDGE_PAYING;
        self::ensureTable($db);
        $stmt = $db->prepare('SELECT token FROM campaign_paying_links WHERE donor_id = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $donorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (is_array($row) && trim((string) ($row['token'] ?? '')) !== '') {
            self::touchGroup($db, $donorId, $group);

            return (string) $row['token'];
        }

        for ($i = 0; $i < 5; $i++) {
            $token = bin2hex(random_bytes(self::TOKEN_BYTES));
            $insert = $db->prepare(
                'INSERT INTO campaign_paying_links (donor_id, token, campaign_group) VALUES (?, ?, ?)'
            );
            if ($insert === false) {
                $insert = $db->prepare(
                    'INSERT INTO campaign_paying_links (donor_id, token) VALUES (?, ?)'
                );
                if ($insert === false) {
                    return null;
                }
                $insert->bind_param('is', $donorId, $token);
            } else {
                $insert->bind_param('iss', $donorId, $token, $group);
            }
            try {
                if ($insert->execute()) {
                    $insert->close();
                    return $token;
                }
            } catch (Throwable $e) {
                $insert->close();
                continue;
            }
            $insert->close();
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function donorByToken(mysqli $db, string $token): ?array
    {
        $token = strtolower(trim($token));
        if ($token === '' || !preg_match('/^[a-f0-9]{16}$/', $token)) {
            return null;
        }
        try {
            $tables = $db->query("SHOW TABLES LIKE 'campaign_paying_links'");
            if (!$tables || $tables->num_rows === 0) {
                return null;
            }
            $stmt = $db->prepare(
                'SELECT d.id, d.name, d.phone, d.donor_type, d.total_pledged, d.total_paid, d.balance,
                        l.campaign_group AS link_group
                 FROM campaign_paying_links l
                 INNER JOIN donors d ON d.id = l.donor_id
                 WHERE l.token = ?
                 LIMIT 1'
            );
            if ($stmt === false) {
                $stmt = $db->prepare(
                    'SELECT d.id, d.name, d.phone, d.donor_type, d.total_pledged, d.total_paid, d.balance
                     FROM campaign_paying_links l
                     INNER JOIN donors d ON d.id = l.donor_id
                     WHERE l.token = ?
                     LIMIT 1'
                );
            }
            if ($stmt === false) {
                return null;
            }
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!is_array($row)) {
                return null;
            }
            $row['campaign_group'] = self::formGroupFromDonorRow($row);

            return $row;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Link sent on WhatsApp. Always the public donate host so phones can open it.
     */
    public static function whatsappUrl(string $token, string $group = DonorCampaignGroups::PLEDGE_PAYING): string
    {
        $host = $group === DonorCampaignGroups::PLEDGE_NOT_STARTED
            ? self::PUBLIC_HOST_NOT_STARTED
            : self::PUBLIC_HOST;

        return $host . '/' . rawurlencode($token);
    }

    public static function publicUrl(
        string $token,
        ?string $host = null,
        ?string $scriptName = null,
        string $group = DonorCampaignGroups::PLEDGE_PAYING
    ): string {
        $token = rawurlencode($token);
        $path = self::publicPath($group);
        $host = strtolower((string) ($host ?? ($_SERVER['HTTP_HOST'] ?? '')));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        if ($host === '' || !self::isLocalHost($host)) {
            return ($path === 'not-started' ? self::PUBLIC_HOST_NOT_STARTED : self::PUBLIC_HOST) . '/' . $token;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $script = str_replace('\\', '/', (string) ($scriptName ?? ($_SERVER['SCRIPT_NAME'] ?? '')));
        $base = '';
        foreach (['/webhooks/', '/paying/', '/not-started/', '/admin/', '/shared/'] as $marker) {
            $pos = strpos($script, $marker);
            if ($pos !== false) {
                $base = rtrim(substr($script, 0, $pos), '/');
                break;
            }
        }

        return $scheme . '://' . ($host !== '' ? $host : 'localhost') . $base . '/' . $path . '/' . $token;
    }

    /**
     * @param array<string, mixed> $reply
     * @return array{sent:bool,reason:string,url:?string}
     */
    public static function sendIfPaying(mysqli $db, array $reply, string $phone, int $conversationId): array
    {
        $empty = ['sent' => false, 'reason' => 'skipped', 'url' => null];
        $group = (string) ($reply['campaign_group'] ?? '');
        if (!self::isEligibleGroup($group)) {
            return ['sent' => false, 'reason' => 'not_paying', 'url' => null];
        }
        $donorId = (int) ($reply['donor_id'] ?? 0);
        if (empty($reply['identified']) || $donorId <= 0) {
            return ['sent' => false, 'reason' => 'unidentified', 'url' => null];
        }
        $status = (string) ($reply['link_status'] ?? '');
        if (in_array($status, [CampaignInboundIdentifier::LINK_SENT, CampaignInboundIdentifier::LINK_ALREADY], true)) {
            return ['sent' => false, 'reason' => 'already_sent', 'url' => null];
        }

        $token = self::issue($db, $donorId, $group);
        if ($token === null) {
            return $empty;
        }
        $url = self::whatsappUrl($token, $group);
        $replyId = isset($reply['reply_id']) ? (int) $reply['reply_id'] : null;

        if (self::recentlySent($db, $donorId)) {
            CampaignInboundIdentifier::markLinkStatus($db, $replyId, CampaignInboundIdentifier::LINK_ALREADY);
            return ['sent' => false, 'reason' => 'already_sent', 'url' => $url];
        }

        require_once __DIR__ . '/../services/UltraMsgService.php';
        $service = UltraMsgService::fromDatabase($db);
        if ($service === null) {
            return ['sent' => false, 'reason' => 'whatsapp_unconfigured', 'url' => $url];
        }

        $name = trim((string) ($reply['donor_name'] ?? ''));
        if ($name === '') {
            $name = 'ጓደኛችን';
        }
        $body = "የተከበሩ {$name}፣\n\nእባክዎ ይህን ሊንክ በመክፈት መረጃዎን ያረጋግጡ።\n\n{$url}";

        $result = $service->send($phone, $body, [
            'donor_id' => $donorId,
            'source_type' => $group === DonorCampaignGroups::PLEDGE_NOT_STARTED
                ? 'campaign_not_started_link'
                : 'campaign_paying_link',
            'log' => true,
        ]);
        if (empty($result['success'])) {
            return ['sent' => false, 'reason' => 'send_failed', 'url' => $url];
        }

        self::touchSent($db, $donorId);
        CampaignInboundIdentifier::markLinkStatus($db, $replyId, CampaignInboundIdentifier::LINK_SENT);
        self::rememberOutgoing(
            $db,
            $donorId,
            (string) ($result['phone_number'] ?? $phone),
            $body,
            isset($result['message_id']) ? (string) $result['message_id'] : null,
            $conversationId
        );

        return ['sent' => true, 'reason' => 'sent', 'url' => $url];
    }

    public static function formatMoney(float $amount): string
    {
        return '£' . number_format($amount, 2);
    }

    private static function isLocalHost(string $host): bool
    {
        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
    }

    private static function recentlySent(mysqli $db, int $donorId): bool
    {
        try {
            $stmt = $db->prepare(
                'SELECT last_sent_at FROM campaign_paying_links
                 WHERE donor_id = ?
                   AND last_sent_at IS NOT NULL
                   AND last_sent_at >= DATE_SUB(NOW(), INTERVAL ' . self::RESEND_MINUTES . ' MINUTE)
                 LIMIT 1'
            );
            if ($stmt === false) {
                return false;
            }
            $stmt->bind_param('i', $donorId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return is_array($row);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Group stored on the unique link. Used for the URL that was sent.
     *
     * @param array<string, mixed> $row
     */
    public static function groupFromDonorRow(array $row): string
    {
        $stored = (string) ($row['link_group'] ?? $row['campaign_group'] ?? '');
        if (self::isEligibleGroup($stored)) {
            return $stored;
        }

        $classified = DonorCampaignGroups::fromDonor($row);

        return self::isEligibleGroup($classified) ? $classified : DonorCampaignGroups::PLEDGE_PAYING;
    }

    /**
     * Copy on the donor form follows the live pledged/paid amounts.
     * A leftover paying link on a not-started donor must not show still-paying text.
     *
     * @param array<string, mixed> $row
     */
    public static function formGroupFromDonorRow(array $row): string
    {
        $classified = DonorCampaignGroups::fromDonor($row);
        if (self::isEligibleGroup($classified)) {
            return $classified;
        }

        return self::groupFromDonorRow($row);
    }

    private static function touchGroup(mysqli $db, int $donorId, string $group): void
    {
        if (!self::isEligibleGroup($group)) {
            return;
        }
        $stmt = $db->prepare('UPDATE campaign_paying_links SET campaign_group = ? WHERE donor_id = ?');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('si', $group, $donorId);
        try {
            $stmt->execute();
        } catch (Throwable $e) {
            // Older tables may not have campaign_group yet.
        }
        $stmt->close();
    }

    private static function touchSent(mysqli $db, int $donorId): void
    {
        $stmt = $db->prepare('UPDATE campaign_paying_links SET last_sent_at = NOW() WHERE donor_id = ?');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('i', $donorId);
        $stmt->execute();
        $stmt->close();
    }

    private static function ensureTable(mysqli $db): void
    {
        $db->query(
            "CREATE TABLE IF NOT EXISTS campaign_paying_links (
                donor_id INT NOT NULL,
                token CHAR(16) NOT NULL,
                campaign_group VARCHAR(40) NOT NULL DEFAULT 'pledge_paying',
                last_sent_at TIMESTAMP NULL DEFAULT NULL,
                step VARCHAR(40) NOT NULL DEFAULT 'welcome',
                answers_json TEXT NULL,
                revision INT UNSIGNED NOT NULL DEFAULT 0,
                progress_updated_at TIMESTAMP NULL DEFAULT NULL,
                opened_at TIMESTAMP NULL DEFAULT NULL,
                call_status VARCHAR(20) NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (donor_id),
                UNIQUE KEY uq_campaign_paying_token (token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        try {
            $db->query(
                "ALTER TABLE campaign_paying_links
                 ADD COLUMN campaign_group VARCHAR(40) NOT NULL DEFAULT 'pledge_paying'
                 AFTER token"
            );
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (
                stripos($msg, 'duplicate column') === false
                && stripos($msg, 'already exists') === false
            ) {
                error_log('Campaign link group column failed: ' . $msg);
            }
        }
    }

    private static function rememberOutgoing(
        mysqli $db,
        int $donorId,
        string $phone,
        string $message,
        ?string $ultramsgId,
        int $conversationId
    ): void {
        try {
            $check = $db->query("SHOW TABLES LIKE 'whatsapp_conversations'");
            if (!$check || $check->num_rows === 0) {
                return;
            }
            if ($conversationId <= 0) {
                $find = $db->prepare('SELECT id FROM whatsapp_conversations WHERE donor_id = ? LIMIT 1');
                if ($find === false) {
                    return;
                }
                $find->bind_param('i', $donorId);
                $find->execute();
                $row = $find->get_result()->fetch_assoc();
                $find->close();
                $conversationId = (int) ($row['id'] ?? 0);
            }
            if ($conversationId <= 0) {
                return;
            }
            $ultramsgId = $ultramsgId ?? '';
            $status = 'sent';
            $userId = 0;
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
            error_log('Campaign paying link inbox log failed: ' . $e->getMessage());
        }
    }
}
