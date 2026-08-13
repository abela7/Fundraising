<?php

declare(strict_types=1);

require_once __DIR__ . '/SimpleXlsxReader.php';
require_once __DIR__ . '/BankStatementReviews.php';

/**
 * Relates donors-bank-data.xlsx rows to members. Excel list is the source.
 */
final class BankStatementMembers
{
    public const FILENAME = 'donors-bank-data.xlsx';

    public static function defaultPath(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . self::FILENAME;
    }

    public static function snapshotPath(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'donors-bank-rows.php';
    }

    /**
     * @return list<list<string>>
     */
    private static function loadGrid(string $path): array
    {
        if (is_file($path) && is_readable($path)) {
            try {
                $grid = SimpleXlsxReader::rows($path);
                if ($grid !== []) {
                    return $grid;
                }
            } catch (Throwable $e) {
                error_log('Bank statement Excel parse failed: ' . $e->getMessage());
            }
        }

        $snapshot = self::snapshotPath();
        if (!is_file($snapshot)) {
            return [];
        }
        $grid = require $snapshot;

        return is_array($grid) ? $grid : [];
    }

    /**
     * @return array{
     *     file_found: bool,
     *     error: string,
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, int|float>,
     *     donors_available?: bool
     * }
     */
    public static function relate(?mysqli $db = null, ?string $path = null): array
    {
        $path = $path ?? self::defaultPath();
        $emptyTotals = [
            'excel_members' => 0,
            'found' => 0,
            'same_amount' => 0,
            'amount_diff' => 0,
            'not_found' => 0,
            'excel_paid' => 0.0,
            'bank_lump' => 0.0,
        ];

        $grid = self::loadGrid($path);
        if ($grid === []) {
            return [
                'file_found' => is_file($path),
                'error' => 'Could not load the bank member list.',
                'rows' => [],
                'totals' => $emptyTotals,
            ];
        }

        $headers = [];
        foreach ($grid[0] as $header) {
            $headers[] = strtolower(trim((string) $header));
        }
        $nameCol = self::headerIndex($headers, ['donor name', 'name', 'donor']);
        $refCol = self::headerIndex($headers, ['reference number', 'reference', 'ref']);
        $paidCol = self::headerIndex($headers, ['total paid amount', 'amount paid', 'paid', 'total paid']);

        if ($nameCol === null && $refCol === null) {
            return [
                'file_found' => true,
                'error' => 'The Excel file needs a Donor name or Reference number column.',
                'rows' => [],
                'totals' => $emptyTotals,
            ];
        }

        $donors = [];
        if ($db instanceof mysqli) {
            try {
                $donors = self::loadDonors($db);
            } catch (Throwable $e) {
                error_log('Bank members donor lookup failed: ' . $e->getMessage());
            }
        }
        $byRef = [];
        $byName = [];
        foreach ($donors as $i => $donor) {
            foreach ($donor['references'] as $ref) {
                $byRef[$ref][] = $i;
            }
            $nameKey = self::normalizeName((string) $donor['name']);
            if ($nameKey !== '') {
                $byName[$nameKey][] = $i;
            }
        }

        $rows = [];
        $totals = $emptyTotals;
        $excelCount = count($grid);

        for ($r = 1; $r < $excelCount; $r++) {
            $line = $grid[$r];
            $excelName = $nameCol !== null ? trim((string) ($line[$nameCol] ?? '')) : '';
            $excelRefRaw = $refCol !== null ? trim((string) ($line[$refCol] ?? '')) : '';
            $excelPaid = $paidCol !== null ? self::parseAmount($line[$paidCol] ?? '') : 0.0;
            $excelRef = self::normalizeRef($excelRefRaw !== '' ? $excelRefRaw : $excelName);

            if ($excelName === '' && $excelRef === '' && $excelPaid <= 0) {
                continue;
            }

            $isBankAccount = self::isBankAccountRow($excelName);
            $donor = null;
            $matchBy = '';

            if ($isBankAccount) {
                $totals['bank_lump'] += $excelPaid;
            } else {
                if ($excelRef !== '' && isset($byRef[$excelRef])) {
                    $donor = $donors[$byRef[$excelRef][0]];
                    $matchBy = 'reference';
                }
                if ($donor === null && $excelName !== '') {
                    $nameKey = self::normalizeName($excelName);
                    $nameNoRef = self::normalizeName(self::stripRefTokens($excelName));
                    if ($nameKey !== '' && isset($byName[$nameKey])) {
                        $donor = $donors[$byName[$nameKey][0]];
                        $matchBy = 'name';
                    } elseif ($nameNoRef !== '' && $nameNoRef !== $nameKey && isset($byName[$nameNoRef])) {
                        $donor = $donors[$byName[$nameNoRef][0]];
                        $matchBy = 'name';
                    }
                }

                $status = 'not_found';
                $paidDiff = null;
                if (is_array($donor)) {
                    $paidDiff = round($excelPaid - (float) $donor['total_paid'], 2);
                    $status = abs($paidDiff) < 0.01 ? 'linked' : 'amount_diff';
                }

                $totals['excel_members']++;
                $totals['excel_paid'] += $excelPaid;
                if ($status === 'linked') {
                    $totals['found']++;
                    $totals['same_amount']++;
                } elseif ($status === 'amount_diff') {
                    $totals['found']++;
                    $totals['amount_diff']++;
                } else {
                    $totals['not_found']++;
                }

                $rows[] = [
                    'excel_row' => $r + 1,
                    'excel_name' => $excelName,
                    'excel_ref' => $excelRef,
                    'excel_paid' => $excelPaid,
                    'row_key' => BankStatementReviews::rowKey($r + 1, $excelName, $excelRef, $excelPaid),
                    'is_bank_account' => false,
                    'donor_id' => is_array($donor) ? (int) $donor['id'] : null,
                    'donor_name' => is_array($donor) ? (string) $donor['name'] : '',
                    'donor_paid' => is_array($donor) ? (float) $donor['total_paid'] : null,
                    'match_by' => $matchBy,
                    'status' => $status,
                    'paid_diff' => $paidDiff,
                ];
                continue;
            }

            $rows[] = [
                'excel_row' => $r + 1,
                'excel_name' => $excelName,
                'excel_ref' => $excelRef,
                'excel_paid' => $excelPaid,
                'is_bank_account' => true,
                'donor_id' => null,
                'donor_name' => '',
                'donor_paid' => null,
                'match_by' => '',
                'status' => 'bank_account',
                'paid_diff' => null,
            ];
        }

        return [
            'file_found' => true,
            'error' => '',
            'rows' => $rows,
            'totals' => $totals,
            'donors_available' => $donors !== [],
        ];
    }

    /**
     * @param list<string> $headers
     * @param list<string> $candidates
     */
    private static function headerIndex(array $headers, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            foreach ($headers as $i => $header) {
                if ($header === $candidate) {
                    return (int) $i;
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{id:int,name:string,total_paid:float,references:list<string>}>
     */
    private static function loadDonors(mysqli $db): array
    {
        $fromTable = self::loadDonorsTable($db);
        if ($fromTable !== []) {
            return $fromTable;
        }

        return self::loadMembersFromTransactions($db);
    }

    /**
     * @return list<array{id:int,name:string,total_paid:float,references:list<string>}>
     */
    private static function loadDonorsTable(mysqli $db): array
    {
        $table = $db->query("SHOW TABLES LIKE 'donors'");
        if (!$table || $table->num_rows === 0) {
            return [];
        }

        $result = $db->query('SELECT id, name, total_paid FROM donors ORDER BY name ASC');
        if (!$result) {
            return [];
        }

        $donors = [];
        while ($row = $result->fetch_assoc()) {
            $donors[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'total_paid' => (float) ($row['total_paid'] ?? 0),
                'references' => [],
            ];
        }
        if ($donors === []) {
            return [];
        }

        $refsByDonor = [];
        $pledgeCols = self::tableColumns($db, 'pledges');
        if (in_array('donor_id', $pledgeCols, true) && in_array('notes', $pledgeCols, true)) {
            $pledgeRes = $db->query(
                "SELECT donor_id, notes FROM pledges
                 WHERE donor_id IS NOT NULL AND notes IS NOT NULL AND notes <> ''"
            );
            if ($pledgeRes) {
                while ($row = $pledgeRes->fetch_assoc()) {
                    $ref = self::normalizeRef((string) ($row['notes'] ?? ''));
                    if ($ref !== '') {
                        $refsByDonor[(int) $row['donor_id']][$ref] = true;
                    }
                }
            }
        }

        $payCols = self::tableColumns($db, 'payments');
        $payRefCol = '';
        foreach (['reference', 'reference_number', 'transaction_ref'] as $candidate) {
            if (in_array($candidate, $payCols, true)) {
                $payRefCol = $candidate;
                break;
            }
        }
        if ($payRefCol !== '' && in_array('donor_id', $payCols, true)) {
            $payRes = $db->query(
                "SELECT donor_id, `{$payRefCol}` AS ref_val FROM payments
                 WHERE donor_id IS NOT NULL AND `{$payRefCol}` IS NOT NULL AND `{$payRefCol}` <> ''"
            );
            if ($payRes) {
                while ($row = $payRes->fetch_assoc()) {
                    $ref = self::normalizeRef((string) ($row['ref_val'] ?? ''));
                    if ($ref !== '') {
                        $refsByDonor[(int) $row['donor_id']][$ref] = true;
                    }
                }
            }
        }

        $ppCols = self::tableColumns($db, 'pledge_payments');
        if (in_array('donor_id', $ppCols, true) && in_array('reference_number', $ppCols, true)) {
            $ppRes = $db->query(
                "SELECT donor_id, reference_number FROM pledge_payments
                 WHERE donor_id IS NOT NULL AND reference_number IS NOT NULL AND reference_number <> ''"
            );
            if ($ppRes) {
                while ($row = $ppRes->fetch_assoc()) {
                    $ref = self::normalizeRef((string) ($row['reference_number'] ?? ''));
                    if ($ref !== '') {
                        $refsByDonor[(int) $row['donor_id']][$ref] = true;
                    }
                }
            }
        }

        foreach ($donors as &$donor) {
            $id = (int) $donor['id'];
            if (!isset($refsByDonor[$id])) {
                continue;
            }
            $refs = array_keys($refsByDonor[$id]);
            sort($refs);
            $donor['references'] = $refs;
        }
        unset($donor);

        return $donors;
    }

    /**
     * Build members from pledges and payments when the donors table is absent.
     *
     * @return list<array{id:int,name:string,total_paid:float,references:list<string>}>
     */
    private static function loadMembersFromTransactions(mysqli $db): array
    {
        $buckets = [];

        $pledgeCols = self::tableColumns($db, 'pledges');
        if (in_array('donor_name', $pledgeCols, true)) {
            $sql = 'SELECT donor_name, amount, type, status, notes FROM pledges WHERE status = \'approved\'';
            $pledgeRes = $db->query($sql);
            if ($pledgeRes) {
                while ($row = $pledgeRes->fetch_assoc()) {
                    $name = trim((string) ($row['donor_name'] ?? ''));
                    $ref = in_array('notes', $pledgeCols, true)
                        ? self::normalizeRef((string) ($row['notes'] ?? ''))
                        : '';
                    $paid = ((string) ($row['type'] ?? '') === 'paid') ? (float) ($row['amount'] ?? 0) : 0.0;
                    self::addTransactionMember($buckets, $name, $ref, $paid);
                }
            }
        }

        $payCols = self::tableColumns($db, 'payments');
        if (in_array('donor_name', $payCols, true)) {
            $payRefCol = '';
            foreach (['reference', 'reference_number', 'transaction_ref'] as $candidate) {
                if (in_array($candidate, $payCols, true)) {
                    $payRefCol = $candidate;
                    break;
                }
            }
            $refSelect = $payRefCol !== '' ? ", `{$payRefCol}` AS ref_val" : ', \'\' AS ref_val';
            $payRes = $db->query("SELECT donor_name, amount{$refSelect} FROM payments WHERE status = 'approved'");
            if ($payRes) {
                while ($row = $payRes->fetch_assoc()) {
                    $name = trim((string) ($row['donor_name'] ?? ''));
                    $ref = self::normalizeRef((string) ($row['ref_val'] ?? ''));
                    self::addTransactionMember($buckets, $name, $ref, (float) ($row['amount'] ?? 0));
                }
            }
        }

        $members = [];
        foreach ($buckets as $bucket) {
            $refs = array_keys($bucket['references']);
            sort($refs);
            $members[] = [
                'id' => 0,
                'name' => (string) $bucket['name'],
                'total_paid' => (float) $bucket['total_paid'],
                'references' => $refs,
            ];
        }

        return $members;
    }

    /**
     * @param array<string, array{name:string,total_paid:float,references:array<string,bool>}> $buckets
     */
    private static function addTransactionMember(array &$buckets, string $name, string $ref, float $paid): void
    {
        $key = self::normalizeName($name);
        if ($key === '') {
            $key = $ref !== '' ? 'ref:' . $ref : '';
        }
        if ($key === '') {
            return;
        }
        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'name' => $name !== '' ? $name : ('Ref ' . $ref),
                'total_paid' => 0.0,
                'references' => [],
            ];
        }
        $buckets[$key]['total_paid'] += $paid;
        if ($ref !== '') {
            $buckets[$key]['references'][$ref] = true;
        }
    }

    /**
     * @return list<string>
     */
    private static function tableColumns(mysqli $db, string $table): array
    {
        $allowed = ['donors', 'pledges', 'payments', 'pledge_payments'];
        if (!in_array($table, $allowed, true)) {
            return [];
        }
        $exists = $db->query("SHOW TABLES LIKE '{$table}'");
        if (!$exists || $exists->num_rows === 0) {
            return [];
        }
        $cols = [];
        $result = $db->query("SHOW COLUMNS FROM `{$table}`");
        if (!$result) {
            return [];
        }
        while ($row = $result->fetch_assoc()) {
            $cols[] = (string) ($row['Field'] ?? '');
        }

        return $cols;
    }

    public static function normalizeRef(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }
        if (preg_match('/^\d{1,4}$/', $s) === 1) {
            return str_pad($s, 4, '0', STR_PAD_LEFT);
        }
        if (preg_match('/\b(\d{4})\b/', $s, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    public static function normalizeName(string $name): string
    {
        $n = strtolower($name);
        $n = (string) preg_replace('/[^a-z0-9]+/', ' ', $n);
        $n = (string) preg_replace('/\s+/', ' ', $n);

        return trim($n);
    }

    public static function stripRefTokens(string $name): string
    {
        return trim((string) preg_replace('/\b\d{4}\b/', ' ', $name));
    }

    public static function isBankAccountRow(string $name): bool
    {
        return preg_match('/account ending/i', $name) === 1;
    }

    public static function parseAmount(mixed $value): float
    {
        $s = strtolower(trim((string) $value));
        if ($s === '' || in_array($s, ['nil', 'cancelled', '-', 'n/a'], true)) {
            return 0.0;
        }
        $n = (float) preg_replace('/[^0-9.\-]/', '', $s);

        return is_finite($n) ? $n : 0.0;
    }
}
