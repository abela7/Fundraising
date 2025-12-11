<?php
require_once '../../config/db.php';
require_once '../../shared/auth.php';
require_once '../../shared/csrf.php';
require_once '../../shared/url.php';
require_once '../../services/UltraMsgService.php';
require_login();
require_admin();

$current_user = current_user();
$db = db();

// Settings and live stats (dynamic, no counters)
$settings = $db->query("SELECT target_amount, currency_code FROM settings WHERE id=1")->fetch_assoc() ?: ['target_amount'=>0,'currency_code'=>'GBP'];
$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');

// Use centralized FinancialCalculator for consistency
require_once __DIR__ . '/../../shared/FinancialCalculator.php';

$calculator = new FinancialCalculator();
$totals = $calculator->getTotals();

$paidTotal = $totals['total_paid'];
$pledgedTotal = $totals['outstanding_pledged'];
$grandTotal = $totals['grand_total'];
$hasPledgePayments = $totals['has_pledge_payments'];

// Donor count: distinct donors across approved pledges, approved payments, and pledge_payments
$donorUnionSql = "SELECT DISTINCT CONCAT(COALESCE(donor_name,''),'|',COALESCE(donor_phone,''),'|',COALESCE(donor_email,'')) ident FROM payments WHERE status='approved'
  UNION
  SELECT DISTINCT CONCAT(COALESCE(donor_name,''),'|',COALESCE(donor_phone,''),'|',COALESCE(donor_email,'')) ident FROM pledges WHERE status='approved'";

if ($hasPledgePayments) {
    $donorUnionSql .= "
  UNION
  SELECT DISTINCT CONCAT(COALESCE(d.name,''),'|',COALESCE(d.phone,''),'|',COALESCE(d.email,'')) ident 
  FROM pledge_payments pp 
  LEFT JOIN donors d ON pp.donor_id = d.id 
  WHERE pp.status='confirmed'";
}

$donorCountRow = $db->query("SELECT COUNT(*) AS c FROM ($donorUnionSql) t")->fetch_assoc();
$donorCount = (int)($donorCountRow['c'] ?? 0);

// Average donation (Total Paid / Payment Count)
// Count all payment transactions (instant + pledge payments)
$paymentCount = $db->query("SELECT COUNT(*) AS c FROM payments WHERE status='approved'")->fetch_assoc();
$totalPaymentCount = (int)($paymentCount['c'] ?? 0);

if ($hasPledgePayments) {
    $ppCount = $db->query("SELECT COUNT(*) AS c FROM pledge_payments WHERE status='confirmed'")->fetch_assoc();
    $totalPaymentCount += (int)($ppCount['c'] ?? 0);
}

$avgDonation = $totalPaymentCount > 0 ? ($paidTotal / $totalPaymentCount) : 0;

$progress = ($settings['target_amount'] > 0) ? round(($grandTotal / (float)$settings['target_amount']) * 100) : 0;

// Date range helper
function resolve_range(mysqli $db): array {
  $range = $_GET['date'] ?? 'month';
  $from = $_GET['from'] ?? '';
  $to   = $_GET['to'] ?? '';
  $now = new DateTime('now');
  switch ($range) {
    case 'today':  $start = (clone $now)->setTime(0,0,0); $end = (clone $now)->setTime(23,59,59); break;
    case 'week':   $start = (clone $now)->modify('monday this week')->setTime(0,0,0); $end = (clone $now)->modify('sunday this week')->setTime(23,59,59); break;
    case 'quarter': $q = floor(((int)$now->format('n')-1)/3)+1; $start = new DateTime($now->format('Y').'-'.(1+($q-1)*3).'-01 00:00:00'); $end = (clone $start)->modify('+3 months -1 second'); break;
    case 'year':   $start = new DateTime($now->format('Y').'-01-01 00:00:00'); $end = new DateTime($now->format('Y').'-12-31 23:59:59'); break;
    case 'custom': $start = DateTime::createFromFormat('Y-m-d', $from) ?: (clone $now); $start->setTime(0,0,0); $end = DateTime::createFromFormat('Y-m-d', $to) ?: (clone $now); $end->setTime(23,59,59); break;
    default:       $start = new DateTime(date('Y-m-01 00:00:00')); $end = (clone $start)->modify('+1 month -1 second'); break; // month
  }
  return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

/**
 * Build donor-summary backup rows (1 row per donor/reference).
 *
 * @return array{rows: array<int, array<string, mixed>>, totals: array{pledge: float, paid: float, balance: float, grand: float}}
 */
function build_donor_backup_summary(mysqli $db, string $fromDate, string $toDate, bool $hasPledgePayments): array {
  $hasDonorsTable = $db->query("SHOW TABLES LIKE 'donors'")->num_rows > 0;
  $pledgesHasDonorId = $db->query("SHOW COLUMNS FROM pledges LIKE 'donor_id'")->num_rows > 0;
  $paymentsHasDonorId = $db->query("SHOW COLUMNS FROM payments LIKE 'donor_id'")->num_rows > 0;
  $paymentsHasReceivedAt = $db->query("SHOW COLUMNS FROM payments LIKE 'received_at'")->num_rows > 0;
  $hasPledgePaymentsTable = $db->query("SHOW TABLES LIKE 'pledge_payments'")->num_rows > 0;
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

  $donors = [];
  if ($hasDonorsTable) {
    $donorRes = $db->query("SELECT id, name, phone FROM donors ORDER BY name ASC, id ASC");
    while ($d = $donorRes->fetch_assoc()) {
      $id = (int)$d['id'];
      $donors[$id] = [
        'donor_id' => $id,
        'ref' => '',
        'name' => $d['name'] ?? '',
        'phone' => $d['phone'] ?? '',
        'pledge' => 0.0,
        'paid' => 0.0,
      ];
    }
  }

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
    if ($ref === '') continue;
    $did = isset($r['donor_id']) ? (int)$r['donor_id'] : 0;
    if ($did > 0 && !isset($refByDonorId[$did])) $refByDonorId[$did] = $ref;
    $phone = trim((string)($r['donor_phone'] ?? ''));
    if ($phone !== '' && !isset($refByPhone[$phone])) $refByPhone[$phone] = $ref;
  }
  $refPledgeStmt->close();

  // Fallback: pledge_payments.reference_number
  if ($hasPledgePayments && $hasPledgePaymentsTable && $ppHasReferenceNumber) {
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
      if ($did > 0 && $ref !== '' && !isset($refByDonorId[$did])) $refByDonorId[$did] = $ref;
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
    if ($ref === '') continue;
    $did = isset($r['donor_id']) ? (int)$r['donor_id'] : 0;
    if ($did > 0 && !isset($refByDonorId[$did])) $refByDonorId[$did] = $ref;
    $phone = trim((string)($r['donor_phone'] ?? ''));
    if ($phone !== '' && !isset($refByPhone[$phone])) $refByPhone[$phone] = $ref;
  }
  $refPayStmt->close();

  // Pledges
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
      if (!isset($donors[$did])) $donors[$did] = ['donor_id'=>$did,'ref'=>'','name'=>'','phone'=>'','pledge'=>0.0,'paid'=>0.0];
      $donors[$did]['pledge'] += $amt;
    }
    $plStmt->close();
  }

  // Payments
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
      if (!isset($donors[$did])) $donors[$did] = ['donor_id'=>$did,'ref'=>'','name'=>'','phone'=>'','pledge'=>0.0,'paid'=>0.0];
      $donors[$did]['paid'] += $amt;
    }
    $payStmt->close();
  }

  // Pledge payments
  if ($hasPledgePayments && $hasPledgePaymentsTable) {
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
      if (!isset($donors[$did])) $donors[$did] = ['donor_id'=>$did,'ref'=>'','name'=>'','phone'=>'','pledge'=>0.0,'paid'=>0.0];
      $donors[$did]['paid'] += $amt;
    }
    $ppStmt->close();
  }

  // Attach ref (always 4 digits)
  foreach ($donors as $did => &$d) {
    $ref = $refByDonorId[$did] ?? '';
    if ($ref === '') {
      $phone = trim((string)($d['phone'] ?? ''));
      if ($phone !== '' && isset($refByPhone[$phone])) $ref = $refByPhone[$phone];
    }
    if ($ref === '') $ref = str_pad((string)$did, 4, '0', STR_PAD_LEFT);
    $d['ref'] = str_pad($ref, 4, '0', STR_PAD_LEFT);
  }
  unset($d);

  $rows = array_values($donors);
  usort($rows, static function ($a, $b) {
    $ap = (float)($a['pledge'] ?? 0);
    $bp = (float)($b['pledge'] ?? 0);
    if (($ap > 0) !== ($bp > 0)) return ($ap > 0) ? -1 : 1;
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
  foreach ($rows as $row) {
    $pledge = (float)($row['pledge'] ?? 0);
    $paid = (float)($row['paid'] ?? 0);
    $balance = max($pledge - $paid, 0);
    $totalPledge += $pledge;
    $totalPaid += $paid;
    $totalBalance += $balance;
  }

  return [
    'rows' => $rows,
    'totals' => [
      'pledge' => $totalPledge,
      'paid' => $totalPaid,
      'balance' => $totalBalance,
      'grand' => ($totalPledge + $totalPaid),
    ]
  ];
}

// Exports (CSV/print) no hard-code
if (isset($_GET['report'])) {
  [$fromDate, $toDate] = resolve_range($db);
  $report = $_GET['report'];
  $format = $_GET['format'] ?? 'csv';

  // WhatsApp daily backup sender:
  // - Manual send: POST (admin session + CSRF)
  // - Automatic send: GET with cron_key (for cPanel cron)
  if ($report === 'whatsapp_backup') {
    $cronKey = '';
    if (defined('FUNDRAISING_CRON_KEY')) {
      $cronKey = (string)FUNDRAISING_CRON_KEY;
    } elseif (getenv('FUNDRAISING_CRON_KEY')) {
      $cronKey = (string)getenv('FUNDRAISING_CRON_KEY');
    }

    $isCron = isset($_GET['cron_key']);
    if ($isCron) {
      if ($cronKey === '' || !hash_equals($cronKey, (string)$_GET['cron_key'])) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Invalid cron key\n";
        exit;
      }
    } else {
      if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Method not allowed\n";
        exit;
      }
      verify_csrf(true);
    }

    $service = UltraMsgService::fromDatabase($db);
    if (!$service) {
      http_response_code(500);
      header('Content-Type: text/plain; charset=utf-8');
      echo "WhatsApp provider (UltraMsg) is not configured\n";
      exit;
    }

    // Force all-time
    $fromDateAll = '1970-01-01 00:00:00';
    $toDateAll = '2999-12-31 23:59:59';
    $summary = build_donor_backup_summary($db, $fromDateAll, $toDateAll, $hasPledgePayments);

    // Save file
    $subdir = 'uploads/whatsapp/backups/' . date('Y/m');
    $absDir = __DIR__ . '/../../' . $subdir;
    if (!is_dir($absDir)) {
      mkdir($absDir, 0755, true);
    }

    // cleanup (> 7 days)
    $cleanupBase = __DIR__ . '/../../uploads/whatsapp/backups';
    if (is_dir($cleanupBase)) {
      $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cleanupBase, FilesystemIterator::SKIP_DOTS));
      $cutoff = time() - (7 * 24 * 60 * 60);
      foreach ($it as $fileInfo) {
        if ($fileInfo->isFile() && $fileInfo->getMTime() < $cutoff) {
          @unlink($fileInfo->getPathname());
        }
      }
    }

    $filename = 'backup-' . date('Y-m-d_H-i-s') . '.xls';
    $absPath = $absDir . '/' . $filename;
    $relPath = $subdir . '/' . $filename;

    $generatedAt = date('Y-m-d H:i:s');
    $rows = $summary['rows'];
    $t = $summary['totals'];

    $html = '<!DOCTYPE html><html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    $html .= '<head><meta charset="UTF-8"><style>';
    $html .= 'table{border-collapse:collapse;width:100%;}th,td{border:1px solid #ddd;padding:8px;text-align:left;}th{background:#f2f2f2;font-weight:bold;}.number{text-align:right;}.text{mso-number-format:"\@";}';
    $html .= '</style></head><body><table>';
    $html .= '<tr><th colspan="7">All Donations Backup (WhatsApp)</th></tr>';
    $html .= '<tr><td colspan="7"><strong>Generated at:</strong> ' . htmlspecialchars($generatedAt, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    $html .= '<tr><td colspan="7">&nbsp;</td></tr>';
    $html .= '<tr><th>#</th><th>Reference</th><th>Name</th><th>Phone</th><th class="number">Pledge (' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ')</th><th class="number">Paid (' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ')</th><th class="number">Balance (' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ')</th></tr>';
    $idx = 1;
    foreach ($rows as $r) {
      $ref = str_pad((string)($r['ref'] ?? ''), 4, '0', STR_PAD_LEFT);
      $pledge = (float)($r['pledge'] ?? 0);
      $paid = (float)($r['paid'] ?? 0);
      $balance = max($pledge - $paid, 0);
      $html .= '<tr>';
      $html .= '<td>' . $idx++ . '</td>';
      $html .= '<td class="text">' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '</td>';
      $html .= '<td>' . htmlspecialchars((string)($r['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
      $html .= '<td>' . htmlspecialchars((string)($r['phone'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
      $html .= '<td class="number">' . number_format($pledge, 2) . '</td>';
      $html .= '<td class="number">' . number_format($paid, 2) . '</td>';
      $html .= '<td class="number">' . number_format($balance, 2) . '</td>';
      $html .= '</tr>';
    }
    $html .= '<tr style="background-color:#f8f9fa;font-weight:bold;">';
    $html .= '<td colspan="4" style="text-align:right;"><strong>Totals:</strong></td>';
    $html .= '<td class="number"><strong>' . number_format((float)$t['pledge'], 2) . '</strong></td>';
    $html .= '<td class="number"><strong>' . number_format((float)$t['paid'], 2) . '</strong></td>';
    $html .= '<td class="number"><strong>' . number_format((float)$t['balance'], 2) . '</strong></td>';
    $html .= '</tr>';
    $html .= '<tr style="background-color:#eef2ff;font-weight:bold;">';
    $html .= '<td colspan="7" style="text-align:right;"><strong>Grand Total (Pledge + Paid):</strong> ' . number_format((float)$t['grand'], 2) . '</td>';
    $html .= '</tr>';
    $html .= '</table></body></html>';

    file_put_contents($absPath, $html);

    // Build public URL (UltraMsg fetches via URL)
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : ($_SERVER['REQUEST_SCHEME'] ?? 'https');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
      http_response_code(500);
      header('Content-Type: text/plain; charset=utf-8');
      echo "Cannot determine host for public URL\n";
      exit;
    }
    $publicUrl = $protocol . '://' . $host . url_for($relPath);

    $to = '07360436171';
    $caption = 'Daily fundraising backup - ' . date('Y-m-d') . ' (Pledge/Paid/Balance)';
    $sendResult = $service->sendDocument($to, $publicUrl, $filename, $caption, ['log' => true, 'source_type' => 'daily_backup']);

    if (!($sendResult['success'] ?? false)) {
      http_response_code(500);
      header('Content-Type: text/plain; charset=utf-8');
      echo "WhatsApp send failed\n";
      exit;
    }

    if ($isCron) {
      header('Content-Type: text/plain; charset=utf-8');
      echo "OK\n";
      exit;
    }

    header('Location: index.php?backup=sent');
    exit;
  }
  
  // Donor report should be "all donors" by default (no date param = all time)
  if ($report === 'donors' && !isset($_GET['date'])) {
    $fromDate = '1970-01-01 00:00:00';
    $toDate = '2999-12-31 23:59:59';
  }
  // All Donations Export should be a disaster-recovery "all time" backup by default
  if ($report === 'all_donations' && !isset($_GET['date'])) {
    $fromDate = '1970-01-01 00:00:00';
    $toDate = '2999-12-31 23:59:59';
  }

  if ($report === 'donors' && ($format === 'csv' || $format === 'excel')) {
    $isExcel = $format === 'excel';
    if ($isExcel) {
      header('Content-Type: application/vnd.ms-excel');
      header('Content-Disposition: attachment; filename="donors_report_' . date('Y-m-d_H-i-s') . '.xls"');
      header('Cache-Control: max-age=0');

      echo '<!DOCTYPE html>';
      echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
      echo '<head>';
      echo '<meta charset="UTF-8">';
      echo '<style>';
      echo 'table { border-collapse: collapse; width: 100%; }';
      echo 'th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }';
      echo 'th { background-color: #f2f2f2; font-weight: bold; }';
      echo '.number { text-align: right; }';
      // Force Excel to treat as text (preserves leading zeros like 0012)
      echo '.text { mso-number-format:"\@"; }';
      echo '</style>';
      echo '</head><body>';
      echo '<table>';
      echo '<tr>';
      echo '<th>Name</th>';
      echo '<th>Phone</th>';
      echo '<th>Pledge Amount (' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ')</th>';
      echo '<th>Paid (' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ')</th>';
      echo '<th>Balance (' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ')</th>';
      echo '</tr>';
    } else {
      header('Content-Type: text/csv');
      header('Content-Disposition: attachment; filename="donors_report.csv"');
      $out = fopen('php://output', 'w');
      fputcsv($out, ['Name', 'Phone', 'Pledge Amount', 'Paid', 'Balance']);
    }

    // Build donor aggregates by grouping on name/phone/email across pledges and payments
    // Note: $hasPledgePayments already set from FinancialCalculator at top of file
    $sql = "SELECT donor_name, donor_phone, donor_email,
                   SUM(CASE WHEN src='pledge' THEN amount ELSE 0 END) AS total_pledged,
                   SUM(CASE WHEN src='payment' THEN amount ELSE 0 END) AS total_paid,
                   MAX(last_seen_at) AS last_seen_at
            FROM (
              SELECT donor_name, donor_phone, donor_email, amount, created_at AS last_seen_at, 'pledge' AS src
              FROM pledges
              WHERE status='approved' AND created_at BETWEEN ? AND ?
              UNION ALL
              SELECT donor_name, donor_phone, donor_email, amount, received_at AS last_seen_at, 'payment' AS src
              FROM payments
              WHERE status='approved' AND received_at BETWEEN ? AND ?
              " . ($hasPledgePayments ? "
              UNION ALL
              SELECT d.name AS donor_name, d.phone AS donor_phone, d.email AS donor_email, pp.amount, pp.created_at AS last_seen_at, 'payment' AS src
              FROM pledge_payments pp
              LEFT JOIN donors d ON pp.donor_id = d.id
              WHERE pp.status='confirmed' AND pp.created_at BETWEEN ? AND ?
              " : "") . "
            ) c
            GROUP BY donor_name, donor_phone, donor_email
            ORDER BY total_paid DESC";
    
    $stmt = $db->prepare($sql);
    if ($hasPledgePayments) {
        $stmt->bind_param('ssssss', $fromDate, $toDate, $fromDate, $toDate, $fromDate, $toDate);
    } else {
        $stmt->bind_param('ssss', $fromDate, $toDate, $fromDate, $toDate);
    }
    $stmt->execute();
    $res=$stmt->get_result();
    while($r=$res->fetch_assoc()){
      $totalPledged = (float)($r['total_pledged'] ?? 0);
      $totalPaid    = (float)($r['total_paid'] ?? 0);
      $balance      = max($totalPledged - $totalPaid, 0);
      $name = ($r['donor_name'] ?? '') !== '' ? $r['donor_name'] : 'Anonymous';
      $phone = $r['donor_phone'] ?? '';

      if ($isExcel) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="number">' . number_format($totalPledged, 2) . '</td>';
        echo '<td class="number">' . number_format($totalPaid, 2) . '</td>';
        echo '<td class="number">' . number_format($balance, 2) . '</td>';
        echo '</tr>';
      } else {
        fputcsv($out, [
          $name,
          $phone,
          number_format($totalPledged, 2, '.', ''),
          number_format($totalPaid, 2, '.', ''),
          number_format($balance, 2, '.', ''),
        ]);
      }
    }
    if ($isExcel) {
      echo '</table>';
      echo '</body></html>';
    } else {
      fclose($out);
    }
    exit;
  }
  if ($report === 'financial' && $format === 'csv') {
    header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="financial_report.csv"');
    $out=fopen('php://output','w'); fputcsv($out,['Payment ID','Donor','Phone','Amount','Method','Reference','Status','Package','Received By','Received At']);
    $sql="SELECT p.id, p.donor_name, p.donor_phone, p.amount, p.method, p.reference, p.status, u.name AS received_by, p.received_at, dp.label AS package_label
          FROM payments p
          LEFT JOIN users u ON u.id=p.received_by_user_id
          LEFT JOIN donation_packages dp ON dp.id=p.package_id
          WHERE p.received_at BETWEEN ? AND ?
          ORDER BY p.received_at DESC";
    $stmt=$db->prepare($sql); $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $res=$stmt->get_result();
    while($r=$res->fetch_assoc()){
      fputcsv($out,[
        $r['id'],
        $r['donor_name'] !== '' ? $r['donor_name'] : 'Anonymous',
        $r['donor_phone'],
        number_format((float)$r['amount'],2,'.',''),
        $r['method'],
        $r['reference'],
        $r['status'],
        $r['package_label'],
        $r['received_by'],
        $r['received_at']
      ]);
    }
    fclose($out); exit;
  }
  if ($report === 'all_donations') {
    if ($format === 'excel') {
      // Set headers for Excel download
      header('Content-Type: application/vnd.ms-excel');
      header('Content-Disposition: attachment; filename="all_donations_backup_' . date('Y-m-d_H-i-s') . '.xls"');
      header('Cache-Control: max-age=0');
      
      // Start Excel content
      echo '<!DOCTYPE html>';
      echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
      echo '<head>';
      echo '<meta charset="UTF-8">';
      echo '<style>';
      echo 'table { border-collapse: collapse; width: 100%; }';
      echo 'th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }';
      echo 'th { background-color: #f2f2f2; font-weight: bold; }';
      echo '.number { text-align: right; }';
      echo '</style>';
      echo '</head><body>';
      
      // Donor-based disaster recovery export:
      // 1 row per donor (combines donors + pledges + payments + pledge_payments)
      // - If donor has only paid: show Paid, Pledge=0, Balance=0
      // - If donor has pledged: show Pledge, Paid, Balance
      // - Include totals: Total Pledge, Total Paid, Grand Total (Paid + Balance)
      $esc = static function ($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
      };

      $reportCurrency = $currency;
      $generatedAt = date('Y-m-d H:i:s');
      
      // Detect optional columns for compatibility across deployments
      $hasDonorsTable = $db->query("SHOW TABLES LIKE 'donors'")->num_rows > 0;
      $pledgesHasDonorId = $db->query("SHOW COLUMNS FROM pledges LIKE 'donor_id'")->num_rows > 0;
      $paymentsHasDonorId = $db->query("SHOW COLUMNS FROM payments LIKE 'donor_id'")->num_rows > 0;
      $paymentsHasReceivedAt = $db->query("SHOW COLUMNS FROM payments LIKE 'received_at'")->num_rows > 0;
      $hasPledgePaymentsTable = $db->query("SHOW TABLES LIKE 'pledge_payments'")->num_rows > 0;
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

      // 1) Load donors (base list)
      $donors = [];
      if ($hasDonorsTable) {
        $donorRes = $db->query("SELECT id, name, phone FROM donors ORDER BY name ASC, id ASC");
        while ($d = $donorRes->fetch_assoc()) {
          $id = (int)$d['id'];
          $donors[$id] = [
            'donor_id' => $id,
            'ref' => '',
            'name' => $d['name'] ?? '',
            'phone' => $d['phone'] ?? '',
            'pledge' => 0.0,
            'paid' => 0.0,
          ];
        }
      }

      // Reference lookup (4-digit) for donor identification
      $extractRef = static function (string $text): string {
        if (preg_match('/\b(\d{4})\b/', $text, $m)) {
          return $m[1];
        }
        return '';
      };

      $refByDonorId = [];
      $refByPhone = [];

      // Prefer reference from latest pledge notes (stored in pledge notes)
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
      if ($hasPledgePaymentsTable && $ppHasReferenceNumber) {
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

      // Fallback: payments.reference / payments.transaction_ref (approved)
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

      // 2) Aggregate pledges by donor_id (approved only)
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
            $donors[$did] = ['donor_id'=>$did,'ref'=>'','name'=>'','phone'=>'','pledge'=>0.0,'paid'=>0.0];
          }
          $donors[$did]['pledge'] += $amt;
        }
        $plStmt->close();
      }

      // 3) Aggregate payments by donor_id (approved only)
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
            $donors[$did] = ['donor_id'=>$did,'ref'=>'','name'=>'','phone'=>'','pledge'=>0.0,'paid'=>0.0];
          }
          $donors[$did]['paid'] += $amt;
        }
        $payStmt->close();
      }

      // 4) Aggregate pledge_payments by donor_id (confirmed only)
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
            $donors[$did] = ['donor_id'=>$did,'ref'=>'','name'=>'','phone'=>'','pledge'=>0.0,'paid'=>0.0];
          }
          $donors[$did]['paid'] += $amt;
        }
        $ppStmt->close();
      }

      // 5) Add "unlinked donors" from pledges/payments where donor_id isn't stored (group by name/phone/email)
      $unlinked = [];
      $makeKey = static function ($name, $phone): string {
        return trim((string)$name) . '|' . trim((string)$phone);
      };

      if (!$pledgesHasDonorId) {
        // still capture pledges by donor fields
        $uplStmt = $db->prepare("
          SELECT donor_name, donor_phone, MAX(notes) AS notes_any, COALESCE(SUM(amount),0) AS total
          FROM pledges
          WHERE status='approved' AND created_at BETWEEN ? AND ?
          GROUP BY donor_name, donor_phone
        ");
      } else {
        $uplStmt = $db->prepare("
          SELECT donor_name, donor_phone, MAX(notes) AS notes_any, COALESCE(SUM(amount),0) AS total
          FROM pledges
          WHERE status='approved' AND (donor_id IS NULL OR donor_id = 0)
            AND created_at BETWEEN ? AND ?
          GROUP BY donor_name, donor_phone
        ");
      }
      $uplStmt->bind_param('ss', $fromDate, $toDate);
      $uplStmt->execute();
      $uplRes = $uplStmt->get_result();
      while ($r = $uplRes->fetch_assoc()) {
        $key = $makeKey($r['donor_name'] ?? '', $r['donor_phone'] ?? '');
        if (!isset($unlinked[$key])) {
          $ref = '';
          $phone = trim((string)($r['donor_phone'] ?? ''));
          if ($phone !== '' && isset($refByPhone[$phone])) {
            $ref = $refByPhone[$phone];
          } else {
            $ref = $extractRef((string)($r['notes_any'] ?? ''));
          }
          $unlinked[$key] = ['ref'=>$ref,'name'=>$r['donor_name'] ?? '','phone'=>$r['donor_phone'] ?? '','pledge'=>0.0,'paid'=>0.0];
        }
        $unlinked[$key]['pledge'] += (float)($r['total'] ?? 0);
      }
      $uplStmt->close();

      if (!$paymentsHasDonorId) {
        $upayStmt = $db->prepare("
          SELECT donor_name, donor_phone, COALESCE(SUM(amount),0) AS total
          FROM payments
          WHERE status='approved' AND {$payDateCol} BETWEEN ? AND ?
          GROUP BY donor_name, donor_phone
        ");
      } else {
        $upayStmt = $db->prepare("
          SELECT donor_name, donor_phone, COALESCE(SUM(amount),0) AS total
          FROM payments
          WHERE status='approved' AND (donor_id IS NULL OR donor_id = 0)
            AND {$payDateCol} BETWEEN ? AND ?
          GROUP BY donor_name, donor_phone
        ");
      }
      $upayStmt->bind_param('ss', $fromDate, $toDate);
      $upayStmt->execute();
      $upayRes = $upayStmt->get_result();
      while ($r = $upayRes->fetch_assoc()) {
        $key = $makeKey($r['donor_name'] ?? '', $r['donor_phone'] ?? '');
        if (!isset($unlinked[$key])) {
          $ref = '';
          $phone = trim((string)($r['donor_phone'] ?? ''));
          if ($phone !== '' && isset($refByPhone[$phone])) {
            $ref = $refByPhone[$phone];
          }
          $unlinked[$key] = ['ref'=>$ref,'name'=>$r['donor_name'] ?? '','phone'=>$r['donor_phone'] ?? '','pledge'=>0.0,'paid'=>0.0];
        }
        $unlinked[$key]['paid'] += (float)($r['total'] ?? 0);
      }
      $upayStmt->close();

      // Attach reference numbers to donors (prefer pledge notes, then phone, then fallback padded donor_id)
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
        }
        $d['ref'] = $ref;
      }
      unset($d);

      // Merge donors + unlinked into one list, then output
      $rows = array_values($donors);
      foreach ($unlinked as $u) {
        // Skip rows that are truly empty
        if (($u['name'] ?? '') === '' && ($u['phone'] ?? '') === '') {
          continue;
        }
        $rows[] = $u;
      }

      // Sort: pledged donors first, then by name/phone
      usort($rows, static function ($a, $b) {
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

      echo '<table>';
      echo '<tr><th colspan="7">All Donations Backup (Donor Summary)</th></tr>';
      echo '<tr><td colspan="7"><strong>Generated at:</strong> ' . $esc($generatedAt) . ' | <strong>Range:</strong> ' . $esc($fromDate) . ' → ' . $esc($toDate) . '</td></tr>';
      echo '<tr><td colspan="7">&nbsp;</td></tr>';
      echo '<tr>';
      echo '<th>#</th>';
      echo '<th>Reference</th>';
      echo '<th>Name</th>';
      echo '<th>Phone</th>';
      echo '<th class="number">Pledge (' . $esc($reportCurrency) . ')</th>';
      echo '<th class="number">Paid (' . $esc($reportCurrency) . ')</th>';
      echo '<th class="number">Balance (' . $esc($reportCurrency) . ')</th>';
      echo '</tr>';

      $i = 1;
      foreach ($rows as $row) {
        $pledge = (float)($row['pledge'] ?? 0);
        $paid = (float)($row['paid'] ?? 0);
        $balance = max($pledge - $paid, 0);

        $totalPledge += $pledge;
        $totalPaid += $paid;
        $totalBalance += $balance;

        echo '<tr>';
        echo '<td>' . $i++ . '</td>';
        $refOut = (string)($row['ref'] ?? '');
        if ($refOut !== '') {
          $refOut = str_pad($refOut, 4, '0', STR_PAD_LEFT);
        }
        echo '<td class="text">' . $esc($refOut) . '</td>';
        echo '<td>' . $esc($row['name'] ?? '') . '</td>';
        echo '<td>' . $esc($row['phone'] ?? '') . '</td>';
        echo '<td class="number">' . number_format($pledge, 2) . '</td>';
        echo '<td class="number">' . number_format($paid, 2) . '</td>';
        echo '<td class="number">' . number_format($balance, 2) . '</td>';
        echo '</tr>';
      }

      // Grand total = pledged commitments + money already paid
      $grandTotalForBackup = $totalPledge + $totalPaid;
      echo '<tr style="background-color: #f8f9fa; font-weight: bold;">';
      echo '<td colspan="4" style="text-align:right;"><strong>Totals:</strong></td>';
      echo '<td class="number"><strong>' . number_format($totalPledge, 2) . '</strong></td>';
      echo '<td class="number"><strong>' . number_format($totalPaid, 2) . '</strong></td>';
      echo '<td class="number"><strong>' . number_format($totalBalance, 2) . '</strong></td>';
      echo '</tr>';
      echo '<tr style="background-color: #eef2ff; font-weight: bold;">';
      echo '<td colspan="7" style="text-align:right;"><strong>Grand Total (Pledge + Paid):</strong> ' . number_format($grandTotalForBackup, 2) . '</td>';
      echo '</tr>';

      echo '</table>';

      echo '</body></html>';
      exit;
    }
  }
  
  if ($report === 'summary') {
    if ($format === 'csv') {
      header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="summary_report.csv"');
      $out=fopen('php://output','w');
      fputcsv($out,['Metric','Value']);
      fputcsv($out,['Total Paid (approved)',number_format($paidTotal,2,'.','')]);
      fputcsv($out,['Total Pledged (approved)',number_format($pledgedTotal,2,'.','')]);
      fputcsv($out,['Grand Total',number_format($grandTotal,2,'.','')]);
      fputcsv($out,['Total Donors',$donorCount]);
      fputcsv($out,['Average Donation',number_format($avgDonation,2,'.','')]);
      fputcsv($out,['Progress (%)',$progress]);
      fclose($out); exit;
    } else {
      // simple print view
      echo '<!doctype html><meta charset="utf-8"><title>Summary Report</title><link rel="stylesheet" href="../assets/admin.css">';
      echo '<div class="main-content" style="padding:2rem">';
      echo '<h2>Summary Report</h2><ul>';
      echo '<li>Total Paid (approved): '.$currency.' '.number_format($paidTotal,0).'</li>';
      echo '<li>Total Pledged (approved): '.$currency.' '.number_format($pledgedTotal,0).'</li>';
      echo '<li>Grand Total: '.$currency.' '.number_format($grandTotal,0).'</li>';
      echo '<li>Total Donors: '.$donorCount.'</li>';
      echo '<li>Average Donation: '.$currency.' '.number_format($avgDonation,2).'</li>';
      echo '<li>Progress: '.$progress.'%</li>';
      echo '</ul></div>'; exit;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Fundraising System</title>
    
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/reports.css">
    
    <!-- Favicon -->
    <link rel="icon" href="../../assets/favicon.svg" type="image/svg+xml">
</head>
<body>
    <div class="admin-wrapper">
        <?php include '../includes/sidebar.php'; ?>
        <div class="admin-content">
            <?php include '../includes/topbar.php'; ?>
            <main class="main-content">
                <div class="container-fluid">
                    <?php if (isset($_GET['backup']) && $_GET['backup'] === 'sent'): ?>
                        <div class="alert alert-success">WhatsApp backup sent successfully.</div>
                    <?php endif; ?>
                    <!-- Page Header (actions only) -->
                    <div class="d-flex justify-content-end mb-4">
                        <button class="btn btn-outline-primary" onclick="refreshData()">
                            <i class="fas fa-sync-alt me-2"></i>Refresh
                        </button>
                    </div>
                    
                    <!-- Quick Stats (Modern Cards) -->
                    <div class="row g-3 mb-4">
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="icon-circle bg-primary">
                                                <i class="fas fa-chart-line text-white"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <div class="small fw-bold text-primary mb-1">Total Raised</div>
            <div class="h5 mb-0"><?php echo $currency.' '.number_format($grandTotal, 0); ?></div>
                                            <div class="small text-muted">Target: <?php echo $currency.' '.number_format((float)$settings['target_amount'],2); ?></div>
                                        </div>
                                </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="icon-circle bg-success">
                                                <i class="fas fa-users text-white"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <div class="small fw-bold text-success mb-1">Total Donors</div>
                                            <div class="h5 mb-0"><?php echo number_format($donorCount); ?></div>
                                            <div class="small text-muted">Active contributors</div>
                                        </div>
                                </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="icon-circle bg-warning">
                                                <i class="fas fa-pound-sign text-white"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <div class="small fw-bold text-warning mb-1">Average Donation</div>
            <div class="h5 mb-0"><?php echo $currency.' '.number_format($avgDonation, 0); ?></div>
                                            <div class="small text-muted">Per payment</div>
                                        </div>
                                </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-shrink-0">
                                            <div class="icon-circle bg-info">
                                                <i class="fas fa-percentage text-white"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 ms-3">
                                            <div class="small fw-bold text-info mb-1">Goal Progress</div>
                                            <div class="h5 mb-0"><?php echo $progress; ?>%</div>
                                            <div class="progress mt-2" style="height: 4px;">
                                                <div class="progress-bar bg-info" style="width: <?php echo min($progress, 100); ?>%"></div>
                                            </div>
                                        </div>
                                </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Available Reports -->
                    <div class="mb-4">
                        <h5 class="mb-3">
                            <i class="fas fa-file-alt me-2 text-primary"></i>
                            Available Reports
                        </h5>
                        <div class="row g-3">
                            <div class="col-lg-4 col-md-6">
                                <div class="card border-0 shadow-sm h-100 report-card">
                                    <div class="card-body text-center p-4">
                                        <div class="mb-3">
                                            <div class="icon-circle bg-success mx-auto" style="width: 60px; height: 60px;">
                                                <i class="fas fa-chart-line text-white fs-4"></i>
                                            </div>
                                        </div>
                                        <h5 class="card-title">Financial Dashboard</h5>
                                        <p class="card-text text-muted">Interactive financial dashboard with charts, trends, and real-time data</p>
                                        <div class="d-grid gap-2">
                                            <a href="financial-dashboard.php" class="btn btn-success">
                                                <i class="fas fa-tachometer-alt me-2"></i>Open Dashboard
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-4 col-md-6">
                                <div class="card border-0 shadow-sm h-100 report-card">
                                    <div class="card-body text-center p-4">
                                        <div class="mb-3">
                                            <div class="icon-circle bg-primary mx-auto" style="width: 60px; height: 60px;">
                                                <i class="fas fa-chart-pie text-white fs-4"></i>
                                            </div>
                                    </div>
                                        <h5 class="card-title">Summary Report</h5>
                                        <p class="card-text text-muted">Overall fundraising summary with key metrics and progress</p>
                                        <div class="d-grid gap-2">
                                            <a href="?report=summary&format=csv" class="btn btn-primary">
                                                <i class="fas fa-download me-2"></i>Download CSV
                                            </a>
                                            <a href="?report=summary&format=print" class="btn btn-outline-primary">
                                                <i class="fas fa-print me-2"></i>Print View
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-lg-4 col-md-6">
                                <div class="card border-0 shadow-sm h-100 report-card">
                                    <div class="card-body text-center p-4">
                                        <div class="mb-3">
                                            <div class="icon-circle bg-dark mx-auto" style="width: 60px; height: 60px;">
                                                <i class="fas fa-layer-group text-white fs-4"></i>
                                            </div>
                                        </div>
                                        <h5 class="card-title">Comprehensive Report</h5>
                                        <p class="card-text text-muted">Full numeric and non-numeric breakdown with charts</p>
                                        <div class="d-grid gap-2">
                                            <a href="comprehensive.php" class="btn btn-dark">
                                                <i class="fas fa-file-alt me-2"></i>Open Report Page
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            
                            </div>
                            <div class="col-lg-4 col-md-6">
                                <div class="card border-0 shadow-sm h-100 report-card">
                                    <div class="card-body text-center p-4">
                                        <div class="mb-3">
                                            <div class="icon-circle bg-primary mx-auto" style="width: 60px; height: 60px;">
                                                <i class="fas fa-chart-bar text-white fs-4"></i>
                                            </div>
                                        </div>
                                        <h5 class="card-title">Visual Report</h5>
                                        <p class="card-text text-muted">Interactive charts: packages, methods, time, statuses, registrars</p>
                                        <div class="d-grid gap-2">
                                            <a href="visual.php?date=month" class="btn btn-primary">
                                                <i class="fas fa-chart-line me-2"></i>Open Visual Report
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-4 col-md-6">
                                <div class="card border-0 shadow-sm h-100 report-card">
                                    <div class="card-body text-center p-4">
                                        <div class="mb-3">
                                            <div class="icon-circle bg-success mx-auto" style="width: 60px; height: 60px;">
                                                <i class="fas fa-users text-white fs-4"></i>
                                            </div>
                                    </div>
                                        <h5 class="card-title">Donor Report</h5>
                                        <p class="card-text text-muted">Detailed list of all donors with their contributions and contact info</p>
                                        <div class="d-grid gap-2">
                                            <a href="?report=donors&format=excel" class="btn btn-success">
                                                <i class="fas fa-file-excel me-2"></i>Download Excel
                                            </a>
                                            <a href="?report=donors&format=csv" class="btn btn-outline-success">
                                                <i class="fas fa-download me-2"></i>Download CSV
                                            </a>
                                            <button class="btn btn-outline-success" onclick="showCustomDateModal('donors')">
                                                <i class="fas fa-calendar me-2"></i>Custom Range
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-4 col-md-6">
                                <div class="card border-0 shadow-sm h-100 report-card">
                                    <div class="card-body text-center p-4">
                                        <div class="mb-3">
                                            <div class="icon-circle bg-info mx-auto" style="width: 60px; height: 60px;">
                                                <i class="fas fa-file-invoice-dollar text-white fs-4"></i>
                                            </div>
                                    </div>
                                        <h5 class="card-title">Financial Report</h5>
                                        <p class="card-text text-muted">Complete financial breakdown and transaction history</p>
                                        <div class="d-grid gap-2">
                                            <a href="?report=financial&format=csv" class="btn btn-info">
                                                <i class="fas fa-download me-2"></i>Download CSV
                                            </a>
                                            <button class="btn btn-outline-info" onclick="showCustomDateModal('financial')">
                                                <i class="fas fa-calendar me-2"></i>Custom Range
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-4 col-md-6">
                                <div class="card border-0 shadow-sm h-100 report-card">
                                    <div class="card-body text-center p-4">
                                        <div class="mb-3">
                                            <div class="icon-circle bg-warning mx-auto" style="width: 60px; height: 60px;">
                                                <i class="fas fa-database text-white fs-4"></i>
                                            </div>
                                    </div>
                                        <h5 class="card-title">All Donations Export</h5>
                                        <p class="card-text text-muted">Complete backup of all donations (pledges & payments) for data safety</p>
                                        <div class="d-grid gap-2">
                                            <a href="?report=all_donations&format=excel" class="btn btn-warning">
                                                <i class="fas fa-download me-2"></i>Download Excel
                                            </a>
                                            <button class="btn btn-outline-warning" onclick="showCustomDateModal('all_donations')">
                                                <i class="fas fa-calendar me-2"></i>Custom Range
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-4 col-md-6">
                                <div class="card border-0 shadow-sm h-100 report-card">
                                    <div class="card-body text-center p-4">
                                        <div class="mb-3">
                                            <div class="icon-circle bg-success mx-auto" style="width: 60px; height: 60px;">
                                                <i class="fab fa-whatsapp text-white fs-4"></i>
                                            </div>
                                        </div>
                                        <h5 class="card-title">WhatsApp Daily Backup</h5>
                                        <p class="card-text text-muted">Automatically send the donor summary Excel to WhatsApp (07360436171)</p>
                                        <div class="d-grid gap-2">
                                            <form method="POST" action="?report=whatsapp_backup">
                                                <?php echo csrf_input(); ?>
                                                <button type="submit" class="btn btn-success">
                                                    <i class="fas fa-paper-plane me-2"></i>Send Report Now
                                                </button>
                                            </form>
                                            <?php
                                                $cronKey = '';
                                                if (defined('FUNDRAISING_CRON_KEY')) {
                                                    $cronKey = (string)FUNDRAISING_CRON_KEY;
                                                } elseif (getenv('FUNDRAISING_CRON_KEY')) {
                                                    $cronKey = (string)getenv('FUNDRAISING_CRON_KEY');
                                                }
                                                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : ($_SERVER['REQUEST_SCHEME'] ?? 'https');
                                                $host = $_SERVER['HTTP_HOST'] ?? '';
                                                $cronUrl = ($cronKey !== '' && $host !== '')
                                                    ? ($protocol . '://' . $host . url_for('admin/reports/index.php') . '?report=whatsapp_backup&cron_key=' . urlencode($cronKey))
                                                    : '';
                                            ?>
                                            <?php if ($cronUrl !== ''): ?>
                                                <small class="text-muted">cPanel Cron URL (run daily):</small>
                                                <code style="display:block; white-space:normal;"><?php echo htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8'); ?></code>
                                            <?php else: ?>
                                                <small class="text-muted">
                                                    To enable automatic daily sending, set <code>FUNDRAISING_CRON_KEY</code>
                                                    (recommended in <code>config/env.local.php</code>), then this page will show a cron URL.
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Date Range Reports -->
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-transparent border-0 pb-0">
                                    <h5 class="mb-0">
                                        <i class="fas fa-calendar-alt me-2 text-primary"></i>
                                        Quick Date Range Reports
                                    </h5>
                                </div>
                                <div class="card-body">
                                <div class="row g-3">
                                        <div class="col-lg-2 col-md-4 col-6">
                                            <div class="d-grid">
                                                <a href="?report=summary&date=today" class="btn btn-outline-primary">
                                                    <i class="fas fa-clock me-1"></i>Today
                                                </a>
                                            </div>
                                        </div>
                                        <div class="col-lg-2 col-md-4 col-6">
                                            <div class="d-grid">
                                                <a href="?report=summary&date=week" class="btn btn-outline-primary">
                                                    <i class="fas fa-calendar-week me-1"></i>This Week
                                                </a>
                                    </div>
                                    </div>
                                        <div class="col-lg-2 col-md-4 col-6">
                                            <div class="d-grid">
                                                <a href="?report=summary&date=month" class="btn btn-outline-primary">
                                                    <i class="fas fa-calendar me-1"></i>This Month
                                                </a>
                                    </div>
                                </div>
                                        <div class="col-lg-2 col-md-4 col-6">
                                            <div class="d-grid">
                                                <a href="?report=summary&date=quarter" class="btn btn-outline-primary">
                                                    <i class="fas fa-calendar-alt me-1"></i>Quarter
                                                </a>
                                            </div>
                                    </div>
                                        <div class="col-lg-2 col-md-4 col-6">
                                            <div class="d-grid">
                                                <a href="?report=summary&date=year" class="btn btn-outline-primary">
                                                    <i class="fas fa-calendar-day me-1"></i>This Year
                                                </a>
                                    </div>
                                </div>
                                        <div class="col-lg-2 col-md-4 col-6">
                                            <div class="d-grid">
                                                <button class="btn btn-primary" onclick="showCustomDateModal('summary')">
                                                    <i class="fas fa-calendar-plus me-1"></i>Custom
                                                </button>
                                            </div>
                                            </div>
                                            </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                    <!-- Recent Activity -->
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="fas fa-clock me-2 text-primary"></i>
                                        Recent Payments
                                    </h5>
                                    <a href="../payments/" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-external-link-alt me-1"></i>View All
                                    </a>
                                </div>
                                <div class="card-body">
                                    <?php $rp=$db->query("SELECT id, donor_name, amount, method, received_at FROM payments WHERE status='approved' ORDER BY received_at DESC LIMIT 5");
                                    if ($rp && $rp->num_rows>0): ?>
                                    <div class="list-group list-group-flush">
                                        <?php while($r=$rp->fetch_assoc()): ?>
                                        <div class="list-group-item border-0 px-0">
                                            <div class="d-flex align-items-center">
                                                <div class="flex-shrink-0">
                                                    <div class="icon-circle bg-success" style="width: 40px; height: 40px;">
                                                        <i class="fas fa-pound-sign text-white"></i>
                                                    </div>
                                                </div>
                                                <div class="flex-grow-1 ms-3">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <h6 class="mb-0"><?php echo htmlspecialchars(($r['donor_name'] ?? '') !== '' ? $r['donor_name'] : 'Anonymous'); ?></h6>
                                                            <small class="text-muted">
                                                                <?php echo htmlspecialchars(ucfirst($r['method'])); ?> · 
                                                                <?php echo date('d M Y, h:i A', strtotime($r['received_at'])); ?>
                                                            </small>
                                </div>
                                                        <div class="text-end">
                                                            <span class="fw-bold text-success"><?php echo $currency.' '.number_format((float)$r['amount'],2); ?></span>
                        </div>
                    </div>
                                </div>
                                </div>
                            </div>
                                        <?php endwhile; ?>
                                    </div>
                                    <?php else: ?>
                                    <div class="text-center py-4">
                                        <div class="mb-3">
                                            <i class="fas fa-inbox text-muted" style="font-size: 3rem;"></i>
                                        </div>
                                        <h5 class="text-muted">No recent payments</h5>
                                        <p class="text-muted mb-0">Once payments are approved, they'll appear here.</p>
                                    </div>
                          <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Custom Date Range Modal -->
    <div class="modal fade" id="customDateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-alt me-2"></i>
                        Custom Date Range
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="customDateForm">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">From Date</label>
                                <input type="date" class="form-control" id="modalFromDate" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">To Date</label>
                                <input type="date" class="form-control" id="modalToDate" required>
                            </div>
                        </div>
                        <input type="hidden" id="modalReportType" value="summary">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="generateCustomReport()">
                        <i class="fas fa-download me-2"></i>Generate Report
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/admin.js"></script>
    <script src="assets/reports.js"></script>
</body>
</html>
