<?php
// admin/reports/registrar-report.php
// Comprehensive Performance Report for Registrars
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
$current_user = current_user();
if (!in_array($current_user['role'] ?? '', ['admin', 'registrar'])) {
    header('Location: ' . url_for('index.php'));
    exit;
}

$page_title = 'Performance Report';
$db = db();

// ============================================
// FILTER PARAMETERS
// ============================================
$filter_period = $_GET['period'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';

// Build date filter
$date_filter = '';
$date_filter_pledges = '';
$date_filter_payments = '';
$date_filter_pledge_payments = '';

switch ($filter_period) {
    case 'today':
        $date_filter = "DATE(created_at) = CURDATE()";
        $date_filter_pledges = "AND DATE(p.created_at) = CURDATE()";
        $date_filter_payments = "AND DATE(py.created_at) = CURDATE()";
        $date_filter_pledge_payments = "AND DATE(pp.created_at) = CURDATE()";
        break;
    case 'week':
        $date_filter = "created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $date_filter_pledges = "AND p.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $date_filter_payments = "AND py.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $date_filter_pledge_payments = "AND pp.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        break;
    case 'month':
        $date_filter = "created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $date_filter_pledges = "AND p.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $date_filter_payments = "AND py.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $date_filter_pledge_payments = "AND pp.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        break;
    case 'year':
        $date_filter = "created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        $date_filter_pledges = "AND p.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        $date_filter_payments = "AND py.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        $date_filter_pledge_payments = "AND pp.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        break;
    default:
        $date_filter = '1=1';
        $date_filter_pledges = '';
        $date_filter_payments = '';
        $date_filter_pledge_payments = '';
}

// ============================================
// 1. DONOR STATISTICS
// ============================================
$donor_stats = $db->query("
    SELECT 
        COUNT(*) as total_donors,
        SUM(CASE WHEN donor_type = 'pledge' THEN 1 ELSE 0 END) as pledgers,
        SUM(CASE WHEN donor_type = 'immediate_payment' THEN 1 ELSE 0 END) as instant_payers,
        SUM(CASE WHEN payment_status = 'completed' THEN 1 ELSE 0 END) as fully_paid,
        SUM(CASE WHEN payment_status = 'paying' THEN 1 ELSE 0 END) as actively_paying,
        SUM(CASE WHEN payment_status = 'not_started' THEN 1 ELSE 0 END) as not_started,
        SUM(CASE WHEN payment_status = 'overdue' THEN 1 ELSE 0 END) as overdue,
        SUM(CASE WHEN payment_status = 'no_pledge' THEN 1 ELSE 0 END) as no_pledge,
        SUM(CASE WHEN has_active_plan = 1 THEN 1 ELSE 0 END) as with_payment_plans
    FROM donors
")->fetch_assoc();

// ============================================
// 2. PLEDGE STATISTICS (from pledges table)
// ============================================
$pledge_stats = $db->query("
    SELECT 
        COUNT(*) as total_entries,
        SUM(CASE WHEN type = 'pledge' THEN 1 ELSE 0 END) as pledge_count,
        SUM(CASE WHEN type = 'paid' THEN 1 ELSE 0 END) as instant_count,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN type = 'pledge' AND status = 'approved' THEN amount ELSE 0 END) as total_pledged,
        SUM(CASE WHEN type = 'paid' AND status = 'approved' THEN amount ELSE 0 END) as instant_amount
    FROM pledges p
    WHERE 1=1 $date_filter_pledges
")->fetch_assoc();

// ============================================
// 3. INSTANT PAYMENTS (from payments table)
// ============================================
$instant_payments = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'voided' THEN 1 ELSE 0 END) as voided,
        COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) as approved_amount,
        COALESCE(SUM(amount), 0) as total_amount
    FROM payments py
    WHERE 1=1 $date_filter_payments
")->fetch_assoc();

// ============================================
// 4. PLEDGE PAYMENTS (from pledge_payments table)
// ============================================
$pledge_payments = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN status = 'voided' THEN 1 ELSE 0 END) as voided,
        COALESCE(SUM(CASE WHEN status = 'confirmed' THEN amount ELSE 0 END), 0) as confirmed_amount,
        COALESCE(SUM(amount), 0) as total_amount
    FROM pledge_payments pp
    WHERE 1=1 $date_filter_pledge_payments
")->fetch_assoc();

// ============================================
// 5. COMBINED FINANCIAL SUMMARY
// ============================================
// Total pledged from donors table (most accurate)
$financial = $db->query("
    SELECT 
        COALESCE(SUM(total_pledged), 0) as total_pledged,
        COALESCE(SUM(total_paid), 0) as total_paid,
        COALESCE(SUM(balance), 0) as outstanding
    FROM donors
")->fetch_assoc();

// Collection rate
$collection_rate = $financial['total_pledged'] > 0 
    ? round(($financial['total_paid'] / $financial['total_pledged']) * 100, 1) 
    : 0;

// ============================================
// 6. PAYMENT METHODS BREAKDOWN
// ============================================
// From instant payments
$methods_instant = $db->query("
    SELECT 
        method,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as amount
    FROM payments
    WHERE status = 'approved'
    GROUP BY method
")->fetch_all(MYSQLI_ASSOC);

// From pledge payments
$methods_pledge = $db->query("
    SELECT 
        payment_method as method,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as amount
    FROM pledge_payments
    WHERE status = 'confirmed'
    GROUP BY payment_method
")->fetch_all(MYSQLI_ASSOC);

// Combine methods
$all_methods = [];
foreach ($methods_instant as $m) {
    $key = $m['method'];
    if (!isset($all_methods[$key])) {
        $all_methods[$key] = ['count' => 0, 'amount' => 0];
    }
    $all_methods[$key]['count'] += $m['count'];
    $all_methods[$key]['amount'] += $m['amount'];
}
foreach ($methods_pledge as $m) {
    $key = str_replace('bank_transfer', 'bank', $m['method']);
    if (!isset($all_methods[$key])) {
        $all_methods[$key] = ['count' => 0, 'amount' => 0];
    }
    $all_methods[$key]['count'] += $m['count'];
    $all_methods[$key]['amount'] += $m['amount'];
}

// ============================================
// 7. MONTHLY TRENDS (Last 6 months)
// ============================================
$monthly_trends = $db->query("
    SELECT 
        DATE_FORMAT(payment_date, '%Y-%m') as month,
        DATE_FORMAT(payment_date, '%b') as month_label,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as amount
    FROM (
        SELECT payment_date, amount FROM pledge_payments WHERE status = 'confirmed'
        UNION ALL
        SELECT received_at as payment_date, amount FROM payments WHERE status = 'approved'
    ) combined
    WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
    ORDER BY month ASC
")->fetch_all(MYSQLI_ASSOC);

// ============================================
// 8. RECENT ACTIVITY (Last 15 combined)
// ============================================
$recent_activity = $db->query("
    (SELECT 
        'pledge' as type,
        p.id,
        p.amount,
        p.type as pledge_type,
        p.status,
        p.created_at,
        d.id as donor_id,
        d.name as donor_name,
        d.phone as donor_phone,
        NULL as method
    FROM pledges p
    LEFT JOIN donors d ON p.donor_id = d.id
    ORDER BY p.created_at DESC
    LIMIT 5)
    
    UNION ALL
    
    (SELECT 
        'instant' as type,
        py.id,
        py.amount,
        NULL as pledge_type,
        py.status,
        py.created_at,
        d.id as donor_id,
        py.donor_name,
        py.donor_phone,
        py.method
    FROM payments py
    LEFT JOIN donors d ON py.donor_id = d.id
    ORDER BY py.created_at DESC
    LIMIT 5)
    
    UNION ALL
    
    (SELECT 
        'pledge_payment' as type,
        pp.id,
        pp.amount,
        NULL as pledge_type,
        pp.status,
        pp.created_at,
        d.id as donor_id,
        d.name as donor_name,
        d.phone as donor_phone,
        pp.payment_method as method
    FROM pledge_payments pp
    LEFT JOIN donors d ON pp.donor_id = d.id
    ORDER BY pp.created_at DESC
    LIMIT 5)
    
    ORDER BY created_at DESC
    LIMIT 15
")->fetch_all(MYSQLI_ASSOC);

// ============================================
// 9. TODAY'S STATS
// ============================================
$today = $db->query("
    SELECT
        (SELECT COUNT(*) FROM pledges WHERE DATE(created_at) = CURDATE()) as pledges_today,
        (SELECT COALESCE(SUM(amount), 0) FROM pledges WHERE DATE(created_at) = CURDATE() AND status = 'approved') as pledges_amount_today,
        (SELECT COUNT(*) FROM payments WHERE DATE(created_at) = CURDATE()) as instant_today,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(created_at) = CURDATE() AND status = 'approved') as instant_amount_today,
        (SELECT COUNT(*) FROM pledge_payments WHERE DATE(created_at) = CURDATE()) as pledge_payments_today,
        (SELECT COALESCE(SUM(amount), 0) FROM pledge_payments WHERE DATE(created_at) = CURDATE() AND status = 'confirmed') as pledge_payments_amount_today
")->fetch_assoc();

$today_total_transactions = $today['pledges_today'] + $today['instant_today'] + $today['pledge_payments_today'];
$today_total_amount = $today['pledges_amount_today'] + $today['instant_amount_today'] + $today['pledge_payments_amount_today'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* ===== PAGE CONTAINER ===== */
        .report-page {
            padding: 0.5rem;
            max-width: 100%;
        }
        
        /* ===== HEADER ===== */
        .report-header {
            background: linear-gradient(135deg, #0a6286 0%, #075985 100%);
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            color: white;
        }
        .report-header h1 {
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .live-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.65rem;
            background: rgba(255,255,255,0.2);
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            margin-left: auto;
        }
        .live-dot {
            width: 6px;
            height: 6px;
            background: #10b981;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }
        
        /* ===== FILTERS ===== */
        .filter-bar {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            overflow-x: auto;
            padding-bottom: 0.25rem;
            -webkit-overflow-scrolling: touch;
        }
        .filter-bar::-webkit-scrollbar { display: none; }
        .filter-btn {
            flex-shrink: 0;
            padding: 0.4rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 500;
            border: 1px solid #e2e8f0;
            background: white;
            border-radius: 20px;
            color: #64748b;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .filter-btn:hover { border-color: #0a6286; color: #0a6286; }
        .filter-btn.active {
            background: #0a6286;
            border-color: #0a6286;
            color: white;
        }
        
        /* ===== STAT CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 0.75rem;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            border-left: 3px solid;
        }
        .stat-card.primary { border-color: #0a6286; }
        .stat-card.success { border-color: #10b981; }
        .stat-card.warning { border-color: #f59e0b; }
        .stat-card.danger { border-color: #ef4444; }
        .stat-card.info { border-color: #3b82f6; }
        .stat-card.accent { border-color: #e2ca18; }
        
        .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.1;
        }
        .stat-label {
            font-size: 0.65rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-top: 0.15rem;
        }
        
        /* Large stat */
        .stat-card.large {
            grid-column: span 2;
            text-align: center;
            padding: 1rem;
        }
        .stat-card.large .stat-value { font-size: 1.5rem; }
        
        /* ===== SECTION CARDS ===== */
        .section-card {
            background: white;
            border-radius: 10px;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .section-title {
            font-size: 0.8rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }
        .section-title i { color: #0a6286; font-size: 0.85rem; }
        
        /* ===== PROGRESS BAR ===== */
        .progress-container {
            margin-bottom: 0.5rem;
        }
        .progress-bar-custom {
            height: 20px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 40px;
        }
        .progress-labels {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: #64748b;
            margin-top: 0.35rem;
        }
        .progress-labels strong { color: #1e293b; }
        
        /* ===== DONOR STATUS PILLS ===== */
        .status-pills {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.4rem;
        }
        .status-pill {
            background: #f8fafc;
            border-radius: 8px;
            padding: 0.5rem;
            text-align: center;
        }
        .status-pill .count {
            font-size: 1rem;
            font-weight: 700;
            line-height: 1;
        }
        .status-pill .label {
            font-size: 0.6rem;
            color: #64748b;
            text-transform: uppercase;
            margin-top: 0.1rem;
        }
        .status-pill.success .count { color: #10b981; }
        .status-pill.warning .count { color: #f59e0b; }
        .status-pill.danger .count { color: #ef4444; }
        .status-pill.info .count { color: #3b82f6; }
        .status-pill.muted .count { color: #94a3b8; }
        
        /* ===== CHARTS ===== */
        .chart-container {
            position: relative;
            height: 160px;
        }
        .chart-container.small { height: 120px; }
        
        /* ===== ACTIVITY LIST ===== */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }
        .activity-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: #f8fafc;
            border-radius: 8px;
            text-decoration: none;
            color: inherit;
            transition: background 0.2s;
        }
        .activity-item:hover { background: #f1f5f9; color: inherit; }
        .activity-icon {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            flex-shrink: 0;
        }
        .activity-icon.pledge { background: #dbeafe; color: #3b82f6; }
        .activity-icon.instant { background: #d1fae5; color: #10b981; }
        .activity-icon.payment { background: #fef3c7; color: #f59e0b; }
        .activity-icon.voided { background: #fee2e2; color: #ef4444; }
        
        .activity-content { flex: 1; min-width: 0; }
        .activity-name {
            font-size: 0.75rem;
            font-weight: 600;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .activity-meta {
            font-size: 0.65rem;
            color: #94a3b8;
        }
        .activity-amount {
            font-size: 0.8rem;
            font-weight: 700;
            color: #10b981;
            white-space: nowrap;
        }
        .activity-amount.pending { color: #f59e0b; }
        .activity-amount.voided { color: #ef4444; text-decoration: line-through; }
        
        /* ===== METHOD BREAKDOWN ===== */
        .method-list {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .method-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
        }
        .method-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .method-label { flex: 1; color: #475569; }
        .method-value { font-weight: 600; color: #1e293b; }
        
        /* ===== TODAY HIGHLIGHT ===== */
        .today-highlight {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-radius: 10px;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .today-icon {
            width: 36px;
            height: 36px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f59e0b;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .today-content { flex: 1; }
        .today-label { font-size: 0.7rem; color: #92400e; }
        .today-value { font-size: 1.1rem; font-weight: 700; color: #78350f; }
        .today-extra { font-size: 0.7rem; color: #a16207; }
        
        /* ===== QUICK ACTIONS ===== */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.4rem;
            margin-bottom: 0.75rem;
        }
        .quick-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
            padding: 0.6rem 0.25rem;
            background: white;
            border-radius: 8px;
            text-decoration: none;
            color: #475569;
            font-size: 0.6rem;
            text-align: center;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            transition: all 0.2s;
        }
        .quick-btn:hover { background: #0a6286; color: white; }
        .quick-btn i { font-size: 0.9rem; color: #0a6286; }
        .quick-btn:hover i { color: white; }
        
        /* ===== FOOTER ===== */
        .report-footer {
            text-align: center;
            font-size: 0.65rem;
            color: #94a3b8;
            padding: 0.75rem 0;
        }
        
        /* ===== DESKTOP ENHANCEMENTS ===== */
        @media (min-width: 768px) {
            .report-page { padding: 1rem; }
            .stats-grid { grid-template-columns: repeat(4, 1fr); gap: 0.75rem; }
            .stat-card.large { grid-column: span 2; }
            .stat-value { font-size: 1.5rem; }
            .status-pills { grid-template-columns: repeat(6, 1fr); }
            .chart-container { height: 200px; }
            
            .two-col-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0.75rem;
            }
        }
        
        @media (min-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(6, 1fr); }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="report-page">
                
                <!-- Header -->
                <div class="report-header">
                    <h1>
                        <i class="fas fa-chart-line"></i>
                        Performance Report
                        <span class="live-badge">
                            <span class="live-dot"></span>
                            Live
                        </span>
                    </h1>
                </div>
                
                <!-- Quick Actions -->
                <div class="quick-actions">
                    <a href="<?php echo url_for('admin/donations/record-pledge-payment.php'); ?>" class="quick-btn">
                        <i class="fas fa-plus"></i>
                        <span>Record</span>
                    </a>
                    <a href="<?php echo url_for('admin/donations/review-pledge-payments.php'); ?>" class="quick-btn">
                        <i class="fas fa-check-circle"></i>
                        <span>Review</span>
                    </a>
                    <a href="<?php echo url_for('admin/donor-management/payments.php'); ?>" class="quick-btn">
                        <i class="fas fa-list"></i>
                        <span>Payments</span>
                    </a>
                    <a href="<?php echo url_for('admin/donor-management/donors.php'); ?>" class="quick-btn">
                        <i class="fas fa-users"></i>
                        <span>Donors</span>
                    </a>
                </div>
                
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <a href="?period=all" class="filter-btn <?php echo $filter_period === 'all' ? 'active' : ''; ?>">All Time</a>
                    <a href="?period=today" class="filter-btn <?php echo $filter_period === 'today' ? 'active' : ''; ?>">Today</a>
                    <a href="?period=week" class="filter-btn <?php echo $filter_period === 'week' ? 'active' : ''; ?>">This Week</a>
                    <a href="?period=month" class="filter-btn <?php echo $filter_period === 'month' ? 'active' : ''; ?>">This Month</a>
                    <a href="?period=year" class="filter-btn <?php echo $filter_period === 'year' ? 'active' : ''; ?>">This Year</a>
                </div>
                
                <!-- Today's Highlight -->
                <?php if ($today_total_transactions > 0): ?>
                <div class="today-highlight">
                    <div class="today-icon"><i class="fas fa-sun"></i></div>
                    <div class="today-content">
                        <div class="today-label">Today's Activity</div>
                        <div class="today-value"><?php echo $today_total_transactions; ?> transactions • £<?php echo number_format($today_total_amount, 0); ?></div>
                        <div class="today-extra">
                            <?php echo $today['pledges_today']; ?> pledges, 
                            <?php echo $today['instant_today']; ?> instant, 
                            <?php echo $today['pledge_payments_today']; ?> plan payments
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Financial Overview -->
                <div class="stats-grid">
                    <div class="stat-card accent large">
                        <div class="stat-value">£<?php echo number_format($financial['total_pledged'], 0); ?></div>
                        <div class="stat-label">Total Pledged</div>
                    </div>
                    <div class="stat-card success large">
                        <div class="stat-value">£<?php echo number_format($financial['total_paid'], 0); ?></div>
                        <div class="stat-label">Total Collected</div>
                    </div>
                </div>
                
                <!-- Collection Progress -->
                <div class="section-card">
                    <div class="section-title"><i class="fas fa-tasks"></i> Collection Progress</div>
                    <div class="progress-container">
                        <div class="progress-bar-custom">
                            <div class="progress-fill" style="width: <?php echo min(100, max(5, $collection_rate)); ?>%">
                                <?php echo $collection_rate; ?>%
                            </div>
                        </div>
                        <div class="progress-labels">
                            <span>Collected: <strong>£<?php echo number_format($financial['total_paid'], 0); ?></strong></span>
                            <span>Outstanding: <strong>£<?php echo number_format($financial['outstanding'], 0); ?></strong></span>
                        </div>
                    </div>
                </div>
                
                <!-- Key Stats -->
                <div class="stats-grid">
                    <div class="stat-card primary">
                        <div class="stat-value"><?php echo number_format($donor_stats['total_donors']); ?></div>
                        <div class="stat-label">Total Donors</div>
                    </div>
                    <div class="stat-card info">
                        <div class="stat-value"><?php echo number_format($pledge_stats['total_entries']); ?></div>
                        <div class="stat-label">Pledges</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-value"><?php echo number_format($instant_payments['approved'] + $pledge_payments['confirmed']); ?></div>
                        <div class="stat-label">Payments</div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-value"><?php echo number_format($pledge_stats['pending'] + $instant_payments['pending'] + $pledge_payments['pending']); ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>
                
                <!-- Donor Payment Status -->
                <div class="section-card">
                    <div class="section-title"><i class="fas fa-users"></i> Donor Status</div>
                    <div class="status-pills">
                        <a href="<?php echo url_for('admin/donor-management/donors.php?status=completed'); ?>" class="status-pill success" style="text-decoration:none;">
                            <div class="count"><?php echo number_format($donor_stats['fully_paid']); ?></div>
                            <div class="label">Completed</div>
                        </a>
                        <a href="<?php echo url_for('admin/donor-management/donors.php?status=paying'); ?>" class="status-pill warning" style="text-decoration:none;">
                            <div class="count"><?php echo number_format($donor_stats['actively_paying']); ?></div>
                            <div class="label">Paying</div>
                        </a>
                        <a href="<?php echo url_for('admin/donor-management/donors.php?status=not_started'); ?>" class="status-pill danger" style="text-decoration:none;">
                            <div class="count"><?php echo number_format($donor_stats['not_started']); ?></div>
                            <div class="label">Not Started</div>
                        </a>
                    </div>
                </div>
                
                <!-- Two Column Grid -->
                <div class="two-col-grid">
                    <!-- Pledge Breakdown -->
                    <div class="section-card">
                        <div class="section-title"><i class="fas fa-hand-holding-heart"></i> Pledges</div>
                        <div class="status-pills">
                            <div class="status-pill info">
                                <div class="count"><?php echo number_format($pledge_stats['pledge_count']); ?></div>
                                <div class="label">Pledges</div>
                            </div>
                            <div class="status-pill success">
                                <div class="count"><?php echo number_format($pledge_stats['instant_count']); ?></div>
                                <div class="label">Instant</div>
                            </div>
                            <div class="status-pill warning">
                                <div class="count"><?php echo number_format($pledge_stats['pending']); ?></div>
                                <div class="label">Pending</div>
                            </div>
                        </div>
                        <div style="margin-top:0.5rem; font-size:0.7rem; color:#64748b;">
                            Approved: <strong>£<?php echo number_format($pledge_stats['total_pledged'], 0); ?></strong> (pledges) + 
                            <strong>£<?php echo number_format($pledge_stats['instant_amount'], 0); ?></strong> (instant)
                        </div>
                    </div>
                    
                    <!-- Payments Breakdown -->
                    <div class="section-card">
                        <div class="section-title"><i class="fas fa-money-bill-wave"></i> Payments</div>
                        <div class="status-pills">
                            <div class="status-pill success">
                                <div class="count"><?php echo number_format($instant_payments['approved']); ?></div>
                                <div class="label">Instant</div>
                            </div>
                            <div class="status-pill info">
                                <div class="count"><?php echo number_format($pledge_payments['confirmed']); ?></div>
                                <div class="label">Plan Pays</div>
                            </div>
                            <div class="status-pill warning">
                                <div class="count"><?php echo number_format($instant_payments['pending'] + $pledge_payments['pending']); ?></div>
                                <div class="label">Pending</div>
                            </div>
                        </div>
                        <div style="margin-top:0.5rem; font-size:0.7rem; color:#64748b;">
                            Total: <strong>£<?php echo number_format($instant_payments['approved_amount'] + $pledge_payments['confirmed_amount'], 0); ?></strong>
                        </div>
                    </div>
                </div>
                
                <!-- Charts Row -->
                <div class="two-col-grid">
                    <!-- Monthly Trends -->
                    <div class="section-card">
                        <div class="section-title"><i class="fas fa-chart-bar"></i> Monthly Trend</div>
                        <div class="chart-container">
                            <canvas id="trendsChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Payment Methods -->
                    <div class="section-card">
                        <div class="section-title"><i class="fas fa-credit-card"></i> Payment Methods</div>
                        <?php 
                        $method_colors = [
                            'cash' => '#10b981',
                            'card' => '#3b82f6', 
                            'bank' => '#0a6286',
                            'bank_transfer' => '#0a6286',
                            'cheque' => '#f59e0b',
                            'other' => '#94a3b8'
                        ];
                        $method_names = [
                            'cash' => 'Cash',
                            'card' => 'Card',
                            'bank' => 'Bank Transfer',
                            'bank_transfer' => 'Bank Transfer',
                            'cheque' => 'Cheque',
                            'other' => 'Other'
                        ];
                        ?>
                        <div class="method-list">
                            <?php foreach ($all_methods as $method => $data): ?>
                            <div class="method-item">
                                <span class="method-dot" style="background: <?php echo $method_colors[$method] ?? '#94a3b8'; ?>"></span>
                                <span class="method-label"><?php echo $method_names[$method] ?? ucfirst($method); ?></span>
                                <span class="method-value"><?php echo $data['count']; ?> (£<?php echo number_format($data['amount'], 0); ?>)</span>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($all_methods)): ?>
                            <div class="text-muted text-center py-2" style="font-size:0.75rem;">No payments yet</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity -->
                <div class="section-card">
                    <div class="section-title">
                        <i class="fas fa-bolt"></i> Recent Activity
                        <a href="<?php echo url_for('admin/donor-management/payments.php'); ?>" class="ms-auto" style="font-size:0.7rem; text-decoration:none; color:#0a6286;">View All →</a>
                    </div>
                    <div class="activity-list">
                        <?php if (empty($recent_activity)): ?>
                            <div class="text-center text-muted py-3" style="font-size:0.75rem;">
                                <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                No recent activity
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_activity as $item): 
                                $icon_class = 'pledge';
                                $icon = 'fa-hand-holding-heart';
                                $type_label = 'Pledge';
                                
                                if ($item['type'] === 'instant') {
                                    $icon_class = 'instant';
                                    $icon = 'fa-bolt';
                                    $type_label = 'Instant';
                                } elseif ($item['type'] === 'pledge_payment') {
                                    $icon_class = 'payment';
                                    $icon = 'fa-coins';
                                    $type_label = 'Plan Pay';
                                }
                                
                                if (in_array($item['status'], ['voided', 'rejected'])) {
                                    $icon_class = 'voided';
                                }
                                
                                $amount_class = '';
                                if ($item['status'] === 'pending') $amount_class = 'pending';
                                if (in_array($item['status'], ['voided', 'rejected'])) $amount_class = 'voided';
                                
                                $link = $item['donor_id'] 
                                    ? url_for('admin/donor-management/view-donor.php?id=' . $item['donor_id'])
                                    : '#';
                            ?>
                            <a href="<?php echo $link; ?>" class="activity-item">
                                <div class="activity-icon <?php echo $icon_class; ?>">
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-name"><?php echo htmlspecialchars($item['donor_name'] ?? 'Unknown'); ?></div>
                                    <div class="activity-meta">
                                        <?php echo $type_label; ?> • 
                                        <?php echo date('d M, H:i', strtotime($item['created_at'])); ?>
                                    </div>
                                </div>
                                <div class="activity-amount <?php echo $amount_class; ?>">
                                    £<?php echo number_format($item['amount'], 0); ?>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Summary Stats -->
                <div class="stats-grid">
                    <div class="stat-card info">
                        <div class="stat-value"><?php echo number_format($donor_stats['with_payment_plans']); ?></div>
                        <div class="stat-label">Active Plans</div>
                    </div>
                    <div class="stat-card accent">
                        <div class="stat-value"><?php echo $collection_rate; ?>%</div>
                        <div class="stat-label">Collection Rate</div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-value"><?php echo number_format($donor_stats['overdue']); ?></div>
                        <div class="stat-label">Overdue</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-value"><?php echo number_format($donor_stats['instant_payers']); ?></div>
                        <div class="stat-label">Instant Payers</div>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="report-footer">
                    <i class="fas fa-sync-alt me-1"></i>
                    Last updated: <?php echo date('d M Y, H:i:s'); ?> • Auto-refreshes every 30s
                </div>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// Monthly Trends Chart
const trendsCtx = document.getElementById('trendsChart');
if (trendsCtx) {
    const trendsData = <?php echo json_encode($monthly_trends); ?>;
    
    new Chart(trendsCtx, {
        type: 'bar',
        data: {
            labels: trendsData.map(d => d.month_label),
            datasets: [{
                label: 'Amount (£)',
                data: trendsData.map(d => parseFloat(d.amount)),
                backgroundColor: '#0a6286',
                borderRadius: 4,
                barThickness: 'flex',
                maxBarThickness: 30
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => '£' + ctx.raw.toLocaleString()
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (val) => '£' + (val >= 1000 ? (val/1000).toFixed(0) + 'k' : val),
                        font: { size: 9 }
                    },
                    grid: { color: '#f1f5f9' }
                },
                x: {
                    ticks: { font: { size: 9 } },
                    grid: { display: false }
                }
            }
        }
    });
}

// Auto-refresh every 30 seconds
setTimeout(() => location.reload(), 30000);
</script>
</body>
</html>
