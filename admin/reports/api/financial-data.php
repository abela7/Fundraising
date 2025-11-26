<?php
/**
 * Financial Dashboard API Endpoint
 * 
 * Returns all financial data for charts and visualizations.
 * 100% database-driven, no hardcoded values.
 * 
 * @endpoint GET api/financial-data.php
 * @query    section (string) - Which data section to return
 * @query    from (string) - Start date Y-m-d
 * @query    to (string) - End date Y-m-d
 */

declare(strict_types=1);

require_once '../../../config/db.php';
require_once '../../../shared/auth.php';
require_once '../../../shared/FinancialCalculator.php';

// Require login
require_login();

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$db = db();

// Get parameters
$section = $_GET['section'] ?? 'all';
$fromDate = $_GET['from'] ?? null;
$toDate = $_GET['to'] ?? null;

// Build date filter SQL
$dateFilterPledges = '';
$dateFilterPayments = '';
$dateFilterPledgePayments = '';
$dateParams = [];

if ($fromDate && $toDate) {
    $fromDateTime = $fromDate . ' 00:00:00';
    $toDateTime = $toDate . ' 23:59:59';
    $dateFilterPledges = " AND created_at BETWEEN ? AND ?";
    $dateFilterPayments = " AND received_at BETWEEN ? AND ?";
    $dateFilterPledgePayments = " AND created_at BETWEEN ? AND ?";
}

// Check if pledge_payments table exists
$hasPledgePayments = false;
$check = $db->query("SHOW TABLES LIKE 'pledge_payments'");
$hasPledgePayments = ($check && $check->num_rows > 0);

$response = [];

try {
    // =====================
    // SECTION: Summary KPIs
    // =====================
    if ($section === 'all' || $section === 'summary') {
        $calculator = new FinancialCalculator();
        $totals = $calculator->getTotals($fromDate ? $fromDate . ' 00:00:00' : null, $toDate ? $toDate . ' 23:59:59' : null);
        
        // Get settings
        $settings = $db->query("SELECT target_amount, currency_code FROM settings WHERE id=1")->fetch_assoc() 
            ?: ['target_amount' => 0, 'currency_code' => 'GBP'];
        
        // Donor count
        $donorCount = $db->query("SELECT COUNT(*) AS c FROM donors WHERE total_pledged > 0 OR total_paid > 0")->fetch_assoc()['c'] ?? 0;
        
        // Active payment plans
        $activePlans = $db->query("SELECT COUNT(*) AS c FROM donor_payment_plans WHERE status = 'active'")->fetch_assoc()['c'] ?? 0;
        
        // Collection rate
        $collectionRate = $totals['grand_total'] > 0 
            ? round(($totals['total_paid'] / $totals['grand_total']) * 100, 1) 
            : 0;
        
        // Progress toward target
        $targetProgress = ($settings['target_amount'] > 0) 
            ? round(($totals['grand_total'] / (float)$settings['target_amount']) * 100, 1) 
            : 0;
        
        $response['summary'] = [
            'total_pledged' => $totals['total_pledges'],
            'total_paid' => $totals['total_paid'],
            'outstanding_balance' => $totals['outstanding_pledged'],
            'grand_total' => $totals['grand_total'],
            'target_amount' => (float)$settings['target_amount'],
            'currency' => $settings['currency_code'],
            'donor_count' => (int)$donorCount,
            'active_payment_plans' => (int)$activePlans,
            'collection_rate' => $collectionRate,
            'target_progress' => min($targetProgress, 100),
            'pledge_count' => $totals['pledge_count'],
            'payment_count' => $totals['total_payment_count']
        ];
    }
    
    // =====================
    // SECTION: Payment Methods Distribution
    // =====================
    if ($section === 'all' || $section === 'payment_methods') {
        $sql = "SELECT method, SUM(amount) AS total, COUNT(*) AS count 
                FROM payments 
                WHERE status = 'approved'" . ($fromDate && $toDate ? " AND received_at BETWEEN ? AND ?" : "") . "
                GROUP BY method 
                ORDER BY total DESC";
        
        if ($fromDate && $toDate) {
            $stmt = $db->prepare($sql);
            $stmt->bind_param('ss', $fromDate . ' 00:00:00', $toDate . ' 23:59:59');
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $db->query($sql);
        }
        
        $methods = [];
        while ($row = $result->fetch_assoc()) {
            $methods[] = [
                'method' => ucfirst($row['method']),
                'total' => (float)$row['total'],
                'count' => (int)$row['count']
            ];
        }
        
        // Add pledge payments if exists
        if ($hasPledgePayments) {
            $sql2 = "SELECT payment_method AS method, SUM(amount) AS total, COUNT(*) AS count 
                     FROM pledge_payments 
                     WHERE status = 'confirmed'" . ($fromDate && $toDate ? " AND created_at BETWEEN ? AND ?" : "") . "
                     GROUP BY payment_method";
            
            if ($fromDate && $toDate) {
                $stmt2 = $db->prepare($sql2);
                $stmt2->bind_param('ss', $fromDate . ' 00:00:00', $toDate . ' 23:59:59');
                $stmt2->execute();
                $result2 = $stmt2->get_result();
            } else {
                $result2 = $db->query($sql2);
            }
            
            while ($row = $result2->fetch_assoc()) {
                $methodName = ucfirst(str_replace('_', ' ', $row['method']));
                // Merge with existing
                $found = false;
                foreach ($methods as &$m) {
                    if (strtolower($m['method']) === strtolower($methodName) || 
                        $m['method'] === 'Bank transfer' && $methodName === 'Bank transfer') {
                        $m['total'] += (float)$row['total'];
                        $m['count'] += (int)$row['count'];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $methods[] = [
                        'method' => $methodName,
                        'total' => (float)$row['total'],
                        'count' => (int)$row['count']
                    ];
                }
            }
        }
        
        $response['payment_methods'] = $methods;
    }
    
    // =====================
    // SECTION: Package Distribution
    // =====================
    if ($section === 'all' || $section === 'packages') {
        $sql = "SELECT dp.label, dp.price, COUNT(p.id) AS count, SUM(p.amount) AS total
                FROM pledges p
                LEFT JOIN donation_packages dp ON dp.id = p.package_id
                WHERE p.status = 'approved'" . ($fromDate && $toDate ? " AND p.created_at BETWEEN ? AND ?" : "") . "
                GROUP BY dp.id, dp.label, dp.price
                ORDER BY total DESC";
        
        if ($fromDate && $toDate) {
            $stmt = $db->prepare($sql);
            $stmt->bind_param('ss', $fromDate . ' 00:00:00', $toDate . ' 23:59:59');
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $db->query($sql);
        }
        
        $packages = [];
        while ($row = $result->fetch_assoc()) {
            $packages[] = [
                'label' => $row['label'] ?: 'Custom',
                'price' => (float)($row['price'] ?? 0),
                'count' => (int)$row['count'],
                'total' => (float)$row['total']
            ];
        }
        
        $response['packages'] = $packages;
    }
    
    // =====================
    // SECTION: Pledge Status Distribution
    // =====================
    if ($section === 'all' || $section === 'pledge_status') {
        $sql = "SELECT status, COUNT(*) AS count, SUM(amount) AS total 
                FROM pledges" . ($fromDate && $toDate ? " WHERE created_at BETWEEN ? AND ?" : "") . "
                GROUP BY status 
                ORDER BY count DESC";
        
        if ($fromDate && $toDate) {
            $stmt = $db->prepare($sql);
            $stmt->bind_param('ss', $fromDate . ' 00:00:00', $toDate . ' 23:59:59');
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $db->query($sql);
        }
        
        $statuses = [];
        while ($row = $result->fetch_assoc()) {
            $statuses[] = [
                'status' => ucfirst($row['status']),
                'count' => (int)$row['count'],
                'total' => (float)$row['total']
            ];
        }
        
        $response['pledge_status'] = $statuses;
    }
    
    // =====================
    // SECTION: Donor Payment Status
    // =====================
    if ($section === 'all' || $section === 'donor_status') {
        $result = $db->query("
            SELECT payment_status, COUNT(*) AS count, SUM(total_pledged) AS pledged, SUM(total_paid) AS paid
            FROM donors 
            WHERE total_pledged > 0 OR total_paid > 0
            GROUP BY payment_status
            ORDER BY count DESC
        ");
        
        $donorStatuses = [];
        while ($row = $result->fetch_assoc()) {
            $donorStatuses[] = [
                'status' => ucfirst(str_replace('_', ' ', $row['payment_status'] ?: 'unknown')),
                'count' => (int)$row['count'],
                'pledged' => (float)$row['pledged'],
                'paid' => (float)$row['paid']
            ];
        }
        
        $response['donor_status'] = $donorStatuses;
    }
    
    // =====================
    // SECTION: Monthly Trends (Last 12 months)
    // =====================
    if ($section === 'all' || $section === 'monthly_trends') {
        // Pledges by month
        $pledgesTrend = [];
        $result = $db->query("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, 
                   SUM(amount) AS total, 
                   COUNT(*) AS count
            FROM pledges 
            WHERE status = 'approved' 
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ");
        while ($row = $result->fetch_assoc()) {
            $pledgesTrend[$row['month']] = [
                'pledges' => (float)$row['total'],
                'pledge_count' => (int)$row['count']
            ];
        }
        
        // Payments by month
        $result = $db->query("
            SELECT DATE_FORMAT(received_at, '%Y-%m') AS month, 
                   SUM(amount) AS total, 
                   COUNT(*) AS count
            FROM payments 
            WHERE status = 'approved' 
              AND received_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(received_at, '%Y-%m')
            ORDER BY month ASC
        ");
        while ($row = $result->fetch_assoc()) {
            if (!isset($pledgesTrend[$row['month']])) {
                $pledgesTrend[$row['month']] = ['pledges' => 0, 'pledge_count' => 0];
            }
            $pledgesTrend[$row['month']]['payments'] = (float)$row['total'];
            $pledgesTrend[$row['month']]['payment_count'] = (int)$row['count'];
        }
        
        // Pledge payments by month
        if ($hasPledgePayments) {
            $result = $db->query("
                SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, 
                       SUM(amount) AS total, 
                       COUNT(*) AS count
                FROM pledge_payments 
                WHERE status = 'confirmed' 
                  AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month ASC
            ");
            while ($row = $result->fetch_assoc()) {
                if (!isset($pledgesTrend[$row['month']])) {
                    $pledgesTrend[$row['month']] = ['pledges' => 0, 'pledge_count' => 0, 'payments' => 0, 'payment_count' => 0];
                }
                $pledgesTrend[$row['month']]['payments'] = ($pledgesTrend[$row['month']]['payments'] ?? 0) + (float)$row['total'];
                $pledgesTrend[$row['month']]['payment_count'] = ($pledgesTrend[$row['month']]['payment_count'] ?? 0) + (int)$row['count'];
            }
        }
        
        // Format for charts
        ksort($pledgesTrend);
        $monthlyData = [];
        foreach ($pledgesTrend as $month => $data) {
            $monthlyData[] = [
                'month' => $month,
                'month_label' => date('M Y', strtotime($month . '-01')),
                'pledges' => $data['pledges'] ?? 0,
                'payments' => $data['payments'] ?? 0,
                'pledge_count' => $data['pledge_count'] ?? 0,
                'payment_count' => $data['payment_count'] ?? 0
            ];
        }
        
        $response['monthly_trends'] = $monthlyData;
    }
    
    // =====================
    // SECTION: Daily Trends (Last 30 days)
    // =====================
    if ($section === 'all' || $section === 'daily_trends') {
        $dailyData = [];
        
        // Initialize last 30 days
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $dailyData[$date] = [
                'date' => $date,
                'date_label' => date('d M', strtotime($date)),
                'pledges' => 0,
                'payments' => 0
            ];
        }
        
        // Pledges
        $result = $db->query("
            SELECT DATE(created_at) AS day, SUM(amount) AS total
            FROM pledges 
            WHERE status = 'approved' 
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
        ");
        while ($row = $result->fetch_assoc()) {
            if (isset($dailyData[$row['day']])) {
                $dailyData[$row['day']]['pledges'] = (float)$row['total'];
            }
        }
        
        // Payments
        $result = $db->query("
            SELECT DATE(received_at) AS day, SUM(amount) AS total
            FROM payments 
            WHERE status = 'approved' 
              AND received_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(received_at)
        ");
        while ($row = $result->fetch_assoc()) {
            if (isset($dailyData[$row['day']])) {
                $dailyData[$row['day']]['payments'] = (float)$row['total'];
            }
        }
        
        // Pledge payments
        if ($hasPledgePayments) {
            $result = $db->query("
                SELECT DATE(created_at) AS day, SUM(amount) AS total
                FROM pledge_payments 
                WHERE status = 'confirmed' 
                  AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY DATE(created_at)
            ");
            while ($row = $result->fetch_assoc()) {
                if (isset($dailyData[$row['day']])) {
                    $dailyData[$row['day']]['payments'] += (float)$row['total'];
                }
            }
        }
        
        $response['daily_trends'] = array_values($dailyData);
    }
    
    // =====================
    // SECTION: Grid Allocation Status
    // =====================
    if ($section === 'all' || $section === 'grid_status') {
        $result = $db->query("
            SELECT status, COUNT(*) AS count, SUM(area_size) AS area, SUM(COALESCE(amount, 0)) AS amount
            FROM floor_grid_cells
            GROUP BY status
            ORDER BY count DESC
        ");
        
        $gridStatus = [];
        while ($row = $result->fetch_assoc()) {
            $gridStatus[] = [
                'status' => ucfirst($row['status']),
                'count' => (int)$row['count'],
                'area' => (float)$row['area'],
                'amount' => (float)$row['amount']
            ];
        }
        
        $response['grid_status'] = $gridStatus;
    }
    
    // =====================
    // SECTION: Top Donors
    // =====================
    if ($section === 'all' || $section === 'top_donors') {
        $result = $db->query("
            SELECT id, name, phone, total_pledged, total_paid, balance, payment_status
            FROM donors 
            WHERE total_pledged > 0
            ORDER BY total_pledged DESC
            LIMIT 10
        ");
        
        $topDonors = [];
        while ($row = $result->fetch_assoc()) {
            $topDonors[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'phone' => substr($row['phone'], 0, 4) . '****' . substr($row['phone'], -3),
                'total_pledged' => (float)$row['total_pledged'],
                'total_paid' => (float)$row['total_paid'],
                'balance' => (float)$row['balance'],
                'status' => ucfirst(str_replace('_', ' ', $row['payment_status']))
            ];
        }
        
        $response['top_donors'] = $topDonors;
    }
    
    // =====================
    // SECTION: Church Distribution
    // =====================
    if ($section === 'all' || $section === 'church_distribution') {
        $result = $db->query("
            SELECT c.name AS church_name, c.city,
                   COUNT(d.id) AS donor_count,
                   SUM(d.total_pledged) AS total_pledged,
                   SUM(d.total_paid) AS total_paid
            FROM churches c
            LEFT JOIN donors d ON d.church_id = c.id
            WHERE d.id IS NOT NULL
            GROUP BY c.id, c.name, c.city
            ORDER BY total_pledged DESC
            LIMIT 10
        ");
        
        $churches = [];
        while ($row = $result->fetch_assoc()) {
            $churches[] = [
                'name' => $row['church_name'],
                'city' => $row['city'],
                'donor_count' => (int)$row['donor_count'],
                'total_pledged' => (float)$row['total_pledged'],
                'total_paid' => (float)$row['total_paid']
            ];
        }
        
        $response['church_distribution'] = $churches;
    }
    
    // =====================
    // SECTION: Payment Plan Status
    // =====================
    if ($section === 'all' || $section === 'payment_plans') {
        $result = $db->query("
            SELECT status, COUNT(*) AS count, SUM(total_amount) AS total_amount, SUM(amount_paid) AS amount_paid
            FROM donor_payment_plans
            GROUP BY status
            ORDER BY count DESC
        ");
        
        $plans = [];
        while ($row = $result->fetch_assoc()) {
            $plans[] = [
                'status' => ucfirst($row['status']),
                'count' => (int)$row['count'],
                'total_amount' => (float)$row['total_amount'],
                'amount_paid' => (float)$row['amount_paid']
            ];
        }
        
        $response['payment_plans'] = $plans;
    }
    
    // =====================
    // SECTION: Recent Transactions
    // =====================
    if ($section === 'all' || $section === 'recent') {
        $transactions = [];
        
        // Recent pledges
        $result = $db->query("
            SELECT 'pledge' AS type, id, donor_name, amount, status, created_at AS date
            FROM pledges
            ORDER BY created_at DESC
            LIMIT 5
        ");
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
        
        // Recent payments
        $result = $db->query("
            SELECT 'payment' AS type, id, donor_name, amount, status, received_at AS date
            FROM payments
            ORDER BY received_at DESC
            LIMIT 5
        ");
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
        
        // Recent pledge payments
        if ($hasPledgePayments) {
            $result = $db->query("
                SELECT 'pledge_payment' AS type, pp.id, d.name AS donor_name, pp.amount, pp.status, pp.created_at AS date
                FROM pledge_payments pp
                LEFT JOIN donors d ON pp.donor_id = d.id
                ORDER BY pp.created_at DESC
                LIMIT 5
            ");
            while ($row = $result->fetch_assoc()) {
                $transactions[] = $row;
            }
        }
        
        // Sort by date desc and take top 10
        usort($transactions, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        $response['recent_transactions'] = array_slice($transactions, 0, 10);
    }
    
    // =====================
    // SECTION: Registrar Performance
    // =====================
    if ($section === 'all' || $section === 'registrar_performance') {
        $result = $db->query("
            SELECT u.id, u.name, u.role,
                   COUNT(p.id) AS pledge_count,
                   SUM(p.amount) AS pledge_total
            FROM users u
            LEFT JOIN pledges p ON p.created_by_user_id = u.id AND p.status = 'approved'
            WHERE u.role IN ('registrar', 'admin')
            GROUP BY u.id, u.name, u.role
            HAVING pledge_count > 0
            ORDER BY pledge_total DESC
            LIMIT 10
        ");
        
        $registrars = [];
        while ($row = $result->fetch_assoc()) {
            $registrars[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'role' => ucfirst($row['role']),
                'pledge_count' => (int)$row['pledge_count'],
                'pledge_total' => (float)$row['pledge_total']
            ];
        }
        
        $response['registrar_performance'] = $registrars;
    }
    
    // Success
    $response['success'] = true;
    $response['generated_at'] = date('Y-m-d H:i:s');
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch financial data',
        'message' => $e->getMessage()
    ]);
}

