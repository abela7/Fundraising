<?php
// admin/reports/registrar-dashboard.php
// Comprehensive Real-Time Report Dashboard for Registrars
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
$current_user = current_user();
if (!in_array($current_user['role'] ?? '', ['admin', 'registrar'])) {
    header('Location: ' . url_for('index.php'));
    exit;
}

$page_title = 'Live Reports Dashboard';
$db = db();

// ============================================================================
// FETCH ALL DATA
// ============================================================================

// 1. Overview Statistics
$stats = [];

// Total Pledges & Amount
$result = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM pledges WHERE status = 'approved'");
$pledgeData = $result->fetch_assoc();
$stats['total_pledges'] = $pledgeData['count'];
$stats['total_pledged'] = $pledgeData['total'];

// Total Collected (confirmed payments)
$result = $db->query("SELECT COALESCE(SUM(amount), 0) as total FROM pledge_payments WHERE status = 'confirmed'");
$stats['total_collected'] = $result->fetch_assoc()['total'];

// Collection Rate
$stats['collection_rate'] = $stats['total_pledged'] > 0 
    ? round(($stats['total_collected'] / $stats['total_pledged']) * 100, 1) 
    : 0;

// Pending Payments
$result = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM pledge_payments WHERE status = 'pending'");
$pendingData = $result->fetch_assoc();
$stats['pending_count'] = $pendingData['count'];
$stats['pending_amount'] = $pendingData['total'];

// Active Donors (with pledges)
$result = $db->query("SELECT COUNT(DISTINCT donor_id) as count FROM pledges WHERE status = 'approved'");
$stats['active_donors'] = $result->fetch_assoc()['count'];

// Active Payment Plans
$result = $db->query("SELECT COUNT(*) as count FROM donor_payment_plans WHERE status = 'active'");
$stats['active_plans'] = $result->fetch_assoc()['count'];

// Today's Stats
$result = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM pledge_payments WHERE status = 'confirmed' AND DATE(approved_at) = CURDATE()");
$todayData = $result->fetch_assoc();
$stats['today_payments'] = $todayData['count'];
$stats['today_amount'] = $todayData['total'];

// This Week Stats
$result = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM pledge_payments WHERE status = 'confirmed' AND approved_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$weekData = $result->fetch_assoc();
$stats['week_payments'] = $weekData['count'];
$stats['week_amount'] = $weekData['total'];

// This Month Stats
$result = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM pledge_payments WHERE status = 'confirmed' AND MONTH(approved_at) = MONTH(CURDATE()) AND YEAR(approved_at) = YEAR(CURDATE())");
$monthData = $result->fetch_assoc();
$stats['month_payments'] = $monthData['count'];
$stats['month_amount'] = $monthData['total'];

// 2. Payment Trends (Last 30 Days)
$paymentTrends = [];
$result = $db->query("
    SELECT DATE(approved_at) as date, COUNT(*) as count, SUM(amount) as total 
    FROM pledge_payments 
    WHERE status = 'confirmed' AND approved_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(approved_at) 
    ORDER BY date ASC
");
while ($row = $result->fetch_assoc()) {
    $paymentTrends[] = $row;
}

// 3. Payment Methods Distribution
$paymentMethods = [];
$result = $db->query("
    SELECT payment_method, COUNT(*) as count, SUM(amount) as total 
    FROM pledge_payments 
    WHERE status = 'confirmed'
    GROUP BY payment_method
");
while ($row = $result->fetch_assoc()) {
    $paymentMethods[] = $row;
}

// 4. Pledge Status Breakdown
$pledgeStatus = [];
$result = $db->query("
    SELECT 
        CASE 
            WHEN d.balance <= 0 THEN 'Fully Paid'
            WHEN d.total_paid > 0 THEN 'Partially Paid'
            ELSE 'No Payment'
        END as status,
        COUNT(*) as count
    FROM donors d
    WHERE d.total_pledged > 0
    GROUP BY status
");
while ($row = $result->fetch_assoc()) {
    $pledgeStatus[] = $row;
}

// 5. Top Donors (by amount paid)
$topDonors = [];
$result = $db->query("
    SELECT d.id, d.name, d.phone, d.total_pledged, d.total_paid, d.balance
    FROM donors d
    WHERE d.total_paid > 0
    ORDER BY d.total_paid DESC
    LIMIT 10
");
while ($row = $result->fetch_assoc()) {
    $topDonors[] = $row;
}

// 6. Recent Payments (Last 20)
$recentPayments = [];
$result = $db->query("
    SELECT pp.id, pp.amount, pp.payment_method, pp.status, pp.created_at, pp.approved_at,
           d.id as donor_id, d.name as donor_name, d.phone as donor_phone
    FROM pledge_payments pp
    LEFT JOIN donors d ON pp.donor_id = d.id
    ORDER BY pp.created_at DESC
    LIMIT 20
");
while ($row = $result->fetch_assoc()) {
    $recentPayments[] = $row;
}

// 7. Recent Pledges (Last 20)
$recentPledges = [];
$result = $db->query("
    SELECT p.id, p.amount, p.status, p.created_at,
           d.id as donor_id, d.name as donor_name, d.phone as donor_phone
    FROM pledges p
    LEFT JOIN donors d ON p.donor_id = d.id
    ORDER BY p.created_at DESC
    LIMIT 20
");
while ($row = $result->fetch_assoc()) {
    $recentPledges[] = $row;
}

// 8. Payment Plan Progress
$paymentPlans = [];
$result = $db->query("
    SELECT dpp.id, dpp.monthly_amount, dpp.payments_made, dpp.total_payments, dpp.status, dpp.next_payment_due,
           d.id as donor_id, d.name as donor_name
    FROM donor_payment_plans dpp
    LEFT JOIN donors d ON dpp.donor_id = d.id
    WHERE dpp.status = 'active'
    ORDER BY dpp.next_payment_due ASC
    LIMIT 15
");
while ($row = $result->fetch_assoc()) {
    $paymentPlans[] = $row;
}

// 9. Registrar Performance (who recorded most payments)
$registrarPerformance = [];
$result = $db->query("
    SELECT u.id, u.name, 
           COUNT(pp.id) as payments_recorded,
           COALESCE(SUM(pp.amount), 0) as total_amount
    FROM pledge_payments pp
    LEFT JOIN users u ON pp.processed_by_user_id = u.id
    WHERE pp.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY u.id, u.name
    ORDER BY payments_recorded DESC
    LIMIT 10
");
while ($row = $result->fetch_assoc()) {
    $registrarPerformance[] = $row;
}

// 10. Hourly Activity (Today)
$hourlyActivity = [];
$result = $db->query("
    SELECT HOUR(created_at) as hour, COUNT(*) as count
    FROM pledge_payments
    WHERE DATE(created_at) = CURDATE()
    GROUP BY HOUR(created_at)
    ORDER BY hour ASC
");
while ($row = $result->fetch_assoc()) {
    $hourlyActivity[$row['hour']] = $row['count'];
}

// 11. Weekly Comparison (This week vs Last week)
$weeklyComparison = [];
$result = $db->query("
    SELECT 
        DAYNAME(approved_at) as day_name,
        DAYOFWEEK(approved_at) as day_num,
        SUM(CASE WHEN YEARWEEK(approved_at, 1) = YEARWEEK(CURDATE(), 1) THEN amount ELSE 0 END) as this_week,
        SUM(CASE WHEN YEARWEEK(approved_at, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 7 DAY), 1) THEN amount ELSE 0 END) as last_week
    FROM pledge_payments
    WHERE status = 'confirmed' AND approved_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    GROUP BY DAYOFWEEK(approved_at), DAYNAME(approved_at)
    ORDER BY day_num ASC
");
while ($row = $result->fetch_assoc()) {
    $weeklyComparison[] = $row;
}

// Get last update timestamps for real-time tracking
$result = $db->query("SELECT MAX(created_at) as last_payment FROM pledge_payments");
$lastPaymentTime = $result->fetch_assoc()['last_payment'] ?? '';

$result = $db->query("SELECT MAX(created_at) as last_pledge FROM pledges");
$lastPledgeTime = $result->fetch_assoc()['last_pledge'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-success: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --gradient-warning: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-info: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-dark: linear-gradient(135deg, #232526 0%, #414345 100%);
        }
        
        /* Page Header */
        .report-header {
            background: var(--gradient-dark);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            color: white;
            position: relative;
            overflow: hidden;
        }
        .report-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            pointer-events: none;
        }
        .report-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .live-dot {
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            animation: pulse-live 2s infinite;
        }
        @keyframes pulse-live {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
        }
        .last-updated {
            font-size: 0.75rem;
            opacity: 0.7;
            margin-top: 0.5rem;
        }
        
        /* Stat Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        @media (min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        @media (min-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(8, 1fr);
            }
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .stat-card.highlight {
            background: var(--gradient-primary);
            color: white;
        }
        .stat-card.success {
            background: var(--gradient-success);
            color: white;
        }
        .stat-card.warning {
            background: var(--gradient-warning);
            color: white;
        }
        .stat-card.info {
            background: var(--gradient-info);
            color: white;
        }
        .stat-card .stat-icon {
            font-size: 1.5rem;
            opacity: 0.2;
            position: absolute;
            right: 0.75rem;
            top: 0.75rem;
        }
        .stat-card .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .stat-card .stat-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.8;
            margin-top: 0.25rem;
        }
        .stat-card .stat-change {
            font-size: 0.7rem;
            margin-top: 0.375rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .stat-card .stat-change.up { color: #10b981; }
        .stat-card .stat-change.down { color: #ef4444; }
        .stat-card.highlight .stat-change,
        .stat-card.success .stat-change,
        .stat-card.warning .stat-change,
        .stat-card.info .stat-change {
            color: rgba(255,255,255,0.9);
        }
        
        /* Chart Cards */
        .chart-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            margin-bottom: 1rem;
            overflow: hidden;
        }
        .chart-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chart-header h3 {
            font-size: 0.95rem;
            font-weight: 600;
            margin: 0;
            color: #1e293b;
        }
        .chart-header .badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }
        .chart-body {
            padding: 1rem;
        }
        .chart-container {
            position: relative;
            height: 250px;
        }
        .chart-container.tall {
            height: 300px;
        }
        
        /* Activity Feed */
        .activity-feed {
            max-height: 400px;
            overflow-y: auto;
        }
        .activity-item {
            display: flex;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.2s;
        }
        .activity-item:hover {
            background: #f8fafc;
            margin: 0 -1rem;
            padding-left: 1rem;
            padding-right: 1rem;
        }
        .activity-item:last-child {
            border-bottom: none;
        }
        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 0.875rem;
        }
        .activity-icon.payment {
            background: #dcfce7;
            color: #16a34a;
        }
        .activity-icon.pledge {
            background: #dbeafe;
            color: #2563eb;
        }
        .activity-icon.pending {
            background: #fef3c7;
            color: #d97706;
        }
        .activity-icon.rejected {
            background: #fee2e2;
            color: #dc2626;
        }
        .activity-content {
            flex: 1;
            min-width: 0;
        }
        .activity-title {
            font-size: 0.875rem;
            font-weight: 500;
            color: #1e293b;
            margin-bottom: 0.125rem;
        }
        .activity-title a {
            color: inherit;
            text-decoration: none;
        }
        .activity-title a:hover {
            color: #6366f1;
            text-decoration: underline;
        }
        .activity-meta {
            font-size: 0.75rem;
            color: #64748b;
        }
        .activity-amount {
            font-weight: 600;
            color: #10b981;
            font-size: 0.875rem;
            text-align: right;
        }
        
        /* Leaderboard */
        .leaderboard-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .leaderboard-item:last-child {
            border-bottom: none;
        }
        .leaderboard-rank {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .leaderboard-rank.gold {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
        }
        .leaderboard-rank.silver {
            background: linear-gradient(135deg, #9ca3af 0%, #6b7280 100%);
            color: white;
        }
        .leaderboard-rank.bronze {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            color: white;
        }
        .leaderboard-rank.default {
            background: #f1f5f9;
            color: #64748b;
        }
        .leaderboard-info {
            flex: 1;
            min-width: 0;
        }
        .leaderboard-name {
            font-size: 0.875rem;
            font-weight: 500;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .leaderboard-name a {
            color: inherit;
            text-decoration: none;
        }
        .leaderboard-name a:hover {
            color: #6366f1;
        }
        .leaderboard-detail {
            font-size: 0.7rem;
            color: #64748b;
        }
        .leaderboard-value {
            font-weight: 600;
            color: #10b981;
            font-size: 0.875rem;
        }
        
        /* Progress Indicators */
        .progress-mini {
            height: 6px;
            border-radius: 3px;
            background: #e2e8f0;
            overflow: hidden;
            margin-top: 0.375rem;
        }
        .progress-mini .bar {
            height: 100%;
            border-radius: 3px;
            transition: width 0.5s ease;
        }
        .progress-mini .bar.green { background: var(--gradient-success); }
        .progress-mini .bar.blue { background: var(--gradient-info); }
        .progress-mini .bar.purple { background: var(--gradient-primary); }
        
        /* Payment Plan Cards */
        .plan-card {
            background: #f8fafc;
            border-radius: 10px;
            padding: 0.875rem;
            margin-bottom: 0.625rem;
            border-left: 3px solid;
            transition: all 0.2s;
        }
        .plan-card:hover {
            background: #f1f5f9;
        }
        .plan-card.on-track { border-left-color: #10b981; }
        .plan-card.due-soon { border-left-color: #f59e0b; }
        .plan-card.overdue { border-left-color: #ef4444; }
        .plan-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }
        .plan-donor {
            font-weight: 600;
            font-size: 0.875rem;
            color: #1e293b;
        }
        .plan-donor a {
            color: inherit;
            text-decoration: none;
        }
        .plan-donor a:hover {
            color: #6366f1;
        }
        .plan-status {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-weight: 600;
        }
        .plan-status.on-track { background: #dcfce7; color: #16a34a; }
        .plan-status.due-soon { background: #fef3c7; color: #d97706; }
        .plan-status.overdue { background: #fee2e2; color: #dc2626; }
        .plan-details {
            font-size: 0.75rem;
            color: #64748b;
        }
        
        /* Refresh Animation */
        .refreshing {
            animation: refresh-spin 1s linear infinite;
        }
        @keyframes refresh-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* New Data Highlight */
        .new-data {
            animation: highlight-new 2s ease-out;
        }
        @keyframes highlight-new {
            0% { background: rgba(99, 102, 241, 0.2); }
            100% { background: transparent; }
        }
        
        /* Mobile Responsive */
        @media (max-width: 767px) {
            .report-header {
                padding: 1rem;
            }
            .report-header h1 {
                font-size: 1.25rem;
                flex-wrap: wrap;
            }
            .stat-card .stat-value {
                font-size: 1.25rem;
            }
            .chart-container {
                height: 200px;
            }
        }
        
        /* Section Title */
        .section-title {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .section-title i {
            color: #6366f1;
        }
        
        /* Quick Stats Row */
        .quick-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
        }
        .quick-stat {
            flex-shrink: 0;
            background: white;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .quick-stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }
        .quick-stat-icon.today { background: #dbeafe; color: #2563eb; }
        .quick-stat-icon.week { background: #dcfce7; color: #16a34a; }
        .quick-stat-icon.month { background: #fae8ff; color: #c026d3; }
        .quick-stat-value {
            font-size: 1.125rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
        }
        .quick-stat-label {
            font-size: 0.7rem;
            color: #64748b;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid p-2 p-md-4">
                
                <!-- Report Header -->
                <div class="report-header">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <h1>
                                <i class="fas fa-chart-line"></i>
                                Live Reports Dashboard
                                <span class="live-indicator">
                                    <span class="live-dot"></span>
                                    LIVE
                                </span>
                            </h1>
                            <div class="last-updated">
                                <i class="fas fa-sync-alt me-1" id="refreshIcon"></i>
                                Last updated: <span id="lastUpdate"><?php echo date('H:i:s'); ?></span>
                                <span class="ms-2">• Auto-refresh: <span id="countdown">30</span>s</span>
                            </div>
                        </div>
                        <button class="btn btn-light btn-sm" onclick="refreshData()">
                            <i class="fas fa-sync-alt me-1"></i>Refresh Now
                        </button>
                    </div>
                </div>
                
                <!-- Quick Time-Based Stats -->
                <div class="quick-stats">
                    <div class="quick-stat">
                        <div class="quick-stat-icon today">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <div>
                            <div class="quick-stat-value">£<?php echo number_format($stats['today_amount'], 0); ?></div>
                            <div class="quick-stat-label">Today (<?php echo $stats['today_payments']; ?> payments)</div>
                        </div>
                    </div>
                    <div class="quick-stat">
                        <div class="quick-stat-icon week">
                            <i class="fas fa-calendar-week"></i>
                        </div>
                        <div>
                            <div class="quick-stat-value">£<?php echo number_format($stats['week_amount'], 0); ?></div>
                            <div class="quick-stat-label">This Week (<?php echo $stats['week_payments']; ?> payments)</div>
                        </div>
                    </div>
                    <div class="quick-stat">
                        <div class="quick-stat-icon month">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div>
                            <div class="quick-stat-value">£<?php echo number_format($stats['month_amount'], 0); ?></div>
                            <div class="quick-stat-label">This Month (<?php echo $stats['month_payments']; ?> payments)</div>
                        </div>
                    </div>
                </div>
                
                <!-- Main Stats Grid -->
                <div class="stats-grid" id="statsGrid">
                    <div class="stat-card highlight">
                        <i class="fas fa-hand-holding-heart stat-icon"></i>
                        <div class="stat-value" id="statPledged">£<?php echo number_format($stats['total_pledged'], 0); ?></div>
                        <div class="stat-label">Total Pledged</div>
                    </div>
                    <div class="stat-card success">
                        <i class="fas fa-coins stat-icon"></i>
                        <div class="stat-value" id="statCollected">£<?php echo number_format($stats['total_collected'], 0); ?></div>
                        <div class="stat-label">Collected</div>
                    </div>
                    <div class="stat-card info">
                        <i class="fas fa-percentage stat-icon"></i>
                        <div class="stat-value" id="statRate"><?php echo $stats['collection_rate']; ?>%</div>
                        <div class="stat-label">Collection Rate</div>
                    </div>
                    <div class="stat-card warning">
                        <i class="fas fa-hourglass-half stat-icon"></i>
                        <div class="stat-value" id="statPending"><?php echo $stats['pending_count']; ?></div>
                        <div class="stat-label">Pending Review</div>
                        <div class="stat-change">£<?php echo number_format($stats['pending_amount'], 0); ?> awaiting</div>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-users stat-icon"></i>
                        <div class="stat-value" id="statDonors"><?php echo number_format($stats['active_donors']); ?></div>
                        <div class="stat-label">Active Donors</div>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-file-invoice-dollar stat-icon"></i>
                        <div class="stat-value" id="statPledges"><?php echo number_format($stats['total_pledges']); ?></div>
                        <div class="stat-label">Total Pledges</div>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-calendar-check stat-icon"></i>
                        <div class="stat-value" id="statPlans"><?php echo number_format($stats['active_plans']); ?></div>
                        <div class="stat-label">Active Plans</div>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-chart-pie stat-icon"></i>
                        <div class="stat-value" id="statBalance">£<?php echo number_format($stats['total_pledged'] - $stats['total_collected'], 0); ?></div>
                        <div class="stat-label">Outstanding</div>
                    </div>
                </div>
                
                <!-- Charts Row 1 -->
                <div class="row g-3 mb-3">
                    <!-- Payment Trends -->
                    <div class="col-12 col-lg-8">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3><i class="fas fa-chart-area text-primary me-2"></i>Payment Trends (30 Days)</h3>
                                <span class="badge bg-primary"><?php echo count($paymentTrends); ?> days with payments</span>
                            </div>
                            <div class="chart-body">
                                <div class="chart-container tall">
                                    <canvas id="trendsChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Methods -->
                    <div class="col-12 col-lg-4">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3><i class="fas fa-credit-card text-info me-2"></i>Payment Methods</h3>
                            </div>
                            <div class="chart-body">
                                <div class="chart-container">
                                    <canvas id="methodsChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Charts Row 2 -->
                <div class="row g-3 mb-3">
                    <!-- Weekly Comparison -->
                    <div class="col-12 col-md-6">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3><i class="fas fa-balance-scale text-warning me-2"></i>This Week vs Last Week</h3>
                            </div>
                            <div class="chart-body">
                                <div class="chart-container">
                                    <canvas id="weeklyChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pledge Status -->
                    <div class="col-12 col-md-6">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3><i class="fas fa-tasks text-success me-2"></i>Donor Payment Status</h3>
                            </div>
                            <div class="chart-body">
                                <div class="chart-container">
                                    <canvas id="statusChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Activity & Leaderboards Row -->
                <div class="row g-3 mb-3">
                    <!-- Recent Payments -->
                    <div class="col-12 col-lg-4">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3><i class="fas fa-receipt text-success me-2"></i>Recent Payments</h3>
                                <a href="../donations/review-pledge-payments.php" class="btn btn-sm btn-outline-primary">View All</a>
                            </div>
                            <div class="chart-body p-0">
                                <div class="activity-feed" id="recentPayments">
                                    <?php foreach ($recentPayments as $payment): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon <?php 
                                                echo $payment['status'] === 'confirmed' ? 'payment' : 
                                                     ($payment['status'] === 'pending' ? 'pending' : 'rejected'); 
                                            ?>">
                                                <i class="fas fa-<?php 
                                                    echo $payment['status'] === 'confirmed' ? 'check' : 
                                                         ($payment['status'] === 'pending' ? 'clock' : 'times'); 
                                                ?>"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-title">
                                                    <a href="../donor-management/view-donor.php?id=<?php echo $payment['donor_id']; ?>">
                                                        <?php echo htmlspecialchars($payment['donor_name'] ?? 'Unknown'); ?>
                                                    </a>
                                                </div>
                                                <div class="activity-meta">
                                                    <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?> • 
                                                    <?php echo date('d M, H:i', strtotime($payment['created_at'])); ?>
                                                </div>
                                            </div>
                                            <div class="activity-amount">
                                                £<?php echo number_format($payment['amount'], 0); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Top Donors -->
                    <div class="col-12 col-lg-4">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3><i class="fas fa-trophy text-warning me-2"></i>Top Donors</h3>
                                <span class="badge bg-warning text-dark">By Amount Paid</span>
                            </div>
                            <div class="chart-body p-0 px-3">
                                <div class="activity-feed">
                                    <?php foreach ($topDonors as $index => $donor): ?>
                                        <div class="leaderboard-item">
                                            <div class="leaderboard-rank <?php 
                                                echo $index === 0 ? 'gold' : ($index === 1 ? 'silver' : ($index === 2 ? 'bronze' : 'default')); 
                                            ?>">
                                                <?php echo $index + 1; ?>
                                            </div>
                                            <div class="leaderboard-info">
                                                <div class="leaderboard-name">
                                                    <a href="../donor-management/view-donor.php?id=<?php echo $donor['id']; ?>">
                                                        <?php echo htmlspecialchars($donor['name']); ?>
                                                    </a>
                                                </div>
                                                <div class="leaderboard-detail">
                                                    Pledged: £<?php echo number_format($donor['total_pledged'], 0); ?>
                                                </div>
                                                <div class="progress-mini">
                                                    <div class="bar green" style="width: <?php echo min(100, ($donor['total_paid'] / max(1, $donor['total_pledged'])) * 100); ?>%"></div>
                                                </div>
                                            </div>
                                            <div class="leaderboard-value">
                                                £<?php echo number_format($donor['total_paid'], 0); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Pledges -->
                    <div class="col-12 col-lg-4">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3><i class="fas fa-hand-holding-usd text-primary me-2"></i>Recent Pledges</h3>
                                <a href="../pledges/" class="btn btn-sm btn-outline-primary">View All</a>
                            </div>
                            <div class="chart-body p-0">
                                <div class="activity-feed" id="recentPledges">
                                    <?php foreach ($recentPledges as $pledge): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon pledge">
                                                <i class="fas fa-hand-holding-heart"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-title">
                                                    <a href="../donor-management/view-donor.php?id=<?php echo $pledge['donor_id']; ?>">
                                                        <?php echo htmlspecialchars($pledge['donor_name'] ?? 'Unknown'); ?>
                                                    </a>
                                                </div>
                                                <div class="activity-meta">
                                                    <?php echo date('d M Y, H:i', strtotime($pledge['created_at'])); ?>
                                                    <?php if ($pledge['status'] === 'pending'): ?>
                                                        <span class="badge bg-warning text-dark ms-1">Pending</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="activity-amount">
                                                £<?php echo number_format($pledge['amount'], 0); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Payment Plans & Performance Row -->
                <div class="row g-3">
                    <!-- Active Payment Plans -->
                    <div class="col-12 col-lg-6">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3><i class="fas fa-calendar-check text-info me-2"></i>Active Payment Plans</h3>
                                <span class="badge bg-info"><?php echo count($paymentPlans); ?> plans</span>
                            </div>
                            <div class="chart-body" style="max-height: 400px; overflow-y: auto;">
                                <?php foreach ($paymentPlans as $plan): 
                                    $progress = ($plan['payments_made'] / max(1, $plan['total_payments'])) * 100;
                                    $dueDate = $plan['next_payment_due'] ? strtotime($plan['next_payment_due']) : null;
                                    $today = strtotime('today');
                                    $status = 'on-track';
                                    if ($dueDate) {
                                        if ($dueDate < $today) {
                                            $status = 'overdue';
                                        } elseif ($dueDate <= strtotime('+7 days')) {
                                            $status = 'due-soon';
                                        }
                                    }
                                ?>
                                    <div class="plan-card <?php echo $status; ?>">
                                        <div class="plan-header">
                                            <div class="plan-donor">
                                                <a href="../donor-management/view-donor.php?id=<?php echo $plan['donor_id']; ?>">
                                                    <?php echo htmlspecialchars($plan['donor_name'] ?? 'Unknown'); ?>
                                                </a>
                                            </div>
                                            <span class="plan-status <?php echo $status; ?>">
                                                <?php 
                                                    echo $status === 'overdue' ? 'Overdue' : 
                                                         ($status === 'due-soon' ? 'Due Soon' : 'On Track');
                                                ?>
                                            </span>
                                        </div>
                                        <div class="plan-details">
                                            <span>£<?php echo number_format($plan['monthly_amount'], 0); ?>/month</span>
                                            <span class="mx-2">•</span>
                                            <span><?php echo $plan['payments_made']; ?>/<?php echo $plan['total_payments']; ?> payments</span>
                                            <?php if ($plan['next_payment_due']): ?>
                                                <span class="mx-2">•</span>
                                                <span>Due: <?php echo date('d M', strtotime($plan['next_payment_due'])); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="progress-mini">
                                            <div class="bar <?php echo $status === 'overdue' ? 'purple' : 'blue'; ?>" 
                                                 style="width: <?php echo $progress; ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($paymentPlans)): ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="fas fa-calendar-times fa-2x mb-2 opacity-50"></i>
                                        <p class="mb-0">No active payment plans</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Team Performance -->
                    <div class="col-12 col-lg-6">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3><i class="fas fa-users-cog text-purple me-2"></i>Team Performance (30 Days)</h3>
                            </div>
                            <div class="chart-body">
                                <div class="chart-container" style="height: 200px;">
                                    <canvas id="performanceChart"></canvas>
                                </div>
                                <div class="mt-3" style="max-height: 180px; overflow-y: auto;">
                                    <?php foreach ($registrarPerformance as $index => $reg): ?>
                                        <div class="leaderboard-item">
                                            <div class="leaderboard-rank <?php 
                                                echo $index === 0 ? 'gold' : ($index === 1 ? 'silver' : ($index === 2 ? 'bronze' : 'default')); 
                                            ?>">
                                                <?php echo $index + 1; ?>
                                            </div>
                                            <div class="leaderboard-info">
                                                <div class="leaderboard-name">
                                                    <?php echo htmlspecialchars($reg['name'] ?? 'Unknown'); ?>
                                                </div>
                                                <div class="leaderboard-detail">
                                                    <?php echo $reg['payments_recorded']; ?> payments recorded
                                                </div>
                                            </div>
                                            <div class="leaderboard-value">
                                                £<?php echo number_format($reg['total_amount'], 0); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
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
<script>
// Chart Data from PHP
const paymentTrends = <?php echo json_encode($paymentTrends); ?>;
const paymentMethods = <?php echo json_encode($paymentMethods); ?>;
const pledgeStatus = <?php echo json_encode($pledgeStatus); ?>;
const weeklyComparison = <?php echo json_encode($weeklyComparison); ?>;
const registrarPerformance = <?php echo json_encode($registrarPerformance); ?>;

// Track last update times for real-time detection
let lastPaymentTime = '<?php echo $lastPaymentTime; ?>';
let lastPledgeTime = '<?php echo $lastPledgeTime; ?>';

// Chart instances
let trendsChart, methodsChart, statusChart, weeklyChart, performanceChart;

// Initialize Charts
document.addEventListener('DOMContentLoaded', function() {
    initCharts();
    startAutoRefresh();
});

function initCharts() {
    // 1. Payment Trends (Line/Area Chart)
    const trendsCtx = document.getElementById('trendsChart').getContext('2d');
    const gradient = trendsCtx.createLinearGradient(0, 0, 0, 300);
    gradient.addColorStop(0, 'rgba(99, 102, 241, 0.3)');
    gradient.addColorStop(1, 'rgba(99, 102, 241, 0)');
    
    // Fill in missing dates
    const last30Days = [];
    const trendMap = {};
    paymentTrends.forEach(t => { trendMap[t.date] = t; });
    
    for (let i = 29; i >= 0; i--) {
        const date = new Date();
        date.setDate(date.getDate() - i);
        const dateStr = date.toISOString().split('T')[0];
        last30Days.push({
            date: dateStr,
            label: date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }),
            total: trendMap[dateStr]?.total || 0,
            count: trendMap[dateStr]?.count || 0
        });
    }
    
    trendsChart = new Chart(trendsCtx, {
        type: 'line',
        data: {
            labels: last30Days.map(d => d.label),
            datasets: [{
                label: 'Amount Collected',
                data: last30Days.map(d => parseFloat(d.total)),
                borderColor: '#6366f1',
                backgroundColor: gradient,
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointBackgroundColor: '#6366f1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `£${ctx.raw.toLocaleString()}`
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (v) => '£' + v.toLocaleString()
                    }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
    
    // 2. Payment Methods (Doughnut Chart)
    const methodColors = {
        'cash': '#10b981',
        'bank_transfer': '#6366f1',
        'card': '#f59e0b',
        'cheque': '#8b5cf6',
        'other': '#64748b'
    };
    
    methodsChart = new Chart(document.getElementById('methodsChart'), {
        type: 'doughnut',
        data: {
            labels: paymentMethods.map(m => m.payment_method.replace('_', ' ').toUpperCase()),
            datasets: [{
                data: paymentMethods.map(m => parseFloat(m.total)),
                backgroundColor: paymentMethods.map(m => methodColors[m.payment_method] || '#64748b'),
                borderWidth: 0,
                hoverOffset: 10
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        padding: 15,
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.label}: £${ctx.raw.toLocaleString()}`
                    }
                }
            }
        }
    });
    
    // 3. Pledge Status (Pie Chart)
    const statusColors = {
        'Fully Paid': '#10b981',
        'Partially Paid': '#f59e0b',
        'No Payment': '#ef4444'
    };
    
    statusChart = new Chart(document.getElementById('statusChart'), {
        type: 'pie',
        data: {
            labels: pledgeStatus.map(s => s.status),
            datasets: [{
                data: pledgeStatus.map(s => s.count),
                backgroundColor: pledgeStatus.map(s => statusColors[s.status] || '#64748b'),
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        padding: 15,
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.label}: ${ctx.raw} donors`
                    }
                }
            }
        }
    });
    
    // 4. Weekly Comparison (Bar Chart)
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const weeklyData = {};
    weeklyComparison.forEach(w => {
        weeklyData[w.day_num] = w;
    });
    
    weeklyChart = new Chart(document.getElementById('weeklyChart'), {
        type: 'bar',
        data: {
            labels: days,
            datasets: [
                {
                    label: 'This Week',
                    data: days.map((_, i) => parseFloat(weeklyData[i + 1]?.this_week || 0)),
                    backgroundColor: '#6366f1',
                    borderRadius: 6
                },
                {
                    label: 'Last Week',
                    data: days.map((_, i) => parseFloat(weeklyData[i + 1]?.last_week || 0)),
                    backgroundColor: '#cbd5e1',
                    borderRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    align: 'end',
                    labels: {
                        boxWidth: 12,
                        padding: 15,
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: £${ctx.raw.toLocaleString()}`
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (v) => '£' + v.toLocaleString()
                    }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
    
    // 5. Team Performance (Horizontal Bar)
    performanceChart = new Chart(document.getElementById('performanceChart'), {
        type: 'bar',
        data: {
            labels: registrarPerformance.slice(0, 5).map(r => r.name || 'Unknown'),
            datasets: [{
                label: 'Amount Recorded',
                data: registrarPerformance.slice(0, 5).map(r => parseFloat(r.total_amount)),
                backgroundColor: ['#fbbf24', '#9ca3af', '#d97706', '#6366f1', '#10b981'],
                borderRadius: 6
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `£${ctx.raw.toLocaleString()}`
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        callback: (v) => '£' + v.toLocaleString()
                    }
                },
                y: {
                    grid: { display: false }
                }
            }
        }
    });
}

// Auto-refresh functionality
let countdown = 30;
let refreshInterval;

function startAutoRefresh() {
    refreshInterval = setInterval(() => {
        countdown--;
        document.getElementById('countdown').textContent = countdown;
        
        if (countdown <= 0) {
            refreshData();
            countdown = 30;
        }
    }, 1000);
}

function refreshData() {
    const icon = document.getElementById('refreshIcon');
    icon.classList.add('refreshing');
    
    fetch('registrar-dashboard-data.php')
        .then(r => r.json())
        .then(data => {
            // Update stats
            if (data.stats) {
                updateStats(data.stats);
            }
            
            // Check for new data and highlight
            if (data.lastPaymentTime && data.lastPaymentTime !== lastPaymentTime) {
                lastPaymentTime = data.lastPaymentTime;
                showNotification('New payment received!');
            }
            
            if (data.lastPledgeTime && data.lastPledgeTime !== lastPledgeTime) {
                lastPledgeTime = data.lastPledgeTime;
                showNotification('New pledge recorded!');
            }
            
            // Update recent activity lists
            if (data.recentPayments) {
                updateRecentPayments(data.recentPayments);
            }
            
            if (data.recentPledges) {
                updateRecentPledges(data.recentPledges);
            }
            
            // Update timestamp
            document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString();
            
            icon.classList.remove('refreshing');
            countdown = 30;
        })
        .catch(err => {
            console.error('Refresh failed:', err);
            icon.classList.remove('refreshing');
        });
}

function updateStats(stats) {
    const updates = {
        'statPledged': `£${parseInt(stats.total_pledged).toLocaleString()}`,
        'statCollected': `£${parseInt(stats.total_collected).toLocaleString()}`,
        'statRate': `${stats.collection_rate}%`,
        'statPending': stats.pending_count,
        'statDonors': parseInt(stats.active_donors).toLocaleString(),
        'statPledges': parseInt(stats.total_pledges).toLocaleString(),
        'statPlans': parseInt(stats.active_plans).toLocaleString(),
        'statBalance': `£${parseInt(stats.total_pledged - stats.total_collected).toLocaleString()}`
    };
    
    for (const [id, value] of Object.entries(updates)) {
        const el = document.getElementById(id);
        if (el && el.textContent !== value) {
            el.textContent = value;
            el.closest('.stat-card').classList.add('new-data');
            setTimeout(() => el.closest('.stat-card').classList.remove('new-data'), 2000);
        }
    }
}

function updateRecentPayments(payments) {
    const container = document.getElementById('recentPayments');
    if (!container) return;
    
    let html = '';
    payments.forEach(p => {
        const statusClass = p.status === 'confirmed' ? 'payment' : (p.status === 'pending' ? 'pending' : 'rejected');
        const icon = p.status === 'confirmed' ? 'check' : (p.status === 'pending' ? 'clock' : 'times');
        
        html += `
            <div class="activity-item">
                <div class="activity-icon ${statusClass}">
                    <i class="fas fa-${icon}"></i>
                </div>
                <div class="activity-content">
                    <div class="activity-title">
                        <a href="../donor-management/view-donor.php?id=${p.donor_id}">${escapeHtml(p.donor_name || 'Unknown')}</a>
                    </div>
                    <div class="activity-meta">
                        ${(p.payment_method || '').replace('_', ' ')} • ${formatDate(p.created_at)}
                    </div>
                </div>
                <div class="activity-amount">£${parseInt(p.amount).toLocaleString()}</div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function updateRecentPledges(pledges) {
    const container = document.getElementById('recentPledges');
    if (!container) return;
    
    let html = '';
    pledges.forEach(p => {
        html += `
            <div class="activity-item">
                <div class="activity-icon pledge">
                    <i class="fas fa-hand-holding-heart"></i>
                </div>
                <div class="activity-content">
                    <div class="activity-title">
                        <a href="../donor-management/view-donor.php?id=${p.donor_id}">${escapeHtml(p.donor_name || 'Unknown')}</a>
                    </div>
                    <div class="activity-meta">
                        ${formatDate(p.created_at)}
                        ${p.status === 'pending' ? '<span class="badge bg-warning text-dark ms-1">Pending</span>' : ''}
                    </div>
                </div>
                <div class="activity-amount">£${parseInt(p.amount).toLocaleString()}</div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function showNotification(message) {
    // Create a toast notification
    const toast = document.createElement('div');
    toast.className = 'position-fixed bottom-0 end-0 p-3';
    toast.style.zIndex = '9999';
    toast.innerHTML = `
        <div class="toast show" role="alert">
            <div class="toast-header bg-success text-white">
                <i class="fas fa-bell me-2"></i>
                <strong class="me-auto">New Update</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body">${message}</div>
        </div>
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => toast.remove(), 5000);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) + 
           ', ' + date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}
</script>
</body>
</html>

