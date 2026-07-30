<?php

declare(strict_types=1);

/**
 * Durable queue for certificate images that must be rendered by PHP CLI.
 *
 * LiteSpeed cannot launch Chrome reliably on the production host. The web
 * request only enqueues work; a cPanel cron worker claims and processes it.
 */
class WhatsAppCertificateJobQueue
{
    private const MAX_ATTEMPTS = 3;
    private const STALE_LOCK_MINUTES = 15;

    /** @var mysqli */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
        $this->ensureTable();
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function enqueue(
        string $jobKey,
        int $operatorUserId,
        int $donorId,
        string $destinationPhone,
        string $failurePhone,
        string $certificateType,
        string $caption,
        array $metadata = []
    ): int {
        if ($operatorUserId <= 0 || $donorId <= 0) {
            throw new InvalidArgumentException('Operator and donor are required.');
        }
        if (!in_array($certificateType, ['progress', 'completed'], true)) {
            throw new InvalidArgumentException('Invalid certificate type.');
        }
        if (trim($destinationPhone) === '') {
            throw new InvalidArgumentException('Destination phone is required.');
        }
        if (trim($failurePhone) === '') {
            throw new InvalidArgumentException('Failure notification phone is required.');
        }

        $metadataJson = json_encode(
            $metadata,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $status = 'pending';
        $maxAttempts = self::MAX_ATTEMPTS;

        $stmt = $this->db->prepare("
            INSERT INTO whatsapp_certificate_jobs
                (job_key, operator_user_id, donor_id, destination_phone,
                 failure_phone, certificate_type, caption, metadata_json,
                 status, attempts, max_attempts, available_at, created_at,
                 updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
        ");
        $stmt->bind_param(
            'siissssssi',
            $jobKey,
            $operatorUserId,
            $donorId,
            $destinationPhone,
            $failurePhone,
            $certificateType,
            $caption,
            $metadataJson,
            $status,
            $maxAttempts
        );
        $stmt->execute();
        $jobId = (int)$this->db->insert_id;
        $stmt->close();

        if ($jobId <= 0) {
            throw new RuntimeException('Certificate job could not be queued.');
        }

        return $jobId;
    }

    /**
     * Atomically claim one due job.
     *
     * @return array<string,mixed>|null
     */
    public function claimNext(): ?array
    {
        $lockToken = bin2hex(random_bytes(16));

        $stmt = $this->db->prepare("
            UPDATE whatsapp_certificate_jobs
            SET status = 'processing',
                attempts = attempts + 1,
                lock_token = ?,
                locked_at = NOW(),
                updated_at = NOW()
            WHERE id = (
                SELECT id FROM (
                    SELECT id
                    FROM whatsapp_certificate_jobs
                    WHERE status = 'pending'
                      AND available_at <= NOW()
                      AND attempts < max_attempts
                    ORDER BY id ASC
                    LIMIT 1
                ) AS due_job
            )
              AND status = 'pending'
        ");
        $stmt->bind_param('s', $lockToken);
        $stmt->execute();
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();

        if (!$claimed) {
            return null;
        }

        $select = $this->db->prepare("
            SELECT id, operator_user_id, donor_id, destination_phone,
                   failure_phone, certificate_type, caption, status, attempts,
                   max_attempts, lock_token
            FROM whatsapp_certificate_jobs
            WHERE lock_token = ? AND status = 'processing'
            LIMIT 1
        ");
        $select->bind_param('s', $lockToken);
        $select->execute();
        $job = $select->get_result()->fetch_assoc();
        $select->close();

        return $job ?: null;
    }

    /**
     * @param array<string,mixed> $result
     */
    public function markCompleted(
        int $jobId,
        string $lockToken,
        array $result
    ): void {
        $resultJson = json_encode(
            $result,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
        $status = 'completed';

        $stmt = $this->db->prepare("
            UPDATE whatsapp_certificate_jobs
            SET status = ?,
                result_json = ?,
                caption = '',
                metadata_json = NULL,
                last_error = NULL,
                lock_token = NULL,
                locked_at = NULL,
                completed_at = NOW(),
                updated_at = NOW()
            WHERE id = ? AND lock_token = ? AND status = 'processing'
        ");
        $stmt->bind_param('ssis', $status, $resultJson, $jobId, $lockToken);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Record a failed attempt and return the resulting job status.
     */
    public function markFailed(
        int $jobId,
        string $lockToken,
        string $error
    ): string {
        $select = $this->db->prepare("
            SELECT attempts, max_attempts
            FROM whatsapp_certificate_jobs
            WHERE id = ? AND lock_token = ? AND status = 'processing'
            LIMIT 1
        ");
        $select->bind_param('is', $jobId, $lockToken);
        $select->execute();
        $row = $select->get_result()->fetch_assoc();
        $select->close();

        if (!$row) {
            throw new RuntimeException('Certificate job lock was lost.');
        }

        $attempts = (int)$row['attempts'];
        $maxAttempts = (int)$row['max_attempts'];
        $status = self::statusAfterFailure($attempts, $maxAttempts);
        $availableAt = date(
            'Y-m-d H:i:s',
            time() + self::retryDelaySeconds($attempts)
        );
        $sanitizedError = self::sanitizeFailure($error);
        $clearSensitiveData = $status === 'failed' ? 1 : 0;

        $stmt = $this->db->prepare("
            UPDATE whatsapp_certificate_jobs
            SET status = ?,
                available_at = ?,
                last_error = ?,
                caption = IF(? = 1, '', caption),
                metadata_json = IF(? = 1, NULL, metadata_json),
                lock_token = NULL,
                locked_at = NULL,
                updated_at = NOW()
            WHERE id = ? AND lock_token = ? AND status = 'processing'
        ");
        $stmt->bind_param(
            'sssiiis',
            $status,
            $availableAt,
            $sanitizedError,
            $clearSensitiveData,
            $clearSensitiveData,
            $jobId,
            $lockToken
        );
        $stmt->execute();
        $stmt->close();

        return $status;
    }

    public static function retryDelaySeconds(int $attempt): int
    {
        if ($attempt <= 1) {
            return 60;
        }
        if ($attempt === 2) {
            return 300;
        }

        return 900;
    }

    public static function statusAfterFailure(
        int $attempts,
        int $maxAttempts
    ): string {
        return $attempts >= $maxAttempts ? 'failed' : 'pending';
    }

    /**
     * Resolve where terminal job failures should be reported.
     *
     * @param array<string,mixed> $job
     */
    public static function failurePhone(array $job): string
    {
        return trim((string)($job['failure_phone'] ?? ''));
    }

    public static function sanitizeFailure(string $value): string
    {
        $value = preg_replace(
            '/(view_token|token|api[_-]?key|authorization|instance|password|'
            . 'client[_-]?secret|private[_-]?key|secret)[=:]\s*[^\s&]+/i',
            '$1=[redacted]',
            $value
        ) ?? '';
        $value = preg_replace(
            '/bearer\s+[a-z0-9._~+\/=-]{12,}/i',
            'Bearer [redacted]',
            $value
        ) ?? '';
        $value = preg_replace(
            '#/(?:home|opt|snap|proc|run|srv|etc|var|tmp|usr|app|web)[^\s:]*#',
            '[server-path]',
            $value
        ) ?? '';
        $value = preg_replace('#https?://[^\s]+#i', '[url-redacted]', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return mb_substr($value, 0, 1500);
    }

    /**
     * Release interrupted jobs and return jobs that exhausted all attempts.
     *
     * @return list<array<string,mixed>>
     */
    public function recoverStaleJobs(): array
    {
        $minutes = self::STALE_LOCK_MINUTES;
        $result = $this->db->query("
            SELECT id, operator_user_id, donor_id, destination_phone,
                   failure_phone, certificate_type, attempts, max_attempts,
                   lock_token, available_at
            FROM whatsapp_certificate_jobs
            WHERE status = 'processing'
              AND locked_at < DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)
        ");

        $failedJobs = [];
        while ($job = $result->fetch_assoc()) {
            $status = self::statusAfterFailure(
                (int)$job['attempts'],
                (int)$job['max_attempts']
            );
            $availableAt = $status === 'pending'
                ? date(
                    'Y-m-d H:i:s',
                    time() + self::retryDelaySeconds((int)$job['attempts'])
                )
                : (string)$job['available_at'];
            $error = 'CLI worker stopped before completing the job.';
            $clearSensitiveData = $status === 'failed' ? 1 : 0;
            $jobId = (int)$job['id'];
            $lockToken = (string)$job['lock_token'];

            $update = $this->db->prepare("
                UPDATE whatsapp_certificate_jobs
                SET status = ?,
                    available_at = ?,
                    last_error = ?,
                    caption = IF(? = 1, '', caption),
                    metadata_json = IF(? = 1, NULL, metadata_json),
                    lock_token = NULL,
                    locked_at = NULL,
                    completed_at = IF(? = 1, NOW(), completed_at),
                    updated_at = NOW()
                WHERE id = ? AND lock_token = ? AND status = 'processing'
            ");
            $update->bind_param(
                'sssiiiis',
                $status,
                $availableAt,
                $error,
                $clearSensitiveData,
                $clearSensitiveData,
                $clearSensitiveData,
                $jobId,
                $lockToken
            );
            $update->execute();
            $updated = $update->affected_rows === 1;
            $update->close();

            if ($updated && $status === 'failed') {
                $job['last_error'] = $error;
                $failedJobs[] = $job;
            }
        }

        return $failedJobs;
    }

    private function ensureTable(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $this->db->query("
            CREATE TABLE IF NOT EXISTS whatsapp_certificate_jobs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job_key VARCHAR(160) NOT NULL,
                operator_user_id INT NOT NULL,
                donor_id INT NOT NULL,
                destination_phone VARCHAR(40) NOT NULL,
                failure_phone VARCHAR(40) NULL,
                certificate_type VARCHAR(20) NOT NULL,
                caption MEDIUMTEXT NOT NULL,
                metadata_json TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
                available_at DATETIME NOT NULL,
                lock_token VARCHAR(64) NULL,
                locked_at DATETIME NULL,
                last_error TEXT NULL,
                result_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                completed_at DATETIME NULL,
                UNIQUE KEY uq_whatsapp_certificate_job_key (job_key),
                INDEX idx_whatsapp_certificate_due
                    (status, available_at, attempts),
                INDEX idx_whatsapp_certificate_lock (lock_token)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        $failurePhoneColumn = $this->db->query("
            SHOW COLUMNS FROM whatsapp_certificate_jobs
            LIKE 'failure_phone'
        ");
        if (!$failurePhoneColumn || $failurePhoneColumn->num_rows === 0) {
            try {
                $this->db->query("
                    ALTER TABLE whatsapp_certificate_jobs
                    ADD COLUMN failure_phone VARCHAR(40) NULL
                    AFTER destination_phone
                ");
            } catch (Throwable $e) {
                $recheck = $this->db->query("
                    SHOW COLUMNS FROM whatsapp_certificate_jobs
                    LIKE 'failure_phone'
                ");
                if (!$recheck || $recheck->num_rows === 0) {
                    throw $e;
                }
            }
        }
        $ensured = true;
    }
}
