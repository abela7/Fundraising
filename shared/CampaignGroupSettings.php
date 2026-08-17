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
    public const MAX_TITLE_LENGTH = 200;

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
     * Default Amharic footer under pledged / paid / remaining.
     */
    public static function defaultStatusMessage(): string
    {
        return 'ይህ መረጃ ትክክል ነው?';
    }

    /**
     * Default heading above pledged / paid / remaining.
     */
    public static function defaultStatusTitle(): string
    {
        return 'ባለን መረጃ መሰረት';
    }

    /**
     * Default Amharic labels for pledged / paid / remaining.
     *
     * @return array{pledge:string,paid:string,remain:string}
     */
    public static function defaultStatusLabels(): array
    {
        return [
            'pledge' => 'ጠቅላላ የገቡት ቃልኪዳን መጠን',
            'paid' => 'እስካሁን የከፈሉት',
            'remain' => 'ቀሪ',
        ];
    }

    /**
     * @param array{pledge?:string,paid?:string,remain?:string} $overrides
     * @return array{pledge:string,paid:string,remain:string}
     */
    public static function statusLabels(?string $savedJson = null, array $overrides = []): array
    {
        $labels = self::defaultStatusLabels();
        if (is_string($savedJson) && trim($savedJson) !== '') {
            $decoded = json_decode($savedJson, true);
            if (is_array($decoded)) {
                foreach (['pledge', 'paid', 'remain'] as $key) {
                    $value = trim((string) ($decoded[$key] ?? ''));
                    if ($value !== '') {
                        $labels[$key] = $value;
                    }
                }
            }
        }
        foreach (['pledge', 'paid', 'remain'] as $key) {
            $value = trim((string) ($overrides[$key] ?? ''));
            if ($value !== '') {
                $labels[$key] = $value;
            }
        }

        return $labels;
    }

    /**
     * Default thank-you after the donor confirms the amounts.
     */
    public static function defaultContactMessage(): string
    {
        return "እናመሰግናለን የተከበሩ {name}።\n\nመረጃው ትክክል ነው። የቀረው {remaining_amount} እንዴት እንደሚጠናቀቅ ለመነጋገር እንወዳለን።";
    }

    /**
     * Default prompt to pick date, time, and contact method.
     */
    public static function defaultContactAsk(): string
    {
        return 'እባክዎ የሚመችዎትን ቀን፣ ሰዓት እና የመገናኛ መንገድ ይምረጡ።';
    }

    /**
     * Default Amharic thank-you after the donor books a call.
     */
    public static function defaultDoneMessage(): string
    {
        return "እናመሰግናለን የተከበሩ {name}።\n\nበመረጡት ቀን እና ሰዓት እንደውልዎታለን።";
    }

    /**
     * Default Amharic question showing the stored phone number.
     */
    public static function defaultPhoneAsk(): string
    {
        return 'በዚህ ቁጥር ብንደዉል እናገኝዎታለን {phone}?';
    }

    /**
     * Default prompt when the stored number is wrong.
     */
    public static function defaultPhoneEnter(): string
    {
        return 'የስልክ ቁጥርዎን ያስገቡ';
    }

    /**
     * @return array{date:string,time:string,method:string,whatsapp:string,phone:string}
     */
    public static function defaultContactLabels(): array
    {
        return [
            'date' => 'ቀን',
            'time' => 'ሰዓት',
            'method' => 'እንዴት እንደውልልዎ?',
            'whatsapp' => 'የWhatsApp ጥሪ',
            'phone' => 'የስልክ ጥሪ',
        ];
    }

    public static function contactMessageText(string $saved): string
    {
        $saved = trim($saved);

        return $saved !== '' ? $saved : self::defaultContactMessage();
    }

    public static function contactAskText(string $saved): string
    {
        $saved = trim($saved);

        return $saved !== '' ? $saved : self::defaultContactAsk();
    }

    /**
     * @param array{date?:string,time?:string,method?:string,whatsapp?:string,phone?:string} $overrides
     * @return array{date:string,time:string,method:string,whatsapp:string,phone:string}
     */
    public static function contactLabels(?string $savedJson = null, array $overrides = []): array
    {
        $labels = self::defaultContactLabels();
        if (is_string($savedJson) && trim($savedJson) !== '') {
            $decoded = json_decode($savedJson, true);
            if (is_array($decoded)) {
                foreach (['date', 'time', 'method', 'whatsapp', 'phone'] as $key) {
                    $value = trim((string) ($decoded[$key] ?? ''));
                    if ($value !== '') {
                        $labels[$key] = $value;
                    }
                }
            }
        }
        foreach (['date', 'time', 'method', 'whatsapp', 'phone'] as $key) {
            $value = trim((string) ($overrides[$key] ?? ''));
            if ($value !== '') {
                $labels[$key] = $value;
            }
        }

        return $labels;
    }

    /**
     * Contact page uses the same variables as the status page.
     *
     * @return list<array{key:string,label:string,token:string}>
     */
    public static function contactVariables(): array
    {
        return self::variables();
    }

    /**
     * Previous status body that repeated the amounts in prose.
     */
    public static function legacyStatusMessage(): string
    {
        return "የተከበሩ {name}፣\n\nቃል የገቡት፦ {pledge_amount}\nእስካሁን የከፈሉት፦ {total_paid}\nቀሪ፦ {remaining_amount}\n\nይህ መረጃ ትክክል ነው?";
    }

    /**
     * Title and footer for the amount card. Old saved text that repeated
     * pledged / paid / remaining is split so the heading sits above the
     * figures and the question stays under them.
     *
     * @return array{title:string,footer:string}
     */
    public static function statusCardCopy(string $savedFooter, string $savedTitle = ''): array
    {
        $savedFooter = trim($savedFooter);
        $savedTitle = trim($savedTitle);

        if ($savedFooter === '' || $savedFooter === self::legacyStatusMessage()) {
            $cleaned = self::defaultStatusMessage();
        } else {
            $cleaned = self::withoutAmountLines($savedFooter);
            if ($cleaned === '') {
                $cleaned = self::defaultStatusMessage();
            }
        }

        $lines = [];
        foreach (preg_split("/\r\n|\n|\r/", $cleaned) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $title = $savedTitle;
        if ($title === '' && count($lines) >= 2) {
            $title = $lines[0];
            array_shift($lines);
        }
        if ($title === '') {
            $title = self::defaultStatusTitle();
        }
        if ($lines !== [] && $lines[0] === $title) {
            array_shift($lines);
        }

        $footer = trim(implode("\n", $lines));
        if ($footer === '') {
            $footer = self::defaultStatusMessage();
        }

        return [
            'title' => $title,
            'footer' => $footer,
        ];
    }

    /**
     * Footer shown under the amount card.
     */
    public static function statusFooterText(string $saved, string $savedTitle = ''): string
    {
        return self::statusCardCopy($saved, $savedTitle)['footer'];
    }

    /**
     * Heading shown above pledged / paid / remaining.
     */
    public static function statusTitleText(string $savedTitle, string $savedFooter = ''): string
    {
        return self::statusCardCopy($savedFooter, $savedTitle)['title'];
    }

    /**
     * Drop lines that only repeat pledged / paid / remaining.
     */
    private static function withoutAmountLines(string $saved): string
    {
        $amountTokens = ['{pledge_amount}', '{total_paid}', '{remaining_amount}'];
        $tokenHits = 0;
        foreach ($amountTokens as $token) {
            if (str_contains($saved, $token)) {
                $tokenHits++;
            }
        }
        if ($tokenHits < 2) {
            return trim($saved);
        }

        $kept = [];
        foreach (preg_split("/\r\n|\n|\r/", $saved) as $line) {
            $drop = false;
            foreach ($amountTokens as $token) {
                if (str_contains($line, $token)) {
                    $drop = true;
                    break;
                }
            }
            if (!$drop) {
                $kept[] = $line;
            }
        }

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept)));
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

    /**
     * Footer under the amount card. Amounts are shown as rows, not in this text.
     *
     * @return list<array{key:string,label:string,token:string}>
     */
    public static function statusVariables(): array
    {
        return self::variables();
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
                    status_message TEXT NULL,
                    status_title TEXT NULL,
                    status_labels TEXT NULL,
                    contact_message TEXT NULL,
                    contact_ask TEXT NULL,
                    contact_labels TEXT NULL,
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
        self::ensureStatusColumn($db);
        self::ensureStatusTitleColumn($db);
        self::ensureStatusLabelsColumn($db);
        self::ensureContactColumns($db);
    }

    private static function ensureWelcomeColumn(mysqli $db): void
    {
        self::addColumnIfMissing(
            $db,
            'ALTER TABLE campaign_group_settings ADD COLUMN welcome_message TEXT NULL AFTER first_message',
            'Campaign welcome column failed: '
        );
    }

    private static function ensureStatusColumn(mysqli $db): void
    {
        self::addColumnIfMissing(
            $db,
            'ALTER TABLE campaign_group_settings ADD COLUMN status_message TEXT NULL AFTER welcome_message',
            'Campaign status column failed: '
        );
    }

    private static function ensureStatusTitleColumn(mysqli $db): void
    {
        self::addColumnIfMissing(
            $db,
            'ALTER TABLE campaign_group_settings ADD COLUMN status_title TEXT NULL AFTER status_message',
            'Campaign status title column failed: '
        );
    }

    private static function ensureStatusLabelsColumn(mysqli $db): void
    {
        self::addColumnIfMissing(
            $db,
            'ALTER TABLE campaign_group_settings ADD COLUMN status_labels TEXT NULL AFTER status_title',
            'Campaign status labels column failed: '
        );
    }

    private static function ensureContactColumns(mysqli $db): void
    {
        self::addColumnIfMissing(
            $db,
            'ALTER TABLE campaign_group_settings ADD COLUMN contact_message TEXT NULL AFTER status_labels',
            'Campaign contact message column failed: '
        );
        self::addColumnIfMissing(
            $db,
            'ALTER TABLE campaign_group_settings ADD COLUMN contact_ask TEXT NULL AFTER contact_message',
            'Campaign contact ask column failed: '
        );
        self::addColumnIfMissing(
            $db,
            'ALTER TABLE campaign_group_settings ADD COLUMN contact_labels TEXT NULL AFTER contact_ask',
            'Campaign contact labels column failed: '
        );
    }

    private static function addColumnIfMissing(mysqli $db, string $sql, string $logPrefix): void
    {
        try {
            $db->query($sql);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (
                stripos($msg, 'duplicate column') === false
                && stripos($msg, 'already exists') === false
            ) {
                error_log($logPrefix . $msg);
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
     *     status_message:string,
     *     default_status:string,
     *     status_title:string,
     *     default_status_title:string,
     *     status_labels:array{pledge:string,paid:string,remain:string},
     *     default_status_labels:array{pledge:string,paid:string,remain:string},
     *     contact_message:string,
     *     default_contact_message:string,
     *     contact_ask:string,
     *     default_contact_ask:string,
     *     contact_labels:array{date:string,time:string,method:string,whatsapp:string,phone:string},
     *     default_contact_labels:array{date:string,time:string,method:string,whatsapp:string,phone:string},
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
            'status_message' => self::defaultStatusMessage(),
            'default_status' => self::defaultStatusMessage(),
            'status_title' => self::defaultStatusTitle(),
            'default_status_title' => self::defaultStatusTitle(),
            'status_labels' => self::defaultStatusLabels(),
            'default_status_labels' => self::defaultStatusLabels(),
            'contact_message' => self::defaultContactMessage(),
            'default_contact_message' => self::defaultContactMessage(),
            'contact_ask' => self::defaultContactAsk(),
            'default_contact_ask' => self::defaultContactAsk(),
            'contact_labels' => self::defaultContactLabels(),
            'default_contact_labels' => self::defaultContactLabels(),
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
                'SELECT first_message, welcome_message, status_message, status_title, status_labels,
                        contact_message, contact_ask, contact_labels, recipient_mode
                 FROM campaign_group_settings
                 WHERE group_key = ?
                 LIMIT 1'
            );
            if ($stmt === false) {
                $stmt = $db->prepare(
                    'SELECT first_message, welcome_message, status_message, status_title, status_labels, recipient_mode
                     FROM campaign_group_settings
                     WHERE group_key = ?
                     LIMIT 1'
                );
            }
            if ($stmt === false) {
                $stmt = $db->prepare(
                    'SELECT first_message, welcome_message, status_message, status_title, recipient_mode
                     FROM campaign_group_settings
                     WHERE group_key = ?
                     LIMIT 1'
                );
            }
            if ($stmt === false) {
                $stmt = $db->prepare(
                    'SELECT first_message, welcome_message, status_message, recipient_mode
                     FROM campaign_group_settings
                     WHERE group_key = ?
                     LIMIT 1'
                );
            }
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
                $card = self::statusCardCopy(
                    (string) ($row['status_message'] ?? ''),
                    (string) ($row['status_title'] ?? '')
                );
                $defaults['status_message'] = $card['footer'];
                $defaults['status_title'] = $card['title'];
                $defaults['status_labels'] = self::statusLabels(
                    isset($row['status_labels']) ? (string) $row['status_labels'] : null
                );
                $defaults['contact_message'] = self::contactMessageText(
                    (string) ($row['contact_message'] ?? '')
                );
                $defaults['contact_ask'] = self::contactAskText(
                    (string) ($row['contact_ask'] ?? '')
                );
                $defaults['contact_labels'] = self::contactLabels(
                    isset($row['contact_labels']) ? (string) $row['contact_labels'] : null
                );
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

    public static function saveStatusMessage(
        mysqli $db,
        string $group,
        string $message,
        int $updatedBy,
        string $title = '',
        array $labels = []
    ): bool {
        if (!self::isAllowedGroup($group)) {
            return false;
        }
        $message = trim($message);
        $title = trim($title);
        $resolved = self::statusLabels(null, $labels);
        $messageLength = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
        if ($message === '' || $messageLength > self::MAX_MESSAGE_LENGTH) {
            return false;
        }
        if ($titleLength > self::MAX_TITLE_LENGTH) {
            return false;
        }
        foreach ($resolved as $label) {
            $labelLength = function_exists('mb_strlen') ? mb_strlen($label) : strlen($label);
            if ($label === '' || $labelLength > self::MAX_TITLE_LENGTH) {
                return false;
            }
        }
        if ($title === '') {
            $title = self::defaultStatusTitle();
        }
        $labelsJson = json_encode($resolved, JSON_UNESCAPED_UNICODE);
        if (!is_string($labelsJson)) {
            return false;
        }
        self::ensureTables($db);
        $existing = self::get($db, $group);
        $first = $existing['first_message'];
        $welcome = $existing['welcome_message'];
        $mode = $existing['recipient_mode'];
        $stmt = $db->prepare(
            'INSERT INTO campaign_group_settings
                (group_key, first_message, welcome_message, status_message, status_title, status_labels, recipient_mode, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status_message = VALUES(status_message),
                status_title = VALUES(status_title),
                status_labels = VALUES(status_labels),
                updated_by = VALUES(updated_by)'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param(
            'sssssssi',
            $group,
            $first,
            $welcome,
            $message,
            $title,
            $labelsJson,
            $mode,
            $updatedBy
        );

        return $stmt->execute();
    }

    /**
     * @param array{date?:string,time?:string,method?:string,whatsapp?:string,phone?:string} $labels
     */
    public static function saveContactCopy(
        mysqli $db,
        string $group,
        string $message,
        string $ask,
        int $updatedBy,
        array $labels = []
    ): bool {
        if (!self::isAllowedGroup($group)) {
            return false;
        }
        $message = trim($message);
        $ask = trim($ask);
        $resolved = self::contactLabels(null, $labels);
        $messageLength = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        $askLength = function_exists('mb_strlen') ? mb_strlen($ask) : strlen($ask);
        if ($message === '' || $messageLength > self::MAX_MESSAGE_LENGTH) {
            return false;
        }
        if ($ask === '' || $askLength > self::MAX_MESSAGE_LENGTH) {
            return false;
        }
        foreach ($resolved as $label) {
            $labelLength = function_exists('mb_strlen') ? mb_strlen($label) : strlen($label);
            if ($label === '' || $labelLength > self::MAX_TITLE_LENGTH) {
                return false;
            }
        }
        $labelsJson = json_encode($resolved, JSON_UNESCAPED_UNICODE);
        if (!is_string($labelsJson)) {
            return false;
        }
        self::ensureTables($db);
        $existing = self::get($db, $group);
        $first = $existing['first_message'];
        $welcome = $existing['welcome_message'];
        $mode = $existing['recipient_mode'];
        $stmt = $db->prepare(
            'INSERT INTO campaign_group_settings
                (group_key, first_message, welcome_message, contact_message, contact_ask, contact_labels, recipient_mode, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                contact_message = VALUES(contact_message),
                contact_ask = VALUES(contact_ask),
                contact_labels = VALUES(contact_labels),
                updated_by = VALUES(updated_by)'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param(
            'sssssssi',
            $group,
            $first,
            $welcome,
            $message,
            $ask,
            $labelsJson,
            $mode,
            $updatedBy
        );

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
            '{phone}' => trim((string) ($donor['phone'] ?? '')),
            '{pledge_amount}' => self::formatMoney((float) ($donor['pledged'] ?? 400)),
            '{total_paid}' => self::formatMoney((float) ($donor['paid'] ?? 120)),
            '{remaining_amount}' => self::formatMoney((float) ($donor['balance'] ?? 280)),
        ];

        return strtr($template, $map);
    }

    /**
     * Fill a page template with a live donor row.
     *
     * @param array<string, mixed> $donor
     */
    public static function previewFromDonor(string $template, array $donor): string
    {
        $name = trim((string) ($donor['name'] ?? ''));

        return self::preview($template, [
            'name' => $name !== '' ? $name : 'ጓደኛችን',
            'phone' => trim((string) ($donor['phone'] ?? '')),
            'pledged' => (float) ($donor['total_pledged'] ?? $donor['pledged'] ?? 0),
            'paid' => (float) ($donor['total_paid'] ?? $donor['paid'] ?? 0),
            'balance' => (float) ($donor['balance'] ?? 0),
        ]);
    }

    public static function formatMoney(float $amount): string
    {
        return '£' . number_format($amount, 2);
    }
}
