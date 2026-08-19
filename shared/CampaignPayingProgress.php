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
    public const STEP_PHONE = 'phone';
    public const STEP_DONE = 'done';
    public const STEP_CORRECTION = 'correction';
    public const STEP_PAY_METHOD = 'pay_method';
    public const STEP_CASH_DETAIL = 'cash_detail';
    public const STEP_BANK_PROOF = 'bank_proof';
    public const STEP_BANK_DATE = 'bank_date';
    public const MAX_JSON_BYTES = 16384;
    public const MAX_KEYS = 40;
    public const MAX_STRING = 500;
    public const MAX_PROOF_BYTES = 5242880;
    public const DRAFT_MAX_AGE_SECONDS = 2592000;

    /** @var list<string> */
    public const STEPS = [
        self::STEP_WELCOME,
        self::STEP_STATUS,
        self::STEP_CORRECTION,
        self::STEP_PAY_METHOD,
        self::STEP_CASH_DETAIL,
        self::STEP_BANK_PROOF,
        self::STEP_BANK_DATE,
        self::STEP_CONTACT,
        self::STEP_PHONE,
        self::STEP_DONE,
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
     * Yes path: contact → phone → thanks.
     * No path: paid-so-far → cash/bank follow-up, or a callback booking.
     *
     * @param array<string, mixed> $answers
     */
    public static function resolveStep(string $step, array $answers = []): string
    {
        $step = self::sanitizeStep($step);
        $answer = (string) ($answers['status_correct'] ?? '');
        if ($answer === 'no') {
            return self::resolveNoPathStep($step, $answers);
        }
        if ($answer === 'yes') {
            return self::resolveYesPathStep($step, $answers);
        }
        if (in_array($step, [
            self::STEP_CONTACT,
            self::STEP_PHONE,
            self::STEP_DONE,
            self::STEP_CORRECTION,
            self::STEP_PAY_METHOD,
            self::STEP_CASH_DETAIL,
            self::STEP_BANK_PROOF,
            self::STEP_BANK_DATE,
        ], true)) {
            return self::STEP_STATUS;
        }

        return $step;
    }

    /**
     * @param array<string, mixed> $answers
     */
    private static function resolveYesPathStep(string $step, array $answers): string
    {
        if (in_array($step, [
            self::STEP_CORRECTION,
            self::STEP_PAY_METHOD,
            self::STEP_CASH_DETAIL,
            self::STEP_BANK_PROOF,
            self::STEP_BANK_DATE,
        ], true)) {
            return self::STEP_CONTACT;
        }
        if ($step === self::STEP_PHONE || $step === self::STEP_DONE) {
            if (!self::isBookingComplete($answers)) {
                return self::STEP_CONTACT;
            }
            if ($step === self::STEP_DONE && !self::isPhoneConfirmed($answers)) {
                return self::STEP_PHONE;
            }
        }

        return $step;
    }

    /**
     * @param array<string, mixed> $answers
     */
    private static function resolveNoPathStep(string $step, array $answers): string
    {
        if ($step === self::STEP_WELCOME || $step === self::STEP_STATUS) {
            return $step;
        }
        if (!self::isReportedPaidComplete($answers)) {
            return self::STEP_CORRECTION;
        }
        if ($step === self::STEP_CORRECTION || $step === self::STEP_PAY_METHOD) {
            return $step;
        }
        if (!self::isPaidMethodComplete($answers)) {
            return self::STEP_PAY_METHOD;
        }

        $method = self::normalizePaidMethod($answers['paid_method'] ?? '');
        if ($method === 'cash') {
            return self::resolveCashPathStep($step, $answers);
        }

        if ($step === self::STEP_BANK_PROOF) {
            return self::STEP_BANK_PROOF;
        }

        $sendProof = strtolower(trim((string) ($answers['send_proof'] ?? '')));
        if ($sendProof === 'no' && $step === self::STEP_BANK_DATE) {
            return self::STEP_BANK_DATE;
        }
        if (self::needsCallback($answers)) {
            if ($step === self::STEP_CONTACT) {
                return self::STEP_CONTACT;
            }
            if ($step === self::STEP_PHONE || $step === self::STEP_DONE) {
                if (!self::isBookingComplete($answers)) {
                    return self::STEP_CONTACT;
                }
                if ($step === self::STEP_DONE && !self::isPhoneConfirmed($answers)) {
                    return self::STEP_PHONE;
                }

                return $step;
            }

            return self::STEP_CONTACT;
        }
        if ($sendProof === 'yes') {
            if ($step === self::STEP_DONE && self::isProofComplete($answers)) {
                return self::STEP_DONE;
            }

            return self::STEP_BANK_PROOF;
        }
        if ($sendProof === 'no') {
            if ($step === self::STEP_DONE && self::isPaidDateComplete($answers)) {
                return self::STEP_DONE;
            }

            return self::STEP_BANK_DATE;
        }

        return self::STEP_BANK_PROOF;
    }

    /**
     * Screen the donor should see when they tap Back.
     *
     * @param array<string, mixed> $answers
     * @return string Empty when there is no previous screen.
     */
    public static function previousStep(string $step, array $answers = []): string
    {
        $step = self::sanitizeStep($step);
        $status = (string) ($answers['status_correct'] ?? '');
        $method = self::normalizePaidMethod($answers['paid_method'] ?? '');
        $sendProof = strtolower(trim((string) ($answers['send_proof'] ?? '')));

        if ($step === self::STEP_STATUS) {
            return self::STEP_WELCOME;
        }
        if ($step === self::STEP_CORRECTION) {
            return self::STEP_STATUS;
        }
        if ($step === self::STEP_PAY_METHOD) {
            return self::STEP_CORRECTION;
        }
        if ($step === self::STEP_CASH_DETAIL) {
            return self::STEP_PAY_METHOD;
        }
        if ($step === self::STEP_BANK_PROOF) {
            return $method === 'cash' ? self::STEP_CASH_DETAIL : self::STEP_PAY_METHOD;
        }
        if ($step === self::STEP_BANK_DATE) {
            return self::STEP_BANK_PROOF;
        }
        if ($step === self::STEP_CONTACT) {
            return $status === 'no' ? self::STEP_BANK_DATE : self::STEP_STATUS;
        }
        if ($step === self::STEP_PHONE) {
            return self::STEP_CONTACT;
        }
        if ($step === self::STEP_DONE) {
            if ($status === 'yes' || self::needsCallback($answers)) {
                return self::STEP_PHONE;
            }
            if ($method === 'cash') {
                return ($sendProof === 'yes' || $sendProof === 'no')
                    ? self::STEP_BANK_PROOF
                    : self::STEP_CASH_DETAIL;
            }
            if ($sendProof === 'yes') {
                return self::STEP_BANK_PROOF;
            }
            if ($method === 'bank') {
                return self::STEP_BANK_DATE;
            }

            return self::STEP_PHONE;
        }

        return '';
    }

    /**
     * Cash: when/whom first, then optional photo, then thank-you.
     *
     * @param array<string, mixed> $answers
     */
    private static function resolveCashPathStep(string $step, array $answers): string
    {
        if ($step === self::STEP_CASH_DETAIL) {
            return self::STEP_CASH_DETAIL;
        }
        if (!self::isCashDetailComplete($answers)) {
            return self::STEP_CASH_DETAIL;
        }
        if ($step === self::STEP_BANK_PROOF) {
            return self::STEP_BANK_PROOF;
        }

        $sendProof = strtolower(trim((string) ($answers['send_proof'] ?? '')));
        if ($sendProof === 'yes') {
            if ($step === self::STEP_DONE && self::isProofComplete($answers)) {
                return self::STEP_DONE;
            }

            return self::STEP_BANK_PROOF;
        }
        if ($sendProof === 'no' && $step === self::STEP_DONE) {
            return self::STEP_DONE;
        }

        return self::STEP_BANK_PROOF;
    }

    /**
     * @param array<string, mixed> $answers
     */
    public static function isReportedPaidComplete(array $answers): bool
    {
        return self::normalizeMoney($answers['reported_paid'] ?? null) !== null;
    }

    /**
     * @param array<string, mixed> $answers
     */
    public static function isPaidMethodComplete(array $answers): bool
    {
        return self::normalizePaidMethod($answers['paid_method'] ?? null) !== null;
    }

    public static function normalizePaidMethod(mixed $value): ?string
    {
        $method = strtolower(trim((string) $value));
        if ($method === 'card') {
            $method = 'bank';
        }

        return in_array($method, ['cash', 'bank'], true) ? $method : null;
    }

    /**
     * @param array<string, mixed> $answers
     */
    public static function isCashDetailComplete(array $answers): bool
    {
        if (self::normalizePaidMethod($answers['paid_method'] ?? null) !== 'cash') {
            return false;
        }
        $remember = strtolower(trim((string) ($answers['cash_remember'] ?? '')));
        if (in_array($remember, ['yes', 'no'], true)) {
            return true;
        }
        $when = trim((string) ($answers['cash_when'] ?? ''));
        $whom = trim((string) ($answers['cash_whom'] ?? ''));

        return $when !== '' || $whom !== '';
    }

    /**
     * @param array<string, mixed> $answers
     */
    public static function isProofComplete(array $answers): bool
    {
        return self::normalizeProofPath($answers['proof_file'] ?? null) !== null;
    }

    /**
     * @param array<string, mixed> $answers
     */
    public static function isPaidDateComplete(array $answers): bool
    {
        return self::normalizeIsoDate($answers['paid_date'] ?? null) !== null;
    }

    /**
     * Bank transfer, no screenshot, and they do not remember the day.
     *
     * @param array<string, mixed> $answers
     */
    public static function needsCallback(array $answers): bool
    {
        return (string) ($answers['status_correct'] ?? '') === 'no'
            && self::normalizePaidMethod($answers['paid_method'] ?? null) === 'bank'
            && strtolower(trim((string) ($answers['send_proof'] ?? ''))) === 'no'
            && strtolower(trim((string) ($answers['paid_remember'] ?? ''))) === 'no';
    }

    public static function normalizeProofPath(mixed $value): ?string
    {
        $path = strtolower(trim((string) $value));
        if (preg_match('#^uploads/paying_proofs/[a-f0-9]{16}_[a-f0-9]{32}\.(jpe?g|png|webp|gif)$#', $path) !== 1) {
            return null;
        }

        return $path;
    }

    public static function proofExtensionForMime(string $mime): ?string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        return $map[strtolower(trim($mime))] ?? null;
    }

    public static function normalizeIsoDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * Accept pounds as 80, 80.5, 80.50, or £80.50. Zero is allowed.
     */
    public static function normalizeMoney(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value) || $value < 0 || $value > 1000000) {
                return null;
            }

            return number_format((float) $value, 2, '.', '');
        }

        $raw = strtolower(trim((string) $value));
        $raw = str_replace(['£', ',', ' '], '', $raw);
        if ($raw === '' || preg_match('/^\d+(\.\d{1,2})?$/', $raw) !== 1) {
            return null;
        }
        $amount = (float) $raw;
        if ($amount > 1000000) {
            return null;
        }

        return number_format($amount, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $answers
     */
    public static function isBookingComplete(array $answers): bool
    {
        $date = trim((string) ($answers['contact_date'] ?? ''));
        $time = trim((string) ($answers['contact_time'] ?? ''));
        $method = strtolower(trim((string) ($answers['contact_method'] ?? '')));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            && preg_match('/^\d{2}:\d{2}/', $time) === 1
            && in_array($method, ['whatsapp', 'phone'], true);
    }

    /**
     * @param array<string, mixed> $answers
     */
    public static function isPhoneConfirmed(array $answers): bool
    {
        $choice = strtolower(trim((string) ($answers['phone_correct'] ?? '')));
        if ($choice === 'yes') {
            return true;
        }
        if ($choice !== 'no') {
            return false;
        }

        return self::normalizeUkPhone((string) ($answers['contact_phone'] ?? '')) !== null;
    }

    /**
     * Accept 07XXXXXXXXX, +447XXXXXXXXX, 447XXXXXXXXX, or 00447XXXXXXXXX.
     */
    public static function normalizeUkPhone(string $raw): ?string
    {
        $compact = preg_replace('/[^\d+]/', '', $raw) ?? '';
        if (str_starts_with($compact, '0044')) {
            $compact = '+44' . substr($compact, 4);
        }
        if (str_starts_with($compact, '+44')) {
            $rest = substr($compact, 3);
            if (preg_match('/^7\d{9}$/', $rest) === 1) {
                return '0' . $rest;
            }

            return null;
        }
        if (preg_match('/^447\d{9}$/', $compact) === 1) {
            return '0' . substr($compact, 2);
        }
        if (preg_match('/^07\d{9}$/', $compact) === 1) {
            return $compact;
        }

        return null;
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
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    public static function mergeAnswers(array $existing, array $incoming): array
    {
        $merged = self::sanitizeAnswers($existing);
        foreach (self::sanitizeAnswers($incoming) as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * Re-apply a device draft after the donor left and came back.
     *
     * @param array{step?:string,answers?:mixed,revision?:mixed,saved_at?:mixed} $server
     * @param array{step?:string,answers?:mixed,revision?:mixed,saved_at?:mixed} $draft
     * @return array{step:string,answers:array<string,mixed>}
     */
    public static function applyDraft(array $server, array $draft): array
    {
        $serverAnswers = self::sanitizeAnswers($server['answers'] ?? []);
        $serverStep = self::resolveStep((string) ($server['step'] ?? self::STEP_WELCOME), $serverAnswers);
        $serverRevision = max(0, (int) ($server['revision'] ?? 0));

        if (!self::isUsableDraft($draft)) {
            return [
                'step' => $serverStep,
                'answers' => $serverAnswers,
            ];
        }

        $draftAnswers = self::sanitizeAnswers($draft['answers'] ?? []);
        $merged = self::mergeAnswers($serverAnswers, $draftAnswers);
        $draftRevision = max(0, (int) ($draft['revision'] ?? 0));
        if ($draftRevision < $serverRevision) {
            return [
                'step' => self::resolveStep($serverStep, $merged),
                'answers' => $merged,
            ];
        }

        return [
            'step' => self::preferStep((string) ($draft['step'] ?? self::STEP_WELCOME), $serverStep, $merged),
            'answers' => $merged,
        ];
    }

    /**
     * @param array<string, mixed> $draft
     */
    public static function isUsableDraft(array $draft): bool
    {
        $savedAt = $draft['saved_at'] ?? $draft['savedAt'] ?? null;
        if ($savedAt === null || $savedAt === '') {
            return true;
        }
        if (!is_numeric($savedAt)) {
            return false;
        }
        $stamp = (int) $savedAt;
        if ($stamp > 20000000000) {
            $stamp = (int) floor($stamp / 1000);
        }
        if ($stamp <= 0) {
            return false;
        }

        return (time() - $stamp) <= self::DRAFT_MAX_AGE_SECONDS;
    }

    /**
     * Keep the furthest valid step so a blank page-open cannot rewind a booking.
     *
     * @param array<string, mixed> $answers
     */
    public static function preferStep(string $requested, string $stored, array $answers): string
    {
        $requestedName = self::sanitizeStep($requested);
        $requested = self::resolveStep($requested, $answers);
        $stored = self::resolveStep($stored, $answers);
        if ($requestedName !== self::STEP_WELCOME) {
            return $requested;
        }
        $requestedIndex = array_search($requested, self::STEPS, true);
        $storedIndex = array_search($stored, self::STEPS, true);
        if ($requestedIndex === false) {
            $requestedIndex = 0;
        }
        if ($storedIndex === false) {
            $storedIndex = 0;
        }

        return self::STEPS[max($requestedIndex, $storedIndex)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeAnswersJson(mixed $json): array
    {
        if (is_array($json)) {
            return self::sanitizeAnswers($json);
        }
        if (!is_string($json) || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return self::sanitizeAnswers($decoded);
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

            $answers = self::decodeAnswersJson($row['answers_json'] ?? null);

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

    public static function markOpened(mysqli $db, string $token): void
    {
        $token = self::normalizeToken($token);
        if ($token === null) {
            return;
        }
        try {
            self::ensureColumns($db);
            $stmt = $db->prepare(
                'UPDATE campaign_paying_links
                 SET opened_at = COALESCE(opened_at, NOW())
                 WHERE token = ?'
            );
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('Paying open stamp failed: ' . $e->getMessage());
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
        $stored = self::readStored($db, $token);
        $merged = self::mergeAnswers($stored['answers'], $answers);
        $step = self::preferStep($step, $stored['step'], $merged);
        $json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
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
            if ($step === self::STEP_DONE) {
                self::syncDonorPhone($db, $token, $merged);
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
            'ALTER TABLE campaign_paying_links ADD COLUMN opened_at TIMESTAMP NULL DEFAULT NULL',
            'ALTER TABLE campaign_paying_links ADD COLUMN call_status VARCHAR(20) NULL DEFAULT NULL',
            "ALTER TABLE campaign_paying_links ADD COLUMN campaign_group VARCHAR(40) NOT NULL DEFAULT 'pledge_paying'",
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
     * @return array{step:string,answers:array<string,mixed>}
     */
    private static function readStored(mysqli $db, string $token): array
    {
        $empty = [
            'step' => self::STEP_WELCOME,
            'answers' => [],
        ];
        try {
            self::ensureColumns($db);
            $stmt = $db->prepare(
                'SELECT step, answers_json
                 FROM campaign_paying_links
                 WHERE token = ?
                 LIMIT 1'
            );
            if ($stmt === false) {
                return $empty;
            }
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!is_array($row)) {
                return $empty;
            }

            return [
                'step' => self::sanitizeStep((string) ($row['step'] ?? self::STEP_WELCOME)),
                'answers' => self::decodeAnswersJson($row['answers_json'] ?? null),
            ];
        } catch (Throwable $e) {
            error_log('Paying progress read failed: ' . $e->getMessage());

            return $empty;
        }
    }

    private static function sanitizeKnownAnswer(string $key, mixed $value): mixed
    {
        if ($key === 'contact_method') {
            $value = strtolower(trim((string) $value));

            return in_array($value, ['whatsapp', 'phone'], true) ? $value : '__reject__';
        }
        if ($key === 'contact_date' || $key === 'cash_when' || $key === 'paid_date') {
            return self::normalizeIsoDate($value) ?? '__reject__';
        }
        if ($key === 'contact_time') {
            $value = trim((string) $value);
            if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?$/', $value, $match) !== 1) {
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
        if ($key === 'phone_correct') {
            $value = strtolower(trim((string) $value));

            return in_array($value, ['yes', 'no'], true) ? $value : '__reject__';
        }
        if ($key === 'contact_phone') {
            $phone = self::normalizeUkPhone((string) $value);

            return $phone ?? '__reject__';
        }
        if ($key === 'reported_paid') {
            return self::normalizeMoney($value) ?? '__reject__';
        }
        if ($key === 'paid_method') {
            return self::normalizePaidMethod($value) ?? '__reject__';
        }
        if ($key === 'cash_remember' || $key === 'send_proof' || $key === 'paid_remember') {
            $value = strtolower(trim((string) $value));

            return in_array($value, ['yes', 'no'], true) ? $value : '__reject__';
        }
        if ($key === 'proof_file') {
            return self::normalizeProofPath($value) ?? '__reject__';
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

    private static function syncDonorPhone(mysqli $db, string $token, array $answers): void
    {
        if (($answers['phone_correct'] ?? '') !== 'no') {
            return;
        }
        $phone = self::normalizeUkPhone((string) ($answers['contact_phone'] ?? ''));
        if ($phone === null) {
            return;
        }
        try {
            $stmt = $db->prepare(
                'UPDATE donors d
                 INNER JOIN campaign_paying_links l ON l.donor_id = d.id
                 SET d.phone = ?
                 WHERE l.token = ?'
            );
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('ss', $phone, $token);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('Paying phone update failed: ' . $e->getMessage());
        }
    }

    private static function secret(): string
    {
        return (defined('DB_PASS') ? (string) DB_PASS : '') . '|paying-sync-v1';
    }
}
