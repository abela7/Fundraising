<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

// Resilient DB setup
$db_error_message = '';
$settings = [
    'currency_code' => 'GBP',
    'target_amount' => 0,
];
$db = null;
try {
    $db = db();
    $settings_table_exists = $db->query("SHOW TABLES LIKE 'settings'")->num_rows > 0;
    if ($settings_table_exists) {
        $settings = $db->query('SELECT target_amount, currency_code FROM settings WHERE id = 1')->fetch_assoc() ?: $settings;
    } else {
        $db_error_message = '`settings` table not found.';
    }
} catch (Exception $e) {
    $db_error_message = 'Database connection failed: ' . $e->getMessage();
}

$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');

// Date range resolver (align with reports index)
function resolve_range(): array {
    $range = $_GET['date'] ?? 'month';
    $from = $_GET['from'] ?? '';
    $to   = $_GET['to'] ?? '';
    $now = new DateTime('now');
    switch ($range) {
        case 'today':   $start = (clone $now)->setTime(0,0,0); $end = (clone $now)->setTime(23,59,59); break;
        case 'week':    $start = (clone $now)->modify('monday this week')->setTime(0,0,0); $end = (clone $now)->modify('sunday this week')->setTime(23,59,59); break;
        case 'quarter': $q = floor(((int)$now->format('n')-1)/3)+1; $start = new DateTime($now->format('Y').'-'.(1+($q-1)*3).'-01 00:00:00'); $end = (clone $start)->modify('+3 months -1 second'); break;
        case 'year':    $start = new DateTime($now->format('Y').'-01-01 00:00:00'); $end = new DateTime($now->format('Y').'-12-31 23:59:59'); break;
        case 'custom':  $start = DateTime::createFromFormat('Y-m-d', $from) ?: (clone $now); $start->setTime(0,0,0); $end = DateTime::createFromFormat('Y-m-d', $to) ?: (clone $now); $end->setTime(23,59,59); break;
        case 'all':     $start = new DateTime('1970-01-01 00:00:00'); $end = new DateTime('2100-01-01 00:00:00'); break;
        default:        $start = new DateTime(date('Y-m-01 00:00:00')); $end = (clone $start)->modify('+1 month -1 second'); break; // month
    }
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

[$fromDate, $toDate] = resolve_range();

// Exports were intentionally removed from the comprehensive report

// Data Quality drilldown (AJAX, dynamic)
if (isset($_GET['dq'])) {
    header('Content-Type: application/json');
    $kind = (string)$_GET['dq'];
    $resp = [ 'success' => true, 'rows' => [] ];
    if ($db && $db_error_message === '') {
        if ($kind === 'missing_contact_payments') {
            $sql = "SELECT id, donor_name, amount, method, reference, status, received_at
                    FROM payments
                    WHERE COALESCE(donor_phone,'')='' AND COALESCE(donor_email,'')=''
                      AND received_at BETWEEN ? AND ?
                    ORDER BY received_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('ss', $fromDate, $toDate);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) { $resp['rows'][] = $r; }
        } elseif ($kind === 'missing_contact_pledges') {
            $sql = "SELECT id, donor_name, amount, type, status, notes, created_at
                    FROM pledges
                    WHERE COALESCE(donor_phone,'')='' AND COALESCE(donor_email,'')=''
                      AND created_at BETWEEN ? AND ?
                    ORDER BY created_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('ss', $fromDate, $toDate);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) { $resp['rows'][] = $r; }
        } else {
            echo json_encode([ 'success' => false, 'error' => 'Unknown data quality key' ]);
            exit;
        }
        echo json_encode($resp);
        exit;
    }
    echo json_encode([ 'success' => false, 'error' => ($db_error_message ?: 'Database unavailable') ]);
    exit;
}

// Aggregate metrics (guard if DB available)
$metrics = [
    'paid_total' => 0.0,
    'pledged_total' => 0.0,
    'grand_total' => 0.0,
    'payments_count' => 0,
    'pledges_count' => 0,
    'donor_count' => 0,
    'avg_donation' => 0.0,
    'overall_outstanding' => 0.0,
    'range_outstanding' => 0.0,
];

$breakdowns = [
    'payments_by_method' => [],
    'payments_by_status' => [],
    'pledges_by_status' => [],
    'payments_by_package' => [],
    'pledges_by_package' => [],
];

$timeseries = [
    'dates' => [],
    'payments' => ['totals' => [], 'counts' => []],
    'pledges' => ['totals' => [], 'counts' => []],
];

$top_donors = [];
$top_registrars = [ 'payments' => [], 'pledges' => [] ];
$data_quality = [ 'missing_contact_payments' => 0, 'missing_contact_pledges' => 0, 'duplicate_phones' => [] ];
$recent_notes = [ 'payments' => [], 'pledges' => [] ];

// Pledge Payment Tracking
$pledge_tracking = [
    'total_pledged_donors' => 0,
    'donors_started_paying' => 0,
    'donors_completed' => 0,
    'donors_not_started' => 0,
    'donors_defaulted' => 0,
    'total_pledge_amount' => 0.0,
    'total_paid_towards_pledges' => 0.0,
    'total_remaining' => 0.0,
    'collection_rate' => 0.0,
    'pledge_payments_count' => 0,
    'avg_payment_amount' => 0.0,
    'payments_by_method' => [],
    'payments_by_status' => [],
    'recent_pledge_payments' => [],
    'top_pledge_payers' => [],
    'monthly_payments' => [], // For chart
];

// Initialize hasPledgePayments (will be updated if DB is available)
$hasPledgePayments = false;

if ($db && $db_error_message === '') {
    require_once '../../shared/FinancialCalculator.php';
    
    // Calculate totals using centralized logic
    $calculator = new FinancialCalculator();
    
    // Special handling for "All Time" reports
    $range = $_GET['date'] ?? 'month';
    if ($range === 'all') {
        // For "All Time", use position semantic (no date filter) to match dashboard
        $totals = $calculator->getTotals();
        
        $metrics['paid_total'] = $totals['total_paid'];
        $metrics['payments_count'] = $totals['total_payment_count'];
        $metrics['pledged_total'] = $totals['outstanding_pledged']; // Position: outstanding
        $metrics['pledges_count'] = $totals['pledge_count'];
        $metrics['grand_total'] = $totals['grand_total'];
    } else {
        // For date-filtered reports, use activity semantic
        $totals = $calculator->getTotals($fromDate, $toDate);
        
        $metrics['paid_total'] = $totals['total_paid'];
        $metrics['payments_count'] = $totals['total_payment_count'];
        $metrics['pledged_total'] = $totals['total_pledges']; // Activity: raw pledges created
        $metrics['pledges_count'] = $totals['pledge_count'];
    $metrics['grand_total'] = $metrics['paid_total'] + $metrics['pledged_total'];
    }
    
    // Extract for SQL query construction
    $hasPledgePayments = $totals['has_pledge_payments'];

    // Donor count in range (distinct across pledges/payments/pledge_payments)
    $unionSql = "SELECT DISTINCT CONCAT(COALESCE(donor_name,''),'|',COALESCE(donor_phone,''),'|',COALESCE(donor_email,'')) ident
              FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ?
              UNION
              SELECT DISTINCT CONCAT(COALESCE(donor_name,''),'|',COALESCE(donor_phone,''),'|',COALESCE(donor_email,'')) ident
              FROM pledges WHERE status='approved' AND created_at BETWEEN ? AND ?";
    
    if ($hasPledgePayments) {
        $unionSql .= " UNION
              SELECT DISTINCT CONCAT(COALESCE(d.name,''),'|',COALESCE(d.phone,''),'|',COALESCE(d.email,'')) ident
              FROM pledge_payments pp LEFT JOIN donors d ON pp.donor_id=d.id
              WHERE pp.status='confirmed' AND pp.created_at BETWEEN ? AND ?";
    }

    $sql = "SELECT COUNT(*) AS c FROM ($unionSql) t";
    $stmt = $db->prepare($sql); 
    if ($hasPledgePayments) {
        $stmt->bind_param('ssssss',$fromDate,$toDate,$fromDate,$toDate,$fromDate,$toDate);
    } else {
        $stmt->bind_param('ssss',$fromDate,$toDate,$fromDate,$toDate);
    }
    $stmt->execute(); $res=$stmt->get_result(); $row=$res->fetch_assoc(); $metrics['donor_count'] = (int)($row['c'] ?? 0);

    // Average donation (approved payments + pledge payments)
    // Weighted average? Or just Total Paid / Total Count?
    // Simple average: (Total Paid Amount) / (Total Count)
    if ($metrics['payments_count'] > 0) {
        $metrics['avg_donation'] = $metrics['paid_total'] / $metrics['payments_count'];
    } else {
        $metrics['avg_donation'] = 0;
    }

    // Outstanding (Overall: Total Pledged - Total Paid)
    // Total Paid includes both instant payments and pledge payments
    $sqlOutstanding = "SELECT (SELECT COALESCE(SUM(amount),0) FROM pledges WHERE status='approved') - (
        (SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='approved')";
    if ($hasPledgePayments) {
        $sqlOutstanding .= " + (SELECT COALESCE(SUM(amount),0) FROM pledge_payments WHERE status='confirmed')";
    }
    $sqlOutstanding .= ") AS outstanding";
    $row = $db->query($sqlOutstanding)->fetch_assoc() ?: ['outstanding'=>0];
    $metrics['overall_outstanding'] = max(0, (float)$row['outstanding']);

    // Range Outstanding (Pledges in range - Total Payments in range)
    $rangePledgeSql = "SELECT COALESCE(SUM(amount),0) FROM pledges WHERE status='approved' AND created_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."'";
    $rangePaymentsSql = "SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='approved' AND received_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."'";
    $rangePPSql = "SELECT 0";
    if ($hasPledgePayments) {
        $rangePPSql = "SELECT COALESCE(SUM(amount),0) FROM pledge_payments WHERE status='confirmed' AND created_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."'";
    }
    
    $row = $db->query("SELECT ($rangePledgeSql) - (($rangePaymentsSql) + ($rangePPSql)) AS outstanding")->fetch_assoc() ?: ['outstanding'=>0];
    $metrics['range_outstanding'] = max(0, (float)$row['outstanding']);

    // Breakdowns
    $stmt = $db->prepare("SELECT method, COUNT(*) c, COALESCE(SUM(amount),0) t FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ? GROUP BY method ORDER BY t DESC");
    $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $breakdowns['payments_by_method'][] = $r; }

    $res = $db->query("SELECT status, COUNT(*) c FROM payments WHERE received_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."' GROUP BY status");
    while($r=$res->fetch_assoc()){ $breakdowns['payments_by_status'][] = $r; }
    $res = $db->query("SELECT status, COUNT(*) c FROM pledges WHERE created_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."' GROUP BY status");
    while($r=$res->fetch_assoc()){ $breakdowns['pledges_by_status'][] = $r; }

    $stmt = $db->prepare("SELECT COALESCE(dp.label,'Custom') label, COUNT(*) c, COALESCE(SUM(p.amount),0) t
                           FROM payments p LEFT JOIN donation_packages dp ON dp.id=p.package_id
                           WHERE p.status='approved' AND p.received_at BETWEEN ? AND ?
                           GROUP BY label ORDER BY t DESC");
    $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $breakdowns['payments_by_package'][] = $r; }

    $stmt = $db->prepare("SELECT COALESCE(dp.label,'Custom') label, COUNT(*) c, COALESCE(SUM(p.amount),0) t
                           FROM pledges p LEFT JOIN donation_packages dp ON dp.id=p.package_id
                           WHERE p.status='approved' AND p.created_at BETWEEN ? AND ?
                           GROUP BY label ORDER BY t DESC");
    $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $breakdowns['pledges_by_package'][] = $r; }

    // Aggregate for pie chart: donations by package (payments + pledges)
    $donations_by_package = [];
    foreach ($breakdowns['payments_by_package'] as $p) {
        $label = (string)($p['label'] ?? 'Custom');
        $donations_by_package[$label] = ($donations_by_package[$label] ?? 0) + (float)($p['t'] ?? 0);
    }
    foreach ($breakdowns['pledges_by_package'] as $p) {
        $label = (string)($p['label'] ?? 'Custom');
        $donations_by_package[$label] = ($donations_by_package[$label] ?? 0) + (float)($p['t'] ?? 0);
    }
    arsort($donations_by_package);
    $breakdowns['donations_by_package_aggregated'] = array_map(
        function ($label, $total) { return ['name' => $label, 'value' => (float)$total]; },
        array_keys($donations_by_package),
        array_values($donations_by_package)
    );

    // Top donors
    $sql = "SELECT donor_name, donor_phone, donor_email,
                   SUM(CASE WHEN src='pledge' THEN amount ELSE 0 END) AS total_pledged,
                   SUM(CASE WHEN src='payment' THEN amount ELSE 0 END) AS total_paid,
                   MAX(last_seen_at) AS last_seen_at
            FROM (
              SELECT donor_name, donor_phone, donor_email, amount, created_at AS last_seen_at, 'pledge' AS src
              FROM pledges WHERE status='approved' AND created_at BETWEEN ? AND ?
              UNION ALL
              SELECT donor_name, donor_phone, donor_email, amount, received_at AS last_seen_at, 'payment' AS src
              FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ?
              " . ($hasPledgePayments ? "
              UNION ALL
              SELECT d.name AS donor_name, d.phone AS donor_phone, d.email AS donor_email, pp.amount, pp.created_at AS last_seen_at, 'payment' AS src
              FROM pledge_payments pp
              LEFT JOIN donors d ON pp.donor_id = d.id
              WHERE pp.status='confirmed' AND pp.created_at BETWEEN ? AND ?
              " : "") . "
            ) c
            GROUP BY donor_name, donor_phone, donor_email
            ORDER BY total_paid DESC, total_pledged DESC
            LIMIT 50";
    $stmt = $db->prepare($sql); 
    if ($hasPledgePayments) {
        $stmt->bind_param('ssssss',$fromDate,$toDate,$fromDate,$toDate,$fromDate,$toDate);
    } else {
        $stmt->bind_param('ssss',$fromDate,$toDate,$fromDate,$toDate);
    }
    $stmt->execute(); $res=$stmt->get_result();
    while($r=$res->fetch_assoc()){ $top_donors[] = $r; }

    // Top registrars (payments received_by)
    $sql = "SELECT u.name as user_name, p.received_by_user_id as user_id, COUNT(*) c, COALESCE(SUM(p.amount),0) t
            FROM payments p LEFT JOIN users u ON u.id = p.received_by_user_id
            WHERE p.status='approved' AND p.received_at BETWEEN ? AND ?
            GROUP BY p.received_by_user_id, u.name
            ORDER BY t DESC";
    $stmt = $db->prepare($sql); $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $top_registrars['payments'][] = $r; }

    // Top registrars (pledges created_by)
    $sql = "SELECT u.name as user_name, p.created_by_user_id as user_id, COUNT(*) c, COALESCE(SUM(p.amount),0) t
            FROM pledges p LEFT JOIN users u ON u.id = p.created_by_user_id
            WHERE p.status='approved' AND p.created_at BETWEEN ? AND ?
            GROUP BY p.created_by_user_id, u.name
            ORDER BY t DESC";
    $stmt = $db->prepare($sql); $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $top_registrars['pledges'][] = $r; }

    // Data quality
    $row = $db->query("SELECT COUNT(*) c FROM payments WHERE (COALESCE(donor_phone,'')='' AND COALESCE(donor_email,'')='') AND received_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."'")->fetch_assoc();
    $data_quality['missing_contact_payments'] = (int)($row['c'] ?? 0);
    $row = $db->query("SELECT COUNT(*) c FROM pledges WHERE (COALESCE(donor_phone,'')='' AND COALESCE(donor_email,'')='') AND created_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."'")->fetch_assoc();
    $data_quality['missing_contact_pledges'] = (int)($row['c'] ?? 0);
    $res = $db->query("SELECT donor_phone, COUNT(*) c FROM (
                         SELECT donor_phone FROM payments WHERE donor_phone IS NOT NULL AND donor_phone<>'' AND received_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."'
                         UNION ALL
                         SELECT donor_phone FROM pledges WHERE donor_phone IS NOT NULL AND donor_phone<>'' AND created_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."'
                       ) x GROUP BY donor_phone HAVING c >= 3 ORDER BY c DESC LIMIT 20");
    while($r=$res->fetch_assoc()){ $data_quality['duplicate_phones'][] = $r; }

    // Recent notes (non-numeric insights)
    $res = $db->query("SELECT donor_name, reference, method, received_at FROM payments WHERE COALESCE(reference,'')<>'' AND received_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."' ORDER BY received_at DESC LIMIT 15");
    while($r=$res->fetch_assoc()){ $recent_notes['payments'][] = $r; }
    $res = $db->query("SELECT donor_name, notes, created_at FROM pledges WHERE COALESCE(notes,'')<>'' AND created_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."' ORDER BY created_at DESC LIMIT 15");
    while($r=$res->fetch_assoc()){ $recent_notes['pledges'][] = $r; }

    // ============================================
    // PLEDGE PAYMENT TRACKING
    // ============================================
    if ($hasPledgePayments) {
        // Check if donors table has payment_status column
        $donorsHasPaymentStatus = false;
        $donorsCols = $db->query("SHOW COLUMNS FROM donors LIKE 'payment_status'");
        if ($donorsCols && $donorsCols->num_rows > 0) {
            $donorsHasPaymentStatus = true;
        }

        // Total donors who have pledges (from donors table)
        $row = $db->query("SELECT COUNT(*) c FROM donors WHERE total_pledged > 0")->fetch_assoc();
        $pledge_tracking['total_pledged_donors'] = (int)($row['c'] ?? 0);

        if ($donorsHasPaymentStatus) {
            // Donors by payment status
            $row = $db->query("SELECT COUNT(*) c FROM donors WHERE payment_status = 'paying'")->fetch_assoc();
            $pledge_tracking['donors_started_paying'] = (int)($row['c'] ?? 0);

            $row = $db->query("SELECT COUNT(*) c FROM donors WHERE payment_status = 'completed'")->fetch_assoc();
            $pledge_tracking['donors_completed'] = (int)($row['c'] ?? 0);

            $row = $db->query("SELECT COUNT(*) c FROM donors WHERE payment_status = 'not_started' AND total_pledged > 0")->fetch_assoc();
            $pledge_tracking['donors_not_started'] = (int)($row['c'] ?? 0);

            $row = $db->query("SELECT COUNT(*) c FROM donors WHERE payment_status = 'defaulted'")->fetch_assoc();
            $pledge_tracking['donors_defaulted'] = (int)($row['c'] ?? 0);
        } else {
            // Fallback: calculate from pledge_payments
            $row = $db->query("SELECT COUNT(DISTINCT donor_id) c FROM pledge_payments WHERE status = 'confirmed'")->fetch_assoc();
            $pledge_tracking['donors_started_paying'] = (int)($row['c'] ?? 0);
        }

        // Total pledge amount (all approved pledges)
        $row = $db->query("SELECT COALESCE(SUM(total_pledged), 0) t FROM donors WHERE total_pledged > 0")->fetch_assoc();
        $pledge_tracking['total_pledge_amount'] = (float)($row['t'] ?? 0);

        // Total paid towards pledges (confirmed pledge payments)
        $row = $db->query("SELECT COALESCE(SUM(amount), 0) t, COUNT(*) c FROM pledge_payments WHERE status = 'confirmed'")->fetch_assoc();
        $pledge_tracking['total_paid_towards_pledges'] = (float)($row['t'] ?? 0);
        $pledge_tracking['pledge_payments_count'] = (int)($row['c'] ?? 0);

        // Total remaining balance
        $row = $db->query("SELECT COALESCE(SUM(balance), 0) t FROM donors WHERE balance > 0")->fetch_assoc();
        $pledge_tracking['total_remaining'] = (float)($row['t'] ?? 0);

        // Collection rate - ensure we're using the right calculation
        // Collection rate = (Total Paid / Total Pledged) * 100
        if ($pledge_tracking['total_pledge_amount'] > 0) {
            $pledge_tracking['collection_rate'] = round(($pledge_tracking['total_paid_towards_pledges'] / $pledge_tracking['total_pledge_amount']) * 100, 1);
        } else {
            $pledge_tracking['collection_rate'] = 0.0;
        }

        // Monthly pledge payments for chart (last 12 months)
        $monthlyQuery = "
            SELECT 
                DATE_FORMAT(payment_date, '%Y-%m') as month,
                DATE_FORMAT(payment_date, '%b %Y') as month_label,
                COUNT(*) as payment_count,
                COALESCE(SUM(amount), 0) as total_amount
            FROM pledge_payments 
            WHERE status = 'confirmed' 
            AND payment_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY month, month_label
            ORDER BY month ASC
        ";
        $monthlyRes = $db->query($monthlyQuery);
        $monthlyData = [];
        while ($r = $monthlyRes->fetch_assoc()) {
            $monthlyData[] = [
                'month' => $r['month'],
                'label' => $r['month_label'],
                'count' => (int)$r['payment_count'],
                'amount' => (float)$r['total_amount']
            ];
        }
        $pledge_tracking['monthly_payments'] = $monthlyData;

        // Average payment amount
        if ($pledge_tracking['pledge_payments_count'] > 0) {
            $pledge_tracking['avg_payment_amount'] = $pledge_tracking['total_paid_towards_pledges'] / $pledge_tracking['pledge_payments_count'];
        }

        // Pledge payments by method (in date range)
        $stmt = $db->prepare("SELECT payment_method, COUNT(*) c, COALESCE(SUM(amount),0) t 
                              FROM pledge_payments 
                              WHERE status = 'confirmed' AND created_at BETWEEN ? AND ? 
                              GROUP BY payment_method ORDER BY t DESC");
        $stmt->bind_param('ss', $fromDate, $toDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $pledge_tracking['payments_by_method'][] = $r;
        }

        // Pledge payments by status (in date range)
        $stmt = $db->prepare("SELECT status, COUNT(*) c, COALESCE(SUM(amount),0) t 
                              FROM pledge_payments 
                              WHERE created_at BETWEEN ? AND ? 
                              GROUP BY status ORDER BY c DESC");
        $stmt->bind_param('ss', $fromDate, $toDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $pledge_tracking['payments_by_status'][] = $r;
        }

        // Recent pledge payments (in date range)
        $stmt = $db->prepare("SELECT pp.id, pp.amount, pp.payment_method, pp.payment_date, pp.status,
                                     d.name as donor_name, d.phone as donor_phone
                              FROM pledge_payments pp
                              LEFT JOIN donors d ON pp.donor_id = d.id
                              WHERE pp.created_at BETWEEN ? AND ?
                              ORDER BY pp.created_at DESC
                              LIMIT 10");
        $stmt->bind_param('ss', $fromDate, $toDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $pledge_tracking['recent_pledge_payments'][] = $r;
        }

        // Top pledge payers (all time for donors)
        $res = $db->query("SELECT d.id, d.name, d.phone, d.total_pledged, d.total_paid, d.balance, d.payment_status
                           FROM donors d
                           WHERE d.total_pledged > 0
                           ORDER BY d.total_paid DESC
                           LIMIT 10");
        while ($r = $res->fetch_assoc()) {
            $pledge_tracking['top_pledge_payers'][] = $r;
        }
    }
}

$progress = ($settings['target_amount'] ?? 0) > 0 ? round((($metrics['paid_total'] + $metrics['pledged_total']) / (float)$settings['target_amount']) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprehensive Report - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/reports.css">
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.0/dist/echarts.min.js"></script>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <?php if (!empty($db_error_message)): ?>
                    <div class="alert alert-danger m-3">
                        <strong><i class="fas fa-exclamation-triangle me-2"></i>Database Error:</strong>
                        <?php echo htmlspecialchars($db_error_message); ?>
                    </div>
                <?php endif; ?>

                <?php
                // Determine current filter for display
                $currentRange = $_GET['date'] ?? 'month';
                $fromParam = $_GET['from'] ?? '';
                $toParam = $_GET['to'] ?? '';
                
                // Build display label
                $rangeLabel = match($currentRange) {
                    'today' => 'Today (' . date('d M Y') . ')',
                    'week' => 'This Week (' . (new DateTime())->modify('monday this week')->format('d M') . ' - ' . (new DateTime())->modify('sunday this week')->format('d M Y') . ')',
                    'month' => date('F Y'),
                    'quarter' => 'Q' . ceil(date('n')/3) . ' ' . date('Y'),
                    'year' => 'Year ' . date('Y'),
                    'all' => 'All Time',
                    'custom' => ($fromParam && $toParam) 
                        ? (DateTime::createFromFormat('Y-m-d', $fromParam)?->format('d M Y') ?? $fromParam) . ' to ' . (DateTime::createFromFormat('Y-m-d', $toParam)?->format('d M Y') ?? $toParam)
                        : 'Custom Range',
                    default => 'This Month',
                };
                ?>
                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="mb-1"><i class="fas fa-file-alt text-primary me-2"></i>Comprehensive Report</h4>
                        <small class="text-muted"><i class="fas fa-calendar-check me-1"></i><?php echo htmlspecialchars($rangeLabel); ?></small>
                    </div>
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#datePickerPanel" aria-expanded="false">
                            <i class="fas fa-calendar-alt me-1"></i>Select Dates
                        </button>
                        <button class="btn btn-outline-secondary" type="button" id="toggleAllAccordions" onclick="toggleAllSections()">
                            <i class="fas fa-expand-alt me-1" id="toggleIcon"></i><span id="toggleText">Expand All</span>
                        </button>
                        <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
                    </div>
                </div>

                <!-- Custom Date Picker Panel -->
                <div class="collapse <?php echo ($currentRange === 'custom' || isset($_GET['showPicker'])) ? 'show' : ''; ?> mb-3" id="datePickerPanel">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="row g-3">
                                <!-- Quick Presets -->
                                <div class="col-12">
                                    <label class="form-label fw-semibold text-muted mb-2"><i class="fas fa-bolt me-1"></i>Quick Presets</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a class="btn btn-sm <?php echo $currentRange === 'today' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?date=today"><i class="fas fa-clock me-1"></i>Today</a>
                                        <a class="btn btn-sm <?php echo $currentRange === 'week' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?date=week"><i class="fas fa-calendar-week me-1"></i>This Week</a>
                                        <a class="btn btn-sm <?php echo $currentRange === 'month' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?date=month"><i class="fas fa-calendar me-1"></i>This Month</a>
                                        <a class="btn btn-sm <?php echo $currentRange === 'quarter' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?date=quarter"><i class="fas fa-calendar-alt me-1"></i>This Quarter</a>
                                        <a class="btn btn-sm <?php echo $currentRange === 'year' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?date=year"><i class="fas fa-calendar-day me-1"></i>This Year</a>
                                        <a class="btn btn-sm <?php echo $currentRange === 'all' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?date=all"><i class="fas fa-infinity me-1"></i>All Time</a>
                                    </div>
                                </div>
                                
                                <div class="col-12"><hr class="my-2"></div>
                                
                                <!-- Custom Date Selection -->
                                <div class="col-12">
                                    <label class="form-label fw-semibold text-muted mb-2"><i class="fas fa-sliders-h me-1"></i>Custom Selection</label>
                                    
                                    <!-- Selection Type Tabs -->
                                    <ul class="nav nav-pills nav-fill mb-3" id="dateSelectionTabs" role="tablist">
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link active" id="tab-day" data-bs-toggle="pill" data-bs-target="#panel-day" type="button" role="tab">
                                                <i class="fas fa-calendar-day me-1"></i>Specific Day
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="tab-week" data-bs-toggle="pill" data-bs-target="#panel-week" type="button" role="tab">
                                                <i class="fas fa-calendar-week me-1"></i>Specific Week
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="tab-month" data-bs-toggle="pill" data-bs-target="#panel-month" type="button" role="tab">
                                                <i class="fas fa-calendar me-1"></i>Specific Month
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="tab-range" data-bs-toggle="pill" data-bs-target="#panel-range" type="button" role="tab">
                                                <i class="fas fa-arrows-left-right me-1"></i>Date Range
                                            </button>
                                        </li>
                                    </ul>
                                    
                                    <div class="tab-content" id="dateSelectionContent">
                                        <!-- Specific Day Panel -->
                                        <div class="tab-pane fade show active" id="panel-day" role="tabpanel">
                                            <form method="get" class="row g-2 align-items-end" onsubmit="return setDayRange()">
                                                <input type="hidden" name="date" value="custom">
                                                <div class="col-md-4">
                                                    <label class="form-label small">Select Date</label>
                                                    <input type="date" class="form-control" id="dayDate" value="<?php echo date('Y-m-d'); ?>" required>
                                                </div>
                                                <div class="col-md-4">
                                                    <button type="submit" class="btn btn-primary w-100">
                                                        <i class="fas fa-search me-1"></i>View Day Report
                                                    </button>
                                                </div>
                                                <input type="hidden" name="from" id="dayFrom">
                                                <input type="hidden" name="to" id="dayTo">
                                            </form>
                                        </div>
                                        
                                        <!-- Specific Week Panel -->
                                        <div class="tab-pane fade" id="panel-week" role="tabpanel">
                                            <form method="get" class="row g-2 align-items-end" onsubmit="return setWeekRange()">
                                                <input type="hidden" name="date" value="custom">
                                                <div class="col-md-4">
                                                    <label class="form-label small">Select Any Date in Week</label>
                                                    <input type="date" class="form-control" id="weekDate" value="<?php echo date('Y-m-d'); ?>" required>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="text-muted small" id="weekPreview">
                                                        Week: <?php 
                                                        $mon = (new DateTime())->modify('monday this week');
                                                        $sun = (new DateTime())->modify('sunday this week');
                                                        echo $mon->format('d M') . ' - ' . $sun->format('d M Y');
                                                        ?>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <button type="submit" class="btn btn-primary w-100">
                                                        <i class="fas fa-search me-1"></i>View Week Report
                                                    </button>
                                                </div>
                                                <input type="hidden" name="from" id="weekFrom">
                                                <input type="hidden" name="to" id="weekTo">
                                            </form>
                                        </div>
                                        
                                        <!-- Specific Month Panel -->
                                        <div class="tab-pane fade" id="panel-month" role="tabpanel">
                                            <form method="get" class="row g-2 align-items-end" onsubmit="return setMonthRange()">
                                                <input type="hidden" name="date" value="custom">
                                                <div class="col-md-3">
                                                    <label class="form-label small">Month</label>
                                                    <select class="form-select" id="monthSelect">
                                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                                            <option value="<?php echo $m; ?>" <?php echo $m == date('n') ? 'selected' : ''; ?>>
                                                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                                            </option>
                                                        <?php endfor; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small">Year</label>
                                                    <select class="form-select" id="yearSelect">
                                                        <?php 
                                                        $currentYear = (int)date('Y');
                                                        for ($y = $currentYear; $y >= $currentYear - 5; $y--): 
                                                        ?>
                                                            <option value="<?php echo $y; ?>" <?php echo $y == $currentYear ? 'selected' : ''; ?>>
                                                                <?php echo $y; ?>
                                                            </option>
                                                        <?php endfor; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="text-muted small" id="monthPreview">
                                                        <?php echo date('F Y'); ?>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <button type="submit" class="btn btn-primary w-100">
                                                        <i class="fas fa-search me-1"></i>View Month Report
                                                    </button>
                                                </div>
                                                <input type="hidden" name="from" id="monthFrom">
                                                <input type="hidden" name="to" id="monthTo">
                                            </form>
                                        </div>
                                        
                                        <!-- Date Range Panel -->
                                        <div class="tab-pane fade" id="panel-range" role="tabpanel">
                                            <form method="get" class="row g-2 align-items-end">
                                                <input type="hidden" name="date" value="custom">
                                                <div class="col-md-3">
                                                    <label class="form-label small">From Date</label>
                                                    <input type="date" class="form-control" name="from" id="rangeFrom" value="<?php echo htmlspecialchars($fromParam ?: date('Y-m-01')); ?>" required>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small">To Date</label>
                                                    <input type="date" class="form-control" name="to" id="rangeTo" value="<?php echo htmlspecialchars($toParam ?: date('Y-m-d')); ?>" required>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="text-muted small" id="rangePreview">
                                                        <!-- Preview updated by JS -->
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <button type="submit" class="btn btn-primary w-100">
                                                        <i class="fas fa-search me-1"></i>View Range Report
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Accordion Container -->
                <div class="accordion accordion-flush" id="reportAccordion">
                    
                    <!-- Key Metrics - Always Expanded -->
                    <div class="accordion-item border-0 shadow-sm mb-3">
                        <h2 class="accordion-header" id="headingMetrics">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseMetrics" aria-expanded="true" aria-controls="collapseMetrics">
                                <i class="fas fa-chart-line me-2 text-primary"></i><strong>Key Metrics</strong>
                            </button>
                        </h2>
                        <div id="collapseMetrics" class="accordion-collapse collapse show" aria-labelledby="headingMetrics" data-bs-parent="#reportAccordion">
                            <div class="accordion-body p-0">
                                <div class="row g-3 p-3">
                                    <div class="col-xl-3 col-md-6">
                                        <div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center">
                                            <div class="icon-circle bg-primary text-white"><i class="fas fa-chart-line"></i></div>
                                            <div class="ms-3">
                                                <div class="small fw-bold text-primary mb-1">Total Raised</div>
                                                <div class="h5 mb-0"><?php echo $currency.' '.number_format($metrics['grand_total'], 2); ?></div>
                                                <div class="small text-muted">Progress: <?php echo (int)$progress; ?>%</div>
                                            </div>
                                        </div></div>
                                    </div>
                                    <div class="col-xl-3 col-md-6">
                                        <div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center">
                                            <div class="icon-circle bg-success text-white"><i class="fas fa-pound-sign"></i></div>
                                            <div class="ms-3">
                                                <div class="small fw-bold text-success mb-1">Paid (approved)</div>
                                                <div class="h5 mb-0"><?php echo $currency.' '.number_format($metrics['paid_total'], 2); ?></div>
                                                <div class="small text-muted">Payments: <?php echo number_format($metrics['payments_count']); ?></div>
                                            </div>
                                        </div></div>
                                    </div>
                                    <div class="col-xl-3 col-md-6">
                                        <div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center">
                                            <div class="icon-circle bg-warning text-white"><i class="fas fa-hand-holding-usd"></i></div>
                                            <div class="ms-3">
                                                <div class="small fw-bold text-warning mb-1">Pledged (approved)</div>
                                                <div class="h5 mb-0"><?php echo $currency.' '.number_format($metrics['pledged_total'], 2); ?></div>
                                                <div class="small text-muted">Pledges: <?php echo number_format($metrics['pledges_count']); ?></div>
                                            </div>
                                        </div></div>
                                    </div>
                                    <div class="col-xl-3 col-md-6">
                                        <div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center">
                                            <div class="icon-circle bg-info text-white"><i class="fas fa-users"></i></div>
                                            <div class="ms-3">
                                                <div class="small fw-bold text-info mb-1">Donors (range)</div>
                                                <div class="h5 mb-0"><?php echo number_format($metrics['donor_count']); ?></div>
                                                <div class="small text-muted">Avg donation: <?php echo $currency.' '.number_format($metrics['avg_donation'],2); ?></div>
                                            </div>
                                        </div></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pledge Payment Tracking -->
                    <?php if ($hasPledgePayments): ?>
                    <div class="accordion-item border-0 shadow-sm mb-3">
                        <h2 class="accordion-header" id="headingPledgeTracking">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePledgeTracking" aria-expanded="false" aria-controls="collapsePledgeTracking">
                                <i class="fas fa-money-bill-transfer me-2 text-success"></i><strong>Pledge Payment Tracking</strong>
                                <span class="badge bg-success ms-2"><?php echo number_format($pledge_tracking['pledge_payments_count']); ?> payments</span>
                            </button>
                        </h2>
                        <div id="collapsePledgeTracking" class="accordion-collapse collapse" aria-labelledby="headingPledgeTracking" data-bs-parent="#reportAccordion">
                            <div class="accordion-body p-0">
                                <!-- Monthly Chart -->
                                <div class="p-3">
                                    <div class="card border-0 shadow-sm mb-3">
                                        <div class="card-body">
                                            <h6 class="mb-3"><i class="fas fa-chart-line me-2 text-success"></i>Monthly Pledge Payment Collection (Last 12 Months)</h6>
                                            <div id="pledgeMonthlyChart" style="height: 350px;"></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Key Metrics Row -->
                                <div class="row g-3 px-3 pb-3">
                                    <div class="col-xl-3 col-md-6">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body">
                                                <div class="d-flex align-items-center">
                                                    <div class="icon-circle bg-success text-white"><i class="fas fa-percentage"></i></div>
                                                    <div class="ms-3">
                                                        <div class="small text-muted">Collection Rate</div>
                                                        <div class="h4 mb-0"><?php echo number_format($pledge_tracking['collection_rate'], 1); ?>%</div>
                                                        <div class="progress mt-2" style="height: 6px;">
                                                            <div class="progress-bar bg-success" style="width: <?php echo min(100, $pledge_tracking['collection_rate']); ?>%"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-xl-3 col-md-6">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body">
                                                <div class="d-flex align-items-center">
                                                    <div class="icon-circle bg-primary text-white"><i class="fas fa-hand-holding-dollar"></i></div>
                                                    <div class="ms-3">
                                                        <div class="small text-muted">Total Pledged</div>
                                                        <div class="h5 mb-0"><?php echo $currency . ' ' . number_format($pledge_tracking['total_pledge_amount'], 2); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-xl-3 col-md-6">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body">
                                                <div class="d-flex align-items-center">
                                                    <div class="icon-circle bg-success text-white"><i class="fas fa-check-circle"></i></div>
                                                    <div class="ms-3">
                                                        <div class="small text-muted">Collected</div>
                                                        <div class="h5 mb-0"><?php echo $currency . ' ' . number_format($pledge_tracking['total_paid_towards_pledges'], 2); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-xl-3 col-md-6">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body">
                                                <div class="d-flex align-items-center">
                                                    <div class="icon-circle bg-warning text-white"><i class="fas fa-hourglass-half"></i></div>
                                                    <div class="ms-3">
                                                        <div class="small text-muted">Remaining</div>
                                                        <div class="h5 mb-0"><?php echo $currency . ' ' . number_format($pledge_tracking['total_remaining'], 2); ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Donor Status Cards -->
                                <div class="row g-3 px-3 pb-3">
                                    <div class="col-12">
                                        <h6 class="mb-3"><i class="fas fa-users me-2 text-primary"></i>Donor Payment Progress</h6>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="p-3 bg-light rounded text-center">
                                            <div class="h4 mb-1 text-primary"><?php echo number_format($pledge_tracking['total_pledged_donors']); ?></div>
                                            <div class="small text-muted">Total Pledged</div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="p-3 bg-light rounded text-center">
                                            <div class="h4 mb-1 text-info"><?php echo number_format($pledge_tracking['donors_not_started']); ?></div>
                                            <div class="small text-muted">Not Started</div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="p-3 bg-light rounded text-center">
                                            <div class="h4 mb-1 text-warning"><?php echo number_format($pledge_tracking['donors_started_paying']); ?></div>
                                            <div class="small text-muted">Paying</div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="p-3 bg-light rounded text-center">
                                            <div class="h4 mb-1 text-success"><?php echo number_format($pledge_tracking['donors_completed']); ?></div>
                                            <div class="small text-muted">Completed</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Payment Breakdowns -->
                                <div class="row g-3 px-3 pb-3">
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body">
                                                <h6 class="mb-3"><i class="fas fa-credit-card me-2 text-success"></i>Pledge Payments by Method</h6>
                                                <?php if (empty($pledge_tracking['payments_by_method'])): ?>
                                                    <div class="text-muted">No payments in this range.</div>
                                                <?php else: ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm align-middle">
                                                        <thead><tr><th>Method</th><th class="text-end">Count</th><th class="text-end">Total (<?php echo $currency; ?>)</th></tr></thead>
                                                        <tbody>
                                                            <?php foreach ($pledge_tracking['payments_by_method'] as $r): ?>
                                                                <tr>
                                                                    <td data-label="Method"><?php echo htmlspecialchars(ucfirst((string)($r['payment_method'] ?? 'Unknown'))); ?></td>
                                                                    <td class="text-end" data-label="Count"><?php echo number_format((int)$r['c']); ?></td>
                                                                    <td class="text-end" data-label="Total"><?php echo number_format((float)$r['t'], 2); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body">
                                                <h6 class="mb-3"><i class="fas fa-tags me-2 text-secondary"></i>Pledge Payments by Status</h6>
                                                <?php if (empty($pledge_tracking['payments_by_status'])): ?>
                                                    <div class="text-muted">No payments in this range.</div>
                                                <?php else: ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm align-middle">
                                                        <thead><tr><th>Status</th><th class="text-end">Count</th><th class="text-end">Amount (<?php echo $currency; ?>)</th></tr></thead>
                                                        <tbody>
                                                            <?php foreach ($pledge_tracking['payments_by_status'] as $r): 
                                                                $statusClass = 'secondary';
                                                                if ($r['status'] === 'confirmed') $statusClass = 'success';
                                                                elseif ($r['status'] === 'pending') $statusClass = 'warning';
                                                                elseif ($r['status'] === 'voided') $statusClass = 'danger';
                                                            ?>
                                                                <tr>
                                                                    <td data-label="Status"><span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst((string)$r['status'])); ?></span></td>
                                                                    <td class="text-end" data-label="Count"><?php echo number_format((int)$r['c']); ?></td>
                                                                    <td class="text-end" data-label="Amount"><?php echo number_format((float)$r['t'], 2); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Top Pledge Payers & Recent Payments -->
                                <div class="row g-3 px-3 pb-3">
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body">
                                                <h6 class="mb-3"><i class="fas fa-trophy me-2 text-warning"></i>Top Pledge Payers</h6>
                                                <?php if (empty($pledge_tracking['top_pledge_payers'])): ?>
                                                    <div class="text-muted">No pledge payers yet.</div>
                                                <?php else: ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm align-middle">
                                                        <thead><tr><th>Donor</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
                                                        <tbody>
                                                            <?php foreach ($pledge_tracking['top_pledge_payers'] as $r): 
                                                                $pStatus = $r['payment_status'] ?? 'unknown';
                                                                $pClass = 'secondary';
                                                                if ($pStatus === 'completed') $pClass = 'success';
                                                                elseif ($pStatus === 'paying') $pClass = 'info';
                                                                elseif ($pStatus === 'not_started') $pClass = 'warning';
                                                                elseif ($pStatus === 'defaulted') $pClass = 'danger';
                                                            ?>
                                                                <tr>
                                                                    <td data-label="Donor">
                                                                        <div class="fw-medium"><?php echo htmlspecialchars((string)($r['name'] ?? 'Anonymous')); ?></div>
                                                                        <small class="text-muted"><?php echo htmlspecialchars((string)($r['phone'] ?? '')); ?></small>
                                                                    </td>
                                                                    <td class="text-end" data-label="Paid"><?php echo number_format((float)($r['total_paid'] ?? 0), 2); ?></td>
                                                                    <td class="text-end" data-label="Balance"><?php echo number_format((float)($r['balance'] ?? 0), 2); ?></td>
                                                                    <td data-label="Status"><span class="badge bg-<?php echo $pClass; ?>"><?php echo ucfirst(str_replace('_', ' ', $pStatus)); ?></span></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body">
                                                <h6 class="mb-3"><i class="fas fa-clock me-2 text-info"></i>Recent Pledge Payments</h6>
                                                <?php if (empty($pledge_tracking['recent_pledge_payments'])): ?>
                                                    <div class="text-muted">No recent payments in this range.</div>
                                                <?php else: ?>
                                                <ul class="list-group list-group-flush">
                                                    <?php foreach ($pledge_tracking['recent_pledge_payments'] as $r): 
                                                        $ppStatus = $r['status'] ?? 'unknown';
                                                        $ppClass = 'secondary';
                                                        if ($ppStatus === 'confirmed') $ppClass = 'success';
                                                        elseif ($ppStatus === 'pending') $ppClass = 'warning';
                                                        elseif ($ppStatus === 'voided') $ppClass = 'danger';
                                                    ?>
                                                        <li class="list-group-item border-0 px-0 py-2">
                                                            <div class="d-flex justify-content-between align-items-center">
                                                                <div>
                                                                    <strong><?php echo htmlspecialchars((string)($r['donor_name'] ?? 'Anonymous')); ?></strong>
                                                                    <span class="badge bg-<?php echo $ppClass; ?> ms-2"><?php echo ucfirst($ppStatus); ?></span>
                                                                    <div class="text-muted small">
                                                                        <?php echo $currency . ' ' . number_format((float)($r['amount'] ?? 0), 2); ?> 
                                                                        · <?php echo htmlspecialchars(ucfirst((string)($r['payment_method'] ?? ''))); ?>
                                                                    </div>
                                                                </div>
                                                                <div class="text-muted small text-end">
                                                                    <?php echo htmlspecialchars((string)($r['payment_date'] ?? '')); ?>
                                                                </div>
                                                            </div>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Quick Stats Summary -->
                                <div class="px-3 pb-3">
                                    <div class="card border-0 shadow-sm bg-light">
                                        <div class="card-body">
                                            <div class="row text-center">
                                                <div class="col-6 col-md-3 border-end">
                                                    <div class="h5 mb-0 text-primary"><?php echo number_format($pledge_tracking['pledge_payments_count']); ?></div>
                                                    <div class="small text-muted">Total Payments</div>
                                                </div>
                                                <div class="col-6 col-md-3 border-end">
                                                    <div class="h5 mb-0 text-success"><?php echo $currency . ' ' . number_format($pledge_tracking['avg_payment_amount'], 2); ?></div>
                                                    <div class="small text-muted">Avg Payment</div>
                                                </div>
                                                <div class="col-6 col-md-3 border-end">
                                                    <div class="h5 mb-0 text-info"><?php echo number_format($pledge_tracking['donors_started_paying'] + $pledge_tracking['donors_completed']); ?></div>
                                                    <div class="small text-muted">Active Payers</div>
                                                </div>
                                                <div class="col-6 col-md-3">
                                                    <div class="h5 mb-0 text-danger"><?php echo number_format($pledge_tracking['donors_defaulted']); ?></div>
                                                    <div class="small text-muted">Defaulted</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Outstanding Balances -->
                    <div class="accordion-item border-0 shadow-sm mb-3">
                        <h2 class="accordion-header" id="headingOutstanding">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOutstanding" aria-expanded="false" aria-controls="collapseOutstanding">
                                <i class="fas fa-balance-scale me-2 text-primary"></i><strong>Outstanding Balances</strong>
                            </button>
                        </h2>
                        <div id="collapseOutstanding" class="accordion-collapse collapse" aria-labelledby="headingOutstanding" data-bs-parent="#reportAccordion">
                            <div class="accordion-body p-0">
                                <div class="row g-3 p-3">
                                    <div class="col-md-6">
                                        <div class="card border-0 shadow-sm h-100 outstanding-card"><div class="card-body">
                                            <h6 class="mb-2"><i class="fas fa-balance-scale me-2 text-primary"></i>Outstanding Balances</h6>
                                            <div class="d-flex justify-content-between"><span>Overall Outstanding</span><strong><?php echo $currency.' '.number_format($metrics['overall_outstanding'],2); ?></strong></div>
                                            <div class="d-flex justify-content-between text-muted"><span>Range Outstanding</span><span><?php echo $currency.' '.number_format($metrics['range_outstanding'],2); ?></span></div>
                                        </div></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Raised Breakdown Chart -->
                    <div class="accordion-item border-0 shadow-sm mb-3">
                        <h2 class="accordion-header" id="headingChart">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseChart" aria-expanded="false" aria-controls="collapseChart">
                                <i class="fas fa-chart-pie me-2 text-primary"></i><strong>Raised Breakdown Chart</strong>
                            </button>
                        </h2>
                        <div id="collapseChart" class="accordion-collapse collapse" aria-labelledby="headingChart" data-bs-parent="#reportAccordion">
                            <div class="accordion-body p-3">
                                <div id="pieContainer" style="height: 320px;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Breakdowns -->
                    <div class="accordion-item border-0 shadow-sm mb-3">
                        <h2 class="accordion-header" id="headingBreakdowns">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseBreakdowns" aria-expanded="false" aria-controls="collapseBreakdowns">
                                <i class="fas fa-receipt me-2 text-success"></i><strong>Payment Breakdowns</strong>
                            </button>
                        </h2>
                        <div id="collapseBreakdowns" class="accordion-collapse collapse" aria-labelledby="headingBreakdowns" data-bs-parent="#reportAccordion">
                            <div class="accordion-body p-0">
                                <div class="row g-3 p-3">
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                                            <h6 class="mb-3"><i class="fas fa-receipt me-2 text-success"></i>Payments by Method</h6>
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle">
                                                    <thead><tr><th>Method</th><th class="text-end">Count</th><th class="text-end">Total (<?php echo $currency; ?>)</th></tr></thead>
                                                    <tbody>
                                                        <?php foreach ($breakdowns['payments_by_method'] as $r): ?>
                                                            <tr><td data-label="Method"><?php echo htmlspecialchars(ucfirst((string)$r['method'])); ?></td><td class="text-end" data-label="Count"><?php echo number_format((int)$r['c']); ?></td><td class="text-end" data-label="Total"><?php echo number_format((float)$r['t'],2); ?></td></tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div></div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                                            <h6 class="mb-3"><i class="fas fa-boxes-stacked me-2 text-warning"></i>Donations by Package</h6>
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle">
                                                    <thead><tr><th>Type</th><th>Package</th><th class="text-end">Count</th><th class="text-end">Total (<?php echo $currency; ?>)</th></tr></thead>
                                                    <tbody>
                                                        <?php foreach ($breakdowns['payments_by_package'] as $r): ?>
                                                            <tr><td data-label="Type">Payment</td><td data-label="Package"><?php echo htmlspecialchars((string)$r['label']); ?></td><td class="text-end" data-label="Count"><?php echo number_format((int)$r['c']); ?></td><td class="text-end" data-label="Total"><?php echo number_format((float)$r['t'],2); ?></td></tr>
                                                        <?php endforeach; ?>
                                                        <?php foreach ($breakdowns['pledges_by_package'] as $r): ?>
                                                            <tr><td data-label="Type">Pledge</td><td data-label="Package"><?php echo htmlspecialchars((string)$r['label']); ?></td><td class="text-end" data-label="Count"><?php echo number_format((int)$r['c']); ?></td><td class="text-end" data-label="Total"><?php echo number_format((float)$r['t'],2); ?></td></tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Status Distribution -->
                    <div class="accordion-item border-0 shadow-sm mb-3">
                        <h2 class="accordion-header" id="headingStatus">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseStatus" aria-expanded="false" aria-controls="collapseStatus">
                                <i class="fas fa-list-check me-2 text-secondary"></i><strong>Status Distribution</strong>
                            </button>
                        </h2>
                        <div id="collapseStatus" class="accordion-collapse collapse" aria-labelledby="headingStatus" data-bs-parent="#reportAccordion">
                            <div class="accordion-body p-0">
                                <div class="row g-3 p-3">
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                                            <h6 class="mb-3"><i class="fas fa-list-check me-2 text-secondary"></i>Payments by Status</h6>
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle">
                                                    <thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead>
                                                    <tbody>
                                                        <?php foreach ($breakdowns['payments_by_status'] as $r): ?>
                                                            <tr><td data-label="Status"><?php echo htmlspecialchars(ucfirst((string)$r['status'])); ?></td><td class="text-end" data-label="Count"><?php echo number_format((int)$r['c']); ?></td></tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div></div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                                            <h6 class="mb-3"><i class="fas fa-list-check me-2 text-secondary"></i>Pledges by Status</h6>
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle">
                                                    <thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead>
                                                    <tbody>
                                                        <?php foreach ($breakdowns['pledges_by_status'] as $r): ?>
                                                            <tr><td data-label="Status"><?php echo htmlspecialchars(ucfirst((string)$r['status'])); ?></td><td class="text-end" data-label="Count"><?php echo number_format((int)$r['c']); ?></td></tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Donors -->
                    <div class="accordion-item border-0 shadow-sm mb-3">
                        <h2 class="accordion-header" id="headingTopDonors">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTopDonors" aria-expanded="false" aria-controls="collapseTopDonors">
                                <i class="fas fa-crown me-2 text-primary"></i><strong>Top Donors</strong>
                            </button>
                        </h2>
                        <div id="collapseTopDonors" class="accordion-collapse collapse" aria-labelledby="headingTopDonors" data-bs-parent="#reportAccordion">
                            <div class="accordion-body p-0">
                                <div class="p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                                        <h6 class="mb-0"><i class="fas fa-crown me-2 text-primary"></i>Top Donors</h6>
                                        <a class="btn btn-sm btn-outline-primary" href="?export=top_donors_csv&date=<?php echo urlencode($_GET['date'] ?? 'month'); ?>&from=<?php echo urlencode($_GET['from'] ?? ''); ?>&to=<?php echo urlencode($_GET['to'] ?? ''); ?>"><i class="fas fa-file-csv me-1"></i>CSV</a>
                                    </div>
                                    <div class="table-responsive table-responsive-mobile">
                                        <table class="table table-sm align-middle top-donors-mobile">
                                            <thead><tr><th>Donor</th><th>Phone</th><th>Email</th><th class="text-end">Pledged</th><th class="text-end">Paid</th><th class="text-end">Outstanding</th><th class="text-end">Last Seen</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($top_donors as $r): $pledged=(float)($r['total_pledged']??0); $paid=(float)($r['total_paid']??0); $outstanding=max($pledged-$paid,0); ?>
                                                    <tr>
                                                        <td data-label="Donor"><?php echo htmlspecialchars(($r['donor_name'] ?? '') !== '' ? (string)$r['donor_name'] : 'Anonymous'); ?></td>
                                                        <td data-label="Phone"><?php echo htmlspecialchars((string)($r['donor_phone'] ?? '')); ?></td>
                                                        <td data-label="Email"><?php echo htmlspecialchars((string)($r['donor_email'] ?? '')); ?></td>
                                                        <td class="text-end" data-label="Pledged"><?php echo number_format($pledged,2); ?></td>
                                                        <td class="text-end" data-label="Paid"><?php echo number_format($paid,2); ?></td>
                                                        <td class="text-end" data-label="Outstanding"><?php echo number_format($outstanding,2); ?></td>
                                                        <td class="text-end" data-label="Last Seen"><?php echo htmlspecialchars((string)($r['last_seen_at'] ?? '')); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Registrars -->
                    <div class="accordion-item border-0 shadow-sm mb-3">
                        <h2 class="accordion-header" id="headingTopRegistrars">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTopRegistrars" aria-expanded="false" aria-controls="collapseTopRegistrars">
                                <i class="fas fa-user-tie me-2 text-success"></i><strong>Top Registrars</strong>
                            </button>
                        </h2>
                        <div id="collapseTopRegistrars" class="accordion-collapse collapse" aria-labelledby="headingTopRegistrars" data-bs-parent="#reportAccordion">
                            <div class="accordion-body p-0">
                                <div class="row g-3 p-3">
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                                            <h6 class="mb-3"><i class="fas fa-user-tie me-2 text-success"></i>Top Registrars by Payments</h6>
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle">
                                                    <thead><tr><th>Registrar</th><th class="text-end">Count</th><th class="text-end">Total (<?php echo $currency; ?>)</th></tr></thead>
                                                    <tbody>
                                                        <?php foreach ($top_registrars['payments'] as $r): ?>
                                                            <tr><td data-label="Registrar"><?php echo htmlspecialchars((string)($r['user_name'] ?? 'Unknown')); ?></td><td class="text-end" data-label="Count"><?php echo number_format((int)$r['c']); ?></td><td class="text-end" data-label="Total"><?php echo number_format((float)$r['t'],2); ?></td></tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div></div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                                            <h6 class="mb-3"><i class="fas fa-user-pen me-2 text-warning"></i>Top Registrars by Pledges</h6>
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle">
                                                    <thead><tr><th>Registrar</th><th class="text-end">Count</th><th class="text-end">Total (<?php echo $currency; ?>)</th></tr></thead>
                                                    <tbody>
                                                        <?php foreach ($top_registrars['pledges'] as $r): ?>
                                                            <tr><td data-label="Registrar"><?php echo htmlspecialchars((string)($r['user_name'] ?? 'Unknown')); ?></td><td class="text-end" data-label="Count"><?php echo number_format((int)$r['c']); ?></td><td class="text-end" data-label="Total"><?php echo number_format((float)$r['t'],2); ?></td></tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Notes -->
                    <div class="accordion-item border-0 shadow-sm mb-3">
                        <h2 class="accordion-header" id="headingNotes">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNotes" aria-expanded="false" aria-controls="collapseNotes">
                                <i class="fas fa-note-sticky me-2 text-secondary"></i><strong>Recent Notes & References</strong>
                            </button>
                        </h2>
                        <div id="collapseNotes" class="accordion-collapse collapse" aria-labelledby="headingNotes" data-bs-parent="#reportAccordion">
                            <div class="accordion-body p-0">
                                <div class="row g-3 p-3">
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                                            <h6 class="mb-3"><i class="fas fa-note-sticky me-2 text-secondary"></i>Recent Payment References</h6>
                                            <?php if (count($recent_notes['payments']) === 0): ?>
                                                <div class="text-muted">No notes in this range.</div>
                                            <?php else: ?>
                                            <ul class="list-group list-group-flush">
                                                <?php foreach ($recent_notes['payments'] as $r): ?>
                                                    <li class="list-group-item border-0 px-0">
                                                        <div class="d-flex justify-content-between">
                                                            <div>
                                                                <strong><?php echo htmlspecialchars(($r['donor_name'] ?? '') !== '' ? (string)$r['donor_name'] : 'Anonymous'); ?></strong>
                                                                <span class="text-muted">· <?php echo htmlspecialchars(ucfirst((string)$r['method'])); ?></span>
                                                                <div class="text-muted small">Ref: <?php echo htmlspecialchars((string)$r['reference']); ?></div>
                                                            </div>
                                                            <div class="text-muted small"><?php echo htmlspecialchars((string)$r['received_at']); ?></div>
                                                        </div>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <?php endif; ?>
                                        </div></div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                                            <h6 class="mb-3"><i class="fas fa-comments me-2 text-secondary"></i>Recent Pledge Notes</h6>
                                            <?php if (count($recent_notes['pledges']) === 0): ?>
                                                <div class="text-muted">No notes in this range.</div>
                                            <?php else: ?>
                                            <ul class="list-group list-group-flush">
                                                <?php foreach ($recent_notes['pledges'] as $r): ?>
                                                    <li class="list-group-item border-0 px-0">
                                                        <div class="d-flex justify-content-between">
                                                            <div>
                                                                <strong><?php echo htmlspecialchars(($r['donor_name'] ?? '') !== '' ? (string)$r['donor_name'] : 'Anonymous'); ?></strong>
                                                                <div class="text-muted small"><?php echo htmlspecialchars((string)$r['notes']); ?></div>
                                                            </div>
                                                            <div class="text-muted small"><?php echo htmlspecialchars((string)$r['created_at']); ?></div>
                                                        </div>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <?php endif; ?>
                                        </div></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Data Quality -->
                    <div class="accordion-item border-0 shadow-sm mb-3">
                        <h2 class="accordion-header" id="headingDataQuality">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDataQuality" aria-expanded="false" aria-controls="collapseDataQuality">
                                <i class="fas fa-shield-halved me-2 text-danger"></i><strong>Data Quality</strong>
                            </button>
                        </h2>
                        <div id="collapseDataQuality" class="accordion-collapse collapse" aria-labelledby="headingDataQuality" data-bs-parent="#reportAccordion">
                            <div class="accordion-body p-3">
                                <div class="row g-3 data-quality-cards">
                                    <div class="col-md-4">
                                        <div class="p-3 bg-light rounded">
                                            <div class="small text-muted">Payments with missing contact</div>
                                            <div class="h5 mb-2"><?php echo number_format($data_quality['missing_contact_payments']); ?></div>
                                            <button class="btn btn-sm btn-outline-danger" onclick="loadDQ('missing_contact_payments')"><i class="fas fa-list me-1"></i>View</button>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="p-3 bg-light rounded">
                                            <div class="small text-muted">Pledges with missing contact</div>
                                            <div class="h5 mb-2"><?php echo number_format($data_quality['missing_contact_pledges']); ?></div>
                                            <button class="btn btn-sm btn-outline-danger" onclick="loadDQ('missing_contact_pledges')"><i class="fas fa-list me-1"></i>View</button>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="p-3 bg-light rounded">
                                            <div class="small text-muted">Duplicate phone hotlist (≥3)</div>
                                            <?php if (count($data_quality['duplicate_phones'])===0): ?>
                                                <div class="text-muted small">None</div>
                                            <?php else: ?>
                                                <ul class="small mb-0">
                                                    <?php foreach ($data_quality['duplicate_phones'] as $r): ?>
                                                        <li><?php echo htmlspecialchars((string)$r['donor_phone']); ?> <span class="text-muted">(<?php echo (int)$r['c']; ?>)</span></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <!-- End Accordion Container -->

                <!-- Data Quality Modal -->
                <div class="modal fade" id="dqModal" tabindex="-1" aria-labelledby="dqModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="dqModalLabel"><i class="fas fa-triangle-exclamation me-2 text-danger"></i>Data Quality Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body" id="dqModalBody">
                                <div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/reports.js"></script>
<script>
  // Embed data for JS exports and charts
  window.COMPREHENSIVE_DATA = <?php echo json_encode([
    'metrics' => $metrics,
    'breakdowns' => $breakdowns,
    'timeseries' => $timeseries,
    'top_donors' => $top_donors,
    'top_registrars' => $top_registrars,
    'data_quality' => $data_quality,
    'pledge_tracking' => $pledge_tracking,
    'range' => ['from' => $fromDate, 'to' => $toDate],
    'currency' => $currency,
  ]); ?>;

  (function(){
    const el = document.getElementById('pieContainer');
    if (!el || !window.echarts) return;
    const d = window.COMPREHENSIVE_DATA;
    let chart = null;
    
    function initChart() {
      if (chart) {
        chart.dispose();
      }
      chart = echarts.init(el);
      const data = d.breakdowns.donations_by_package_aggregated;
      
      // Responsive chart options based on screen size
      const isMobile = window.innerWidth <= 768;
      const isSmallMobile = window.innerWidth <= 576;
      
      chart.setOption({
        tooltip: { 
          trigger: 'item', 
          formatter: params => `${params.name}: ${d.currency} ` + Number(params.value).toLocaleString(undefined,{minimumFractionDigits:2}) + ` (${params.percent}%)`,
          textStyle: { fontSize: isSmallMobile ? 12 : 14 }
        },
        legend: { 
          orient: isMobile ? 'vertical' : 'horizontal', 
          bottom: isMobile ? 10 : 0,
          left: isMobile ? 'left' : 'center',
          itemWidth: isSmallMobile ? 12 : 14,
          itemHeight: isSmallMobile ? 12 : 14,
          textStyle: { fontSize: isSmallMobile ? 11 : 12 }
        },
        series: [{
          name: 'Donations by Package',
          type: 'pie',
          radius: isSmallMobile ? ['30%','60%'] : isMobile ? ['35%','65%'] : ['40%','70%'],
          center: isMobile ? ['50%','40%'] : ['50%','45%'],
          avoidLabelOverlap: true,
          itemStyle: { borderRadius: 6, borderColor: '#fff', borderWidth: 2 },
          label: { 
            show: !isSmallMobile,
            formatter: '{b}: {d}%',
            fontSize: isSmallMobile ? 10 : 12
          },
          emphasis: {
            label: {
              show: true,
              fontSize: isSmallMobile ? 11 : 13,
              fontWeight: 'bold'
            }
          },
          data
        }]
      });
    }
    
    // Initialize chart when accordion section is shown
    const chartAccordion = document.getElementById('collapseChart');
    if (chartAccordion) {
      chartAccordion.addEventListener('shown.bs.collapse', function() {
        setTimeout(() => {
          if (!chart) {
            initChart();
          } else {
            chart.resize();
          }
        }, 300);
      });
    }
    
    // Initialize chart if section is already open
    if (chartAccordion && chartAccordion.classList.contains('show')) {
      initChart();
    }
    
    // Handle resize with debounce
    let resizeTimer;
    window.addEventListener('resize', () => {
      if (!chart) return;
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(() => {
        chart.resize();
      }, 250);
    });
  })();

  // Monthly Pledge Payment Chart
  (function(){
    const el = document.getElementById('pledgeMonthlyChart');
    if (!el || !window.echarts) return;
    const d = window.COMPREHENSIVE_DATA;
    let monthlyChart = null;
    
    function initMonthlyChart() {
      if (monthlyChart) {
        monthlyChart.dispose();
      }
      monthlyChart = echarts.init(el);
      
      const monthlyData = d.pledge_tracking?.monthly_payments || [];
      if (monthlyData.length === 0) {
        el.innerHTML = '<div class="text-center py-5 text-muted">No pledge payment data available for the last 12 months.</div>';
        return;
      }
      
      const months = monthlyData.map(m => m.label);
      const amounts = monthlyData.map(m => m.amount);
      const counts = monthlyData.map(m => m.count);
      
      const isMobile = window.innerWidth <= 768;
      const isSmallMobile = window.innerWidth <= 576;
      
      monthlyChart.setOption({
        tooltip: {
          trigger: 'axis',
          axisPointer: { type: 'shadow' },
          formatter: function(params) {
            let result = params[0].name + '<br/>';
            params.forEach(function(item) {
              result += item.marker + item.seriesName + ': ' + d.currency + ' ' + 
                        Number(item.value).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '<br/>';
            });
            return result;
          },
          textStyle: { fontSize: isSmallMobile ? 11 : 13 }
        },
        legend: {
          data: ['Amount Collected'],
          bottom: 0,
          textStyle: { fontSize: isSmallMobile ? 11 : 12 }
        },
        grid: {
          left: isSmallMobile ? '10%' : '3%',
          right: isSmallMobile ? '10%' : '4%',
          bottom: isSmallMobile ? '15%' : '10%',
          top: '10%',
          containLabel: true
        },
        xAxis: {
          type: 'category',
          data: months,
          axisLabel: {
            rotate: isSmallMobile ? 45 : 0,
            fontSize: isSmallMobile ? 10 : 12,
            interval: 0
          }
        },
        yAxis: {
          type: 'value',
          name: 'Amount (' + d.currency + ')',
          nameLocation: 'middle',
          nameGap: isSmallMobile ? 30 : 50,
          nameTextStyle: { fontSize: isSmallMobile ? 11 : 12 },
          axisLabel: {
            formatter: function(value) {
              return (value / 1000).toFixed(0) + 'k';
            },
            fontSize: isSmallMobile ? 10 : 11
          }
        },
        series: [{
          name: 'Amount Collected',
          type: 'bar',
          data: amounts,
          itemStyle: {
            color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
              { offset: 0, color: '#10b981' },
              { offset: 1, color: '#059669' }
            ]),
            borderRadius: [4, 4, 0, 0]
          },
          emphasis: {
            itemStyle: {
              color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                { offset: 0, color: '#059669' },
                { offset: 1, color: '#047857' }
              ])
            }
          },
          label: {
            show: !isSmallMobile,
            position: 'top',
            formatter: function(params) {
              return (params.value / 1000).toFixed(1) + 'k';
            },
            fontSize: isSmallMobile ? 9 : 10
          }
        }]
      });
    }
    
    // Initialize chart when accordion section is shown
    const pledgeAccordion = document.getElementById('collapsePledgeTracking');
    if (pledgeAccordion) {
      pledgeAccordion.addEventListener('shown.bs.collapse', function() {
        setTimeout(() => {
          if (!monthlyChart) {
            initMonthlyChart();
          } else {
            monthlyChart.resize();
          }
        }, 300);
      });
    }
    
    // Initialize chart if section is already open
    if (pledgeAccordion && pledgeAccordion.classList.contains('show')) {
      initMonthlyChart();
    }
    
    // Handle resize with debounce
    let resizeTimer;
    window.addEventListener('resize', () => {
      if (!monthlyChart) return;
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(() => {
        monthlyChart.resize();
      }, 250);
    });
  })();

  // Data Quality drilldown loader
  function loadDQ(kind){
    const body = document.getElementById('dqModalBody');
    const modalEl = document.getElementById('dqModal');
    if (modalEl && modalEl.parentElement !== document.body) {
      document.body.appendChild(modalEl);
    }
    const modal = new bootstrap.Modal(modalEl, { backdrop: true, focus: true });
    body.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';
    modal.show();
    const url = new URL(window.location.href);
    url.searchParams.set('dq', kind);
    fetch(url.toString())
      .then(r=>r.json())
      .then(data=>{
        if(!data.success){ body.innerHTML = '<div class="alert alert-danger">'+(data.error||'Failed to load')+'</div>'; return; }
        const rows = Array.isArray(data.rows) ? data.rows : [];
        const titleEl = document.querySelector('#dqModal .modal-title');
        const label = (kind==='missing_contact_payments' ? 'Payments with missing contact' : 'Pledges with missing contact');
        if(titleEl){ titleEl.innerHTML = '<i class="fas fa-triangle-exclamation me-2 text-danger"></i>'+label+' ('+rows.length+')'; }

        const currency = (window.COMPREHENSIVE_DATA && window.COMPREHENSIVE_DATA.currency) ? window.COMPREHENSIVE_DATA.currency : 'GBP';
        const state = {
          kind: kind,
          allRows: rows,
          filteredRows: [],
          page: 1,
          pageSize: 10,
          sortKey: 'id',
          sortDir: 'desc',
          search: ''
        };

        function formatAmount(n){
          const v = Number(n||0);
          return currency + ' ' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function badge(text){
          const t = String(text||'').toLowerCase();
          let cls = 'secondary';
          if(t==='approved' || t==='paid') cls = 'success';
          else if(t==='pending') cls = 'warning';
          else if(t==='void' || t==='cancelled') cls = 'danger';
          return `<span class="badge bg-${cls}">${text||''}</span>`;
        }
        function applyFilterSort(){
          const q = state.search.trim().toLowerCase();
          let arr = state.allRows.slice();
          if(q){
            arr = arr.filter(r => String(r.id).includes(q) || String(r.donor_name||'anonymous').toLowerCase().includes(q) || String(r.method||r.type||'').toLowerCase().includes(q) || String(r.status||'').toLowerCase().includes(q));
          }
          arr.sort((a,b)=>{
            const k = state.sortKey;
            let va = a[k]; let vb = b[k];
            if(k==='amount'){ va = Number(va||0); vb = Number(vb||0); }
            if(va==null) va = '';
            if(vb==null) vb = '';
            if(va<vb) return state.sortDir==='asc' ? -1 : 1;
            if(va>vb) return state.sortDir==='asc' ? 1 : -1;
            return 0;
          });
          state.filteredRows = arr;
        }
        function render(){
          applyFilterSort();
          if(state.filteredRows.length===0){ body.innerHTML = '<div class="text-muted">No records found for this range.</div>'; return; }
          const totalPages = Math.max(1, Math.ceil(state.filteredRows.length / state.pageSize));
          if(state.page > totalPages) state.page = totalPages;
          const start = (state.page - 1) * state.pageSize;
          const pageRows = state.filteredRows.slice(start, start + state.pageSize);

          const safeVal = (v)=>String(v||'').replaceAll('\\','\\\\').replaceAll('"','\\"');
          let controls = `
            <div class="d-flex align-items-center justify-content-between mb-3">
              <div class="d-flex align-items-center gap-2">
                <input id="dqSearch" class="form-control form-control-sm" placeholder="Search (ID, donor, status)" value="${safeVal(state.search)}" />
                <select id="dqPageSize" class="form-select form-select-sm" style="width:auto">
                  <option value="10" ${state.pageSize===10?'selected':''}>10</option>
                  <option value="25" ${state.pageSize===25?'selected':''}>25</option>
                  <option value="50" ${state.pageSize===50?'selected':''}>50</option>
                  <option value="100" ${state.pageSize===100?'selected':''}>100</option>
                </select>
              </div>
              <div class="text-muted small">${state.filteredRows.length} records • Page ${state.page} / ${totalPages}</div>
            </div>`;

          let head = '';
          if(state.kind==='missing_contact_payments'){
            head = '<th data-sort="id">ID</th><th data-sort="donor_name">Donor</th><th data-sort="amount">Amount</th><th data-sort="method">Method</th><th data-sort="status">Status</th><th data-sort="received_at">Received At</th><th>Action</th>';
          } else {
            head = '<th data-sort="id">ID</th><th data-sort="donor_name">Donor</th><th data-sort="amount">Amount</th><th data-sort="type">Type</th><th data-sort="status">Status</th><th data-sort="created_at">Created At</th><th>Action</th>';
          }

          let rowsHtml = '';
          pageRows.forEach(r=>{
            if(state.kind==='missing_contact_payments'){
              rowsHtml += `<tr>
                <td data-label="ID">${r.id}</td>
                <td data-label="Donor">${r.donor_name||'Anonymous'}</td>
                <td data-label="Amount">${formatAmount(r.amount)}</td>
                <td data-label="Method">${r.method||''}</td>
                <td data-label="Status">${badge(r.status)}</td>
                <td data-label="Received At">${r.received_at||''}</td>
                <td data-label="Action"><a class="btn btn-sm btn-outline-primary" target="_blank" href="../donations/payment.php?id=${r.id}">Open</a></td>
              </tr>`;
            } else {
              rowsHtml += `<tr>
                <td data-label="ID">${r.id}</td>
                <td data-label="Donor">${r.donor_name||'Anonymous'}</td>
                <td data-label="Amount">${formatAmount(r.amount)}</td>
                <td data-label="Type">${r.type||''}</td>
                <td data-label="Status">${badge(r.status)}</td>
                <td data-label="Created At">${r.created_at||''}</td>
                <td data-label="Action"><a class="btn btn-sm btn-outline-primary" target="_blank" href="../donations/pledge.php?id=${r.id}">Open</a></td>
              </tr>`;
            }
          });

          const table = `
            <div class="table-responsive table-responsive-mobile">
              <table class="table table-sm align-middle">
                <thead><tr>${head}</tr></thead>
                <tbody>${rowsHtml}</tbody>
              </table>
            </div>`;

          const pager = `
            <div class="d-flex justify-content-between align-items-center mt-2">
              <div class="text-muted small">Showing ${start+1}-${Math.min(start+state.pageSize, state.filteredRows.length)} of ${state.filteredRows.length}</div>
              <div class="btn-group btn-group-sm">
                <button id="dqPrev" class="btn btn-outline-secondary" ${state.page<=1?'disabled':''}>Prev</button>
                <button id="dqNext" class="btn btn-outline-secondary" ${state.page>=totalPages?'disabled':''}>Next</button>
              </div>
            </div>`;

          body.innerHTML = controls + table + pager;

          const searchEl = document.getElementById('dqSearch');
          const sizeEl = document.getElementById('dqPageSize');
          const prevEl = document.getElementById('dqPrev');
          const nextEl = document.getElementById('dqNext');

          if(searchEl){ searchEl.addEventListener('input', (e)=>{ state.search = e.target.value; state.page = 1; render(); }); }
          if(sizeEl){ sizeEl.addEventListener('change', (e)=>{ state.pageSize = parseInt(e.target.value||'10',10); state.page = 1; render(); }); }
          if(prevEl){ prevEl.addEventListener('click', ()=>{ if(state.page>1){ state.page -= 1; render(); } }); }
          if(nextEl){ nextEl.addEventListener('click', ()=>{ const tp = Math.max(1, Math.ceil(state.filteredRows.length / state.pageSize)); if(state.page<tp){ state.page += 1; render(); } }); }

          // Sort handlers
          body.querySelectorAll('th[data-sort]').forEach(th=>{
            th.style.cursor = 'pointer';
            th.addEventListener('click', ()=>{
              const k = th.getAttribute('data-sort');
              if(state.sortKey === k){ state.sortDir = (state.sortDir==='asc'?'desc':'asc'); }
              else { state.sortKey = k; state.sortDir = 'asc'; }
              render();
            });
          });
        }

        render();
      })
      .catch(()=>{ body.innerHTML = '<div class="alert alert-danger">Failed to load details.</div>'; });
  }

  // =============================================
  // Toggle All Accordions
  // =============================================
  
  let allExpanded = false;
  
  function toggleAllSections() {
    const accordion = document.getElementById('reportAccordion');
    if (!accordion) return;
    
    const collapses = accordion.querySelectorAll('.accordion-collapse');
    const toggleIcon = document.getElementById('toggleIcon');
    const toggleText = document.getElementById('toggleText');
    
    if (allExpanded) {
      // Collapse all
      collapses.forEach(collapse => {
        const bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapse, { toggle: false });
        bsCollapse.hide();
      });
      toggleIcon.className = 'fas fa-expand-alt me-1';
      toggleText.textContent = 'Expand All';
      allExpanded = false;
    } else {
      // Expand all
      collapses.forEach(collapse => {
        const bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapse, { toggle: false });
        bsCollapse.show();
      });
      toggleIcon.className = 'fas fa-compress-alt me-1';
      toggleText.textContent = 'Collapse All';
      allExpanded = true;
    }
  }
  
  // Update button state based on actual accordion states
  document.addEventListener('DOMContentLoaded', function() {
    const accordion = document.getElementById('reportAccordion');
    if (!accordion) return;
    
    // Check initial state - if all are expanded, update button
    function updateToggleButtonState() {
      const collapses = accordion.querySelectorAll('.accordion-collapse');
      const expandedCount = accordion.querySelectorAll('.accordion-collapse.show').length;
      const toggleIcon = document.getElementById('toggleIcon');
      const toggleText = document.getElementById('toggleText');
      
      if (expandedCount === collapses.length) {
        allExpanded = true;
        toggleIcon.className = 'fas fa-compress-alt me-1';
        toggleText.textContent = 'Collapse All';
      } else if (expandedCount === 0) {
        allExpanded = false;
        toggleIcon.className = 'fas fa-expand-alt me-1';
        toggleText.textContent = 'Expand All';
      }
    }
    
    // Listen for accordion state changes
    accordion.addEventListener('shown.bs.collapse', updateToggleButtonState);
    accordion.addEventListener('hidden.bs.collapse', updateToggleButtonState);
    
    // Initial state check
    setTimeout(updateToggleButtonState, 100);
  });

  // =============================================
  // Custom Date Picker Functions
  // =============================================
  
  // Helper: Format date for display
  function formatDateDisplay(dateStr) {
    const d = new Date(dateStr);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
  }
  
  // Helper: Get Monday of the week for a given date
  function getMondayOfWeek(date) {
    const d = new Date(date);
    const day = d.getDay();
    const diff = d.getDate() - day + (day === 0 ? -6 : 1);
    return new Date(d.setDate(diff));
  }
  
  // Helper: Get Sunday of the week for a given date
  function getSundayOfWeek(date) {
    const monday = getMondayOfWeek(date);
    const sunday = new Date(monday);
    sunday.setDate(monday.getDate() + 6);
    return sunday;
  }
  
  // Helper: Format date as YYYY-MM-DD
  function toYMD(date) {
    const d = new Date(date);
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }
  
  // Set day range (same day for from and to)
  function setDayRange() {
    const dateInput = document.getElementById('dayDate');
    const fromInput = document.getElementById('dayFrom');
    const toInput = document.getElementById('dayTo');
    
    if (dateInput && fromInput && toInput) {
      fromInput.value = dateInput.value;
      toInput.value = dateInput.value;
    }
    return true;
  }
  
  // Set week range from selected date
  function setWeekRange() {
    const dateInput = document.getElementById('weekDate');
    const fromInput = document.getElementById('weekFrom');
    const toInput = document.getElementById('weekTo');
    
    if (dateInput && fromInput && toInput) {
      const selectedDate = new Date(dateInput.value);
      const monday = getMondayOfWeek(selectedDate);
      const sunday = getSundayOfWeek(selectedDate);
      
      fromInput.value = toYMD(monday);
      toInput.value = toYMD(sunday);
    }
    return true;
  }
  
  // Set month range from selected month/year
  function setMonthRange() {
    const monthSelect = document.getElementById('monthSelect');
    const yearSelect = document.getElementById('yearSelect');
    const fromInput = document.getElementById('monthFrom');
    const toInput = document.getElementById('monthTo');
    
    if (monthSelect && yearSelect && fromInput && toInput) {
      const month = parseInt(monthSelect.value);
      const year = parseInt(yearSelect.value);
      
      // First day of month
      const firstDay = new Date(year, month - 1, 1);
      // Last day of month
      const lastDay = new Date(year, month, 0);
      
      fromInput.value = toYMD(firstDay);
      toInput.value = toYMD(lastDay);
    }
    return true;
  }
  
  // Update week preview when date changes
  document.addEventListener('DOMContentLoaded', function() {
    const weekDateInput = document.getElementById('weekDate');
    const weekPreview = document.getElementById('weekPreview');
    
    if (weekDateInput && weekPreview) {
      weekDateInput.addEventListener('change', function() {
        const selectedDate = new Date(this.value);
        const monday = getMondayOfWeek(selectedDate);
        const sunday = getSundayOfWeek(selectedDate);
        
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        weekPreview.textContent = 'Week: ' + monday.getDate() + ' ' + months[monday.getMonth()] + 
                                  ' - ' + sunday.getDate() + ' ' + months[sunday.getMonth()] + ' ' + sunday.getFullYear();
      });
    }
    
    // Update month preview when month/year changes
    const monthSelect = document.getElementById('monthSelect');
    const yearSelect = document.getElementById('yearSelect');
    const monthPreview = document.getElementById('monthPreview');
    
    function updateMonthPreview() {
      if (monthSelect && yearSelect && monthPreview) {
        const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                        'July', 'August', 'September', 'October', 'November', 'December'];
        const month = parseInt(monthSelect.value);
        const year = parseInt(yearSelect.value);
        monthPreview.textContent = months[month - 1] + ' ' + year;
      }
    }
    
    if (monthSelect) monthSelect.addEventListener('change', updateMonthPreview);
    if (yearSelect) yearSelect.addEventListener('change', updateMonthPreview);
    
    // Update range preview when dates change
    const rangeFrom = document.getElementById('rangeFrom');
    const rangeTo = document.getElementById('rangeTo');
    const rangePreview = document.getElementById('rangePreview');
    
    function updateRangePreview() {
      if (rangeFrom && rangeTo && rangePreview) {
        const from = new Date(rangeFrom.value);
        const to = new Date(rangeTo.value);
        
        if (!isNaN(from.getTime()) && !isNaN(to.getTime())) {
          const diffTime = Math.abs(to - from);
          const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
          rangePreview.innerHTML = '<i class="fas fa-info-circle me-1"></i>' + diffDays + ' day' + (diffDays !== 1 ? 's' : '') + ' selected';
        }
      }
    }
    
    if (rangeFrom) rangeFrom.addEventListener('change', updateRangePreview);
    if (rangeTo) rangeTo.addEventListener('change', updateRangePreview);
    
    // Initial update
    updateRangePreview();
  });
</script>
</body>
</html>


