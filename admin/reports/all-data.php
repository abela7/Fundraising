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
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="mb-0"><i class="fas fa-database text-primary me-2"></i>All Data Report</h4>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary btn-sm" href="?date=all">All Time</a>
                        <a class="btn btn-outline-secondary btn-sm" href="?date=month">This Month</a>
                        <a class="btn btn-outline-secondary btn-sm" href="?date=year">This Year</a>
                        <a class="btn btn-primary btn-sm" href="?date=<?php echo urlencode($range['range']); ?>&format=json" target="_blank">
                            <i class="fas fa-code me-1"></i>JSON
                        </a>
                    </div>
                </div>

                <?php if (!empty($report['health']['error'])): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($report['health']['error']); ?></div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card h-100"><div class="card-body">
                            <div class="text-muted small">Total Raised</div>
                            <div class="h4 mb-0"><?php echo $currency . ' ' . number_format((float)$report['financial']['total_raised'], 2); ?></div>
                        </div></div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card h-100"><div class="card-body">
                            <div class="text-muted small">Cash Received</div>
                            <div class="h4 mb-0"><?php echo $currency . ' ' . number_format((float)$report['financial']['cash_received_total'], 2); ?></div>
                        </div></div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card h-100"><div class="card-body">
                            <div class="text-muted small">Outstanding Pledged</div>
                            <div class="h4 mb-0"><?php echo $currency . ' ' . number_format((float)$report['financial']['outstanding_pledged_total'], 2); ?></div>
                        </div></div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card h-100"><div class="card-body">
                            <div class="text-muted small">Campaign Value</div>
                            <div class="h4 mb-0"><?php echo $currency . ' ' . number_format((float)$report['financial']['campaign_value_approved'], 2); ?></div>
                        </div></div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">Backend Data Payload</div>
                    <div class="card-body">
                        <p class="text-muted mb-2">Use <code>window.ALL_DATA_REPORT</code> for frontend rendering.</p>
                        <pre class="mb-0" style="max-height: 480px; overflow: auto;"><?php echo htmlspecialchars(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?></pre>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
window.ALL_DATA_REPORT = <?php echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
</body>
</html>

