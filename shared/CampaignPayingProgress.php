<?php

declare(strict_types=1);

/**
 * Token-gated paying-page progress: current step plus donor answers.
 */
final class CampaignPayingProgress
{
    public const STEP_WELCOME = 'welcome';
    public const STEP_STATUS = 'status';
    public const STEP_CONTACT = 'contact';
    public const MAX_JSON_BYTES = 16384;
    public const MAX_KEYS = 40;
    public const MAX_STRING = 500;

    /** @var list<string> */
    public const STEPS = [
        self::STEP_WELCOME,
        self::STEP_STATUS,
        self::STEP_CONTACT,
    ];

    /** @var list<string> */
    private const BLOCKED_KEYS = [
        'donor_id',
        'id',
        'token',
        'sign',
        'password',
        'csrf',
        'csrf_token',
    ];

    public static function normalizeToken(string $token): ?string
    {
        $token = strtolower(trim($token));
        if ($token === '' || !preg_match('/^[a-f0-9]{16}$/', $token)) {
            return null;
        }

        return $token;
    }

    public static function sign(string $token): string
    {
        return hash_hmac('sha256', 'paying-sync-v1|' . $token, self::secret());
    }

    public static function verifySign(string $token, string $sign): bool
    {
        $sign = strtolower(trim($sign));
        if ($sign === '' || !preg_match('/^[a-f0-9]{64}$/', $sign)) {
            return false;
        }

        return hash_equals(self::sign($token), $sign);
    }

    public static function sanitizeStep(string $step): string
    {
        $step = strtolower(trim($step));
        if ($step === 'info') {
            return self::STEP_STATUS;
        }

        return in_array($step, self::STEPS, true) ? $step : self::STEP_WELCOME;
    }

    /**
     * Contact is only reachable after the donor confirms the amounts.
     *
     * @param array<string, mixed> $answers
     */
    public static function resolveStep(string $step, array $answers = []): string
    {
        $step = self::sanitizeStep($step);
        if ($step === self::STEP_CONTACT && ($answers['status_correct'] ?? '') !== 'yes') {
            return self::STEP_STATUS;
        }

        return $step;
    }

    /**
     * @return array<string, mixed>
     */
    public static function sanitizeAnswers(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            if (count($out) >= self::MAX_KEYS) {
                break;
            }
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
                continue;
            }
            if (in_array($key, self::BLOCKED_KEYS, true)) {
                continue;
            }
            $clean = self::sanitizeValue($value);
            if ($clean === '__reject__') {
                continue;
            }
            $clean = self::sanitizeKnownAnswer($key, $clean);
            if ($clean === '__reject__') {
                continue;
            }
            $out[$key] = $clean;
        }

        return $out;
    }

    /**
     * Empty PHP arrays become [] in JSON, which JavaScript treats as an Array.
     * Named keys set on that array are then dropped by JSON.stringify, so the
     * donor's Yes/No never reaches the server and Continue cannot open contact.
     *
     * @param array<string, mixed> $answers
     */
    public static function answersForClient(array $answers): object
    {
        return (object) $answers;
    }

    /**
     * @return array{step:string,answers:array<string,mixed>,revision:int}
     */
    public static function emptyState(): array
    {
        return [
            'step' => self::STEP_WELCOME,
            'answers' => [],
            'revision' => 0,
        ];
    }

    /**
     * @return array{step:string,answers:array<string,mixed>,revision:int}
     */
    public static function load(mysqli $db, string $token): array
    {
        $token = self::normalizeToken($token);
        if ($token === null) {
            return self::emptyState();
        }
        try {
            self::ensureColumns($db);
            $stmt = $db->prepare(
                'SELECT step, answers_json, revision
                 FROM campaign_paying_links
                 WHERE token = ?
                 LIMIT 1'
            );
            if ($stmt === false) {
                return self::emptyState();
            }
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!is_array($row)) {
                return self::emptyState();
            }

            $answers = self::decodeAnswers($row['answers_json'] ?? null);

            return [
                'step' => self::resolveStep(
                    (string) ($row['step'] ?? self::STEP_WELCOME),
                    $answers
                ),
                'answers' => $answers,
                'revision' => max(0, (int) ($row['revision'] ?? 0)),
            ];
        } catch (Throwable $e) {
            error_log('Paying progress load failed: ' . $e->getMessage());

            return self::emptyState();
        }
    }

    /**
     * @param array<string, mixed> $answers
     * @return array{ok:bool,step:string,answers:array<string,mixed>,revision:int}|null
     */
    public static function save(mysqli $db, string $token, string $step, array $answers): ?array
    {
        $token = self::normalizeToken($token);
        if ($token === null) {
            return null;
        }
        $answers = self::sanitizeAnswers($answers);
        $step = self::resolveStep($step, $answers);
        $json = json_encode($answers, JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > self::MAX_JSON_BYTES) {
            return null;
        }

        try {
            self::ensureColumns($db);
            $stmt = $db->prepare(
                'UPDATE campaign_paying_links
                 SET step = ?,
                     answers_json = ?,
                     revision = revision + 1,
                     progress_updated_at = NOW()
                 WHERE token = ?'
            );
            if ($stmt === false) {
                return null;
            }
            $stmt->bind_param('sss', $step, $json, $token);
            $ok = $stmt->execute();
            $stmt->close();
            if (!$ok) {
                return null;
            }

            return self::load($db, $token);
        } catch (Throwable $e) {
            error_log('Paying progress save failed: ' . $e->getMessage());

            return null;
        }
    }

    public static function tokenExists(mysqli $db, string $token): bool
    {
        $token = self::normalizeToken($token);
        if ($token === null) {
            return false;
        }
        try {
            $stmt = $db->prepare('SELECT 1 FROM campaign_paying_links WHERE token = ? LIMIT 1');
            if ($stmt === false) {
                return false;
            }
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return is_array($row);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function ensureColumns(mysqli $db): void
    {
        $alters = [
            "ALTER TABLE campaign_paying_links ADD COLUMN step VARCHAR(40) NOT NULL DEFAULT 'welcome'",
            'ALTER TABLE campaign_paying_links ADD COLUMN answers_json TEXT NULL',
            'ALTER TABLE campaign_paying_links ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 0',
            'ALTER TABLE campaign_paying_links ADD COLUMN progress_updated_at TIMESTAMP NULL DEFAULT NULL',
        ];
        foreach ($alters as $sql) {
            try {
                $db->query($sql);
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (
                    stripos($msg, 'duplicate column') === false
                    && stripos($msg, 'already exists') === false
                ) {
                    error_log('Paying progress column failed: ' . $msg);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeAnswers(mixed $json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return self::sanitizeAnswers($decoded);
    }

    private static function sanitizeKnownAnswer(string $key, mixed $value): mixed
    {
        if ($key === 'contact_method') {
            $value = strtolower(trim((string) $value));

            return in_array($value, ['whatsapp', 'phone'], true) ? $value : '__reject__';
        }
        if ($key === 'contact_date') {
            $value = trim((string) $value);

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '__reject__';
        }
        if ($key === 'contact_time') {
            $value = trim((string) $value);
            if (preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $value, $match) !== 1) {
                return '__reject__';
            }
            $hour = (int) $match[1];
            $minute = (int) $match[2];
            if ($hour > 23 || $minute > 59) {
                return '__reject__';
            }

            return sprintf('%02d:%02d', $hour, $minute);
        }
        if ($key === 'status_correct') {
            $value = strtolower(trim((string) $value));

            return in_array($value, ['yes', 'no'], true) ? $value : '__reject__';
        }

        return $value;
    }

    private static function sanitizeValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : '__reject__';
        }
        if (is_string($value)) {
            $value = strip_tags(trim($value));
            if (function_exists('mb_substr')) {
                return mb_substr($value, 0, self::MAX_STRING);
            }

            return substr($value, 0, self::MAX_STRING);
        }
        if (is_array($value)) {
            $list = [];
            foreach (array_slice(array_values($value), 0, 20) as $item) {
                if (is_string($item) || is_int($item) || is_float($item) || is_bool($item) || $item === null) {
                    $clean = self::sanitizeValue($item);
                    if ($clean !== '__reject__') {
                        $list[] = $clean;
                    }
                }
            }

            return $list;
        }

        return '__reject__';
    }

    private static function secret(): string
    {
        return (defined('DB_PASS') ? (string) DB_PASS : '') . '|paying-sync-v1';
    }
}
