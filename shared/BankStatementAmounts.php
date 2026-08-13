<?php

declare(strict_types=1);

/**
 * Saved Bank paid overrides for Excel rows on Bank Members.
 */
final class BankStatementAmounts
{
    public const MAX_PAID = 9999999.99;

    /**
     * Stable identity for an Excel person row. Does not include the amount,
     * so later edits still match the same row.
     */
    public static function rowKey(int $excelRow, string $name, string $ref): string
    {
        return hash('sha256', $excelRow . "\0" . $name . "\0" . $ref);
    }

    /**
     * Parse a posted Bank paid amount, or null if invalid.
     */
    public static function parsePaid(string $raw): ?float
    {
        $raw = trim($raw);
        if (preg_match('/^\d+(\.\d{1,2})?$/', $raw) !== 1) {
            return null;
        }
        $paid = round((float) $raw, 2);
        if (!is_finite($paid) || $paid < 0 || $paid > self::MAX_PAID) {
            return null;
        }

        return $paid;
    }

    /**
     * Create the override table if it does not exist.
     */
    public static function ensureTable(mysqli $db): void
    {
        $db->query(
            "CREATE TABLE IF NOT EXISTS bank_statement_amounts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                row_key CHAR(64) NOT NULL,
                excel_row INT NOT NULL DEFAULT 0,
                excel_name VARCHAR(255) NOT NULL DEFAULT '',
                excel_ref VARCHAR(32) NOT NULL DEFAULT '',
                original_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                bank_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                updated_by INT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_bank_amount_key (row_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * @return array<string, float>
     */
    public static function all(mysqli $db): array
    {
        self::ensureTable($db);
        $map = [];
        $result = $db->query('SELECT row_key, bank_paid FROM bank_statement_amounts');
        if (!$result) {
            return $map;
        }
        while ($row = $result->fetch_assoc()) {
            $key = (string) ($row['row_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $map[$key] = round((float) ($row['bank_paid'] ?? 0), 2);
        }

        return $map;
    }

    /**
     * Save or update the Bank paid amount for one Excel row.
     */
    public static function set(
        mysqli $db,
        string $rowKey,
        int $excelRow,
        string $name,
        string $ref,
        float $originalPaid,
        float $bankPaid,
        int $updatedBy
    ): bool {
        if (!preg_match('/^[a-f0-9]{64}$/', $rowKey)) {
            return false;
        }
        $expected = self::rowKey($excelRow, $name, $ref);
        if (!hash_equals($expected, $rowKey)) {
            return false;
        }
        if ($bankPaid < 0 || $bankPaid > self::MAX_PAID) {
            return false;
        }

        self::ensureTable($db);
        $stmt = $db->prepare(
            'INSERT INTO bank_statement_amounts
                (row_key, excel_row, excel_name, excel_ref, original_paid, bank_paid, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                bank_paid = VALUES(bank_paid),
                excel_row = VALUES(excel_row),
                excel_name = VALUES(excel_name),
                excel_ref = VALUES(excel_ref),
                updated_by = VALUES(updated_by)'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param(
            'sissddi',
            $rowKey,
            $excelRow,
            $name,
            $ref,
            $originalPaid,
            $bankPaid,
            $updatedBy
        );

        return $stmt->execute();
    }
}
