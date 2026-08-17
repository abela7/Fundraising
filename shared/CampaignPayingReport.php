<?php

declare(strict_types=1);

require_once __DIR__ . '/CampaignPayingProgress.php';
require_once __DIR__ . '/CampaignPayingLink.php';
require_once __DIR__ . '/DonorCampaignGroups.php';

/**
 * Still-paying campaign report: opened links, answers, and booked calls.
 */
final class CampaignPayingReport
{
    public const FILTER_ALL = 'all';
    public const FILTER_SENT = 'sent';
    public const FILTER_NOT_OPENED = 'not_opened';
    public const FILTER_OPENED = 'opened';
    public const FILTER_ANSWERED = 'answered';
    public const FILTER_BOOKED = 'booked';
    public const FILTER_PENDING = 'pending';
    public const FILTER_CONTACTED = 'contacted';
    public const FILTER_NOT_ANSWERING = 'not_answering';

    public const CALL_PENDING = 'pending';
    public const CALL_CONTACTED = 'contacted';
    public const CALL_NOT_ANSWERING = 'not_answering';

    /** @var list<string> */
    public const FILTERS = [
        self::FILTER_ALL,
        self::FILTER_SENT,
        self::FILTER_NOT_OPENED,
        self::FILTER_OPENED,
        self::FILTER_ANSWERED,
        self::FILTER_BOOKED,
        self::FILTER_PENDING,
        self::FILTER_CONTACTED,
        self::FILTER_NOT_ANSWERING,
    ];

    /** @var list<string> */
    public const CALL_STATUSES = [
        self::CALL_PENDING,
        self::CALL_CONTACTED,
        self::CALL_NOT_ANSWERING,
    ];

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   sent:bool,
     *   opened:bool,
     *   answered:bool,
     *   answer:?string,
     *   booked:bool,
     *   call_status:string,
     *   contact_date:?string,
     *   contact_time:?string,
     *   contact_method:?string
     * }
     */
    public static function classify(array $row): array
    {
        $answers = self::answersFromRow($row);
        $answer = isset($answers['status_correct']) && is_string($answers['status_correct'])
            ? $answers['status_correct']
            : null;
        if ($answer !== 'yes' && $answer !== 'no') {
            $answer = null;
        }
        $date = self::stringOrNull($answers['contact_date'] ?? null);
        $time = self::stringOrNull($answers['contact_time'] ?? null);
        $method = self::stringOrNull($answers['contact_method'] ?? null);
        if ($method !== 'whatsapp' && $method !== 'phone') {
            $method = null;
        }
        $step = CampaignPayingProgress::sanitizeStep((string) ($row['step'] ?? ''));
        $reachedContact = in_array($step, [
            CampaignPayingProgress::STEP_CONTACT,
            CampaignPayingProgress::STEP_PHONE,
            CampaignPayingProgress::STEP_DONE,
        ], true);
        $reachedPhone = in_array($step, [
            CampaignPayingProgress::STEP_PHONE,
            CampaignPayingProgress::STEP_DONE,
        ], true);
        if ($answer === null && $reachedContact) {
            $answer = 'yes';
        }
        $booked = ($date !== null && $time !== null && $method !== null) || $reachedPhone;
        $answered = $answer !== null;
        $opened = self::hasTime($row['opened_at'] ?? null)
            || self::hasTime($row['progress_updated_at'] ?? null)
            || (int) ($row['revision'] ?? 0) > 0
            || $answered
            || $booked;

        return [
            'sent' => self::hasTime($row['last_sent_at'] ?? null),
            'opened' => $opened,
            'answered' => $answered,
            'answer' => $answer,
            'booked' => $booked,
            'call_status' => self::resolveCallStatus($booked, $row['call_status'] ?? ''),
            'contact_date' => $date,
            'contact_time' => $time,
            'contact_method' => $method,
        ];
    }

    public static function sanitizeCallStatus(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace([' ', '-'], '_', $value);
        if ($value === 'notanswering') {
            $value = self::CALL_NOT_ANSWERING;
        }

        return in_array($value, self::CALL_STATUSES, true) ? $value : '';
    }

    public static function resolveCallStatus(bool $booked, mixed $stored): string
    {
        if (!$booked) {
            return '';
        }
        $status = self::sanitizeCallStatus($stored);

        return $status !== '' ? $status : self::CALL_PENDING;
    }

    public static function callStatusLabel(string $status): string
    {
        if ($status === self::CALL_CONTACTED) {
            return 'Contacted';
        }
        if ($status === self::CALL_NOT_ANSWERING) {
            return 'Not answering';
        }
        if ($status === self::CALL_PENDING) {
            return 'Pending';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $classified
     */
    public static function matchesFilter(array $classified, string $filter): bool
    {
        $filter = strtolower(trim($filter));
        if ($filter === self::FILTER_SENT) {
            return !empty($classified['sent']);
        }
        if ($filter === self::FILTER_NOT_OPENED) {
            return !empty($classified['sent']) && empty($classified['opened']);
        }
        if ($filter === self::FILTER_OPENED) {
            return !empty($classified['opened']);
        }
        if ($filter === self::FILTER_ANSWERED) {
            return !empty($classified['answered']);
        }
        if ($filter === self::FILTER_BOOKED) {
            return !empty($classified['booked']);
        }
        if (
            $filter === self::FILTER_PENDING
            || $filter === self::FILTER_CONTACTED
            || $filter === self::FILTER_NOT_ANSWERING
        ) {
            return self::resolveCallStatus(
                !empty($classified['booked']),
                $classified['call_status'] ?? ''
            ) === $filter;
        }

        return true;
    }

    public static function sanitizeFilter(string $filter): string
    {
        $filter = strtolower(trim($filter));

        return in_array($filter, self::FILTERS, true) ? $filter : self::FILTER_ALL;
    }

    public static function isCallFilter(string $filter): bool
    {
        return in_array($filter, [
            self::FILTER_BOOKED,
            self::FILTER_PENDING,
            self::FILTER_CONTACTED,
            self::FILTER_NOT_ANSWERING,
        ], true);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{
     *   donors:int,
     *   sent:int,
     *   opened:int,
     *   not_opened:int,
     *   answered:int,
     *   answered_yes:int,
     *   answered_no:int,
     *   booked:int,
     *   call_pending:int,
     *   call_contacted:int,
     *   call_not_answering:int
     * }
     */
    public static function summarize(array $rows): array
    {
        $summary = [
            'donors' => 0,
            'sent' => 0,
            'opened' => 0,
            'not_opened' => 0,
            'answered' => 0,
            'answered_yes' => 0,
            'answered_no' => 0,
            'booked' => 0,
            'call_pending' => 0,
            'call_contacted' => 0,
            'call_not_answering' => 0,
        ];
        foreach ($rows as $row) {
            $item = isset($row['sent']) || isset($row['opened']) || isset($row['answered'])
                ? $row
                : self::classify($row);
            $summary['donors']++;
            if (!empty($item['sent'])) {
                $summary['sent']++;
            }
            if (!empty($item['opened'])) {
                $summary['opened']++;
            }
            if (!empty($item['sent']) && empty($item['opened'])) {
                $summary['not_opened']++;
            }
            if (!empty($item['answered'])) {
                $summary['answered']++;
            }
            if (($item['answer'] ?? null) === 'yes') {
                $summary['answered_yes']++;
            }
            if (($item['answer'] ?? null) === 'no') {
                $summary['answered_no']++;
            }
            if (!empty($item['booked'])) {
                $summary['booked']++;
            }
            $callStatus = self::resolveCallStatus(
                !empty($item['booked']),
                $item['call_status'] ?? ''
            );
            if ($callStatus === self::CALL_PENDING) {
                $summary['call_pending']++;
            }
            if ($callStatus === self::CALL_CONTACTED) {
                $summary['call_contacted']++;
            }
            if ($callStatus === self::CALL_NOT_ANSWERING) {
                $summary['call_not_answering']++;
            }
        }

        return $summary;
    }

    public static function formatWhen(mixed $datetime): string
    {
        $value = trim((string) $datetime);
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return '';
        }

        return date('j M Y, g:i A', $timestamp);
    }

    /**
     * Staff follow-up after a booked call: pending, contacted, or not answering.
     *
     * @return array{donor_id:int,call_status:string,call_status_label:string}|null
     */
    public static function setCallStatus(mysqli $db, int $donorId, string $status): ?array
    {
        $status = self::sanitizeCallStatus($status);
        if ($donorId <= 0 || $status === '') {
            return null;
        }
        $donor = self::findDonor($db, $donorId);
        if ($donor === null || empty($donor['booked'])) {
            return null;
        }
        $stmt = $db->prepare(
            'UPDATE campaign_paying_links SET call_status = ? WHERE donor_id = ?'
        );
        if ($stmt === false) {
            throw new RuntimeException('Paying call status update failed.');
        }
        $stmt->bind_param('si', $status, $donorId);
        $stmt->execute();
        $stmt->close();

        return [
            'donor_id' => $donorId,
            'call_status' => $status,
            'call_status_label' => self::callStatusLabel($status),
        ];
    }

    public static function stepLabel(string $step): string
    {
        $step = CampaignPayingProgress::sanitizeStep($step);
        if ($step === CampaignPayingProgress::STEP_STATUS) {
            return 'Status check';
        }
        if ($step === CampaignPayingProgress::STEP_CONTACT) {
            return 'Contact page';
        }
        if ($step === CampaignPayingProgress::STEP_PHONE) {
            return 'Phone check';
        }
        if ($step === CampaignPayingProgress::STEP_DONE) {
            return 'Thank you';
        }

        return 'Welcome page';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $classified = self::classify($row);
        $token = strtolower(trim((string) ($row['token'] ?? '')));
        if ($token !== '' && !preg_match('/^[a-f0-9]{16}$/', $token)) {
            $token = '';
        }
        $hasLink = $token !== '';
        $answer = $classified['answer'];
        $openedAt = self::stringOrNull($row['opened_at'] ?? null);
        $savedAt = self::stringOrNull($row['progress_updated_at'] ?? null);
        $sentAt = self::stringOrNull($row['last_sent_at'] ?? null);
        $timeline = [];
        self::pushEvent($timeline, 'sent', 'Link sent', $sentAt);
        self::pushEvent($timeline, 'opened', 'Opened the link', $openedAt);
        if ($savedAt !== null && $savedAt !== $openedAt) {
            self::pushEvent($timeline, 'saved', 'Last saved on the page', $savedAt);
        }
        usort($timeline, static function (array $a, array $b): int {
            return strcmp((string) ($a['when'] ?? ''), (string) ($b['when'] ?? ''));
        });

        $pledged = (float) ($row['total_pledged'] ?? $row['pledged'] ?? 0);
        $paid = (float) ($row['total_paid'] ?? $row['paid'] ?? 0);
        $balance = (float) ($row['balance'] ?? 0);
        $answers = self::answersFromRow($row);
        $phoneChoice = (string) ($answers['phone_correct'] ?? '');
        $phoneLabel = 'Not confirmed';
        if ($phoneChoice === 'yes') {
            $phoneLabel = 'Stored number';
        } elseif ($phoneChoice === 'no') {
            $phoneLabel = 'New number';
        }

        return [
            'donor_id' => (int) ($row['id'] ?? $row['donor_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'reference' => (string) ($row['reference'] ?? ''),
            'pledged' => $pledged,
            'paid' => $paid,
            'balance' => $balance,
            'pledged_label' => CampaignPayingLink::formatMoney($pledged),
            'paid_label' => CampaignPayingLink::formatMoney($paid),
            'balance_label' => CampaignPayingLink::formatMoney($balance),
            'has_link' => $hasLink,
            'token' => $token,
            'paying_url' => $hasLink ? CampaignPayingLink::whatsappUrl($token) : '',
            'step' => (string) ($row['step'] ?? ''),
            'step_label' => $hasLink ? self::stepLabel((string) ($row['step'] ?? '')) : 'No paying link',
            'revision' => max(0, (int) ($row['revision'] ?? 0)),
            'sent' => $classified['sent'],
            'opened' => $classified['opened'],
            'answered' => $classified['answered'],
            'booked' => $classified['booked'],
            'call_status' => $classified['call_status'],
            'call_status_label' => self::callStatusLabel($classified['call_status']),
            'answer' => $answer,
            'answer_label' => $answer === 'yes' ? 'Yes' : ($answer === 'no' ? 'No' : 'Not answered'),
            'sent_label' => self::formatWhen($sentAt),
            'opened_label' => self::formatWhen($openedAt ?? $savedAt),
            'saved_label' => self::formatWhen($savedAt),
            'contact_date' => $classified['contact_date'],
            'contact_time' => $classified['contact_time'],
            'contact_method' => $classified['contact_method'],
            'method_label' => $classified['contact_method'] === 'phone'
                ? 'Phone'
                : ($classified['contact_method'] === 'whatsapp' ? 'WhatsApp' : ''),
            'booking_label' => self::formatBooking(
                $classified['contact_date'],
                $classified['contact_time'],
                $classified['contact_method']
            ),
            'contact_phone' => (string) ($answers['contact_phone'] ?? ''),
            'phone_correct' => $phoneChoice,
            'phone_correct_label' => $phoneLabel,
            'answers' => $answers,
            'timeline' => $timeline,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findDonor(mysqli $db, int $donorId): ?array
    {
        if ($donorId <= 0) {
            return null;
        }
        CampaignPayingProgress::ensureColumns($db);
        $tables = $db->query("SHOW TABLES LIKE 'campaign_paying_links'");
        $hasLinks = $tables && $tables->num_rows > 0;
        $join = $hasLinks
            ? 'LEFT JOIN campaign_paying_links l ON l.donor_id = d.id'
            : '';
        $linkSelect = self::linkSelectSql($db, $hasLinks, true);

        $sql = "
            SELECT
                d.id,
                d.name,
                d.phone,
                d.donor_type,
                d.total_pledged,
                d.total_paid,
                d.balance,
                {$linkSelect},
                (
                    SELECT p.notes
                    FROM pledges p
                    WHERE p.donor_id = d.id
                      AND p.status IN ('approved', 'pending')
                      AND p.notes REGEXP '^[0-9]{4}$'
                    ORDER BY (p.status = 'approved') DESC, p.id DESC
                    LIMIT 1
                ) AS reference
            FROM donors d
            {$join}
            WHERE d.id = ?
            LIMIT 1
        ";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Paying activity query failed.');
        }
        $stmt->bind_param('i', $donorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        $presented = self::present($row);
        $group = DonorCampaignGroups::fromDonor($row);
        if ($group !== DonorCampaignGroups::PLEDGE_PAYING && empty($presented['has_link'])) {
            return null;
        }

        return $presented;
    }

    public static function formatBooking(?string $date, ?string $time, ?string $method): string
    {
        if ($date === null || $time === null || $method === null) {
            return '';
        }
        $timestamp = strtotime($date . ' ' . $time);
        if ($timestamp === false) {
            return '';
        }
        $when = date('j M Y, g:i A', $timestamp);
        $label = $method === 'phone' ? 'Phone' : 'WhatsApp';

        return $when . ' · ' . $label;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fetch(mysqli $db): array
    {
        CampaignPayingProgress::ensureColumns($db);
        $tables = $db->query("SHOW TABLES LIKE 'campaign_paying_links'");
        $hasLinks = $tables && $tables->num_rows > 0;
        $groupExpr = DonorCampaignGroups::sqlCase('d');
        $join = $hasLinks
            ? 'LEFT JOIN campaign_paying_links l ON l.donor_id = d.id'
            : '';
        $linkSelect = self::linkSelectSql($db, $hasLinks, false);

        $sql = "
            SELECT
                d.id,
                d.name,
                d.phone,
                d.total_pledged,
                d.total_paid,
                d.balance,
                {$linkSelect},
                (
                    SELECT p.notes
                    FROM pledges p
                    WHERE p.donor_id = d.id
                      AND p.status IN ('approved', 'pending')
                      AND p.notes REGEXP '^[0-9]{4}$'
                    ORDER BY (p.status = 'approved') DESC, p.id DESC
                    LIMIT 1
                ) AS reference
            FROM donors d
            {$join}
            WHERE ({$groupExpr}) = ?
            ORDER BY d.name ASC, d.id ASC
        ";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Paying report query failed.');
        }
        $group = DonorCampaignGroups::PLEDGE_PAYING;
        $stmt->bind_param('s', $group);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $classified = self::classify($row);
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'donor_id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'reference' => (string) ($row['reference'] ?? ''),
                'pledged' => (float) ($row['total_pledged'] ?? 0),
                'paid' => (float) ($row['total_paid'] ?? 0),
                'balance' => (float) ($row['balance'] ?? 0),
                'sent' => $classified['sent'],
                'opened' => $classified['opened'],
                'opened_at' => self::stringOrNull($row['opened_at'] ?? null)
                    ?? self::stringOrNull($row['progress_updated_at'] ?? null),
                'opened_label' => self::formatWhen(
                    $row['opened_at'] ?? $row['progress_updated_at'] ?? null
                ),
                'answered' => $classified['answered'],
                'answer' => $classified['answer'],
                'booked' => $classified['booked'],
                'call_status' => $classified['call_status'],
                'call_status_label' => self::callStatusLabel($classified['call_status']),
                'contact_date' => $classified['contact_date'],
                'contact_time' => $classified['contact_time'],
                'contact_method' => $classified['contact_method'],
                'booking_label' => self::formatBooking(
                    $classified['contact_date'],
                    $classified['contact_time'],
                    $classified['contact_method']
                ),
            ];
        }
        $stmt->close();

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function filterRows(array $rows, string $filter, string $search): array
    {
        $search = self::lower(trim($search));
        $out = [];
        foreach ($rows as $row) {
            if (!self::matchesFilter($row, $filter)) {
                continue;
            }
            if ($search !== '') {
                $hay = self::lower(
                    (string) ($row['name'] ?? '')
                    . ' '
                    . (string) ($row['phone'] ?? '')
                    . ' '
                    . (string) ($row['reference'] ?? '')
                    . ' '
                    . (string) ($row['call_status'] ?? '')
                    . ' '
                    . (string) ($row['call_status_label'] ?? '')
                );
                if (!str_contains($hay, $search)) {
                    continue;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    private static function linkSelectSql(mysqli $db, bool $hasLinks, bool $withToken): string
    {
        if (!$hasLinks) {
            $token = $withToken ? 'NULL AS token, ' : '';

            return $token
                . 'NULL AS last_sent_at, NULL AS opened_at, NULL AS step, NULL AS answers_json, '
                . '0 AS revision, NULL AS progress_updated_at, NULL AS call_status';
        }
        $token = $withToken ? 'l.token, ' : '';
        $callStatus = self::hasLinkColumn($db, 'call_status')
            ? 'l.call_status'
            : 'NULL AS call_status';

        return $token
            . 'l.last_sent_at, l.opened_at, l.step, l.answers_json, l.revision, '
            . 'l.progress_updated_at, '
            . $callStatus;
    }

    private static function hasLinkColumn(mysqli $db, string $column): bool
    {
        $column = strtolower(preg_replace('/[^a-z0-9_]/', '', $column) ?? '');
        if ($column === '') {
            return false;
        }
        try {
            $result = $db->query(
                "SHOW COLUMNS FROM campaign_paying_links LIKE '" . $db->real_escape_string($column) . "'"
            );

            return $result !== false && $result->num_rows > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function answersFromRow(array $row): array
    {
        if (isset($row['answers']) && is_array($row['answers'])) {
            return CampaignPayingProgress::sanitizeAnswers($row['answers']);
        }
        $raw = $row['answers_json'] ?? null;
        if ($raw !== null && $raw !== '') {
            return CampaignPayingProgress::decodeAnswersJson($raw);
        }

        return [];
    }

    /**
     * @param list<array<string, string>> $events
     */
    private static function pushEvent(array &$events, string $key, string $label, mixed $when): void
    {
        if (!self::hasTime($when)) {
            return;
        }
        $stamp = trim((string) $when);
        $events[] = [
            'key' => $key,
            'label' => $label,
            'when' => $stamp,
            'when_label' => self::formatWhen($stamp),
        ];
    }

    private static function lower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value);
        }

        return strtolower($value);
    }

    private static function hasTime(mixed $value): bool
    {
        $value = trim((string) $value);

        return $value !== '' && $value !== '0000-00-00 00:00:00';
    }

    private static function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
