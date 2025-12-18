<?php
declare(strict_types=1);

require_once '../../../config/db.php';
require_once '../../../shared/auth.php';

// Set JSON header
header('Content-Type: application/json');

// Auth check
require_login();
require_admin();

try {
    $db = db();
    $response = [];

    // Date range filter (default to all time or last 12 months for trends)
    // For simplicity in this version, trends are last 12 months, totals are all time or filtered by year if requested.
    // We will implement basic totals first.

    // 1. KPI Cards Data
    // Keep dashboard consistent with FinancialCalculator semantics:
    // - Total Paid = payments (approved) + pledge_payments (confirmed)
    // - Outstanding Pledged = approved pledges - confirmed pledge_payments
    //   (i.e. promises remaining, not "sum of donor balances")

    $hasPledgePayments = false;
    $ppCheck = $db->query("SHOW TABLES LIKE 'pledge_payments'");
    if ($ppCheck && $ppCheck->num_rows > 0) {
        $hasPledgePayments = true;
    }

    $kpiQuery = "SELECT 
        (SELECT IFNULL(SUM(amount),0) FROM pledges WHERE status = 'approved') as total_pledged,
        (SELECT IFNULL(SUM(amount),0) FROM payments WHERE status = 'approved') as total_paid_direct,
        " . ($hasPledgePayments ? "(SELECT IFNULL(SUM(amount),0) FROM pledge_payments WHERE status = 'confirmed')" : "0") . " as total_paid_pledge,
        (SELECT COUNT(*) FROM donors) as total_donors,
        (SELECT COUNT(*) FROM donor_payment_plans WHERE status = 'active') as active_plans";

$kpiResult = $db->query($kpiQuery);
$kpiData = $kpiResult->fetch_assoc();

    // Calculate true total paid (Direct + Pledge Payments)
    $totalPaid = (float)$kpiData['total_paid_direct'] + (float)$kpiData['total_paid_pledge'];

    // Outstanding pledged (promises remaining)
    $outstandingPledged = max(0.0, (float)$kpiData['total_pledged'] - (float)$kpiData['total_paid_pledge']);

    $response['kpi'] = [
        'total_pledged' => (float)$kpiData['total_pledged'],
        'total_paid' => (float)$totalPaid,
        'outstanding' => (float)$outstandingPledged,
        'total_donors' => (int)$kpiData['total_donors'],
        'active_plans' => (int)$kpiData['active_plans'],
        'collection_rate' => ((float)$kpiData['total_pledged'] > 0) ? round(($totalPaid / (float)$kpiData['total_pledged']) * 100, 1) : 0
    ];

// 2. Monthly Trends (Last 12 Months)
// Pledges
$trendPledges = $db->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, SUM(amount) as total 
    FROM pledges 
    WHERE status = 'approved' AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month 
    ORDER BY month ASC
");

// Payments (Direct + Pledge Payments)
// We need to combine them for the chart
$trendPayments = $db->query("
    SELECT month, SUM(total) as total FROM (
        SELECT DATE_FORMAT(received_at, '%Y-%m') as month, SUM(amount) as total 
        FROM payments 
        WHERE status = 'approved' AND received_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY month
        UNION ALL
        SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as total 
        FROM pledge_payments 
        WHERE status = 'confirmed' AND payment_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY month
    ) as combined
    GROUP BY month
    ORDER BY month ASC
");

$months = [];
$pledgeData = [];
$paymentData = [];

// Initialize last 12 months
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $months[$m] = ['label' => date('M Y', strtotime("-$i months")), 'pledged' => 0, 'paid' => 0];
}

while ($row = $trendPledges->fetch_assoc()) {
    if (isset($months[$row['month']])) {
        $months[$row['month']]['pledged'] = (float)$row['total'];
    }
}
while ($row = $trendPayments->fetch_assoc()) {
    if (isset($months[$row['month']])) {
        $months[$row['month']]['paid'] = (float)$row['total'];
    }
}

$response['trends'] = array_values($months);

// 3. Payment Methods (All time)
// Combine methods from both tables - normalize method names
$methodsQuery = "
    SELECT method, COUNT(*) as count, SUM(amount) as total FROM (
        SELECT method, amount FROM payments WHERE status = 'approved'
        UNION ALL
        SELECT 
            CASE 
                WHEN payment_method = 'bank_transfer' THEN 'bank'
                WHEN payment_method = 'card' THEN 'card'
                WHEN payment_method = 'cash' THEN 'cash'
                WHEN payment_method = 'cheque' THEN 'other'
                ELSE 'other'
            END as method, 
            amount 
        FROM pledge_payments 
        WHERE status = 'confirmed'
    ) as combined
    GROUP BY method
";
$methodsResult = $db->query($methodsQuery);
$methodsData = [];
while ($row = $methodsResult->fetch_assoc()) {
    $methodName = $row['method'];
    // Normalize display names
    if ($methodName === 'bank') $methodName = 'Bank Transfer';
    elseif ($methodName === 'card') $methodName = 'Card';
    elseif ($methodName === 'cash') $methodName = 'Cash';
    else $methodName = 'Other';
    
    $methodsData[] = [
        'method' => $methodName,
        'count' => (int)$row['count'],
        'total' => (float)$row['total']
    ];
}
$response['payment_methods'] = $methodsData;

// 4. Pledge Status Breakdown
$pledgeStatusQuery = "SELECT status, COUNT(*) as count, SUM(amount) as total FROM pledges GROUP BY status";
$pledgeStatusResult = $db->query($pledgeStatusQuery);
$pledgeStatusData = [];
while ($row = $pledgeStatusResult->fetch_assoc()) {
    $pledgeStatusData[] = [
        'status' => ucfirst($row['status']),
        'count' => (int)$row['count'],
        'total' => (float)$row['total']
    ];
}
$response['pledge_status'] = $pledgeStatusData;

// 5. Top 10 Donors (By Total Paid)
$topDonorsQuery = "
    SELECT name, total_pledged, total_paid 
    FROM donors 
    ORDER BY total_paid DESC 
    LIMIT 10
";
$topDonorsResult = $db->query($topDonorsQuery);
$topDonors = [];
while ($row = $topDonorsResult->fetch_assoc()) {
    $topDonors[] = [
        'name' => $row['name'],
        'pledged' => (float)$row['total_pledged'],
        'paid' => (float)$row['total_paid']
    ];
}
$response['top_donors'] = $topDonors;

// 6. Recent Transactions (Last 10)
// Union of direct payments and pledge payments
$recentQuery = "
    SELECT * FROM (
        SELECT 'Direct' as type, donor_name, amount, method, status, received_at as date 
        FROM payments 
        WHERE status = 'approved'
        UNION ALL
        SELECT 
            'Pledge' as type, 
            COALESCE(d.name, 'Unknown') as donor_name, 
            pp.amount, 
            CASE 
                WHEN pp.payment_method = 'bank_transfer' THEN 'bank'
                WHEN pp.payment_method = 'card' THEN 'card'
                WHEN pp.payment_method = 'cash' THEN 'cash'
                ELSE 'other'
            END as method, 
            pp.status, 
            pp.created_at as date
        FROM pledge_payments pp
        LEFT JOIN donors d ON pp.donor_id = d.id
        WHERE pp.status = 'confirmed'
    ) as combined
    ORDER BY date DESC
    LIMIT 10
";
$recentResult = $db->query($recentQuery);
$recentTransactions = [];
while ($row = $recentResult->fetch_assoc()) {
    $methodName = $row['method'];
    // Normalize display names
    if ($methodName === 'bank') $methodName = 'Bank Transfer';
    elseif ($methodName === 'card') $methodName = 'Card';
    elseif ($methodName === 'cash') $methodName = 'Cash';
    else $methodName = 'Other';
    
    $recentTransactions[] = [
        'type' => $row['type'],
        'donor' => $row['donor_name'] ?: 'Anonymous',
        'amount' => (float)$row['amount'],
        'method' => $methodName,
        'date' => date('d M, H:i', strtotime($row['date'])),
        'status' => ucfirst($row['status'])
    ];
}
$response['recent_transactions'] = $recentTransactions;

// 7. Payment Plan Status
$planStatusQuery = "SELECT status, COUNT(*) as count FROM donor_payment_plans GROUP BY status";
$planStatusResult = $db->query($planStatusQuery);
$planStatusData = [];
while ($row = $planStatusResult->fetch_assoc()) {
    $planStatusData[] = [
        'status' => ucfirst($row['status']),
        'count' => (int)$row['count']
    ];
}
    $response['plan_status'] = $planStatusData;

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);
    error_log('Financial Dashboard API Error: ' . $e->getMessage());
}

