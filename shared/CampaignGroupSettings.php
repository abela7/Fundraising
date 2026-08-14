<?php

declare(strict_types=1);

require_once __DIR__ . '/DonorCampaignGroups.php';

/**
 * Saved first-message settings for one campaign group.
 */
final class CampaignGroupSettings
{
    public const GROUP_PAYING = DonorCampaignGroups::PLEDGE_PAYING;
    public const MODE_ALL = 'all';
    public const MODE_SELECTED = 'selected';
    public const MAX_MESSAGE_LENGTH = 4000;

    /**
     * Default Amharic hello for still-paying donors.
     */
    public static function defaultFirstMessage(): string
    {
        return "ሰላም ጤና ይስጥልን የተከበሩ {name}። ከሊቨርፑል መካነ ቅዱሳን አቡነ ተክለሃይማኖት ቤተክርስቲያን ነው።";
    }

    /**
     * Default Amharic welcome on the paying page.
     */
    public static function defaultWelcomeMessage(): string
    {
        return "የተከበሩ {name}፣\n\nእንኳን በደህና መጡ። ከሊቨርፑል መካነ ቅዱሳን አቡነ ተክለሃይማኖት ቤተክርስቲያን ነው።";
    }

    /**
     * @return list<array{key:string,label:string,token:string}>
     */
    public static function variables(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name', 'token' => '{name}'],
            ['key' => 'pledge_amount', 'label' => 'Pledge amount', 'token' => '{pledge_amount}'],
            ['key' => 'total_paid', 'label' => 'Total paid', 'token' => '{total_paid}'],
            ['key' => 'remaining_amount', 'label' => 'Remaining amount', 'token' => '{remaining_amount}'],
        ];
    }

    /**
     * Welcome composer only inserts the donor name.
     *
     * @return list<array{key:string,label:string,token:string}>
     */
    public static function welcomeVariables(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name', 'token' => '{name}'],
        ];
    }

    public static function isAllowedGroup(string $group): bool
    {
        return $group === self::GROUP_PAYING;
    }

    public static function ensureTables(mysqli $db): void
    {
        try {
            $db->query(
                "CREATE TABLE IF NOT EXISTS campaign_group_settings (
                    group_key VARCHAR(40) NOT NULL,
                    first_message TEXT NOT NULL,
                    welcome_message TEXT NULL,
                    recipient_mode VARCHAR(20) NOT NULL DEFAULT 'all',
                    updated_by INT NULL,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (group_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
        }

        try {
            $db->query(
                "CREATE TABLE IF NOT EXISTS campaign_group_recipients (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    group_key VARCHAR(40) NOT NULL,
                    donor_id INT NOT NULL,
                    UNIQUE KEY uq_campaign_recipient (group_key, donor_id),
                    KEY idx_campaign_recipient_group (group_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
        }

        self::ensureWelcomeColumn($db);
    }

    private static function ensureWelcomeColumn(mysqli $db): void
    {
        try {
            $db->query(
                'ALTER TABLE campaign_group_settings ADD COLUMN welcome_message TEXT NULL AFTER first_message'
            );
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (
                stripos($msg, 'duplicate column') === false
                && stripos($msg, 'already exists') === false
            ) {
                error_log('Campaign welcome column failed: ' . $msg);
            }
        }
    }

    /**
     * @return array{
     *     group:string,
     *     first_message:string,
     *     default_message:string,
     *     welcome_message:string,
     *     default_welcome:string,
     *     recipient_mode:string,
     *     donor_ids:list<int>
     * }
     */
    public static function get(mysqli $db, string $group): array
    {
        $defaults = [
            'group' => $group,
            'first_message' => self::defaultFirstMessage(),
            'default_message' => self::defaultFirstMessage(),
            'welcome_message' => self::defaultWelcomeMessage(),
            'default_welcome' => self::defaultWelcomeMessage(),
            'recipient_mode' => self::MODE_ALL,
            'donor_ids' => [],
        ];
        if (!self::isAllowedGroup($group)) {
            return $defaults;
        }

        try {
            self::ensureTables($db);
        } catch (Throwable $e) {
            error_log('Campaign settings tables failed: ' . $e->getMessage());
        }

        try {
            $stmt = $db->prepare(
                'SELECT first_message, welcome_message, recipient_mode
                 FROM campaign_group_settings
                 WHERE group_key = ?
                 LIMIT 1'
            );
            if ($stmt === false) {
                return $defaults;
            }
            $stmt->bind_param('s', $group);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (is_array($row)) {
                $message = trim((string) ($row['first_message'] ?? ''));
                if ($message !== '') {
                    $defaults['first_message'] = $message;
                }
                $welcome = trim((string) ($row['welcome_message'] ?? ''));
                if ($welcome !== '') {
                    $defaults['welcome_message'] = $welcome;
                }
                $mode = (string) ($row['recipient_mode'] ?? self::MODE_ALL);
                $defaults['recipient_mode'] = $mode === self::MODE_SELECTED ? self::MODE_SELECTED : self::MODE_ALL;
            }
        } catch (Throwable $e) {
            return $defaults;
        }

        try {
            $stmt = $db->prepare(
                'SELECT donor_id FROM campaign_group_recipients WHERE group_key = ? ORDER BY donor_id ASC'
            );
            if ($stmt === false) {
                return $defaults;
            }
            $stmt->bind_param('s', $group);
            $stmt->execute();
            $result = $stmt->get_result();
            $ids = [];
            while ($row = $result->fetch_assoc()) {
                $id = (int) ($row['donor_id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $stmt->close();
            $defaults['donor_ids'] = $ids;
        } catch (Throwable $e) {
            return $defaults;
        }

        return $defaults;
    }

    public static function saveMessage(mysqli $db, string $group, string $message, int $updatedBy): bool
    {
        if (!self::isAllowedGroup($group)) {
            return false;
        }
        $message = trim($message);
        $length = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        if ($message === '' || $length > self::MAX_MESSAGE_LENGTH) {
            return false;
        }
        self::ensureTables($db);
        $existing = self::get($db, $group);
        $mode = $existing['recipient_mode'];
        $stmt = $db->prepare(
            'INSERT INTO campaign_group_settings (group_key, first_message, recipient_mode, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                first_message = VALUES(first_message),
                updated_by = VALUES(updated_by)'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('sssi', $group, $message, $mode, $updatedBy);

        return $stmt->execute();
    }

    public static function saveWelcomeMessage(mysqli $db, string $group, string $message, int $updatedBy): bool
    {
        if (!self::isAllowedGroup($group)) {
            return false;
        }
        $message = trim($message);
        $length = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        if ($message === '' || $length > self::MAX_MESSAGE_LENGTH) {
            return false;
        }
        self::ensureTables($db);
        $existing = self::get($db, $group);
        $first = $existing['first_message'];
        $mode = $existing['recipient_mode'];
        $stmt = $db->prepare(
            'INSERT INTO campaign_group_settings (group_key, first_message, welcome_message, recipient_mode, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                welcome_message = VALUES(welcome_message),
                updated_by = VALUES(updated_by)'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ssssi', $group, $first, $message, $mode, $updatedBy);

        return $stmt->execute();
    }

    /**
     * @param list<int> $donorIds
     */
    public static function saveRecipients(mysqli $db, string $group, string $mode, array $donorIds, int $updatedBy): bool
    {
        if (!self::isAllowedGroup($group)) {
            return false;
        }
        $mode = $mode === self::MODE_SELECTED ? self::MODE_SELECTED : self::MODE_ALL;
        self::ensureTables($db);

        $message = self::get($db, $group)['first_message'];
        $stmt = $db->prepare(
            'INSERT INTO campaign_group_settings (group_key, first_message, recipient_mode, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                recipient_mode = VALUES(recipient_mode),
                updated_by = VALUES(updated_by)'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('sssi', $group, $message, $mode, $updatedBy);
        if (!$stmt->execute()) {
            return false;
        }

        $del = $db->prepare('DELETE FROM campaign_group_recipients WHERE group_key = ?');
        if ($del === false) {
            return false;
        }
        $del->bind_param('s', $group);
        $del->execute();
        $del->close();

        if ($mode !== self::MODE_SELECTED) {
            return true;
        }

        $validIds = self::payingDonorIds($db, $donorIds);
        if ($validIds === []) {
            return true;
        }
        $insert = $db->prepare(
            'INSERT IGNORE INTO campaign_group_recipients (group_key, donor_id) VALUES (?, ?)'
        );
        if ($insert === false) {
            return false;
        }
        foreach ($validIds as $donorId) {
            $insert->bind_param('si', $group, $donorId);
            $insert->execute();
        }
        $insert->close();

        return true;
    }

    /**
     * Keep only still-paying pledge donors.
     *
     * @param list<int> $donorIds
     * @return list<int>
     */
    public static function payingDonorIds(mysqli $db, array $donorIds): array
    {
        $ids = [];
        foreach ($donorIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            return [];
        }

        $tables = $db->query("SHOW TABLES LIKE 'donors'");
        if (!$tables || $tables->num_rows === 0) {
            return [];
        }

        $groupExpr = DonorCampaignGroups::sqlCase('d');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "SELECT d.id FROM donors d WHERE d.id IN ({$placeholders}) AND ({$groupExpr}) = ?";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $group = self::GROUP_PAYING;
        $bind = array_merge($ids, [$group]);
        $stmt->bind_param($types . 's', ...$bind);
        $stmt->execute();
        $result = $stmt->get_result();
        $valid = [];
        while ($row = $result->fetch_assoc()) {
            $valid[] = (int) ($row['id'] ?? 0);
        }
        $stmt->close();

        return array_values(array_filter($valid, static fn (int $id): bool => $id > 0));
    }

    /**
     * @param array{name?:string,pledged?:float,paid?:float,balance?:float} $donor
     */
    public static function preview(string $template, array $donor): string
    {
        $name = trim((string) ($donor['name'] ?? 'Abeba'));
        if ($name === '') {
            $name = 'Abeba';
        }

        $map = [
            '{name}' => $name,
            '{pledge_amount}' => self::formatMoney((float) ($donor['pledged'] ?? 400)),
            '{total_paid}' => self::formatMoney((float) ($donor['paid'] ?? 120)),
            '{remaining_amount}' => self::formatMoney((float) ($donor['balance'] ?? 280)),
        ];

        return strtr($template, $map);
    }

    public static function formatMoney(float $amount): string
    {
        return '£' . number_format($amount, 2);
    }
}
