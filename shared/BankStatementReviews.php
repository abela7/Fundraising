<?php

declare(strict_types=1);

/**
 * Saved review status for bank-statement Excel rows.
 */
final class BankStatementReviews
{
    public const PENDING = 'pending';
    public const IDENTIFIED = 'identified';
    public const NOT_IDENTIFIED = 'not_identified';

    /**
     * @return list<string>
     */
    public static function allStatuses(): array
    {
        return [self::PENDING, self::IDENTIFIED, self::NOT_IDENTIFIED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::allStatuses(), true);
    }

    public static function rowKey(int $excelRow, string $name, string $ref, float $paid): string
    {
        return hash(
            'sha256',
            $excelRow . "\0" . $name . "\0" . $ref . "\0" . number_format($paid, 2, '.', '')
        );
    }

    public static function ensureTable(mysqli $db): void
    {
        $db->query(
            "CREATE TABLE IF NOT EXISTS bank_statement_reviews (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                row_key CHAR(64) NOT NULL,
                excel_row INT NOT NULL DEFAULT 0,
                excel_name VARCHAR(255) NOT NULL DEFAULT '',
                excel_ref VARCHAR(32) NOT NULL DEFAULT '',
                excel_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                review_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                updated_by INT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_bank_review_key (row_key),
                KEY idx_bank_review_status (review_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * @return array<string, string>
     */
    public static function all(mysqli $db): array
    {
        self::ensureTable($db);
        $map = [];
        $result = $db->query('SELECT row_key, review_status FROM bank_statement_reviews');
        if (!$result) {
            return $map;
        }
        while ($row = $result->fetch_assoc()) {
            $status = (string) ($row['review_status'] ?? '');
            if (!self::isValid($status)) {
                $status = self::PENDING;
            }
            $map[(string) ($row['row_key'] ?? '')] = $status;
        }

        return $map;
    }

    public static function set(
        mysqli $db,
        string $rowKey,
        string $status,
        int $excelRow,
        string $name,
        string $ref,
        float $paid,
        int $updatedBy
    ): bool {
        if (!self::isValid($status) || !preg_match('/^[a-f0-9]{64}$/', $rowKey)) {
            return false;
        }
        self::ensureTable($db);
        $stmt = $db->prepare(
            'INSERT INTO bank_statement_reviews
                (row_key, excel_row, excel_name, excel_ref, excel_paid, review_status, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                review_status = VALUES(review_status),
                excel_row = VALUES(excel_row),
                excel_name = VALUES(excel_name),
                excel_ref = VALUES(excel_ref),
                excel_paid = VALUES(excel_paid),
                updated_by = VALUES(updated_by)'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param(
            'sissdsi',
            $rowKey,
            $excelRow,
            $name,
            $ref,
            $paid,
            $status,
            $updatedBy
        );

        return $stmt->execute();
    }
}
