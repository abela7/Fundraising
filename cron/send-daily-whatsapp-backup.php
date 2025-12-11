<?php
/**
 * Daily WhatsApp Excel Backup
 *
 * Generates the donor-summary "All Donations Backup" Excel and sends it via
 * WhatsApp (UltraMsg) to a fixed phone number as an offsite backup.
 *
 * Run daily via cron / task scheduler:
 *   php C:\xampp\htdocs\Fundraising\cron\send-daily-whatsapp-backup.php
 *
 * Requirements:
 * - WhatsApp provider configured in DB (whatsapp_providers, provider_name='ultramsg')
 * - A public base URL so UltraMsg can fetch the file:
 *     set FUNDRAISING_PUBLIC_URL="https://your-domain.com/Fundraising"
 *
 * Optional env overrides:
 * - WHATSAPP_DAILY_BACKUP_TO (default: 07360436171)
 */
declare(strict_types=1);

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Access denied. CLI only.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/UltraMsgService.php';

/**
 * Log to file and stdout.
 */
function cron_log(string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    $logFile = __DIR__ . '/../logs/whatsapp-daily-backup-' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    echo $logMessage;
}

/**
 * Basic HTML escape.
 */
function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

try {
    $db = db();

    $to = getenv('WHATSAPP_DAILY_BACKUP_TO') ?: '07360436171';
    $publicBaseUrl = rtrim((string)(getenv('FUNDRAISING_PUBLIC_URL') ?: ''), '/');

    if ($publicBaseUrl === '') {
        cron_log('ERROR: FUNDRAISING_PUBLIC_URL is not set. Cannot build a public file URL for UltraMsg.');
        exit(1);
    }

    $service = UltraMsgService::fromDatabase($db);
    if (!$service) {
        cron_log('ERROR: WhatsApp provider (UltraMsg) is not configured in database.');
        exit(1);
    }

    // Generate "all time" donor summary
    $fromDate = '1970-01-01 00:00:00';
    $toDate = '2999-12-31 23:59:59';

    // Currency for headers (fallback GBP)
    $settings = $db->query("SELECT currency_code FROM settings WHERE id=1")->fetch_assoc() ?: ['currency_code' => 'GBP'];
    $currency = (string)($settings['currency_code'] ?? 'GBP');

    // Detect optional columns
    $hasDonorsTable = $db->query("SHOW TABLES LIKE 'donors'")->num_rows > 0;
    $pledgesHasDonorId = $db->query("SHOW COLUMNS FROM pledges LIKE 'donor_id'")->num_rows > 0;
    $paymentsHasDonorId = $db->query("SHOW COLUMNS FROM payments LIKE 'donor_id'")->num_rows > 0;
    $paymentsHasReceivedAt = $db->query("SHOW COLUMNS FROM payments LIKE 'received_at'")->num_rows > 0;
    $hasPledgePaymentsTable = $db->query("SHOW TABLES LIKE 'pledge_payments'")->num_rows > 0;
    $hasPledgePayments = $hasPledgePaymentsTable;
    $ppHasReferenceNumber = $hasPledgePaymentsTable ? ($db->query("SHOW COLUMNS FROM pledge_payments LIKE 'reference_number'")->num_rows > 0) : false;

    // Payment reference column can differ across installs
    $paymentRefCol = 'reference';
    $paymentColumnsRes = $db->query("SHOW COLUMNS FROM payments");
    if ($paymentColumnsRes) {
        $paymentCols = [];
        while ($col = $paymentColumnsRes->fetch_assoc()) {
            $paymentCols[] = $col['Field'];
        }
        if (in_array('transaction_ref', $paymentCols, true)) {
            $paymentRefCol = 'transaction_ref';
        }
    }

    // Load donors (base list)
    $donors = [];
    if ($hasDonorsTable) {
        $donorRes = $db->query("SELECT id, name, phone FROM donors ORDER BY name ASC, id ASC");
        while ($d = $donorRes->fetch_assoc()) {
            $id = (int)$d['id'];
            $donors[$id] = [
                'donor_id' => $id,
                'ref' => '',
                'name' => (string)($d['name'] ?? ''),
                'phone' => (string)($d['phone'] ?? ''),
                'pledge' => 0.0,
                'paid' => 0.0,
            ];
        }
    }

    // Reference extraction (4 digits)
    $extractRef = static function (string $text): string {
        if (preg_match('/\b(\d{4})\b/', $text, $m)) {
            return $m[1];
        }
        return '';
    };

    $refByDonorId = [];
    $refByPhone = [];

    // Prefer reference from pledge notes
    $refPledgeStmt = $db->prepare("
        SELECT donor_id, donor_phone, notes
        FROM pledges
        WHERE status='approved'
          AND notes REGEXP '[0-9]{4}'
          AND created_at BETWEEN ? AND ?
        ORDER BY created_at DESC
    ");
    $refPledgeStmt->bind_param('ss', $fromDate, $toDate);
    $refPledgeStmt->execute();
    $refPledgeRes = $refPledgeStmt->get_result();
    while ($r = $refPledgeRes->fetch_assoc()) {
        $ref = $extractRef((string)($r['notes'] ?? ''));
        if ($ref === '') {
            continue;
        }
        $did = isset($r['donor_id']) ? (int)$r['donor_id'] : 0;
        if ($did > 0 && !isset($refByDonorId[$did])) {
            $refByDonorId[$did] = $ref;
        }
        $phone = trim((string)($r['donor_phone'] ?? ''));
        if ($phone !== '' && !isset($refByPhone[$phone])) {
            $refByPhone[$phone] = $ref;
        }
    }
    $refPledgeStmt->close();

    // Fallback: pledge_payments.reference_number
    if ($hasPledgePayments && $ppHasReferenceNumber) {
        $refPPStmt = $db->prepare("
            SELECT donor_id, reference_number
            FROM pledge_payments
            WHERE status='confirmed'
              AND reference_number REGEXP '[0-9]{4}'
              AND created_at BETWEEN ? AND ?
            ORDER BY created_at DESC
        ");
        $refPPStmt->bind_param('ss', $fromDate, $toDate);
        $refPPStmt->execute();
        $refPPRes = $refPPStmt->get_result();
        while ($r = $refPPRes->fetch_assoc()) {
            $did = (int)($r['donor_id'] ?? 0);
            $ref = $extractRef((string)($r['reference_number'] ?? ''));
            if ($did > 0 && $ref !== '' && !isset($refByDonorId[$did])) {
                $refByDonorId[$did] = $ref;
            }
        }
        $refPPStmt->close();
    }

    // Fallback: payments reference column
    $payDateCol = $paymentsHasReceivedAt ? 'received_at' : 'created_at';
    $refPayStmt = $db->prepare("
        SELECT donor_id, donor_phone, {$paymentRefCol} AS ref
        FROM payments
        WHERE status='approved'
          AND {$paymentRefCol} REGEXP '[0-9]{4}'
          AND {$payDateCol} BETWEEN ? AND ?
        ORDER BY {$payDateCol} DESC
    ");
    $refPayStmt->bind_param('ss', $fromDate, $toDate);
    $refPayStmt->execute();
    $refPayRes = $refPayStmt->get_result();
    while ($r = $refPayRes->fetch_assoc()) {
        $ref = $extractRef((string)($r['ref'] ?? ''));
        if ($ref === '') {
            continue;
        }
        $did = isset($r['donor_id']) ? (int)$r['donor_id'] : 0;
        if ($did > 0 && !isset($refByDonorId[$did])) {
            $refByDonorId[$did] = $ref;
        }
        $phone = trim((string)($r['donor_phone'] ?? ''));
        if ($phone !== '' && !isset($refByPhone[$phone])) {
            $refByPhone[$phone] = $ref;
        }
    }
    $refPayStmt->close();

    // Aggregate pledges by donor_id (approved)
    if ($pledgesHasDonorId) {
        $plStmt = $db->prepare("
            SELECT donor_id, COALESCE(SUM(amount),0) AS total
            FROM pledges
            WHERE status='approved' AND donor_id IS NOT NULL AND donor_id <> 0
              AND created_at BETWEEN ? AND ?
            GROUP BY donor_id
        ");
        $plStmt->bind_param('ss', $fromDate, $toDate);
        $plStmt->execute();
        $plRes = $plStmt->get_result();
        while ($r = $plRes->fetch_assoc()) {
            $did = (int)$r['donor_id'];
            $amt = (float)$r['total'];
            if (!isset($donors[$did])) {
                $donors[$did] = ['donor_id' => $did, 'ref' => '', 'name' => '', 'phone' => '', 'pledge' => 0.0, 'paid' => 0.0];
            }
            $donors[$did]['pledge'] += $amt;
        }
        $plStmt->close();
    }

    // Aggregate payments by donor_id (approved)
    if ($paymentsHasDonorId) {
        $dateCol = $paymentsHasReceivedAt ? 'received_at' : 'created_at';
        $payStmt = $db->prepare("
            SELECT donor_id, COALESCE(SUM(amount),0) AS total
            FROM payments
            WHERE status='approved' AND donor_id IS NOT NULL AND donor_id <> 0
              AND {$dateCol} BETWEEN ? AND ?
            GROUP BY donor_id
        ");
        $payStmt->bind_param('ss', $fromDate, $toDate);
        $payStmt->execute();
        $payRes = $payStmt->get_result();
        while ($r = $payRes->fetch_assoc()) {
            $did = (int)$r['donor_id'];
            $amt = (float)$r['total'];
            if (!isset($donors[$did])) {
                $donors[$did] = ['donor_id' => $did, 'ref' => '', 'name' => '', 'phone' => '', 'pledge' => 0.0, 'paid' => 0.0];
            }
            $donors[$did]['paid'] += $amt;
        }
        $payStmt->close();
    }

    // Aggregate pledge_payments by donor_id (confirmed)
    if ($hasPledgePayments) {
        $ppStmt = $db->prepare("
            SELECT donor_id, COALESCE(SUM(amount),0) AS total
            FROM pledge_payments
            WHERE status='confirmed' AND donor_id IS NOT NULL AND donor_id <> 0
              AND created_at BETWEEN ? AND ?
            GROUP BY donor_id
        ");
        $ppStmt->bind_param('ss', $fromDate, $toDate);
        $ppStmt->execute();
        $ppRes = $ppStmt->get_result();
        while ($r = $ppRes->fetch_assoc()) {
            $did = (int)$r['donor_id'];
            $amt = (float)$r['total'];
            if (!isset($donors[$did])) {
                $donors[$did] = ['donor_id' => $did, 'ref' => '', 'name' => '', 'phone' => '', 'pledge' => 0.0, 'paid' => 0.0];
            }
            $donors[$did]['paid'] += $amt;
        }
        $ppStmt->close();
    }

    // Attach references (prefer donor_id match, then phone match, then padded donor_id)
    foreach ($donors as $did => &$d) {
        $ref = $refByDonorId[$did] ?? '';
        if ($ref === '') {
            $phone = trim((string)($d['phone'] ?? ''));
            if ($phone !== '' && isset($refByPhone[$phone])) {
                $ref = $refByPhone[$phone];
            }
        }
        if ($ref === '') {
            $ref = str_pad((string)$did, 4, '0', STR_PAD_LEFT);
        } else {
            $ref = str_pad($ref, 4, '0', STR_PAD_LEFT);
        }
        $d['ref'] = $ref;
    }
    unset($d);

    $rows = array_values($donors);
    usort($rows, static function (array $a, array $b): int {
        $ap = (float)($a['pledge'] ?? 0);
        $bp = (float)($b['pledge'] ?? 0);
        if (($ap > 0) !== ($bp > 0)) {
            return ($ap > 0) ? -1 : 1;
        }
        $an = strtolower(trim((string)($a['name'] ?? '')));
        $bn = strtolower(trim((string)($b['name'] ?? '')));
        if ($an !== $bn) return $an <=> $bn;
        $aph = strtolower(trim((string)($a['phone'] ?? '')));
        $bph = strtolower(trim((string)($b['phone'] ?? '')));
        return $aph <=> $bph;
    });

    $totalPledge = 0.0;
    $totalPaid = 0.0;
    $totalBalance = 0.0;

    // Build Excel (HTML) content
    $generatedAt = date('Y-m-d H:i:s');
    $html = '';
    $html .= '<!DOCTYPE html>';
    $html .= '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    $html .= '<head><meta charset="UTF-8"><style>';
    $html .= 'table { border-collapse: collapse; width: 100%; }';
    $html .= 'th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }';
    $html .= 'th { background-color: #f2f2f2; font-weight: bold; }';
    $html .= '.number { text-align: right; }';
    $html .= '.text { mso-number-format:"\@"; }';
    $html .= '</style></head><body>';

    $html .= '<table>';
    $html .= '<tr><th colspan="7">All Donations Backup (Daily WhatsApp)</th></tr>';
    $html .= '<tr><td colspan="7"><strong>Generated at:</strong> ' . esc($generatedAt) . '</td></tr>';
    $html .= '<tr><td colspan="7">&nbsp;</td></tr>';
    $html .= '<tr>';
    $html .= '<th>#</th>';
    $html .= '<th>Reference</th>';
    $html .= '<th>Name</th>';
    $html .= '<th>Phone</th>';
    $html .= '<th class="number">Pledge (' . esc($currency) . ')</th>';
    $html .= '<th class="number">Paid (' . esc($currency) . ')</th>';
    $html .= '<th class="number">Balance (' . esc($currency) . ')</th>';
    $html .= '</tr>';

    $i = 1;
    foreach ($rows as $row) {
        $pledge = (float)($row['pledge'] ?? 0);
        $paid = (float)($row['paid'] ?? 0);
        $balance = max($pledge - $paid, 0);

        $totalPledge += $pledge;
        $totalPaid += $paid;
        $totalBalance += $balance;

        $html .= '<tr>';
        $html .= '<td>' . $i++ . '</td>';
        $html .= '<td class="text">' . esc((string)($row['ref'] ?? '')) . '</td>';
        $html .= '<td>' . esc((string)($row['name'] ?? '')) . '</td>';
        $html .= '<td>' . esc((string)($row['phone'] ?? '')) . '</td>';
        $html .= '<td class="number">' . number_format($pledge, 2) . '</td>';
        $html .= '<td class="number">' . number_format($paid, 2) . '</td>';
        $html .= '<td class="number">' . number_format($balance, 2) . '</td>';
        $html .= '</tr>';
    }

    $grandTotal = $totalPledge + $totalPaid;
    $html .= '<tr style="background-color: #f8f9fa; font-weight: bold;">';
    $html .= '<td colspan="4" style="text-align:right;"><strong>Totals:</strong></td>';
    $html .= '<td class="number"><strong>' . number_format($totalPledge, 2) . '</strong></td>';
    $html .= '<td class="number"><strong>' . number_format($totalPaid, 2) . '</strong></td>';
    $html .= '<td class="number"><strong>' . number_format($totalBalance, 2) . '</strong></td>';
    $html .= '</tr>';
    $html .= '<tr style="background-color: #eef2ff; font-weight: bold;">';
    $html .= '<td colspan="7" style="text-align:right;"><strong>Grand Total (Pledge + Paid):</strong> ' . number_format($grandTotal, 2) . '</td>';
    $html .= '</tr>';

    $html .= '</table>';
    $html .= '</body></html>';

    // Save under uploads/whatsapp/backups/YYYY/m/
    $subdir = 'uploads/whatsapp/backups/' . date('Y/m');
    $absDir = __DIR__ . '/../' . $subdir;
    if (!is_dir($absDir)) {
        if (!mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            throw new RuntimeException('Failed to create backup directory: ' . $absDir);
        }
    }

    $filename = 'daily-backup-' . date('Y-m-d') . '.xls';
    $absPath = $absDir . '/' . $filename;
    $relPath = $subdir . '/' . $filename;

    if (file_put_contents($absPath, $html) === false) {
        throw new RuntimeException('Failed to write backup file: ' . $absPath);
    }

    $publicUrl = $publicBaseUrl . '/' . $relPath;
    $caption = 'Daily fundraising backup - ' . date('Y-m-d') . ' (Pledge/Paid/Balance)';

    cron_log("INFO: Sending WhatsApp document to {$to}: {$publicUrl}");
    $result = $service->sendDocument($to, $publicUrl, $filename, $caption, ['log' => true, 'source_type' => 'daily_backup']);

    if (!($result['success'] ?? false)) {
        $err = $result['error'] ?? 'Unknown error';
        cron_log('ERROR: WhatsApp send failed: ' . (is_string($err) ? $err : json_encode($err)));
        exit(1);
    }

    cron_log('OK: Backup sent. message_id=' . (($result['message_id'] ?? '') !== '' ? (string)$result['message_id'] : 'n/a'));
    exit(0);
} catch (Throwable $e) {
    cron_log('FATAL: ' . $e->getMessage());
    exit(1);
}

