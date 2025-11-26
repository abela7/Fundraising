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

                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                    <h4 class="mb-0"><i class="fas fa-file-alt text-primary me-2"></i>Comprehensive Report</h4>
                    <div class="d-flex gap-2 date-range-buttons">
                        <a class="btn btn-outline-secondary" href="?date=today"><i class="fas fa-clock me-1"></i>Today</a>
                        <a class="btn btn-outline-secondary" href="?date=week"><i class="fas fa-calendar-week me-1"></i>This Week</a>
                        <a class="btn btn-outline-secondary" href="?date=month"><i class="fas fa-calendar me-1"></i>This Month</a>
                        <a class="btn btn-outline-secondary" href="?date=quarter"><i class="fas fa-calendar-alt me-1"></i>Quarter</a>
                        <a class="btn btn-outline-secondary" href="?date=year"><i class="fas fa-calendar-day me-1"></i>This Year</a>
                        <a class="btn btn-outline-secondary" href="?date=all"><i class="fas fa-infinity me-1"></i>All Time</a>
                        <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
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
</script>
</body>
</html>


