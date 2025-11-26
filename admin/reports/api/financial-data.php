<?php
/**
 * Financial Dashboard API - Returns JSON data for charts
 * Mobile-optimized, lightweight responses
 */
declare(strict_types=1);

require_once '../../../config/db.php';
require_once '../../../shared/auth.php';
require_once '../../../shared/FinancialCalculator.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Require login
if (!function_exists('current_user') || !current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$calculator = new FinancialCalculator();

// Date range handling
$range = $_GET['range'] ?? 'all';
$dateFrom = null;
$dateTo = null;

$now = new DateTime('now');
switch ($range) {
    case 'today':
        $dateFrom = (clone $now)->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $dateTo = (clone $now)->setTime(23, 59, 59)->format('Y-m-d H:i:s');
        break;
    case 'week':
        $dateFrom = (clone $now)->modify('monday this week')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $dateTo = (clone $now)->modify('sunday this week')->setTime(23, 59, 59)->format('Y-m-d H:i:s');
        break;
    case 'month':
        $dateFrom = (new DateTime(date('Y-m-01 00:00:00')))->format('Y-m-d H:i:s');
        $dateTo = (new DateTime(date('Y-m-t 23:59:59')))->format('Y-m-d H:i:s');
        break;
    case 'quarter':
        $q = (int)floor(((int)$now->format('n') - 1) / 3) + 1;
        $startMonth = 1 + ($q - 1) * 3;
        $dateFrom = (new DateTime($now->format('Y') . '-' . $startMonth . '-01 00:00:00'))->format('Y-m-d H:i:s');
        $dateTo = (new DateTime($now->format('Y') . '-' . ($startMonth + 2) . '-01'))->modify('last day of this month')->setTime(23, 59, 59)->format('Y-m-d H:i:s');
        break;
    case 'year':
        $dateFrom = (new DateTime($now->format('Y') . '-01-01 00:00:00'))->format('Y-m-d H:i:s');
        $dateTo = (new DateTime($now->format('Y') . '-12-31 23:59:59'))->format('Y-m-d H:i:s');
        break;
    default: // 'all'
        $dateFrom = null;
        $dateTo = null;
}

// Get settings
$settings = $db->query("SELECT target_amount, currency_code FROM settings WHERE id=1")->fetch_assoc() 
    ?: ['target_amount' => 0, 'currency_code' => 'GBP'];
$targetAmount = (float)($settings['target_amount'] ?? 0);

// Get KPI data using calculator
$totals = $calculator->getTotals($dateFrom, $dateTo);

$totalPaid = $totals['total_paid'];
$outstandingPledged = $totals['outstanding_pledged'];
$grandTotal = $totals['grand_total'];
$instantPayments = $totals['instant_payments'];
$pledgePayments = $totals['pledge_payments'];
$paymentCount = $totals['total_payment_count'];

// Collection rate
$collectionRate = $grandTotal > 0 ? round(($totalPaid / $grandTotal) * 100, 1) : 0;

// Goal progress
$goalProgress = $targetAmount > 0 ? round(($grandTotal / $targetAmount) * 100, 1) : 0;

// Average payment
$avgPayment = $paymentCount > 0 ? $totalPaid / $paymentCount : 0;

// Donor count within range
if ($dateFrom !== null && $dateTo !== null) {
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT donor_id) AS c FROM (
            SELECT donor_id FROM payments WHERE status='approved' AND donor_id IS NOT NULL AND received_at BETWEEN ? AND ?
            UNION
            SELECT donor_id FROM pledge_payments WHERE status='confirmed' AND donor_id IS NOT NULL AND created_at BETWEEN ? AND ?
        ) t
    ");
    $stmt->bind_param('ssss', $dateFrom, $dateTo, $dateFrom, $dateTo);
    $stmt->execute();
    $donorCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
} else {
    $donorCount = (int)($db->query("SELECT COUNT(DISTINCT id) AS c FROM donors WHERE total_pledged > 0 OR total_paid > 0")->fetch_assoc()['c'] ?? 0);
}

// --- Top 10 Donors (by amount paid) ---
$topDonors = [];
$topDonorsSql = "
    SELECT name, total_paid 
    FROM donors 
    WHERE total_paid > 0 
    ORDER BY total_paid DESC 
    LIMIT 10
";
$res = $db->query($topDonorsSql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $topDonors[] = [
            'name' => $row['name'],
            'amount' => (float)$row['total_paid']
        ];
    }
}

// --- Recent Transactions (Last 10) ---
// Union of payments and pledge_payments
$recentTransactions = [];
$transactionsSql = "
    SELECT 
        p.id,
        COALESCE(p.donor_name, 'Anonymous') as name,
        p.amount,
        p.method,
        p.received_at as date,
        'payment' as type
    FROM payments p
    WHERE p.status = 'approved'
    UNION ALL
    SELECT 
        pp.id,
        COALESCE(d.name, 'Unknown') as name,
        pp.amount,
        pp.payment_method as method,
        pp.created_at as date,
        'pledge_payment' as type
    FROM pledge_payments pp
    LEFT JOIN donors d ON pp.donor_id = d.id
    WHERE pp.status = 'confirmed'
    ORDER BY date DESC
    LIMIT 10
";
$res = $db->query($transactionsSql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $recentTransactions[] = [
            'name' => $row['name'],
            'amount' => (float)$row['amount'],
            'method' => ucfirst(str_replace('_', ' ', $row['method'])),
            'date' => $row['date'],
            'type' => $row['type'] === 'pledge_payment' ? 'Pledge Pay' : 'Donation'
        ];
    }
}

// --- Payment Plans Status ---
$paymentPlans = [
    'active' => 0,
    'completed' => 0,
    'paused' => 0,
    'defaulted' => 0,
    'cancelled' => 0
];
// Check if table exists first to avoid errors
$check = $db->query("SHOW TABLES LIKE 'donor_payment_plans'");
if ($check && $check->num_rows > 0) {
    $res = $db->query("SELECT status, COUNT(*) as c FROM donor_payment_plans GROUP BY status");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (isset($paymentPlans[$row['status']])) {
                $paymentPlans[$row['status']] = (int)$row['c'];
            }
        }
    }
}

// Payment methods breakdown
$methodsData = ['cash' => 0, 'bank_transfer' => 0, 'card' => 0, 'other' => 0];

if ($dateFrom !== null && $dateTo !== null) {
    // Instant payments
    $stmt = $db->prepare("SELECT method, SUM(amount) AS total FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ? GROUP BY method");
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $m = strtolower($row['method'] ?? 'other');
        if ($m === 'bank') $m = 'bank_transfer';
        if (!isset($methodsData[$m])) $m = 'other';
        $methodsData[$m] += (float)$row['total'];
    }
    
    // Pledge payments
    $stmt = $db->prepare("SELECT payment_method, SUM(amount) AS total FROM pledge_payments WHERE status='confirmed' AND created_at BETWEEN ? AND ? GROUP BY payment_method");
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $m = strtolower($row['payment_method'] ?? 'other');
        if ($m === 'bank') $m = 'bank_transfer';
        if (!isset($methodsData[$m])) $m = 'other';
        $methodsData[$m] += (float)$row['total'];
    }
} else {
    // All time - instant payments
    $res = $db->query("SELECT method, SUM(amount) AS total FROM payments WHERE status='approved' GROUP BY method");
    while ($row = $res->fetch_assoc()) {
        $m = strtolower($row['method'] ?? 'other');
        if ($m === 'bank') $m = 'bank_transfer';
        if (!isset($methodsData[$m])) $m = 'other';
        $methodsData[$m] += (float)$row['total'];
    }
    
    // All time - pledge payments
    $res = $db->query("SELECT payment_method, SUM(amount) AS total FROM pledge_payments WHERE status='confirmed' GROUP BY payment_method");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $m = strtolower($row['payment_method'] ?? 'other');
            if ($m === 'bank') $m = 'bank_transfer';
            if (!isset($methodsData[$m])) $m = 'other';
            $methodsData[$m] += (float)$row['total'];
        }
    }
}

// Monthly data for last 12 months
$monthlyData = [];
for ($i = 11; $i >= 0; $i--) {
    $monthDate = new DateTime();
    $monthDate->modify("-{$i} months");
    $monthKey = $monthDate->format('Y-m');
    $monthLabel = $monthDate->format('M Y');
    $monthlyData[$monthKey] = [
        'label' => $monthLabel,
        'payments' => 0,
        'pledges' => 0
    ];
}

// Get monthly payments
$res = $db->query("
    SELECT DATE_FORMAT(received_at, '%Y-%m') AS month, SUM(amount) AS total 
    FROM payments 
    WHERE status='approved' AND received_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month
");
while ($row = $res->fetch_assoc()) {
    if (isset($monthlyData[$row['month']])) {
        $monthlyData[$row['month']]['payments'] = (float)$row['total'];
    }
}

// Get monthly pledge payments
$res = $db->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, SUM(amount) AS total 
    FROM pledge_payments 
    WHERE status='confirmed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        if (isset($monthlyData[$row['month']])) {
            $monthlyData[$row['month']]['payments'] += (float)$row['total'];
        }
    }
}

// Get monthly pledges created
$res = $db->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, SUM(amount) AS total 
    FROM pledges 
    WHERE status='approved' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month
");
while ($row = $res->fetch_assoc()) {
    if (isset($monthlyData[$row['month']])) {
        $monthlyData[$row['month']]['pledges'] = (float)$row['total'];
    }
}

// Weekly pattern (payments by day of week)
$weeklyPattern = [
    'Sun' => 0, 'Mon' => 0, 'Tue' => 0, 'Wed' => 0, 'Thu' => 0, 'Fri' => 0, 'Sat' => 0
];

$res = $db->query("
    SELECT DAYNAME(received_at) AS dayname, SUM(amount) AS total 
    FROM payments 
    WHERE status='approved' AND received_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
    GROUP BY DAYOFWEEK(received_at), dayname
");
while ($row = $res->fetch_assoc()) {
    $dayShort = substr($row['dayname'], 0, 3);
    if (isset($weeklyPattern[$dayShort])) {
        $weeklyPattern[$dayShort] = (float)$row['total'];
    }
}

// Add pledge payments to weekly
$res = $db->query("
    SELECT DAYNAME(created_at) AS dayname, SUM(amount) AS total 
    FROM pledge_payments 
    WHERE status='confirmed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
    GROUP BY DAYOFWEEK(created_at), dayname
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $dayShort = substr($row['dayname'], 0, 3);
        if (isset($weeklyPattern[$dayShort])) {
            $weeklyPattern[$dayShort] += (float)$row['total'];
        }
    }
}

// Daily trend for last 30 days
$dailyTrend = [];
for ($i = 29; $i >= 0; $i--) {
    $date = new DateTime();
    $date->modify("-{$i} days");
    $dateKey = $date->format('Y-m-d');
    $dailyTrend[$dateKey] = [
        'label' => $date->format('M d'),
        'amount' => 0
    ];
}

$res = $db->query("
    SELECT DATE(received_at) AS day, SUM(amount) AS total 
    FROM payments 
    WHERE status='approved' AND received_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY day
");
while ($row = $res->fetch_assoc()) {
    if (isset($dailyTrend[$row['day']])) {
        $dailyTrend[$row['day']]['amount'] = (float)$row['total'];
    }
}

// Add pledge payments to daily
$res = $db->query("
    SELECT DATE(created_at) AS day, SUM(amount) AS total 
    FROM pledge_payments 
    WHERE status='confirmed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY day
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        if (isset($dailyTrend[$row['day']])) {
            $dailyTrend[$row['day']]['amount'] += (float)$row['total'];
        }
    }
}

// Build response
$response = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'range' => $range,
    'kpis' => [
        'grandTotal' => $grandTotal,
        'totalPaid' => $totalPaid,
        'outstanding' => $outstandingPledged,
        'collectionRate' => $collectionRate,
        'goalProgress' => $goalProgress,
        'donorCount' => $donorCount,
        'paymentCount' => $paymentCount,
        'avgPayment' => $avgPayment,
        'instantPayments' => $instantPayments,
        'pledgePayments' => $pledgePayments
    ],
    'lists' => [
        'topDonors' => $topDonors,
        'recentTransactions' => $recentTransactions,
        'paymentPlans' => $paymentPlans
    ],
    'charts' => [
        'methods' => [
            'labels' => ['Cash', 'Bank Transfer', 'Card', 'Other'],
            'data' => [
                $methodsData['cash'],
                $methodsData['bank_transfer'],
                $methodsData['card'],
                $methodsData['other']
            ]
        ],
        'sources' => [
            'labels' => ['Instant Payments', 'Pledge Payments'],
            'data' => [$instantPayments, $pledgePayments]
        ],
        'pledgeVsPayment' => [
            'labels' => ['Collected', 'Outstanding'],
            'data' => [$totalPaid, $outstandingPledged]
        ],
        'monthly' => [
            'labels' => array_column(array_values($monthlyData), 'label'),
            'payments' => array_column(array_values($monthlyData), 'payments'),
            'pledges' => array_column(array_values($monthlyData), 'pledges')
        ],
        'weekly' => [
            'labels' => array_keys($weeklyPattern),
            'data' => array_values($weeklyPattern)
        ],
        'trend' => [
            'labels' => array_column(array_values($dailyTrend), 'label'),
            'data' => array_column(array_values($dailyTrend), 'amount')
        ]
    ]
];

echo json_encode($response, JSON_NUMERIC_CHECK);
