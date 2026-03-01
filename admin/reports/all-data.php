<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

function table_exists(mysqli $db, string $table): bool {
    $stmt = $db->prepare("SHOW TABLES LIKE ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

function column_exists(mysqli $db, string $table, string $column): bool {
    $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->bind_param('s', $column);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

function fetch_scalar(mysqli $db, string $sql, string $types = '', array $params = []): float {
    $stmt = $db->prepare($sql);
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    return isset($row[0]) ? (float)$row[0] : 0.0;
}

function fetch_count(mysqli $db, string $sql, string $types = '', array $params = []): int {
    $stmt = $db->prepare($sql);
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    return isset($row[0]) ? (int)$row[0] : 0;
}

function resolve_range(): array {
    $range = $_GET['date'] ?? 'all';
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $now = new DateTime('now');

    switch ($range) {
        case 'today':
            $start = (clone $now)->setTime(0, 0, 0);
            $end = (clone $now)->setTime(23, 59, 59);
            break;
        case 'week':
            $start = (clone $now)->modify('monday this week')->setTime(0, 0, 0);
            $end = (clone $now)->modify('sunday this week')->setTime(23, 59, 59);
            break;
        case 'month':
            $start = new DateTime(date('Y-m-01 00:00:00'));
            $end = (clone $start)->modify('+1 month -1 second');
            break;
        case 'quarter':
            $q = (int)floor(((int)$now->format('n') - 1) / 3) + 1;
            $start = new DateTime($now->format('Y') . '-' . (1 + ($q - 1) * 3) . '-01 00:00:00');
            $end = (clone $start)->modify('+3 months -1 second');
            break;
        case 'year':
            $start = new DateTime($now->format('Y') . '-01-01 00:00:00');
            $end = new DateTime($now->format('Y') . '-12-31 23:59:59');
            break;
        case 'custom':
            $start = DateTime::createFromFormat('Y-m-d', $from) ?: (clone $now);
            $end = DateTime::createFromFormat('Y-m-d', $to) ?: (clone $now);
            $start->setTime(0, 0, 0);
            $end->setTime(23, 59, 59);
            break;
        case 'all':
        default:
            $start = new DateTime('1970-01-01 00:00:00');
            $end = new DateTime('2100-01-01 00:00:00');
            $range = 'all';
            break;
    }

    return [
        'range' => $range,
        'from' => $start->format('Y-m-d H:i:s'),
        'to' => $end->format('Y-m-d H:i:s')
    ];
}

$db = null;
$db_error_message = '';
$settings = ['currency_code' => 'GBP', 'target_amount' => 0.0];

try {
    $db = db();
    if (table_exists($db, 'settings')) {
        $settingsRow = $db->query("SELECT currency_code, target_amount FROM settings WHERE id = 1")->fetch_assoc();
        if ($settingsRow) {
            $settings['currency_code'] = (string)($settingsRow['currency_code'] ?? 'GBP');
            $settings['target_amount'] = (float)($settingsRow['target_amount'] ?? 0);
        }
    }
} catch (Exception $e) {
    $db_error_message = 'Database connection failed: ' . $e->getMessage();
}

$range = resolve_range();
$currencyCode = $settings['currency_code'];

$report = [
    'meta' => [
        'generated_at' => date('Y-m-d H:i:s'),
        'currency' => $currencyCode,
        'range' => $range['range'],
        'from' => $range['from'],
        'to' => $range['to'],
        'timezone' => date_default_timezone_get(),
        'data_source' => 'live_database'
    ],
    'health' => [
        'database_ok' => ($db !== null && $db_error_message === ''),
        'error' => $db_error_message,
        'tables' => [],
        'columns' => []
    ],
    'financial' => [
        'approved_pledges_amount' => 0.0,
        'approved_pledges_count' => 0,
        'approved_direct_payments_amount' => 0.0,
        'approved_direct_payments_count' => 0,
        'confirmed_pledge_payments_amount' => 0.0,
        'confirmed_pledge_payments_count' => 0,
        'cash_received_total' => 0.0,
        'outstanding_pledged_total' => 0.0,
        'total_raised' => 0.0,
        'campaign_value_approved' => 0.0,
        'target_amount' => (float)$settings['target_amount'],
        'progress_percent' => 0.0
    ],
    'status_breakdown' => [
        'payments' => [],
        'pledges' => [],
        'pledge_payments' => []
    ],
    'package_mix' => [],
    'monthly_trend_last_12_months' => [],
    'top_donors' => [],
    'data_quality' => [
        'approved_payments_missing_donor_id_count' => 0,
        'approved_pledges_missing_donor_id_count' => 0,
        'payments_without_contact_count' => 0,
        'pledges_without_contact_count' => 0,
        'duplicate_phone_groups' => []
    ]
];

if ($db && $db_error_message === '') {
    $hasPledgePayments = table_exists($db, 'pledge_payments');
    $hasDonors = table_exists($db, 'donors');
    $ppDateColumn = null;

    if ($hasPledgePayments) {
        if (column_exists($db, 'pledge_payments', 'created_at')) {
            $ppDateColumn = 'created_at';
        } elseif (column_exists($db, 'pledge_payments', 'payment_date')) {
            $ppDateColumn = 'payment_date';
        }
    }

    $report['health']['tables'] = [
        'payments' => table_exists($db, 'payments'),
        'pledges' => table_exists($db, 'pledges'),
        'donors' => $hasDonors,
        'pledge_payments' => $hasPledgePayments,
        'donation_packages' => table_exists($db, 'donation_packages'),
        'settings' => table_exists($db, 'settings')
    ];

    $report['health']['columns'] = [
        'pledge_payments_date_column' => $ppDateColumn ?: '',
        'pledge_payments_has_pledge_id' => ($hasPledgePayments && column_exists($db, 'pledge_payments', 'pledge_id')),
        'pledge_payments_has_donor_id' => ($hasPledgePayments && column_exists($db, 'pledge_payments', 'donor_id'))
    ];

    $fromDate = $range['from'];
    $toDate = $range['to'];

    $report['financial']['approved_pledges_amount'] = fetch_scalar(
        $db,
        "SELECT COALESCE(SUM(amount),0) FROM pledges WHERE status='approved' AND created_at BETWEEN ? AND ?",
        'ss',
        [$fromDate, $toDate]
    );
    $report['financial']['approved_pledges_count'] = fetch_count(
        $db,
        "SELECT COUNT(*) FROM pledges WHERE status='approved' AND created_at BETWEEN ? AND ?",
        'ss',
        [$fromDate, $toDate]
    );

    $report['financial']['approved_direct_payments_amount'] = fetch_scalar(
        $db,
        "SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ?",
        'ss',
        [$fromDate, $toDate]
    );
    $report['financial']['approved_direct_payments_count'] = fetch_count(
        $db,
        "SELECT COUNT(*) FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ?",
        'ss',
        [$fromDate, $toDate]
    );

    if ($hasPledgePayments && $ppDateColumn !== null) {
        $report['financial']['confirmed_pledge_payments_amount'] = fetch_scalar(
            $db,
            "SELECT COALESCE(SUM(amount),0) FROM pledge_payments WHERE status='confirmed' AND {$ppDateColumn} BETWEEN ? AND ?",
            'ss',
            [$fromDate, $toDate]
        );
        $report['financial']['confirmed_pledge_payments_count'] = fetch_count(
            $db,
            "SELECT COUNT(*) FROM pledge_payments WHERE status='confirmed' AND {$ppDateColumn} BETWEEN ? AND ?",
            'ss',
            [$fromDate, $toDate]
        );
    }

    $approvedPledges = (float)$report['financial']['approved_pledges_amount'];
    $directPaid = (float)$report['financial']['approved_direct_payments_amount'];
    $pledgePaid = (float)$report['financial']['confirmed_pledge_payments_amount'];

    $report['financial']['cash_received_total'] = $directPaid + $pledgePaid;
    $report['financial']['outstanding_pledged_total'] = max(0.0, $approvedPledges - $pledgePaid);
    $report['financial']['total_raised'] = $report['financial']['cash_received_total'] + $report['financial']['outstanding_pledged_total'];
    $report['financial']['campaign_value_approved'] = $approvedPledges + $directPaid;

    if ((float)$report['financial']['target_amount'] > 0) {
        $report['financial']['progress_percent'] = round(
            ($report['financial']['total_raised'] / (float)$report['financial']['target_amount']) * 100,
            2
        );
    }

    $statusQueries = [
        'payments' => "SELECT status, COUNT(*) AS count, COALESCE(SUM(amount),0) AS amount FROM payments GROUP BY status ORDER BY status",
        'pledges' => "SELECT status, COUNT(*) AS count, COALESCE(SUM(amount),0) AS amount FROM pledges GROUP BY status ORDER BY status"
    ];
    foreach ($statusQueries as $key => $sql) {
        $res = $db->query($sql);
        while ($row = $res->fetch_assoc()) {
            $report['status_breakdown'][$key][] = [
                'status' => (string)$row['status'],
                'count' => (int)$row['count'],
                'amount' => (float)$row['amount']
            ];
        }
    }
    if ($hasPledgePayments) {
        $res = $db->query("SELECT status, COUNT(*) AS count, COALESCE(SUM(amount),0) AS amount FROM pledge_payments GROUP BY status ORDER BY status");
        while ($row = $res->fetch_assoc()) {
            $report['status_breakdown']['pledge_payments'][] = [
                'status' => (string)$row['status'],
                'count' => (int)$row['count'],
                'amount' => (float)$row['amount']
            ];
        }
    }

    $byPackage = [];
    $sqlPackagePayments = "
        SELECT COALESCE(dp.label,'Custom') AS label, COALESCE(SUM(p.amount),0) AS amount
        FROM payments p
        LEFT JOIN donation_packages dp ON dp.id = p.package_id
        WHERE p.status = 'approved' AND p.received_at BETWEEN ? AND ?
        GROUP BY label
    ";
    $stmt = $db->prepare($sqlPackagePayments);
    $stmt->bind_param('ss', $fromDate, $toDate);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $label = (string)$row['label'];
        $byPackage[$label] = ($byPackage[$label] ?? 0.0) + (float)$row['amount'];
    }

    $sqlPackagePledges = "
        SELECT COALESCE(dp.label,'Custom') AS label, COALESCE(SUM(p.amount),0) AS amount
        FROM pledges p
        LEFT JOIN donation_packages dp ON dp.id = p.package_id
        WHERE p.status = 'approved' AND p.created_at BETWEEN ? AND ?
        GROUP BY label
    ";
    $stmt = $db->prepare($sqlPackagePledges);
    $stmt->bind_param('ss', $fromDate, $toDate);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $label = (string)$row['label'];
        $byPackage[$label] = ($byPackage[$label] ?? 0.0) + (float)$row['amount'];
    }
    arsort($byPackage);
    foreach ($byPackage as $label => $amount) {
        $report['package_mix'][] = ['label' => $label, 'amount' => (float)$amount];
    }

    $paymentsMonthly = [];
    $pledgesMonthly = [];
    $ppMonthly = [];

    $res = $db->query("
        SELECT DATE_FORMAT(received_at, '%Y-%m') AS ym, COALESCE(SUM(amount),0) AS amount
        FROM payments
        WHERE status='approved'
          AND received_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
        GROUP BY ym ORDER BY ym
    ");
    while ($row = $res->fetch_assoc()) {
        $paymentsMonthly[(string)$row['ym']] = (float)$row['amount'];
    }

    $res = $db->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(amount),0) AS amount
        FROM pledges
        WHERE status='approved'
          AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
        GROUP BY ym ORDER BY ym
    ");
    while ($row = $res->fetch_assoc()) {
        $pledgesMonthly[(string)$row['ym']] = (float)$row['amount'];
    }

    if ($hasPledgePayments && $ppDateColumn !== null) {
        $res = $db->query("
            SELECT DATE_FORMAT({$ppDateColumn}, '%Y-%m') AS ym, COALESCE(SUM(amount),0) AS amount
            FROM pledge_payments
            WHERE status='confirmed'
              AND {$ppDateColumn} >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
            GROUP BY ym ORDER BY ym
        ");
        while ($row = $res->fetch_assoc()) {
            $ppMonthly[(string)$row['ym']] = (float)$row['amount'];
        }
    }

    $cursor = new DateTime(date('Y-m-01'));
    $cursor->modify('-11 months');
    for ($i = 0; $i < 12; $i++) {
        $ym = $cursor->format('Y-m');
        $direct = (float)($paymentsMonthly[$ym] ?? 0.0);
        $pledged = (float)($pledgesMonthly[$ym] ?? 0.0);
        $ppaid = (float)($ppMonthly[$ym] ?? 0.0);
        $report['monthly_trend_last_12_months'][] = [
            'month' => $ym,
            'approved_direct_payments' => $direct,
            'approved_pledges' => $pledged,
            'confirmed_pledge_payments' => $ppaid,
            'cash_received' => $direct + $ppaid,
            'campaign_value' => $direct + $pledged
        ];
        $cursor->modify('+1 month');
    }

    if ($hasDonors) {
        $topDonorSql = "
            SELECT
                d.id,
                d.name,
                d.phone,
                COALESCE(pl.pledged, 0) AS pledged,
                COALESCE(py.direct_paid, 0) AS direct_paid,
                COALESCE(pp.pp_paid, 0) AS pledge_paid
            FROM donors d
            LEFT JOIN (
                SELECT donor_id, SUM(amount) AS pledged
                FROM pledges
                WHERE status = 'approved'
                  AND donor_id IS NOT NULL
                  AND created_at BETWEEN ? AND ?
                GROUP BY donor_id
            ) pl ON pl.donor_id = d.id
            LEFT JOIN (
                SELECT donor_id, SUM(amount) AS direct_paid
                FROM payments
                WHERE status = 'approved'
                  AND donor_id IS NOT NULL
                  AND received_at BETWEEN ? AND ?
                GROUP BY donor_id
            ) py ON py.donor_id = d.id
        ";

        if ($hasPledgePayments && $ppDateColumn !== null && column_exists($db, 'pledge_payments', 'donor_id')) {
            $topDonorSql .= "
            LEFT JOIN (
                SELECT donor_id, SUM(amount) AS pp_paid
                FROM pledge_payments
                WHERE status = 'confirmed'
                  AND donor_id IS NOT NULL
                  AND {$ppDateColumn} BETWEEN ? AND ?
                GROUP BY donor_id
            ) pp ON pp.donor_id = d.id
            ";
        } else {
            $topDonorSql .= "
            LEFT JOIN (
                SELECT NULL AS donor_id, 0 AS pp_paid
            ) pp ON 1=0
            ";
        }

        $topDonorSql .= "
            WHERE COALESCE(pl.pledged,0) > 0
               OR COALESCE(py.direct_paid,0) > 0
               OR COALESCE(pp.pp_paid,0) > 0
            ORDER BY (COALESCE(pl.pledged,0) + COALESCE(py.direct_paid,0)) DESC,
                     (COALESCE(py.direct_paid,0) + COALESCE(pp.pp_paid,0)) DESC
            LIMIT 50
        ";

        $stmt = $db->prepare($topDonorSql);
        if ($hasPledgePayments && $ppDateColumn !== null && column_exists($db, 'pledge_payments', 'donor_id')) {
            $stmt->bind_param('ssssss', $fromDate, $toDate, $fromDate, $toDate, $fromDate, $toDate);
        } else {
            $stmt->bind_param('ssss', $fromDate, $toDate, $fromDate, $toDate);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $pledged = (float)$row['pledged'];
            $directPaidRow = (float)$row['direct_paid'];
            $pledgePaidRow = (float)$row['pledge_paid'];
            $report['top_donors'][] = [
                'donor_id' => (int)$row['id'],
                'name' => (string)($row['name'] ?? ''),
                'phone' => (string)($row['phone'] ?? ''),
                'approved_pledged' => $pledged,
                'approved_direct_paid' => $directPaidRow,
                'confirmed_pledge_paid' => $pledgePaidRow,
                'cash_received' => $directPaidRow + $pledgePaidRow,
                'outstanding_pledged' => max(0.0, $pledged - $pledgePaidRow),
                'campaign_value' => $pledged + $directPaidRow
            ];
        }
    }

    $report['data_quality']['approved_payments_missing_donor_id_count'] = fetch_count(
        $db,
        "SELECT COUNT(*) FROM payments WHERE status='approved' AND donor_id IS NULL"
    );
    $report['data_quality']['approved_pledges_missing_donor_id_count'] = fetch_count(
        $db,
        "SELECT COUNT(*) FROM pledges WHERE status='approved' AND donor_id IS NULL"
    );
    $report['data_quality']['payments_without_contact_count'] = fetch_count(
        $db,
        "SELECT COUNT(*) FROM payments WHERE status='approved' AND COALESCE(donor_phone,'')='' AND COALESCE(donor_email,'')=''"
    );
    $report['data_quality']['pledges_without_contact_count'] = fetch_count(
        $db,
        "SELECT COUNT(*) FROM pledges WHERE status='approved' AND COALESCE(donor_phone,'')='' AND COALESCE(donor_email,'')=''"
    );

    if ($hasDonors) {
        $res = $db->query("
            SELECT phone, COUNT(*) AS c
            FROM donors
            WHERE COALESCE(phone, '') <> ''
            GROUP BY phone
            HAVING COUNT(*) > 1
            ORDER BY c DESC, phone ASC
            LIMIT 100
        ");
        while ($row = $res->fetch_assoc()) {
            $report['data_quality']['duplicate_phone_groups'][] = [
                'phone' => (string)$row['phone'],
                'count' => (int)$row['c']
            ];
        }
    }
}

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$currency = htmlspecialchars($currencyCode, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Data Report</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .metric-card { border-left: 4px solid #0d6efd; }
        .metric-label { font-size: 0.82rem; color: #6c757d; text-transform: uppercase; letter-spacing: .03em; }
        .metric-value { font-size: 1.5rem; font-weight: 700; }
        .metric-sub { font-size: 0.82rem; color: #6c757d; }
        .trend-cell { min-width: 210px; }
        .trend-bar-wrap { height: 8px; background: #e9ecef; border-radius: 100px; overflow: hidden; }
        .trend-bar { height: 100%; background: #198754; }
        .section-title { font-size: 1rem; font-weight: 700; }
        .code-block { max-height: 480px; overflow: auto; font-size: 0.8rem; background: #111827; color: #e5e7eb; padding: 1rem; border-radius: .5rem; }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <?php
                    $f = $report['financial'];
                    $progressPct = max(0.0, min(100.0, (float)$f['progress_percent']));
                    $rangeLabelMap = [
                        'today' => 'Today',
                        'week' => 'This Week',
                        'month' => 'This Month',
                        'quarter' => 'This Quarter',
                        'year' => 'This Year',
                        'custom' => 'Custom',
                        'all' => 'All Time'
                    ];
                    $activeRangeLabel = $rangeLabelMap[$range['range']] ?? 'All Time';
                    $campaignTarget = (float)$f['target_amount'];
                    $targetRemaining = max(0.0, $campaignTarget - (float)$f['total_raised']);
                    $maxTrend = 0.0;
                    foreach ($report['monthly_trend_last_12_months'] as $trendRow) {
                        $maxTrend = max($maxTrend, (float)$trendRow['campaign_value']);
                    }
                    $customFrom = htmlspecialchars((string)($_GET['from'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $customTo = htmlspecialchars((string)($_GET['to'] ?? ''), ENT_QUOTES, 'UTF-8');
                ?>

                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h4 class="mb-1"><i class="fas fa-database text-primary me-2"></i>All Data Financial Report</h4>
                        <div class="text-muted small">
                            Scope: <strong><?php echo htmlspecialchars($activeRangeLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                            (<?php echo htmlspecialchars($range['from'], ENT_QUOTES, 'UTF-8'); ?> to <?php echo htmlspecialchars($range['to'], ENT_QUOTES, 'UTF-8'); ?>)
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary btn-sm" href="?date=today">Today</a>
                        <a class="btn btn-outline-secondary btn-sm" href="?date=week">Week</a>
                        <a class="btn btn-outline-secondary btn-sm" href="?date=month">Month</a>
                        <a class="btn btn-outline-secondary btn-sm" href="?date=quarter">Quarter</a>
                        <a class="btn btn-outline-secondary btn-sm" href="?date=year">Year</a>
                        <a class="btn btn-outline-secondary btn-sm" href="?date=all">All</a>
                        <a class="btn btn-primary btn-sm" href="?date=<?php echo urlencode($range['range']); ?>&format=json" target="_blank">
                            <i class="fas fa-code me-1"></i>JSON
                        </a>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body">
                        <form class="row g-2 align-items-end" method="get">
                            <input type="hidden" name="date" value="custom">
                            <div class="col-12 col-md-4">
                                <label class="form-label mb-1">From</label>
                                <input class="form-control form-control-sm" type="date" name="from" value="<?php echo $customFrom; ?>">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label mb-1">To</label>
                                <input class="form-control form-control-sm" type="date" name="to" value="<?php echo $customTo; ?>">
                            </div>
                            <div class="col-12 col-md-4 d-grid">
                                <button class="btn btn-sm btn-dark" type="submit"><i class="fas fa-filter me-1"></i>Apply Custom Range</button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (!empty($report['health']['error'])): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($report['health']['error'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card metric-card h-100"><div class="card-body">
                            <div class="metric-label">Total Raised</div>
                            <div class="metric-value"><?php echo $currency . ' ' . number_format((float)$f['total_raised'], 2); ?></div>
                            <div class="metric-sub">Cash + outstanding pledged</div>
                        </div></div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card metric-card h-100"><div class="card-body">
                            <div class="metric-label">Cash Received</div>
                            <div class="metric-value"><?php echo $currency . ' ' . number_format((float)$f['cash_received_total'], 2); ?></div>
                            <div class="metric-sub">Approved direct + confirmed pledge payments</div>
                        </div></div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card metric-card h-100"><div class="card-body">
                            <div class="metric-label">Outstanding Pledged</div>
                            <div class="metric-value"><?php echo $currency . ' ' . number_format((float)$f['outstanding_pledged_total'], 2); ?></div>
                            <div class="metric-sub">Approved pledges not yet paid</div>
                        </div></div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card metric-card h-100"><div class="card-body">
                            <div class="metric-label">Campaign Value</div>
                            <div class="metric-value"><?php echo $currency . ' ' . number_format((float)$f['campaign_value_approved'], 2); ?></div>
                            <div class="metric-sub">Approved pledges + approved direct payments</div>
                        </div></div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-lg-7">
                        <div class="card h-100">
                            <div class="card-header section-title">Financial Reconciliation</div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <tbody>
                                        <tr><th>Approved Pledges</th><td class="text-end"><?php echo $currency . ' ' . number_format((float)$f['approved_pledges_amount'], 2); ?></td></tr>
                                        <tr><th>Approved Direct Payments</th><td class="text-end"><?php echo $currency . ' ' . number_format((float)$f['approved_direct_payments_amount'], 2); ?></td></tr>
                                        <tr><th>Confirmed Pledge Payments</th><td class="text-end"><?php echo $currency . ' ' . number_format((float)$f['confirmed_pledge_payments_amount'], 2); ?></td></tr>
                                        <tr class="table-light"><th>Cash Received Total</th><td class="text-end fw-bold"><?php echo $currency . ' ' . number_format((float)$f['cash_received_total'], 2); ?></td></tr>
                                        <tr class="table-light"><th>Outstanding Pledged Total</th><td class="text-end fw-bold"><?php echo $currency . ' ' . number_format((float)$f['outstanding_pledged_total'], 2); ?></td></tr>
                                        <tr class="table-primary"><th>Total Raised</th><td class="text-end fw-bold"><?php echo $currency . ' ' . number_format((float)$f['total_raised'], 2); ?></td></tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mt-3 small text-muted">Formula: <code>Total Raised = Cash Received + Outstanding Pledged</code></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-5">
                        <div class="card h-100">
                            <div class="card-header section-title">Target Progress</div>
                            <div class="card-body">
                                <div class="mb-2 d-flex justify-content-between">
                                    <span class="text-muted">Progress</span>
                                    <strong><?php echo number_format($progressPct, 2); ?>%</strong>
                                </div>
                                <div class="progress mb-3" role="progressbar" aria-valuenow="<?php echo (int)$progressPct; ?>" aria-valuemin="0" aria-valuemax="100" style="height: 14px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $progressPct; ?>%"></div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <tbody>
                                        <tr><th>Target</th><td class="text-end"><?php echo $currency . ' ' . number_format($campaignTarget, 2); ?></td></tr>
                                        <tr><th>Raised</th><td class="text-end"><?php echo $currency . ' ' . number_format((float)$f['total_raised'], 2); ?></td></tr>
                                        <tr><th>Remaining</th><td class="text-end"><?php echo $currency . ' ' . number_format($targetRemaining, 2); ?></td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-lg-4">
                        <div class="card h-100">
                            <div class="card-header section-title">Payment Status Breakdown</div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead><tr><th>Status</th><th class="text-end">Count</th><th class="text-end">Amount</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($report['status_breakdown']['payments'] as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-end"><?php echo number_format((int)$row['count']); ?></td>
                                                <td class="text-end"><?php echo $currency . ' ' . number_format((float)$row['amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <div class="card h-100">
                            <div class="card-header section-title">Pledge Status Breakdown</div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead><tr><th>Status</th><th class="text-end">Count</th><th class="text-end">Amount</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($report['status_breakdown']['pledges'] as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-end"><?php echo number_format((int)$row['count']); ?></td>
                                                <td class="text-end"><?php echo $currency . ' ' . number_format((float)$row['amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <div class="card h-100">
                            <div class="card-header section-title">Package Mix (Campaign Value)</div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead><tr><th>Package</th><th class="text-end">Amount</th><th class="text-end">Share</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($report['package_mix'] as $row): ?>
                                            <?php $share = ((float)$f['campaign_value_approved'] > 0) ? (((float)$row['amount'] / (float)$f['campaign_value_approved']) * 100.0) : 0.0; ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)$row['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-end"><?php echo $currency . ' ' . number_format((float)$row['amount'], 2); ?></td>
                                                <td class="text-end"><?php echo number_format($share, 1); ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header section-title">Monthly Trend (Last 12 Months)</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th class="text-end">Direct Paid</th>
                                        <th class="text-end">Pledge Paid</th>
                                        <th class="text-end">Cash Received</th>
                                        <th class="text-end">Approved Pledges</th>
                                        <th class="trend-cell">Campaign Value Trend</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($report['monthly_trend_last_12_months'] as $row): ?>
                                    <?php
                                        $campaignValue = (float)$row['campaign_value'];
                                        $w = ($maxTrend > 0) ? round(($campaignValue / $maxTrend) * 100, 2) : 0;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$row['month'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-end"><?php echo $currency . ' ' . number_format((float)$row['approved_direct_payments'], 2); ?></td>
                                        <td class="text-end"><?php echo $currency . ' ' . number_format((float)$row['confirmed_pledge_payments'], 2); ?></td>
                                        <td class="text-end fw-semibold"><?php echo $currency . ' ' . number_format((float)$row['cash_received'], 2); ?></td>
                                        <td class="text-end"><?php echo $currency . ' ' . number_format((float)$row['approved_pledges'], 2); ?></td>
                                        <td class="trend-cell">
                                            <div class="trend-bar-wrap">
                                                <div class="trend-bar" style="width: <?php echo $w; ?>%"></div>
                                            </div>
                                            <div class="small text-muted mt-1"><?php echo $currency . ' ' . number_format($campaignValue, 2); ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-xl-8">
                        <div class="card h-100">
                            <div class="card-header section-title">Top Donors (By Approved Commitment)</div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead>
                                        <tr>
                                            <th>Donor</th>
                                            <th>Phone</th>
                                            <th class="text-end">Approved Pledged</th>
                                            <th class="text-end">Cash Received</th>
                                            <th class="text-end">Outstanding</th>
                                            <th class="text-end">Campaign Value</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach (array_slice($report['top_donors'], 0, 20) as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)$row['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string)$row['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-end"><?php echo $currency . ' ' . number_format((float)$row['approved_pledged'], 2); ?></td>
                                                <td class="text-end"><?php echo $currency . ' ' . number_format((float)$row['cash_received'], 2); ?></td>
                                                <td class="text-end"><?php echo $currency . ' ' . number_format((float)$row['outstanding_pledged'], 2); ?></td>
                                                <td class="text-end fw-semibold"><?php echo $currency . ' ' . number_format((float)$row['campaign_value'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-xl-4">
                        <div class="card h-100">
                            <div class="card-header section-title">Data Quality</div>
                            <div class="card-body">
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Approved payments missing donor_id
                                        <span class="badge bg-warning text-dark"><?php echo number_format((int)$report['data_quality']['approved_payments_missing_donor_id_count']); ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Approved pledges missing donor_id
                                        <span class="badge bg-warning text-dark"><?php echo number_format((int)$report['data_quality']['approved_pledges_missing_donor_id_count']); ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Payments without contact
                                        <span class="badge bg-danger"><?php echo number_format((int)$report['data_quality']['payments_without_contact_count']); ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Pledges without contact
                                        <span class="badge bg-danger"><?php echo number_format((int)$report['data_quality']['pledges_without_contact_count']); ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Duplicate phone groups
                                        <span class="badge bg-secondary"><?php echo number_format(count($report['data_quality']['duplicate_phone_groups'])); ?></span>
                                    </li>
                                </ul>
                                <?php if (!empty($report['data_quality']['duplicate_phone_groups'])): ?>
                                    <div class="mt-3">
                                        <div class="small fw-semibold mb-1">Top duplicate phones</div>
                                        <?php foreach (array_slice($report['data_quality']['duplicate_phone_groups'], 0, 8) as $dup): ?>
                                            <div class="d-flex justify-content-between small border-bottom py-1">
                                                <span><?php echo htmlspecialchars((string)$dup['phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="text-muted"><?php echo (int)$dup['count']; ?> records</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header section-title">System Health</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12 col-lg-6">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead><tr><th>Table</th><th class="text-end">Status</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($report['health']['tables'] as $table => $ok): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)$table, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-end">
                                                    <?php if ($ok): ?><span class="badge bg-success">OK</span><?php else: ?><span class="badge bg-danger">Missing</span><?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead><tr><th>Check</th><th class="text-end">Value</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($report['health']['columns'] as $k => $v): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-end"><?php echo htmlspecialchars(is_bool($v) ? ($v ? 'true' : 'false') : (string)$v, ENT_QUOTES, 'UTF-8'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <details class="card mb-3">
                    <summary class="card-header section-title" style="cursor: pointer;">Raw Backend Data Payload</summary>
                    <div class="card-body">
                        <p class="text-muted mb-2">Use <code>window.ALL_DATA_REPORT</code> or <code>?format=json</code> for frontend integration.</p>
                        <pre class="code-block mb-0"><?php echo htmlspecialchars(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?></pre>
                    </div>
                </details>
            </div>
        </main>
    </div>
</div>

<script>
window.ALL_DATA_REPORT = <?php echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
</body>
</html>
