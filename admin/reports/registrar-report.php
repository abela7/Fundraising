<?php
// admin/reports/registrar-report.php
// Comprehensive Report Dashboard for Registrars
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
// FETCH ALL STATISTICS
// ============================================

// Total Pledges & Amount
$pledge_stats = $db->query("
    SELECT 
        COUNT(*) as total_pledges,
        COALESCE(SUM(amount), 0) as total_pledged
    FROM pledges 
    WHERE status = 'approved'
")->fetch_assoc();

// Total Confirmed Payments
$payment_stats = $db->query("
    SELECT 
        COUNT(*) as total_payments,
        COALESCE(SUM(amount), 0) as total_paid
    FROM pledge_payments 
    WHERE status = 'confirmed'
")->fetch_assoc();

// Donor Statistics
$donor_stats = $db->query("
    SELECT 
        COUNT(*) as total_donors,
        SUM(CASE WHEN total_paid > 0 THEN 1 ELSE 0 END) as donors_who_paid,
        SUM(CASE WHEN total_paid >= total_pledged AND total_pledged > 0 THEN 1 ELSE 0 END) as fully_paid,
        SUM(CASE WHEN total_paid > 0 AND total_paid < total_pledged THEN 1 ELSE 0 END) as partially_paid,
        SUM(CASE WHEN total_paid = 0 OR total_paid IS NULL THEN 1 ELSE 0 END) as not_started,
        SUM(CASE WHEN has_active_plan = 1 THEN 1 ELSE 0 END) as with_payment_plans
    FROM donors
    WHERE total_pledged > 0
")->fetch_assoc();

// Payment Status Distribution
$payment_status = $db->query("
    SELECT 
        status,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as amount
    FROM pledge_payments
    GROUP BY status
")->fetch_all(MYSQLI_ASSOC);

$status_data = ['pending' => 0, 'confirmed' => 0, 'voided' => 0];
$status_amounts = ['pending' => 0, 'confirmed' => 0, 'voided' => 0];
foreach ($payment_status as $s) {
    $status_data[$s['status']] = (int)$s['count'];
    $status_amounts[$s['status']] = (float)$s['amount'];
}

// Monthly Payment Trends (Last 6 months)
$monthly_trends = $db->query("
    SELECT 
        DATE_FORMAT(payment_date, '%Y-%m') as month,
        DATE_FORMAT(payment_date, '%b %Y') as month_label,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as amount
    FROM pledge_payments
    WHERE status = 'confirmed'
    AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
    ORDER BY month ASC
")->fetch_all(MYSQLI_ASSOC);

// Payment Methods Distribution
$payment_methods = $db->query("
    SELECT 
        payment_method,
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as amount
    FROM pledge_payments
    WHERE status = 'confirmed'
    GROUP BY payment_method
    ORDER BY count DESC
")->fetch_all(MYSQLI_ASSOC);

// Recent Payments (last 10)
$recent_payments = $db->query("
    SELECT 
        pp.id,
        pp.amount,
        pp.payment_method,
        pp.payment_date,
        pp.status,
        pp.created_at,
        d.id as donor_id,
        d.name as donor_name,
        d.phone as donor_phone
    FROM pledge_payments pp
    LEFT JOIN donors d ON pp.donor_id = d.id
    ORDER BY pp.created_at DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Top Donors (by amount paid)
$top_donors = $db->query("
    SELECT 
        id,
        name,
        phone,
        total_pledged,
        total_paid,
        balance,
        payment_status
    FROM donors
    WHERE total_paid > 0
    ORDER BY total_paid DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Calculate collection rate
$collection_rate = $pledge_stats['total_pledged'] > 0 
    ? round(($payment_stats['total_paid'] / $pledge_stats['total_pledged']) * 100, 1) 
    : 0;

// Outstanding balance
$outstanding = $pledge_stats['total_pledged'] - $payment_stats['total_paid'];

// Today's stats
$today_stats = $db->query("
    SELECT 
        COUNT(*) as payments_today,
        COALESCE(SUM(amount), 0) as amount_today
    FROM pledge_payments
    WHERE status = 'confirmed'
    AND DATE(approved_at) = CURDATE()
")->fetch_assoc();
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
            --brand-primary: #0a6286;
            --brand-accent: #e2ca18;
            --color-success: #10b981;
            --color-warning: #f59e0b;
            --color-danger: #ef4444;
            --color-info: #3b82f6;
        }
        
        /* Page Header */
        .report-header {
            background: linear-gradient(135deg, var(--brand-primary) 0%, #075985 100%);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            color: white;
        }
        .report-header h1 {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
        }
        .report-header .subtitle {
            font-size: 0.8rem;
            opacity: 0.85;
            margin-top: 0.25rem;
        }
        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            font-size: 0.7rem;
            background: rgba(255,255,255,0.2);
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
        }
        .live-dot {
            width: 8px;
            height: 8px;
            background: var(--color-success);
            border-radius: 50%;
            animation: pulse-dot 2s infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
        }
        
        /* Stat Cards */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }
        .stat-card.primary::before { background: var(--brand-primary); }
        .stat-card.success::before { background: var(--color-success); }
        .stat-card.warning::before { background: var(--color-warning); }
        .stat-card.danger::before { background: var(--color-danger); }
        .stat-card.info::before { background: var(--color-info); }
        .stat-card.accent::before { background: var(--brand-accent); }
        
        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }
        .stat-card.primary .stat-icon { background: #e0f2fe; color: var(--brand-primary); }
        .stat-card.success .stat-icon { background: #d1fae5; color: var(--color-success); }
        .stat-card.warning .stat-icon { background: #fef3c7; color: var(--color-warning); }
        .stat-card.danger .stat-icon { background: #fee2e2; color: var(--color-danger); }
        .stat-card.info .stat-icon { background: #dbeafe; color: var(--color-info); }
        .stat-card.accent .stat-icon { background: #fef9c3; color: #a16207; }
        
        .stat-value {
            font-size: 1.375rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
        }
        .stat-label {
            font-size: 0.7rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 0.25rem;
        }
        
        /* Large Stat Card */
        .stat-card.large {
            grid-column: span 2;
            text-align: center;
            padding: 1.25rem;
        }
        .stat-card.large .stat-icon {
            width: 48px;
            height: 48px;
            font-size: 1.25rem;
            margin: 0 auto 0.75rem;
        }
        .stat-card.large .stat-value {
            font-size: 1.75rem;
        }
        
        /* Progress Section */
        .progress-section {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .progress-section h3 {
            font-size: 0.875rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.75rem;
        }
        .progress-bar-custom {
            height: 24px;
            background: #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            position: relative;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--color-success) 0%, #059669 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
            transition: width 0.5s ease;
        }
        .progress-labels {
            display: flex;
            justify-content: space-between;
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: #64748b;
        }
        .progress-labels .amount {
            font-weight: 600;
            color: #1e293b;
        }
        
        /* Chart Section */
        .chart-section {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .chart-section h3 {
            font-size: 0.875rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .chart-section h3 i {
            color: var(--brand-primary);
        }
        .chart-container {
            position: relative;
            height: 200px;
        }
        .chart-container.pie {
            height: 180px;
        }
        
        /* Donor Status Pills */
        .donor-status-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .status-pill {
            background: white;
            border-radius: 10px;
            padding: 0.75rem 0.5rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .status-pill .count {
            font-size: 1.25rem;
            font-weight: 700;
            line-height: 1;
        }
        .status-pill .label {
            font-size: 0.65rem;
            color: #64748b;
            margin-top: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .status-pill.success .count { color: var(--color-success); }
        .status-pill.warning .count { color: var(--color-warning); }
        .status-pill.danger .count { color: var(--color-danger); }
        
        /* Recent Activity */
        .activity-section {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .activity-section h3 {
            font-size: 0.875rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .activity-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem;
            background: #f8fafc;
            border-radius: 8px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
        }
        .activity-item:hover {
            background: #f1f5f9;
            color: inherit;
        }
        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            flex-shrink: 0;
        }
        .activity-icon.confirmed {
            background: #d1fae5;
            color: var(--color-success);
        }
        .activity-icon.pending {
            background: #fef3c7;
            color: var(--color-warning);
        }
        .activity-icon.voided {
            background: #fee2e2;
            color: var(--color-danger);
        }
        .activity-content {
            flex: 1;
            min-width: 0;
        }
        .activity-name {
            font-size: 0.8rem;
            font-weight: 600;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .activity-detail {
            font-size: 0.7rem;
            color: #64748b;
        }
        .activity-amount {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--color-success);
            white-space: nowrap;
        }
        .activity-amount.pending {
            color: var(--color-warning);
        }
        
        /* Top Donors */
        .top-donor-item {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            padding: 0.625rem;
            background: #f8fafc;
            border-radius: 8px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
        }
        .top-donor-item:hover {
            background: #f1f5f9;
            color: inherit;
        }
        .donor-rank {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--brand-primary);
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .donor-rank.gold { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .donor-rank.silver { background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%); }
        .donor-rank.bronze { background: linear-gradient(135deg, #a16207 0%, #854d0e 100%); }
        .donor-info {
            flex: 1;
            min-width: 0;
        }
        .donor-info .name {
            font-size: 0.8rem;
            font-weight: 600;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .donor-info .phone {
            font-size: 0.7rem;
            color: #64748b;
        }
        .donor-paid {
            text-align: right;
        }
        .donor-paid .amount {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--color-success);
        }
        .donor-paid .progress-text {
            font-size: 0.65rem;
            color: #64748b;
        }
        
        /* Payment Method Legend */
        .method-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }
        .method-item {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            font-size: 0.7rem;
            color: #475569;
            background: #f1f5f9;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }
        .method-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        
        /* Quick Links */
        .quick-links {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .quick-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem;
            background: white;
            border-radius: 10px;
            text-decoration: none;
            color: #475569;
            font-size: 0.8rem;
            font-weight: 500;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            transition: all 0.2s;
        }
        .quick-link:hover {
            background: var(--brand-primary);
            color: white;
        }
        .quick-link i {
            font-size: 1rem;
            color: var(--brand-primary);
        }
        .quick-link:hover i {
            color: white;
        }
        
        /* Last Updated */
        .last-updated {
            text-align: center;
            font-size: 0.7rem;
            color: #94a3b8;
            padding: 0.75rem;
        }
        
        /* Responsive */
        @media (min-width: 768px) {
            .stat-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            .stat-card.large {
                grid-column: span 2;
            }
            .donor-status-grid {
                grid-template-columns: repeat(6, 1fr);
            }
            .chart-container {
                height: 250px;
            }
            .quick-links {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        @media (min-width: 1024px) {
            .report-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1rem;
            }
            .report-grid .full-width {
                grid-column: span 2;
            }
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
                
                <!-- Page Header -->
                <div class="report-header">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h1><i class="fas fa-chart-line me-2"></i><?php echo $page_title; ?></h1>
                            <p class="subtitle mb-0">Church Fundraising Overview</p>
                        </div>
                        <div class="live-indicator">
                            <span class="live-dot"></span>
                            <span>Live</span>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Links -->
                <div class="quick-links">
                    <a href="<?php echo url_for('admin/donations/record-pledge-payment.php'); ?>" class="quick-link">
                        <i class="fas fa-plus-circle"></i>
                        <span>Record Payment</span>
                    </a>
                    <a href="<?php echo url_for('admin/donations/review-pledge-payments.php'); ?>" class="quick-link">
                        <i class="fas fa-check-circle"></i>
                        <span>Review Payments</span>
                    </a>
                    <a href="<?php echo url_for('admin/donor-management/payments.php'); ?>" class="quick-link">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Payment List</span>
                    </a>
                    <a href="<?php echo url_for('admin/donor-management/donors.php'); ?>" class="quick-link">
                        <i class="fas fa-users"></i>
                        <span>Donor List</span>
                    </a>
                </div>
                
                <!-- Key Metrics -->
                <div class="stat-grid">
                    <div class="stat-card primary">
                        <div class="stat-icon"><i class="fas fa-hand-holding-heart"></i></div>
                        <div class="stat-value"><?php echo number_format($pledge_stats['total_pledges']); ?></div>
                        <div class="stat-label">Total Pledges</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div class="stat-value"><?php echo number_format($donor_stats['total_donors']); ?></div>
                        <div class="stat-label">Total Donors</div>
                    </div>
                    <div class="stat-card info">
                        <div class="stat-icon"><i class="fas fa-receipt"></i></div>
                        <div class="stat-value"><?php echo number_format($payment_stats['total_payments']); ?></div>
                        <div class="stat-label">Payments Made</div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-icon"><i class="fas fa-clock"></i></div>
                        <div class="stat-value"><?php echo number_format($status_data['pending']); ?></div>
                        <div class="stat-label">Pending Review</div>
                    </div>
                </div>
                
                <!-- Financial Summary -->
                <div class="stat-grid">
                    <div class="stat-card accent large">
                        <div class="stat-icon"><i class="fas fa-pound-sign"></i></div>
                        <div class="stat-value">£<?php echo number_format($pledge_stats['total_pledged'], 0); ?></div>
                        <div class="stat-label">Total Pledged</div>
                    </div>
                    <div class="stat-card success large">
                        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-value">£<?php echo number_format($payment_stats['total_paid'], 0); ?></div>
                        <div class="stat-label">Total Collected</div>
                    </div>
                </div>
                
                <!-- Collection Progress -->
                <div class="progress-section">
                    <h3><i class="fas fa-tasks me-2 text-primary"></i>Collection Progress</h3>
                    <div class="progress-bar-custom">
                        <div class="progress-fill" style="width: <?php echo min(100, $collection_rate); ?>%">
                            <?php echo $collection_rate; ?>%
                        </div>
                    </div>
                    <div class="progress-labels">
                        <span>Collected: <span class="amount">£<?php echo number_format($payment_stats['total_paid'], 0); ?></span></span>
                        <span>Outstanding: <span class="amount">£<?php echo number_format($outstanding, 0); ?></span></span>
                    </div>
                </div>
                
                <!-- Donor Status Distribution -->
                <div class="donor-status-grid">
                    <div class="status-pill success">
                        <div class="count"><?php echo number_format($donor_stats['fully_paid']); ?></div>
                        <div class="label">Fully Paid</div>
                    </div>
                    <div class="status-pill warning">
                        <div class="count"><?php echo number_format($donor_stats['partially_paid']); ?></div>
                        <div class="label">Paying</div>
                    </div>
                    <div class="status-pill danger">
                        <div class="count"><?php echo number_format($donor_stats['not_started']); ?></div>
                        <div class="label">Not Started</div>
                    </div>
                </div>
                
                <div class="report-grid">
                    <!-- Payment Trends Chart -->
                    <div class="chart-section">
                        <h3><i class="fas fa-chart-bar"></i>Monthly Payments</h3>
                        <div class="chart-container">
                            <canvas id="trendsChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Payment Status Chart -->
                    <div class="chart-section">
                        <h3><i class="fas fa-chart-pie"></i>Payment Status</h3>
                        <div class="chart-container pie">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Recent Activity -->
                    <div class="activity-section">
                        <h3>
                            <span><i class="fas fa-bolt me-2 text-warning"></i>Recent Activity</span>
                            <a href="<?php echo url_for('admin/donations/review-pledge-payments.php?filter=all'); ?>" class="btn btn-sm btn-outline-primary">View All</a>
                        </h3>
                        <div class="activity-list">
                            <?php if (empty($recent_payments)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="fas fa-inbox fa-2x mb-2 opacity-50"></i>
                                    <p class="mb-0 small">No recent payments</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recent_payments as $payment): ?>
                                    <a href="<?php echo url_for('admin/donor-management/view-donor.php?id=' . $payment['donor_id']); ?>" class="activity-item">
                                        <div class="activity-icon <?php echo $payment['status']; ?>">
                                            <?php if ($payment['status'] === 'confirmed'): ?>
                                                <i class="fas fa-check"></i>
                                            <?php elseif ($payment['status'] === 'pending'): ?>
                                                <i class="fas fa-clock"></i>
                                            <?php else: ?>
                                                <i class="fas fa-times"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-name"><?php echo htmlspecialchars($payment['donor_name'] ?? 'Unknown'); ?></div>
                                            <div class="activity-detail">
                                                <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?> • 
                                                <?php echo date('d M, H:i', strtotime($payment['created_at'])); ?>
                                            </div>
                                        </div>
                                        <div class="activity-amount <?php echo $payment['status'] === 'pending' ? 'pending' : ''; ?>">
                                            £<?php echo number_format($payment['amount'], 0); ?>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Top Donors -->
                    <div class="activity-section">
                        <h3>
                            <span><i class="fas fa-trophy me-2 text-warning"></i>Top Donors</span>
                            <a href="<?php echo url_for('admin/donor-management/donors.php'); ?>" class="btn btn-sm btn-outline-primary">View All</a>
                        </h3>
                        <div class="activity-list">
                            <?php if (empty($top_donors)): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="fas fa-medal fa-2x mb-2 opacity-50"></i>
                                    <p class="mb-0 small">No donors yet</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($top_donors as $index => $donor): 
                                    $rank_class = $index === 0 ? 'gold' : ($index === 1 ? 'silver' : ($index === 2 ? 'bronze' : ''));
                                    $progress = $donor['total_pledged'] > 0 
                                        ? round(($donor['total_paid'] / $donor['total_pledged']) * 100) 
                                        : 0;
                                ?>
                                    <a href="<?php echo url_for('admin/donor-management/view-donor.php?id=' . $donor['id']); ?>" class="top-donor-item">
                                        <div class="donor-rank <?php echo $rank_class; ?>"><?php echo $index + 1; ?></div>
                                        <div class="donor-info">
                                            <div class="name"><?php echo htmlspecialchars($donor['name']); ?></div>
                                            <div class="phone"><?php echo htmlspecialchars($donor['phone']); ?></div>
                                        </div>
                                        <div class="donor-paid">
                                            <div class="amount">£<?php echo number_format($donor['total_paid'], 0); ?></div>
                                            <div class="progress-text"><?php echo $progress; ?>% of pledge</div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Payment Methods -->
                    <div class="chart-section full-width">
                        <h3><i class="fas fa-credit-card"></i>Payment Methods</h3>
                        <div class="chart-container">
                            <canvas id="methodsChart"></canvas>
                        </div>
                        <div class="method-legend" id="methodLegend"></div>
                    </div>
                </div>
                
                <!-- Today's Summary -->
                <div class="stat-grid">
                    <div class="stat-card info">
                        <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                        <div class="stat-value"><?php echo number_format($today_stats['payments_today']); ?></div>
                        <div class="stat-label">Today's Payments</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-icon"><i class="fas fa-pound-sign"></i></div>
                        <div class="stat-value">£<?php echo number_format($today_stats['amount_today'], 0); ?></div>
                        <div class="stat-label">Today's Collection</div>
                    </div>
                    <div class="stat-card primary">
                        <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                        <div class="stat-value"><?php echo number_format($donor_stats['with_payment_plans']); ?></div>
                        <div class="stat-label">Active Plans</div>
                    </div>
                    <div class="stat-card accent">
                        <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                        <div class="stat-value"><?php echo $collection_rate; ?>%</div>
                        <div class="stat-label">Collection Rate</div>
                    </div>
                </div>
                
                <!-- Last Updated -->
                <div class="last-updated">
                    <i class="fas fa-sync-alt me-1"></i>
                    Last updated: <span id="lastUpdated"><?php echo date('d M Y, H:i:s'); ?></span>
                    <span class="ms-2">(Auto-updates every 30s)</span>
                </div>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// Chart colors using brand palette
const chartColors = {
    primary: '#0a6286',
    accent: '#e2ca18',
    success: '#10b981',
    warning: '#f59e0b',
    danger: '#ef4444',
    info: '#3b82f6',
    gray: '#94a3b8'
};

// Monthly Trends Chart
const trendsCtx = document.getElementById('trendsChart').getContext('2d');
const trendsData = <?php echo json_encode($monthly_trends); ?>;

new Chart(trendsCtx, {
    type: 'bar',
    data: {
        labels: trendsData.map(d => d.month_label),
        datasets: [{
            label: 'Amount (£)',
            data: trendsData.map(d => d.amount),
            backgroundColor: chartColors.primary,
            borderRadius: 6,
            barThickness: 'flex',
            maxBarThickness: 40
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
                    callback: (val) => '£' + (val >= 1000 ? (val/1000) + 'k' : val),
                    font: { size: 10 }
                },
                grid: { color: '#f1f5f9' }
            },
            x: {
                ticks: { font: { size: 10 } },
                grid: { display: false }
            }
        }
    }
});

// Payment Status Doughnut Chart
const statusCtx = document.getElementById('statusChart').getContext('2d');
const statusData = <?php echo json_encode($status_data); ?>;

new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Confirmed', 'Pending', 'Voided'],
        datasets: [{
            data: [statusData.confirmed, statusData.pending, statusData.voided],
            backgroundColor: [chartColors.success, chartColors.warning, chartColors.danger],
            borderWidth: 0
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
                    padding: 15,
                    usePointStyle: true,
                    pointStyle: 'circle',
                    font: { size: 11 }
                }
            }
        }
    }
});

// Payment Methods Chart
const methodsCtx = document.getElementById('methodsChart').getContext('2d');
const methodsData = <?php echo json_encode($payment_methods); ?>;
const methodColors = [chartColors.primary, chartColors.success, chartColors.warning, chartColors.info, chartColors.accent];

new Chart(methodsCtx, {
    type: 'bar',
    data: {
        labels: methodsData.map(d => d.payment_method.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())),
        datasets: [{
            label: 'Amount (£)',
            data: methodsData.map(d => d.amount),
            backgroundColor: methodsData.map((_, i) => methodColors[i % methodColors.length]),
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
                    label: (ctx) => '£' + ctx.raw.toLocaleString()
                }
            }
        },
        scales: {
            x: {
                beginAtZero: true,
                ticks: {
                    callback: (val) => '£' + (val >= 1000 ? (val/1000) + 'k' : val),
                    font: { size: 10 }
                },
                grid: { color: '#f1f5f9' }
            },
            y: {
                ticks: { font: { size: 10 } },
                grid: { display: false }
            }
        }
    }
});

// Build method legend
const legendEl = document.getElementById('methodLegend');
methodsData.forEach((method, i) => {
    const item = document.createElement('div');
    item.className = 'method-item';
    item.innerHTML = `
        <span class="method-dot" style="background: ${methodColors[i % methodColors.length]}"></span>
        <span>${method.payment_method.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}: ${method.count}</span>
    `;
    legendEl.appendChild(item);
});

// Auto-refresh every 30 seconds
setInterval(() => {
    location.reload();
}, 30000);

// Update timestamp display
function updateTimestamp() {
    document.getElementById('lastUpdated').textContent = new Date().toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
}
</script>
</body>
</html>

