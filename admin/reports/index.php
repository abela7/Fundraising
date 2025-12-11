<?php
require_once '../../config/db.php';
require_once '../../shared/auth.php';
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

// Exports (CSV/print) no hard-code
if (isset($_GET['report'])) {
  [$fromDate, $toDate] = resolve_range($db);
  $report = $_GET['report'];
  $format = $_GET['format'] ?? 'csv';
  
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
      
      // Disaster-recovery friendly export:
      // - Includes stable IDs (transaction id + donor_id when available)
      // - Includes linkage fields (pledge_id for pledge payments)
      // - Includes method/reference/date fields for pledge payments
      // - Works with any date range (all time by default)
      $esc = static function ($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
      };

      // Detect optional columns for compatibility across deployments
      $pledgesHasDonorId = $db->query("SHOW COLUMNS FROM pledges LIKE 'donor_id'")->num_rows > 0;
      $paymentsHasDonorId = $db->query("SHOW COLUMNS FROM payments LIKE 'donor_id'")->num_rows > 0;
      $paymentsHasNotes = $db->query("SHOW COLUMNS FROM payments LIKE 'notes'")->num_rows > 0;
      $paymentsHasReceivedAt = $db->query("SHOW COLUMNS FROM payments LIKE 'received_at'")->num_rows > 0;

      $ppHasPledgeId = $hasPledgePayments ? ($db->query("SHOW COLUMNS FROM pledge_payments LIKE 'pledge_id'")->num_rows > 0) : false;
      $ppHasPaymentMethod = $hasPledgePayments ? ($db->query("SHOW COLUMNS FROM pledge_payments LIKE 'payment_method'")->num_rows > 0) : false;
      $ppHasReferenceNumber = $hasPledgePayments ? ($db->query("SHOW COLUMNS FROM pledge_payments LIKE 'reference_number'")->num_rows > 0) : false;
      $ppHasPaymentDate = $hasPledgePayments ? ($db->query("SHOW COLUMNS FROM pledge_payments LIKE 'payment_date'")->num_rows > 0) : false;

      $ppHasApprovedBy = $hasPledgePayments ? ($db->query("SHOW COLUMNS FROM pledge_payments LIKE 'approved_by_user_id'")->num_rows > 0) : false;
      $ppHasVoidedBy = $hasPledgePayments ? ($db->query("SHOW COLUMNS FROM pledge_payments LIKE 'voided_by_user_id'")->num_rows > 0) : false;

      $reportCurrency = $currency; // already escaped above
      $generatedAt = date('Y-m-d H:i:s');
      
      echo '<table>';
      echo '<tr><th colspan="18">All Donations Backup Export</th></tr>';
      echo '<tr><td colspan="18"><strong>Generated at:</strong> ' . $esc($generatedAt) . ' | <strong>Range:</strong> ' . $esc($fromDate) . ' → ' . $esc($toDate) . '</td></tr>';
      echo '<tr><td colspan="18">&nbsp;</td></tr>';
      echo '<tr>';
      echo '<th>#</th>';
      echo '<th>Source Table</th>';
      echo '<th>Donation Type</th>';
      echo '<th>Transaction ID</th>';
      echo '<th>Related Pledge ID</th>';
      echo '<th>Donor ID</th>';
      echo '<th>Donor Name</th>';
      echo '<th>Phone</th>';
      echo '<th>Email</th>';
      echo '<th class="number">Amount (' . $esc($reportCurrency) . ')</th>';
      echo '<th>Status</th>';
      echo '<th>Method</th>';
      echo '<th>Reference</th>';
      echo '<th>Notes</th>';
      echo '<th>Package</th>';
      echo '<th>Transaction Date</th>';
      echo '<th>Recorded By</th>';
      echo '<th>Recorded By User ID</th>';
      echo '</tr>';
      
      $rowNumber = 1;
      $countPledges = 0;
      $countPayments = 0;
      $countPledgePayments = 0;
      
      // Pledges
      $pledgeDonorIdSelect = $pledgesHasDonorId ? "p.donor_id" : "NULL";
      $pledgesStmt = $db->prepare("
        SELECT
          p.id,
          {$pledgeDonorIdSelect} AS donor_id,
          p.donor_name,
          p.donor_phone,
          p.donor_email,
          p.amount,
          p.status,
          p.notes,
          dp.label AS package_label,
          p.created_at AS transaction_date,
          u.name AS recorded_by,
          p.created_by_user_id AS recorded_by_user_id,
          p.anonymous
        FROM pledges p
        LEFT JOIN donation_packages dp ON dp.id = p.package_id
        LEFT JOIN users u ON u.id = p.created_by_user_id
        WHERE p.created_at BETWEEN ? AND ?
        ORDER BY p.created_at DESC
      ");
      $pledgesStmt->bind_param('ss', $fromDate, $toDate);
      $pledgesStmt->execute();
      $pledgesResult = $pledgesStmt->get_result();
      while ($pledge = $pledgesResult->fetch_assoc()) {
        $countPledges++;
        $donorName = ($pledge['anonymous'] ?? 0) ? 'Anonymous' : ($pledge['donor_name'] ?: 'Anonymous');
        echo '<tr>';
        echo '<td>' . $rowNumber++ . '</td>';
        echo '<td>pledges</td>';
        echo '<td>Pledge</td>';
        echo '<td>' . $esc($pledge['id']) . '</td>';
        echo '<td></td>';
        echo '<td>' . $esc($pledge['donor_id'] ?? '') . '</td>';
        echo '<td>' . $esc($donorName) . '</td>';
        echo '<td>' . $esc($pledge['donor_phone'] ?? '') . '</td>';
        echo '<td>' . $esc($pledge['donor_email'] ?? '') . '</td>';
        echo '<td class="number">' . number_format((float)($pledge['amount'] ?? 0), 2) . '</td>';
        echo '<td>' . $esc($pledge['status'] ?? '') . '</td>';
        echo '<td></td>';
        echo '<td></td>';
        echo '<td>' . $esc($pledge['notes'] ?? '') . '</td>';
        echo '<td>' . $esc($pledge['package_label'] ?: 'Custom') . '</td>';
        echo '<td>' . $esc($pledge['transaction_date'] ?? '') . '</td>';
        echo '<td>' . $esc($pledge['recorded_by'] ?: 'Self Pledged') . '</td>';
        echo '<td>' . $esc($pledge['recorded_by_user_id'] ?? '') . '</td>';
        echo '</tr>';
      }
      $pledgesStmt->close();
      
      // Payments
      $paymentDonorIdSelect = $paymentsHasDonorId ? "p.donor_id" : "NULL";
      $paymentNotesSelect = $paymentsHasNotes ? "p.notes" : "''";
      $paymentDateCol = $paymentsHasReceivedAt ? "p.received_at" : "p.created_at";
      $paymentsStmt = $db->prepare("
        SELECT
          p.id,
          {$paymentDonorIdSelect} AS donor_id,
          p.donor_name,
          p.donor_phone,
          p.donor_email,
          p.amount,
          p.status,
          p.method,
          p.reference,
          {$paymentNotesSelect} AS notes,
          dp.label AS package_label,
          {$paymentDateCol} AS transaction_date,
          u.name AS recorded_by,
          p.received_by_user_id AS recorded_by_user_id
        FROM payments p
        LEFT JOIN donation_packages dp ON dp.id = p.package_id
        LEFT JOIN users u ON u.id = p.received_by_user_id
        WHERE {$paymentDateCol} BETWEEN ? AND ?
        ORDER BY {$paymentDateCol} DESC
      ");
      $paymentsStmt->bind_param('ss', $fromDate, $toDate);
      $paymentsStmt->execute();
      $paymentsResult = $paymentsStmt->get_result();
      while ($payment = $paymentsResult->fetch_assoc()) {
        $countPayments++;
        echo '<tr>';
        echo '<td>' . $rowNumber++ . '</td>';
        echo '<td>payments</td>';
        echo '<td>Payment</td>';
        echo '<td>' . $esc($payment['id']) . '</td>';
        echo '<td></td>';
        echo '<td>' . $esc($payment['donor_id'] ?? '') . '</td>';
        echo '<td>' . $esc($payment['donor_name'] ?: 'Anonymous') . '</td>';
        echo '<td>' . $esc($payment['donor_phone'] ?? '') . '</td>';
        echo '<td>' . $esc($payment['donor_email'] ?? '') . '</td>';
        echo '<td class="number">' . number_format((float)($payment['amount'] ?? 0), 2) . '</td>';
        echo '<td>' . $esc($payment['status'] ?? '') . '</td>';
        echo '<td>' . $esc($payment['method'] ?? '') . '</td>';
        echo '<td>' . $esc($payment['reference'] ?? '') . '</td>';
        echo '<td>' . $esc($payment['notes'] ?? '') . '</td>';
        echo '<td>' . $esc($payment['package_label'] ?: 'Custom') . '</td>';
        echo '<td>' . $esc($payment['transaction_date'] ?? '') . '</td>';
        echo '<td>' . $esc($payment['recorded_by'] ?: 'Direct') . '</td>';
        echo '<td>' . $esc($payment['recorded_by_user_id'] ?? '') . '</td>';
        echo '</tr>';
      }
      $paymentsStmt->close();

      // Get pledge payments
      // Note: $hasPledgePayments already set from FinancialCalculator at top of file
      if ($hasPledgePayments) {
          $ppPledgeIdSelect = $ppHasPledgeId ? "pp.pledge_id" : "NULL";
          $ppMethodSelect = $ppHasPaymentMethod ? "pp.payment_method" : "''";
          $ppRefSelect = $ppHasReferenceNumber ? "pp.reference_number" : "''";
          $ppDateSelect = $ppHasPaymentDate ? "COALESCE(pp.payment_date, pp.created_at)" : "pp.created_at";

          $ppApprovedJoin = $ppHasApprovedBy ? "LEFT JOIN users approver ON pp.approved_by_user_id = approver.id" : "";
          $ppApprovedSelect = $ppHasApprovedBy ? "approver.name AS approved_by" : "'' AS approved_by";
          $ppVoidedJoin = $ppHasVoidedBy ? "LEFT JOIN users voider ON pp.voided_by_user_id = voider.id" : "";
          $ppVoidedSelect = $ppHasVoidedBy ? "voider.name AS voided_by" : "'' AS voided_by";

          $ppStmt = $db->prepare("
            SELECT
              pp.id,
              {$ppPledgeIdSelect} AS pledge_id,
              pp.donor_id,
              COALESCE(d.name, 'Unknown') AS donor_name,
              COALESCE(d.phone, '') AS donor_phone,
              COALESCE(d.email, '') AS donor_email,
              pp.amount,
              pp.status,
              pp.notes,
              {$ppMethodSelect} AS method,
              {$ppRefSelect} AS reference,
              {$ppDateSelect} AS transaction_date,
              COALESCE(u.name, 'Admin') AS recorded_by,
              pp.processed_by_user_id AS recorded_by_user_id,
              {$ppApprovedSelect},
              {$ppVoidedSelect}
            FROM pledge_payments pp
            LEFT JOIN donors d ON d.id = pp.donor_id
            LEFT JOIN users u ON u.id = pp.processed_by_user_id
            {$ppApprovedJoin}
            {$ppVoidedJoin}
            WHERE {$ppDateSelect} BETWEEN ? AND ?
            ORDER BY {$ppDateSelect} DESC
          ");
          $ppStmt->bind_param('ss', $fromDate, $toDate);
          $ppStmt->execute();
          $ppResult = $ppStmt->get_result();
          while ($pp = $ppResult->fetch_assoc()) {
            $countPledgePayments++;
            echo '<tr>';
            echo '<td>' . $rowNumber++ . '</td>';
            echo '<td>pledge_payments</td>';
            echo '<td>Pledge Payment</td>';
            echo '<td>' . $esc($pp['id']) . '</td>';
            echo '<td>' . $esc($pp['pledge_id'] ?? '') . '</td>';
            echo '<td>' . $esc($pp['donor_id'] ?? '') . '</td>';
            echo '<td>' . $esc($pp['donor_name'] ?? 'Unknown') . '</td>';
            echo '<td>' . $esc($pp['donor_phone'] ?? '') . '</td>';
            echo '<td>' . $esc($pp['donor_email'] ?? '') . '</td>';
            echo '<td class="number">' . number_format((float)($pp['amount'] ?? 0), 2) . '</td>';
            echo '<td>' . $esc($pp['status'] ?? '') . '</td>';
            echo '<td>' . $esc($pp['method'] ?? '') . '</td>';
            echo '<td>' . $esc($pp['reference'] ?? '') . '</td>';
            // Include additional recovery hints in Notes if available (approved/voided by)
            $noteBits = [];
            $rawNotes = $pp['notes'] ?? '';
            if ($rawNotes !== '') {
              $noteBits[] = $rawNotes;
            }
            if (!empty($pp['approved_by'])) {
              $noteBits[] = 'Approved by: ' . $pp['approved_by'];
            }
            if (!empty($pp['voided_by'])) {
              $noteBits[] = 'Voided by: ' . $pp['voided_by'];
            }
            echo '<td>' . $esc(implode(' | ', $noteBits)) . '</td>';
            echo '<td>Pledge Fulfillment</td>';
            echo '<td>' . $esc($pp['transaction_date'] ?? '') . '</td>';
            echo '<td>' . $esc($pp['recorded_by'] ?? 'Admin') . '</td>';
            echo '<td>' . $esc($pp['recorded_by_user_id'] ?? '') . '</td>';
            echo '</tr>';
          }
          $ppStmt->close();
      }
      
      echo '</table>';

      // Summary (counts only, to avoid mixing pledges + payments totals)
      echo '<br>';
      echo '<table>';
      echo '<tr><th colspan="2">Export Summary</th></tr>';
      echo '<tr><td>Pledges rows</td><td class="number">' . number_format($countPledges) . '</td></tr>';
      echo '<tr><td>Payments rows</td><td class="number">' . number_format($countPayments) . '</td></tr>';
      echo '<tr><td>Pledge payments rows</td><td class="number">' . number_format($countPledgePayments) . '</td></tr>';
      echo '<tr><td><strong>Total rows</strong></td><td class="number"><strong>' . number_format($countPledges + $countPayments + $countPledgePayments) . '</strong></td></tr>';
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
