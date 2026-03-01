<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

function table_exists(mysqli $db, string $table): bool {
    $safeTable = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safeTable}'");
    if ($res === false) {
        throw new RuntimeException('Error checking table existence for ' . $table . ': ' . $db->error);
    }
    return $res && $res->num_rows > 0;
}

function column_exists(mysqli $db, string $table, string $column): bool {
    $safeTable = $db->real_escape_string($table);
    $safeColumn = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    if ($res === false) {
        throw new RuntimeException('Error checking column existence for ' . $table . '.' . $column . ': ' . $db->error);
    }
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
        /* ── Metric Cards ── */
        .metric-card { border-left: 4px solid #0d6efd; transition: box-shadow .15s; }
        .metric-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); }
        .metric-card.mc-green  { border-left-color: #198754; }
        .metric-card.mc-orange { border-left-color: #fd7e14; }
        .metric-card.mc-purple { border-left-color: #6f42c1; }
        .metric-icon { font-size: 1.6rem; opacity: .15; position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); }
        .metric-label { font-size: 0.75rem; color: #6c757d; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
        .metric-value { font-size: 1.55rem; font-weight: 700; line-height: 1.2; margin: .25rem 0; }
        .metric-sub { font-size: 0.75rem; color: #6c757d; }

        /* ── Section Headers ── */
        .section-title { font-size: .9rem; font-weight: 700; letter-spacing: .02em; }
        .card-header { background: #f8f9fa; border-bottom: 1px solid rgba(0,0,0,.08); }

        /* ── Range Buttons ── */
        .range-btn.active { background: #0d6efd; color: #fff; border-color: #0d6efd; }

        /* ── Status Badges ── */
        .status-badge { display: inline-block; padding: .2em .55em; border-radius: .3rem; font-size: .72rem; font-weight: 600; text-transform: capitalize; }
        .status-approved, .status-confirmed { background: #d1fae5; color: #065f46; }
        .status-pending  { background: #fef3c7; color: #92400e; }
        .status-rejected, .status-failed, .status-cancelled { background: #fee2e2; color: #991b1b; }
        .status-default  { background: #e9ecef; color: #495057; }

        /* ── Trend Bar ── */
        .trend-cell { min-width: 160px; }
        .trend-bar-wrap { height: 8px; background: #e9ecef; border-radius: 100px; overflow: hidden; }
        .trend-bar { height: 100%; background: linear-gradient(90deg, #198754, #34d399); border-radius: 100px; }

        /* ── Package share bar ── */
        .share-bar-wrap { height: 6px; background: #e9ecef; border-radius: 100px; overflow: hidden; margin-top: 3px; }
        .share-bar { height: 100%; background: linear-gradient(90deg, #6f42c1, #a78bfa); border-radius: 100px; }

        /* ── Top Donors ── */
        .donor-rank { font-size: .7rem; font-weight: 700; color: #adb5bd; width: 1.5rem; }
        .donor-rank.top-3 { color: #d4af37; }

        /* ── Data Quality ── */
        .dq-badge-ok      { background: #d1fae5; color: #065f46; }
        .dq-badge-warn    { background: #fef3c7; color: #92400e; }
        .dq-badge-danger  { background: #fee2e2; color: #991b1b; }

        /* ── System Health columns ── */
        .col-check-true  { background: #d1fae5; color: #065f46; }
        .col-check-false { background: #fee2e2; color: #991b1b; }
        .col-check-num   { background: #e9ecef; color: #495057; }

        /* ── Raw Payload ── */
        .code-block { max-height: 400px; overflow: auto; font-size: .78rem; background: #111827; color: #e5e7eb; padding: 1rem; border-radius: .5rem; }

        /* ── Progress bar ── */
        .progress { border-radius: 100px; }
        .progress-bar { border-radius: 100px; }

        /* ── Tables ── */
        .table > :not(caption) > * > * { padding: .45rem .6rem; }
        .table thead th { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #6c757d; font-weight: 600; background: #f8f9fa; }

        /* ── Raw payload toggle button ── */
        .payload-toggle { cursor: pointer; user-select: none; }
        .payload-toggle .toggle-icon { transition: transform .2s; }
        .payload-toggle.open .toggle-icon { transform: rotate(180deg); }
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
                        'today'   => 'Today',
                        'week'    => 'This Week',
                        'month'   => 'This Month',
                        'quarter' => 'This Quarter',
                        'year'    => 'This Year',
                        'custom'  => 'Custom',
                        'all'     => 'All Time'
                    ];
                    $activeRange      = $range['range'];
                    $activeRangeLabel = $rangeLabelMap[$activeRange] ?? 'All Time';
                    $campaignTarget   = (float)$f['target_amount'];
                    $targetRemaining  = max(0.0, $campaignTarget - (float)$f['total_raised']);
                    $maxTrend = 0.0;
                    foreach ($report['monthly_trend_last_12_months'] as $trendRow) {
                        $maxTrend = max($maxTrend, (float)$trendRow['campaign_value']);
                    }
                    $customFrom = htmlspecialchars((string)($_GET['from'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $customTo   = htmlspecialchars((string)($_GET['to']   ?? ''), ENT_QUOTES, 'UTF-8');

                    // Helper: status badge class
                    function statusBadgeClass(string $s): string {
                        $s = strtolower(trim($s));
                        if (in_array($s, ['approved','confirmed'])) return 'status-approved';
                        if ($s === 'pending')                        return 'status-pending';
                        if (in_array($s, ['rejected','failed','cancelled'])) return 'status-rejected';
                        return 'status-default';
                    }
                ?>

                <!-- ── Page Header ── -->
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <h4 class="mb-1 fw-bold"><i class="fas fa-chart-bar text-primary me-2"></i>All Data Financial Report</h4>
                        <div class="text-muted small">
                            Scope: <strong class="text-dark"><?php echo htmlspecialchars($activeRangeLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                            &mdash; <?php echo htmlspecialchars($range['from'], ENT_QUOTES, 'UTF-8'); ?> to <?php echo htmlspecialchars($range['to'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <?php
                        $rangeButtons = [
                            'today'   => 'Today',
                            'week'    => 'Week',
                            'month'   => 'Month',
                            'quarter' => 'Quarter',
                            'year'    => 'Year',
                            'all'     => 'All',
                        ];
                        foreach ($rangeButtons as $key => $label):
                            $isActive = ($activeRange === $key);
                        ?>
                        <a class="btn btn-sm btn-outline-secondary range-btn <?php echo $isActive ? 'active' : ''; ?>"
                           href="?date=<?php echo $key; ?>"><?php echo $label; ?></a>
                        <?php endforeach; ?>
                        <a class="btn btn-sm btn-outline-primary" href="?date=<?php echo urlencode($activeRange); ?>&format=json" target="_blank">
                            <i class="fas fa-code me-1"></i>JSON
                        </a>
                    </div>
                </div>

                <!-- ── Custom Date Range ── -->
                <div class="card mb-4 border-0 bg-light">
                    <div class="card-body py-2">
                        <form class="row g-2 align-items-end" method="get">
                            <input type="hidden" name="date" value="custom">
                            <div class="col-12 col-sm-auto">
                                <label class="form-label mb-1 small fw-semibold">From</label>
                                <input class="form-control form-control-sm" type="date" name="from" value="<?php echo $customFrom; ?>">
                            </div>
                            <div class="col-12 col-sm-auto">
                                <label class="form-label mb-1 small fw-semibold">To</label>
                                <input class="form-control form-control-sm" type="date" name="to" value="<?php echo $customTo; ?>">
                            </div>
                            <div class="col-12 col-sm-auto">
                                <button class="btn btn-sm btn-dark" type="submit">
                                    <i class="fas fa-filter me-1"></i>Apply
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (!empty($report['health']['error'])): ?>
                    <div class="alert alert-danger d-flex align-items-center gap-2">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($report['health']['error'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <!-- ── Metric Cards ── -->
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card metric-card h-100 position-relative overflow-hidden">
                            <div class="card-body">
                                <div class="metric-label">Total Raised</div>
                                <div class="metric-value"><?php echo $currency . ' ' . number_format((float)$f['total_raised'], 2); ?></div>
                                <div class="metric-sub">Cash + outstanding pledged</div>
                            </div>
                            <i class="fas fa-coins metric-icon"></i>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card metric-card mc-green h-100 position-relative overflow-hidden">
                            <div class="card-body">
                                <div class="metric-label">Cash Received</div>
                                <div class="metric-value text-success"><?php echo $currency . ' ' . number_format((float)$f['cash_received_total'], 2); ?></div>
                                <div class="metric-sub">Approved direct + confirmed pledge payments</div>
                            </div>
                            <i class="fas fa-hand-holding-dollar metric-icon"></i>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card metric-card mc-orange h-100 position-relative overflow-hidden">
                            <div class="card-body">
                                <div class="metric-label">Outstanding Pledged</div>
                                <div class="metric-value text-warning"><?php echo $currency . ' ' . number_format((float)$f['outstanding_pledged_total'], 2); ?></div>
                                <div class="metric-sub">Approved pledges not yet paid</div>
                            </div>
                            <i class="fas fa-clock metric-icon"></i>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card metric-card mc-purple h-100 position-relative overflow-hidden">
                            <div class="card-body">
                                <div class="metric-label">Campaign Value</div>
                                <div class="metric-value" style="color:#6f42c1;"><?php echo $currency . ' ' . number_format((float)$f['campaign_value_approved'], 2); ?></div>
                                <div class="metric-sub">Approved pledges + approved direct payments</div>
                            </div>
                            <i class="fas fa-trophy metric-icon"></i>
                        </div>
                    </div>
                </div>

                <!-- ── Financial Reconciliation + Target Progress ── -->
                <div class="row g-3 mb-4">
                    <div class="col-12 col-lg-7">
                        <div class="card h-100">
                            <div class="card-header d-flex align-items-center gap-2">
                                <i class="fas fa-balance-scale text-primary"></i>
                                <span class="section-title">Financial Reconciliation</span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <tbody>
                                        <tr>
                                            <td class="text-muted ps-3">Approved Pledges</td>
                                            <td class="text-end pe-3"><?php echo $currency . ' ' . number_format((float)$f['approved_pledges_amount'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted ps-3">Approved Direct Payments</td>
                                            <td class="text-end pe-3"><?php echo $currency . ' ' . number_format((float)$f['approved_direct_payments_amount'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted ps-3">Confirmed Pledge Payments</td>
                                            <td class="text-end pe-3"><?php echo $currency . ' ' . number_format((float)$f['confirmed_pledge_payments_amount'], 2); ?></td>
                                        </tr>
                                        <tr class="table-light">
                                            <td class="fw-semibold ps-3">Cash Received Total</td>
                                            <td class="text-end fw-semibold pe-3 text-success"><?php echo $currency . ' ' . number_format((float)$f['cash_received_total'], 2); ?></td>
                                        </tr>
                                        <tr class="table-light">
                                            <td class="fw-semibold ps-3">Outstanding Pledged Total</td>
                                            <td class="text-end fw-semibold pe-3 text-warning"><?php echo $currency . ' ' . number_format((float)$f['outstanding_pledged_total'], 2); ?></td>
                                        </tr>
                                        <tr style="background:#dbeafe;">
                                            <td class="fw-bold ps-3 text-primary">Total Raised</td>
                                            <td class="text-end fw-bold pe-3 text-primary"><?php echo $currency . ' ' . number_format((float)$f['total_raised'], 2); ?></td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent py-2">
                                <span class="small text-muted"><i class="fas fa-info-circle me-1"></i>Total Raised = Cash Received + Outstanding Pledged</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-5">
                        <div class="card h-100">
                            <div class="card-header d-flex align-items-center gap-2">
                                <i class="fas fa-bullseye text-success"></i>
                                <span class="section-title">Target Progress</span>
                                <span class="ms-auto badge <?php echo $progressPct >= 100 ? 'bg-success' : 'bg-primary'; ?>">
                                    <?php echo number_format($progressPct, 1); ?>%
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="progress mb-3" style="height: 18px;" role="progressbar"
                                     aria-valuenow="<?php echo (int)$progressPct; ?>" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar <?php echo $progressPct >= 100 ? 'bg-success' : 'bg-primary'; ?>"
                                         style="width: <?php echo $progressPct; ?>%; font-size:.7rem; line-height:18px;">
                                        <?php if ($progressPct >= 10): echo number_format($progressPct, 1) . '%'; endif; ?>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <tbody>
                                        <tr>
                                            <td class="text-muted">Target</td>
                                            <td class="text-end fw-semibold"><?php echo $currency . ' ' . number_format($campaignTarget, 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted">Raised</td>
                                            <td class="text-end fw-semibold text-success"><?php echo $currency . ' ' . number_format((float)$f['total_raised'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted">Remaining</td>
                                            <td class="text-end fw-semibold text-danger"><?php echo $currency . ' ' . number_format($targetRemaining, 2); ?></td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Status Breakdowns ── -->
                <div class="row g-3 mb-4">
                    <div class="col-12 col-lg-4">
                        <div class="card h-100">
                            <div class="card-header d-flex align-items-center gap-2">
                                <i class="fas fa-credit-card text-info"></i>
                                <span class="section-title">Payment Status</span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead><tr><th class="ps-3">Status</th><th class="text-end">Count</th><th class="text-end pe-3">Amount</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($report['status_breakdown']['payments'] as $row): ?>
                                            <tr>
                                                <td class="ps-3">
                                                    <span class="status-badge <?php echo statusBadgeClass((string)$row['status']); ?>">
                                                        <?php echo htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end"><?php echo number_format((int)$row['count']); ?></td>
                                                <td class="text-end pe-3"><?php echo $currency . ' ' . number_format((float)$row['amount'], 2); ?></td>
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
                            <div class="card-header d-flex align-items-center gap-2">
                                <i class="fas fa-handshake text-warning"></i>
                                <span class="section-title">Pledge Status</span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead><tr><th class="ps-3">Status</th><th class="text-end">Count</th><th class="text-end pe-3">Amount</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($report['status_breakdown']['pledges'] as $row): ?>
                                            <tr>
                                                <td class="ps-3">
                                                    <span class="status-badge <?php echo statusBadgeClass((string)$row['status']); ?>">
                                                        <?php echo htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end"><?php echo number_format((int)$row['count']); ?></td>
                                                <td class="text-end pe-3"><?php echo $currency . ' ' . number_format((float)$row['amount'], 2); ?></td>
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
                            <div class="card-header d-flex align-items-center gap-2">
                                <i class="fas fa-layer-group text-purple" style="color:#6f42c1;"></i>
                                <span class="section-title">Package Mix</span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead><tr><th class="ps-3">Package</th><th class="text-end pe-3">Amount</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($report['package_mix'] as $row): ?>
                                            <?php $share = ((float)$f['campaign_value_approved'] > 0)
                                                ? round(((float)$row['amount'] / (float)$f['campaign_value_approved']) * 100, 1)
                                                : 0.0; ?>
                                            <tr>
                                                <td class="ps-3">
                                                    <div class="small"><?php echo htmlspecialchars((string)$row['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                    <div class="share-bar-wrap">
                                                        <div class="share-bar" style="width:<?php echo $share; ?>%"></div>
                                                    </div>
                                                    <div style="font-size:.68rem;color:#6c757d;"><?php echo $share; ?>%</div>
                                                </td>
                                                <td class="text-end pe-3 align-middle fw-semibold"><?php echo $currency . ' ' . number_format((float)$row['amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Monthly Trend ── -->
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center gap-2">
                        <i class="fas fa-chart-line text-success"></i>
                        <span class="section-title">Monthly Trend — Last 12 Months</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-3">Month</th>
                                        <th class="text-end">Direct Paid</th>
                                        <th class="text-end">Pledge Paid</th>
                                        <th class="text-end">Cash Received</th>
                                        <th class="text-end">Approved Pledges</th>
                                        <th class="trend-cell pe-3">Campaign Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($report['monthly_trend_last_12_months'] as $row):
                                    $campaignValue = (float)$row['campaign_value'];
                                    $w = ($maxTrend > 0) ? round(($campaignValue / $maxTrend) * 100, 2) : 0;
                                ?>
                                    <tr>
                                        <td class="ps-3 fw-semibold"><?php echo htmlspecialchars((string)$row['month'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-end"><?php echo $currency . ' ' . number_format((float)$row['approved_direct_payments'], 2); ?></td>
                                        <td class="text-end"><?php echo $currency . ' ' . number_format((float)$row['confirmed_pledge_payments'], 2); ?></td>
                                        <td class="text-end fw-semibold text-success"><?php echo $currency . ' ' . number_format((float)$row['cash_received'], 2); ?></td>
                                        <td class="text-end"><?php echo $currency . ' ' . number_format((float)$row['approved_pledges'], 2); ?></td>
                                        <td class="trend-cell pe-3">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <span class="small fw-semibold"><?php echo $currency . ' ' . number_format($campaignValue, 2); ?></span>
                                                <span style="font-size:.65rem;color:#adb5bd;"><?php echo $w; ?>%</span>
                                            </div>
                                            <div class="trend-bar-wrap">
                                                <div class="trend-bar" style="width: <?php echo $w; ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ── Top Donors + Data Quality ── -->
                <div class="row g-3 mb-4">
                    <div class="col-12 col-xl-8">
                        <div class="card h-100">
                            <div class="card-header d-flex align-items-center gap-2">
                                <i class="fas fa-medal text-warning"></i>
                                <span class="section-title">Top Donors</span>
                                <span class="ms-auto text-muted small">by approved commitment</span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover mb-0">
                                        <thead>
                                        <tr>
                                            <th class="ps-3" style="width:2rem;">#</th>
                                            <th>Donor</th>
                                            <th>Phone</th>
                                            <th class="text-end">Pledged</th>
                                            <th class="text-end">Cash Received</th>
                                            <th class="text-end">Outstanding</th>
                                            <th class="text-end pe-3">Campaign Value</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach (array_slice($report['top_donors'], 0, 20) as $i => $row): ?>
                                            <tr>
                                                <td class="ps-3">
                                                    <span class="donor-rank <?php echo $i < 3 ? 'top-3' : ''; ?>">
                                                        <?php echo $i < 3 ? ['🥇','🥈','🥉'][$i] : ($i + 1); ?>
                                                    </span>
                                                </td>
                                                <td class="fw-semibold"><?php echo htmlspecialchars((string)$row['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-muted small font-monospace"><?php echo htmlspecialchars((string)$row['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-end"><?php echo $currency . ' ' . number_format((float)$row['approved_pledged'], 2); ?></td>
                                                <td class="text-end text-success"><?php echo $currency . ' ' . number_format((float)$row['cash_received'], 2); ?></td>
                                                <td class="text-end text-warning"><?php echo $currency . ' ' . number_format((float)$row['outstanding_pledged'], 2); ?></td>
                                                <td class="text-end fw-bold pe-3"><?php echo $currency . ' ' . number_format((float)$row['campaign_value'], 2); ?></td>
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
                            <div class="card-header d-flex align-items-center gap-2">
                                <i class="fas fa-shield-halved text-danger"></i>
                                <span class="section-title">Data Quality</span>
                            </div>
                            <div class="card-body p-0">
                                <?php
                                $dqItems = [
                                    ['label' => 'Payments missing donor ID',  'val' => (int)$report['data_quality']['approved_payments_missing_donor_id_count'], 'severity' => 'warn'],
                                    ['label' => 'Pledges missing donor ID',   'val' => (int)$report['data_quality']['approved_pledges_missing_donor_id_count'],  'severity' => 'warn'],
                                    ['label' => 'Payments without contact',   'val' => (int)$report['data_quality']['payments_without_contact_count'],           'severity' => 'danger'],
                                    ['label' => 'Pledges without contact',    'val' => (int)$report['data_quality']['pledges_without_contact_count'],            'severity' => 'danger'],
                                    ['label' => 'Duplicate phone groups',     'val' => count($report['data_quality']['duplicate_phone_groups']),                 'severity' => 'warn'],
                                ];
                                ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($dqItems as $dq):
                                        $cls = $dq['val'] === 0 ? 'dq-badge-ok' : 'dq-badge-' . $dq['severity'];
                                    ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-3">
                                        <span class="small"><?php echo $dq['label']; ?></span>
                                        <span class="badge <?php echo $cls; ?> rounded-pill"><?php echo number_format($dq['val']); ?></span>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if (!empty($report['data_quality']['duplicate_phone_groups'])): ?>
                                    <div class="px-3 pt-3 pb-2">
                                        <div class="small fw-semibold text-muted mb-2">Top duplicate phones</div>
                                        <?php foreach (array_slice($report['data_quality']['duplicate_phone_groups'], 0, 8) as $dup): ?>
                                            <div class="d-flex justify-content-between small py-1 border-bottom">
                                                <span class="font-monospace"><?php echo htmlspecialchars((string)$dup['phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="badge bg-secondary rounded-pill"><?php echo (int)$dup['count']; ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── System Health ── -->
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center gap-2">
                        <i class="fas fa-server text-secondary"></i>
                        <span class="section-title">System Health</span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12 col-lg-6">
                                <div class="small fw-semibold text-muted text-uppercase mb-2" style="letter-spacing:.05em;">Tables</div>
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead><tr><th>Table</th><th class="text-end">Status</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($report['health']['tables'] as $table => $ok): ?>
                                            <tr>
                                                <td class="font-monospace small"><?php echo htmlspecialchars((string)$table, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-end">
                                                    <?php if ($ok): ?>
                                                        <span class="badge dq-badge-ok"><i class="fas fa-check me-1"></i>OK</span>
                                                    <?php else: ?>
                                                        <span class="badge dq-badge-danger"><i class="fas fa-times me-1"></i>Missing</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <div class="small fw-semibold text-muted text-uppercase mb-2" style="letter-spacing:.05em;">Column Checks</div>
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead><tr><th>Check</th><th class="text-end">Value</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($report['health']['columns'] as $k => $v): ?>
                                            <tr>
                                                <td class="small"><?php echo htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-end">
                                                    <?php if (is_bool($v)): ?>
                                                        <span class="badge <?php echo $v ? 'dq-badge-ok' : 'dq-badge-danger'; ?>">
                                                            <?php echo $v ? 'true' : 'false'; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge col-check-num"><?php echo htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Raw Data Payload ── -->
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center gap-2 payload-toggle" id="payloadToggle">
                        <i class="fas fa-code text-secondary"></i>
                        <span class="section-title">Raw Backend Data Payload</span>
                        <i class="fas fa-chevron-down toggle-icon ms-auto text-muted"></i>
                    </div>
                    <div class="card-body d-none" id="payloadBody">
                        <p class="text-muted small mb-2">Use <code>window.ALL_DATA_REPORT</code> in JS or append <code>?format=json</code> for API access.</p>
                        <pre class="code-block mb-0"><?php echo htmlspecialchars(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?></pre>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
window.ALL_DATA_REPORT = <?php echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

// Raw payload toggle
(function () {
    const toggle = document.getElementById('payloadToggle');
    const body   = document.getElementById('payloadBody');
    if (!toggle || !body) return;
    toggle.addEventListener('click', function () {
        const hidden = body.classList.toggle('d-none');
        toggle.classList.toggle('open', !hidden);
    });
})();
</script>
</body>
</html>
