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

// Export handlers (CSV for sections)
if ($db && isset($_GET['export'])) {
    $export = $_GET['export'];
    if ($export === 'top_donors_csv') {
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="top_donors.csv"');
        $out = fopen('php://output','w');
        fputcsv($out,['Donor','Phone','Email','Total Pledged','Total Paid','Outstanding','Last Seen']);
        $sql = "SELECT donor_name, donor_phone, donor_email,
                       SUM(CASE WHEN src='pledge' THEN amount ELSE 0 END) AS total_pledged,
                       SUM(CASE WHEN src='payment' THEN amount ELSE 0 END) AS total_paid,
                       MAX(last_seen_at) AS last_seen
                FROM (
                  SELECT donor_name, donor_phone, donor_email, amount, created_at AS last_seen_at, 'pledge' AS src
                  FROM pledges WHERE status='approved' AND created_at BETWEEN ? AND ?
                  UNION ALL
                  SELECT donor_name, donor_phone, donor_email, amount, received_at AS last_seen_at, 'payment' AS src
                  FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ?
                ) x
                GROUP BY donor_name, donor_phone, donor_email
                ORDER BY total_paid DESC, total_pledged DESC
                LIMIT 200";
        $stmt = $db->prepare($sql); $stmt->bind_param('ssss',$fromDate,$toDate,$fromDate,$toDate); $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $pledged = (float)($r['total_pledged'] ?? 0);
            $paid    = (float)($r['total_paid'] ?? 0);
            $outstanding = max($pledged - $paid, 0);
            fputcsv($out,[
                $r['donor_name'] !== '' ? $r['donor_name'] : 'Anonymous',
                $r['donor_phone'],
                $r['donor_email'],
                number_format($pledged,2,'.',''),
                number_format($paid,2,'.',''),
                number_format($outstanding,2,'.',''),
                $r['last_seen']
            ]);
        }
        fclose($out); exit;
    }
    if ($export === 'method_breakdown_csv') {
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="payments_by_method.csv"');
        $out = fopen('php://output','w'); fputcsv($out,['Method','Count','Total']);
        $stmt = $db->prepare("SELECT method, COUNT(*) c, COALESCE(SUM(amount),0) t FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ? GROUP BY method ORDER BY t DESC");
        $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $res=$stmt->get_result();
        while($r=$res->fetch_assoc()){ fputcsv($out,[$r['method'],(int)$r['c'],number_format((float)$r['t'],2,'.','')]); }
        fclose($out); exit;
    }
    if ($export === 'package_breakdown_csv') {
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="donations_by_package.csv"');
        $out = fopen('php://output','w'); fputcsv($out,['Type','Package','Count','Total']);
        $sqlP = "SELECT 'Payment' as type, COALESCE(dp.label,'Custom') label, COUNT(*) c, COALESCE(SUM(p.amount),0) t
                 FROM payments p LEFT JOIN donation_packages dp ON dp.id=p.package_id
                 WHERE p.status='approved' AND p.received_at BETWEEN ? AND ?
                 GROUP BY label ORDER BY t DESC";
        $stmt = $db->prepare($sqlP); $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $res=$stmt->get_result();
        while($r=$res->fetch_assoc()){ fputcsv($out,[$r['type'],$r['label'],(int)$r['c'],number_format((float)$r['t'],2,'.','')]); }
        $sqlL = "SELECT 'Pledge' as type, COALESCE(dp.label,'Custom') label, COUNT(*) c, COALESCE(SUM(p.amount),0) t
                 FROM pledges p LEFT JOIN donation_packages dp ON dp.id=p.package_id
                 WHERE p.status='approved' AND p.created_at BETWEEN ? AND ?
                 GROUP BY label ORDER BY t DESC";
        $stmt = $db->prepare($sqlL); $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $res=$stmt->get_result();
        while($r=$res->fetch_assoc()){ fputcsv($out,[$r['type'],$r['label'],(int)$r['c'],number_format((float)$r['t'],2,'.','')]); }
        fclose($out); exit;
    }
    if ($export === 'timeseries_csv') {
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="timeseries.csv"');
        $out=fopen('php://output','w'); fputcsv($out,['Date','Payments Total','Payments Count','Pledges Total','Pledges Count']);
        $mp = $db->prepare("SELECT DATE(received_at) d, COALESCE(SUM(amount),0) t, COUNT(*) c FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ? GROUP BY DATE(received_at)");
        $mp->bind_param('ss',$fromDate,$toDate); $mp->execute(); $rp=$mp->get_result(); $mapP=[]; while($r=$rp->fetch_assoc()){ $mapP[$r['d']] = ['t'=>(float)$r['t'],'c'=>(int)$r['c']]; }
        $ml = $db->prepare("SELECT DATE(created_at) d, COALESCE(SUM(amount),0) t, COUNT(*) c FROM pledges WHERE status='approved' AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at)");
        $ml->bind_param('ss',$fromDate,$toDate); $ml->execute(); $rl=$ml->get_result(); $mapL=[]; while($r=$rl->fetch_assoc()){ $mapL[$r['d']] = ['t'=>(float)$r['t'],'c'=>(int)$r['c']]; }
        $days = array_unique(array_merge(array_keys($mapP), array_keys($mapL))); sort($days);
        foreach($days as $d){
            $pT = $mapP[$d]['t'] ?? 0; $pC = $mapP[$d]['c'] ?? 0; $lT = $mapL[$d]['t'] ?? 0; $lC = $mapL[$d]['c'] ?? 0;
            fputcsv($out,[$d, number_format((float)$pT,2,'.',''), $pC, number_format((float)$lT,2,'.',''), $lC]);
        }
        fclose($out); exit;
    }
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
    // Totals in range
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0), COUNT(*) FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ?");
    $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $stmt->bind_result($sum, $cnt); $stmt->fetch(); $stmt->close();
    $metrics['paid_total'] = (float)$sum; $metrics['payments_count'] = (int)$cnt;

    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0), COUNT(*) FROM pledges WHERE status='approved' AND created_at BETWEEN ? AND ?");
    $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $stmt->bind_result($sum, $cnt); $stmt->fetch(); $stmt->close();
    $metrics['pledged_total'] = (float)$sum; $metrics['pledges_count'] = (int)$cnt;

    $metrics['grand_total'] = $metrics['paid_total'] + $metrics['pledged_total'];

    // Donor count in range (distinct across pledges/payments)
    $sql = "SELECT COUNT(*) AS c FROM (
              SELECT DISTINCT CONCAT(COALESCE(donor_name,''),'|',COALESCE(donor_phone,''),'|',COALESCE(donor_email,'')) ident
              FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ?
              UNION
              SELECT DISTINCT CONCAT(COALESCE(donor_name,''),'|',COALESCE(donor_phone,''),'|',COALESCE(donor_email,'')) ident
              FROM pledges WHERE status='approved' AND created_at BETWEEN ? AND ?
            ) t";
    $stmt = $db->prepare($sql); $stmt->bind_param('ssss',$fromDate,$toDate,$fromDate,$toDate); $stmt->execute(); $res=$stmt->get_result(); $row=$res->fetch_assoc(); $metrics['donor_count'] = (int)($row['c'] ?? 0);

    // Average donation (approved payments)
    $stmt = $db->prepare("SELECT COALESCE(AVG(amount),0) FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ?");
    $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $stmt->bind_result($avg); $stmt->fetch(); $stmt->close();
    $metrics['avg_donation'] = (float)$avg;

    // Outstanding (overall lifetime and range)
    $row = $db->query("SELECT
              (SELECT COALESCE(SUM(amount),0) FROM pledges WHERE status='approved')
            - (SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='approved') AS outstanding")->fetch_assoc() ?: ['outstanding'=>0];
    $metrics['overall_outstanding'] = (float)$row['outstanding'];

    $row = $db->query("SELECT
              (SELECT COALESCE(SUM(amount),0) FROM pledges WHERE status='approved' AND created_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."')
            - (SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='approved' AND received_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."') AS outstanding")->fetch_assoc() ?: ['outstanding'=>0];
    $metrics['range_outstanding'] = (float)$row['outstanding'];

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

    // Time series
    $mp = $db->prepare("SELECT DATE(received_at) d, COALESCE(SUM(amount),0) t, COUNT(*) c FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ? GROUP BY DATE(received_at) ORDER BY d");
    $mp->bind_param('ss',$fromDate,$toDate); $mp->execute(); $rp=$mp->get_result(); $dates=[]; $mapP=[]; while($r=$rp->fetch_assoc()){ $dates[]=$r['d']; $mapP[$r['d']]=$r; }
    $ml = $db->prepare("SELECT DATE(created_at) d, COALESCE(SUM(amount),0) t, COUNT(*) c FROM pledges WHERE status='approved' AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY d");
    $ml->bind_param('ss',$fromDate,$toDate); $ml->execute(); $rl=$ml->get_result(); $mapL=[]; while($r=$rl->fetch_assoc()){ $dates[]=$r['d']; $mapL[$r['d']]=$r; }
    $dates = array_values(array_unique($dates)); sort($dates);
    $timeseries['dates'] = $dates;
    foreach($dates as $d){
        $timeseries['payments']['totals'][] = (float)($mapP[$d]['t'] ?? 0);
        $timeseries['payments']['counts'][] = (int)($mapP[$d]['c'] ?? 0);
        $timeseries['pledges']['totals'][]  = (float)($mapL[$d]['t'] ?? 0);
        $timeseries['pledges']['counts'][]  = (int)($mapL[$d]['c'] ?? 0);
    }

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
            ) c
            GROUP BY donor_name, donor_phone, donor_email
            ORDER BY total_paid DESC, total_pledged DESC
            LIMIT 50";
    $stmt = $db->prepare($sql); $stmt->bind_param('ssss',$fromDate,$toDate,$fromDate,$toDate); $stmt->execute(); $res=$stmt->get_result();
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="?date=today"><i class="fas fa-clock me-1"></i>Today</a>
                        <a class="btn btn-outline-secondary" href="?date=week"><i class="fas fa-calendar-week me-1"></i>This Week</a>
                        <a class="btn btn-outline-secondary" href="?date=month"><i class="fas fa-calendar me-1"></i>This Month</a>
                        <a class="btn btn-outline-secondary" href="?date=quarter"><i class="fas fa-calendar-alt me-1"></i>Quarter</a>
                        <a class="btn btn-outline-secondary" href="?date=year"><i class="fas fa-calendar-day me-1"></i>This Year</a>
                        <a class="btn btn-outline-secondary" href="?date=all"><i class="fas fa-infinity me-1"></i>All Time</a>
                        <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
                    </div>
                </div>

                <!-- Key Metrics -->
                <div class="row g-3 mb-4">
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

                <!-- Outstanding -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                            <h6 class="mb-2"><i class="fas fa-balance-scale me-2 text-primary"></i>Outstanding Balances</h6>
                            <div class="d-flex justify-content-between"><span>Overall Outstanding</span><strong><?php echo $currency.' '.number_format($metrics['overall_outstanding'],2); ?></strong></div>
                            <div class="d-flex justify-content-between text-muted"><span>Range Outstanding</span><span><?php echo $currency.' '.number_format($metrics['range_outstanding'],2); ?></span></div>
                        </div></div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="mb-2"><i class="fas fa-download me-2 text-primary"></i>Exports</h6>
                                <div class="d-flex flex-wrap gap-2">
                                    <a class="btn btn-outline-primary btn-sm" href="?export=top_donors_csv&date=<?php echo urlencode($_GET['date'] ?? 'month'); ?>&from=<?php echo urlencode($_GET['from'] ?? ''); ?>&to=<?php echo urlencode($_GET['to'] ?? ''); ?>"><i class="fas fa-file-csv me-1"></i>Top Donors CSV</a>
                                    <a class="btn btn-outline-primary btn-sm" href="?export=method_breakdown_csv&date=<?php echo urlencode($_GET['date'] ?? 'month'); ?>&from=<?php echo urlencode($_GET['from'] ?? ''); ?>&to=<?php echo urlencode($_GET['to'] ?? ''); ?>"><i class="fas fa-file-csv me-1"></i>Methods CSV</a>
                                    <a class="btn btn-outline-primary btn-sm" href="?export=package_breakdown_csv&date=<?php echo urlencode($_GET['date'] ?? 'month'); ?>&from=<?php echo urlencode($_GET['from'] ?? ''); ?>&to=<?php echo urlencode($_GET['to'] ?? ''); ?>"><i class="fas fa-file-csv me-1"></i>Packages CSV</a>
                                    <a class="btn btn-outline-primary btn-sm" href="?export=timeseries_csv&date=<?php echo urlencode($_GET['date'] ?? 'month'); ?>&from=<?php echo urlencode($_GET['from'] ?? ''); ?>&to=<?php echo urlencode($_GET['to'] ?? ''); ?>"><i class="fas fa-file-csv me-1"></i>Time Series CSV</a>
                                </div>
                            </div>
                            <div>
                                <button class="btn btn-outline-secondary btn-sm" onclick="exportJSON(window.COMPREHENSIVE_DATA, 'comprehensive_report.json')"><i class="fas fa-file-code me-1"></i>JSON</button>
                            </div>
                        </div></div>
                    </div>
                </div>

                <!-- Time Series Chart -->
                <div class="card border-0 shadow-sm mb-4"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0"><i class="fas fa-chart-area me-2 text-primary"></i>Daily Totals</h6>
                    </div>
                    <canvas id="tsChart" height="96"></canvas>
                </div></div>

                <!-- Breakdowns -->
                <div class="row g-3 mb-4">
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                            <h6 class="mb-3"><i class="fas fa-receipt me-2 text-success"></i>Payments by Method</h6>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead><tr><th>Method</th><th class="text-end">Count</th><th class="text-end">Total (<?php echo $currency; ?>)</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($breakdowns['payments_by_method'] as $r): ?>
                                            <tr><td><?php echo htmlspecialchars(ucfirst((string)$r['method'])); ?></td><td class="text-end"><?php echo number_format((int)$r['c']); ?></td><td class="text-end"><?php echo number_format((float)$r['t'],2); ?></td></tr>
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
                                            <tr><td>Payment</td><td><?php echo htmlspecialchars((string)$r['label']); ?></td><td class="text-end"><?php echo number_format((int)$r['c']); ?></td><td class="text-end"><?php echo number_format((float)$r['t'],2); ?></td></tr>
                                        <?php endforeach; ?>
                                        <?php foreach ($breakdowns['pledges_by_package'] as $r): ?>
                                            <tr><td>Pledge</td><td><?php echo htmlspecialchars((string)$r['label']); ?></td><td class="text-end"><?php echo number_format((int)$r['c']); ?></td><td class="text-end"><?php echo number_format((float)$r['t'],2); ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div></div>
                    </div>
                </div>

                <!-- Status Distribution -->
                <div class="row g-3 mb-4">
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                            <h6 class="mb-3"><i class="fas fa-list-check me-2 text-secondary"></i>Payments by Status</h6>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($breakdowns['payments_by_status'] as $r): ?>
                                            <tr><td><?php echo htmlspecialchars(ucfirst((string)$r['status'])); ?></td><td class="text-end"><?php echo number_format((int)$r['c']); ?></td></tr>
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
                                            <tr><td><?php echo htmlspecialchars(ucfirst((string)$r['status'])); ?></td><td class="text-end"><?php echo number_format((int)$r['c']); ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div></div>
                    </div>
                </div>

                <!-- Top Tables -->
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm"><div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0"><i class="fas fa-crown me-2 text-primary"></i>Top Donors</h6>
                                <a class="btn btn-sm btn-outline-primary" href="?export=top_donors_csv&date=<?php echo urlencode($_GET['date'] ?? 'month'); ?>&from=<?php echo urlencode($_GET['from'] ?? ''); ?>&to=<?php echo urlencode($_GET['to'] ?? ''); ?>"><i class="fas fa-file-csv me-1"></i>CSV</a>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead><tr><th>Donor</th><th>Phone</th><th>Email</th><th class="text-end">Pledged</th><th class="text-end">Paid</th><th class="text-end">Outstanding</th><th class="text-end">Last Seen</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($top_donors as $r): $pledged=(float)($r['total_pledged']??0); $paid=(float)($r['total_paid']??0); $outstanding=max($pledged-$paid,0); ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(($r['donor_name'] ?? '') !== '' ? (string)$r['donor_name'] : 'Anonymous'); ?></td>
                                                <td><?php echo htmlspecialchars((string)($r['donor_phone'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars((string)($r['donor_email'] ?? '')); ?></td>
                                                <td class="text-end"><?php echo number_format($pledged,2); ?></td>
                                                <td class="text-end"><?php echo number_format($paid,2); ?></td>
                                                <td class="text-end"><?php echo number_format($outstanding,2); ?></td>
                                                <td class="text-end"><?php echo htmlspecialchars((string)($r['last_seen_at'] ?? '')); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div></div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                            <h6 class="mb-3"><i class="fas fa-user-tie me-2 text-success"></i>Top Registrars by Payments</h6>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead><tr><th>Registrar</th><th class="text-end">Count</th><th class="text-end">Total (<?php echo $currency; ?>)</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($top_registrars['payments'] as $r): ?>
                                            <tr><td><?php echo htmlspecialchars((string)($r['user_name'] ?? 'Unknown')); ?></td><td class="text-end"><?php echo number_format((int)$r['c']); ?></td><td class="text-end"><?php echo number_format((float)$r['t'],2); ?></td></tr>
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
                                            <tr><td><?php echo htmlspecialchars((string)($r['user_name'] ?? 'Unknown')); ?></td><td class="text-end"><?php echo number_format((int)$r['c']); ?></td><td class="text-end"><?php echo number_format((float)$r['t'],2); ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div></div>
                    </div>
                </div>

                <!-- Non-Numerical Insights -->
                <div class="row g-3 mb-4">
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

                <!-- Data Quality -->
                <div class="card border-0 shadow-sm mb-4"><div class="card-body">
                    <h6 class="mb-3"><i class="fas fa-shield-halved me-2 text-danger"></i>Data Quality</h6>
                    <div class="row g-3">
                        <div class="col-md-4"><div class="p-3 bg-light rounded"><div class="small text-muted">Payments with missing contact</div><div class="h5 mb-0"><?php echo number_format($data_quality['missing_contact_payments']); ?></div></div></div>
                        <div class="col-md-4"><div class="p-3 bg-light rounded"><div class="small text-muted">Pledges with missing contact</div><div class="h5 mb-0"><?php echo number_format($data_quality['missing_contact_pledges']); ?></div></div></div>
                        <div class="col-md-4"><div class="p-3 bg-light rounded"><div class="small text-muted">Duplicate phone hotlist (≥3)</div>
                            <?php if (count($data_quality['duplicate_phones'])===0): ?>
                                <div class="text-muted small">None</div>
                            <?php else: ?>
                                <ul class="small mb-0">
                                    <?php foreach ($data_quality['duplicate_phones'] as $r): ?>
                                        <li><?php echo htmlspecialchars((string)$r['donor_phone']); ?> <span class="text-muted">(<?php echo (int)$r['c']; ?>)</span></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div></div>
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
    const ctx = document.getElementById('tsChart');
    if (!ctx || !window.Chart) return;
    const d = window.COMPREHENSIVE_DATA;
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: d.timeseries.dates,
        datasets: [
          { label: 'Payments', data: d.timeseries.payments.totals, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.1)', tension: 0.2 },
          { label: 'Pledges', data: d.timeseries.pledges.totals, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.1)', tension: 0.2 }
        ]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true } }
      }
    });
  })();
</script>
</body>
</html>


