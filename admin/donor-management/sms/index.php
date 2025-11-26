<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../shared/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_login();
require_admin();

$page_title = 'SMS Dashboard';
$current_user = current_user();

// Initialize stats with defaults
$stats = [
    'today_sent' => 0,
    'today_delivered' => 0,
    'today_failed' => 0,
    'today_cost' => 0,
    'pending_queue' => 0,
    'templates_active' => 0,
    'month_sent' => 0,
    'month_cost' => 0,
    'delivery_rate' => 0,
    'donors_with_sms' => 0,
    'blacklisted' => 0,
    'reminders_due_today' => 0
];

$recent_sms = [];
$queue_items = [];
$provider_status = null;
$error_message = null;

try {
    $db = db();
    
    // Check if SMS tables exist
    $tables_check = $db->query("SHOW TABLES LIKE 'sms_log'");
    $sms_tables_exist = ($tables_check && $tables_check->num_rows > 0);
    
    if ($sms_tables_exist) {
        // Today's stats
        $today = date('Y-m-d');
        $today_stats = $db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                COALESCE(SUM(cost_pence), 0) as cost
            FROM sms_log
            WHERE DATE(sent_at) = '$today'
        ");
        if ($today_stats && $row = $today_stats->fetch_assoc()) {
            $stats['today_sent'] = (int)$row['total'];
            $stats['today_delivered'] = (int)$row['delivered'];
            $stats['today_failed'] = (int)$row['failed'];
            $stats['today_cost'] = (float)$row['cost'];
        }
        
        // This month stats
        $month_start = date('Y-m-01');
        $month_stats = $db->query("
            SELECT 
                COUNT(*) as total,
                COALESCE(SUM(cost_pence), 0) as cost
            FROM sms_log
            WHERE sent_at >= '$month_start'
        ");
        if ($month_stats && $row = $month_stats->fetch_assoc()) {
            $stats['month_sent'] = (int)$row['total'];
            $stats['month_cost'] = (float)$row['cost'];
        }
        
        // Queue stats
        $queue_check = $db->query("SHOW TABLES LIKE 'sms_queue'");
        if ($queue_check && $queue_check->num_rows > 0) {
            $queue_stats = $db->query("
                SELECT COUNT(*) as pending 
                FROM sms_queue 
                WHERE status IN ('pending', 'processing')
            ");
            if ($queue_stats && $row = $queue_stats->fetch_assoc()) {
                $stats['pending_queue'] = (int)$row['pending'];
            }
            
            // Get recent queue items
            $queue_result = $db->query("
                SELECT q.*, d.name as donor_name
                FROM sms_queue q
                LEFT JOIN donors d ON q.donor_id = d.id
                WHERE q.status IN ('pending', 'processing')
                ORDER BY q.priority DESC, q.created_at ASC
                LIMIT 5
            ");
            if ($queue_result) {
                while ($row = $queue_result->fetch_assoc()) {
                    $queue_items[] = $row;
                }
            }
        }
        
        // Template stats
        $template_check = $db->query("SHOW TABLES LIKE 'sms_templates'");
        if ($template_check && $template_check->num_rows > 0) {
            $template_stats = $db->query("SELECT COUNT(*) as active FROM sms_templates WHERE is_active = 1");
            if ($template_stats && $row = $template_stats->fetch_assoc()) {
                $stats['templates_active'] = (int)$row['active'];
            }
        }
        
        // Blacklist stats
        $blacklist_check = $db->query("SHOW TABLES LIKE 'sms_blacklist'");
        if ($blacklist_check && $blacklist_check->num_rows > 0) {
            $blacklist_stats = $db->query("SELECT COUNT(*) as total FROM sms_blacklist");
            if ($blacklist_stats && $row = $blacklist_stats->fetch_assoc()) {
                $stats['blacklisted'] = (int)$row['total'];
            }
        }
        
        // Provider status
        $provider_check = $db->query("SHOW TABLES LIKE 'sms_providers'");
        if ($provider_check && $provider_check->num_rows > 0) {
            $provider_result = $db->query("
                SELECT name, display_name, is_active, is_default, last_success_at, failure_count
                FROM sms_providers 
                WHERE is_default = 1 AND is_active = 1
                LIMIT 1
            ");
            if ($provider_result && $provider_result->num_rows > 0) {
                $provider_status = $provider_result->fetch_assoc();
            }
        }
        
        // Recent SMS log
        $recent_result = $db->query("
            SELECT l.*, d.name as donor_name
            FROM sms_log l
            LEFT JOIN donors d ON l.donor_id = d.id
            ORDER BY l.sent_at DESC
            LIMIT 10
        ");
        if ($recent_result) {
            while ($row = $recent_result->fetch_assoc()) {
                $recent_sms[] = $row;
            }
        }
        
        // Calculate delivery rate
        if ($stats['today_sent'] > 0) {
            $stats['delivery_rate'] = round(($stats['today_delivered'] / $stats['today_sent']) * 100, 1);
        }
    }
    
    // Donors with SMS opt-in
    $donors_sms = $db->query("SELECT COUNT(*) as total FROM donors WHERE sms_opt_in = 1");
    if ($donors_sms && $row = $donors_sms->fetch_assoc()) {
        $stats['donors_with_sms'] = (int)$row['total'];
    }
    
    // Payment reminders due today (from payment_plan_schedule)
    $schedule_check = $db->query("SHOW TABLES LIKE 'payment_plan_schedule'");
    if ($schedule_check && $schedule_check->num_rows > 0) {
        // Check if reminder columns exist
        $col_check = $db->query("SHOW COLUMNS FROM payment_plan_schedule LIKE 'reminder_3day_sent'");
        if ($col_check && $col_check->num_rows > 0) {
            $reminder_date = date('Y-m-d', strtotime('+3 days'));
            $reminders = $db->query("
                SELECT COUNT(*) as due 
                FROM payment_plan_schedule 
                WHERE due_date = '$reminder_date' 
                AND status = 'pending' 
                AND reminder_3day_sent = 0
            ");
            if ($reminders && $row = $reminders->fetch_assoc()) {
                $stats['reminders_due_today'] = (int)$row['due'];
            }
        }
    }
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
    error_log("SMS Dashboard Error: " . $e->getMessage());
}

// Format cost display
$today_cost_display = '£' . number_format($stats['today_cost'] / 100, 2);
$month_cost_display = '£' . number_format($stats['month_cost'] / 100, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/admin.css">
    <link rel="stylesheet" href="../assets/donor-management.css">
    <style>
        /* SMS Dashboard Specific Styles */
        .sms-header {
            background: linear-gradient(135deg, #0a6286 0%, #0ea5e9 100%);
            color: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 15px rgba(10, 98, 134, 0.3);
        }
        
        .sms-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }
        
        .sms-header p {
            margin: 0.5rem 0 0;
            opacity: 0.9;
            font-size: 0.875rem;
        }
        
        /* Provider Status Badge */
        .provider-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255,255,255,0.2);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.8125rem;
            font-weight: 600;
        }
        
        .provider-badge.connected {
            background: rgba(25, 135, 84, 0.3);
        }
        
        .provider-badge.disconnected {
            background: rgba(220, 38, 38, 0.3);
        }
        
        /* Stat Cards */
        .sms-stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid #e5e7eb;
            height: 100%;
            transition: all 0.3s;
        }
        
        .sms-stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }
        
        .sms-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }
        
        .sms-stat-value {
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 0.25rem;
        }
        
        .sms-stat-label {
            font-size: 0.8125rem;
            color: #64748b;
            font-weight: 600;
        }
        
        .sms-stat-trend {
            font-size: 0.75rem;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        /* Use system colors */
        .text-purple { color: #0a6286 !important; }
        .text-indigo { color: #0a6286 !important; }
        .bg-purple-light { background: #e0f2fe; color: #0a6286; }
        .bg-indigo-light { background: #e0f2fe; color: #0a6286; }
        
        /* Quick Actions */
        .action-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        
        .action-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .action-title {
            font-size: 1rem;
            font-weight: 700;
            margin: 0;
            color: #1e293b;
        }
        
        .action-list {
            padding: 0.5rem;
        }
        
        .action-item {
            display: flex;
            align-items: center;
            gap: 0.875rem;
            padding: 0.875rem;
            border-radius: 8px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
        }
        
        .action-item:hover {
            background: #f8fafc;
            transform: translateX(4px);
        }
        
        .action-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .action-content {
            flex: 1;
        }
        
        .action-name {
            font-weight: 600;
            font-size: 0.9375rem;
            margin: 0;
            color: #1e293b;
        }
        
        .action-desc {
            font-size: 0.75rem;
            color: #64748b;
            margin: 0;
        }
        
        /* SMS Log Table */
        .sms-log-item {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }
        
        .sms-log-item:last-child {
            border-bottom: none;
        }
        
        .sms-log-item:hover {
            background: #f8fafc;
        }
        
        .sms-status {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .sms-status.delivered { background: var(--sms-success); }
        .sms-status.sent { background: var(--sms-info); }
        .sms-status.failed { background: var(--sms-danger); }
        .sms-status.pending { background: var(--sms-warning); }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
        }
        
        .empty-icon {
            font-size: 3.5rem;
            color: #e2e8f0;
            margin-bottom: 1rem;
        }
        
        .empty-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 0.5rem;
        }
        
        .empty-desc {
            color: #64748b;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }
        
        /* Setup Required Alert */
        .setup-alert {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 1px solid #fcd34d;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .setup-alert h5 {
            color: #92400e;
            font-weight: 700;
        }
        
        .setup-alert p {
            color: #78350f;
            margin-bottom: 0;
        }
        
        /* Mobile Responsive */
        @media (max-width: 767px) {
            .sms-header {
                padding: 1.25rem;
                border-radius: 12px;
            }
            
            .sms-header h1 {
                font-size: 1.25rem;
            }
            
            .provider-badge {
                margin-top: 0.75rem;
                font-size: 0.75rem;
            }
            
            .sms-stat-card {
                padding: 1rem;
            }
            
            .sms-stat-icon {
                width: 40px;
                height: 40px;
                font-size: 1rem;
                margin-bottom: 0.75rem;
            }
            
            .sms-stat-value {
                font-size: 1.5rem;
            }
            
            .sms-stat-label {
                font-size: 0.75rem;
            }
            
            .action-item {
                padding: 0.75rem;
            }
            
            .action-icon {
                width: 36px;
                height: 36px;
                font-size: 0.875rem;
            }
            
            .action-name {
                font-size: 0.875rem;
            }
        }
        
        @media (max-width: 575px) {
            .sms-header {
                padding: 1rem;
            }
            
            .sms-stat-value {
                font-size: 1.375rem;
            }
        }
        
        /* Color utilities - using system colors */
        .bg-primary-light { background: #e0f2fe; color: #0a6286; }
        .bg-green-light { background: #d1fae5; color: #059669; }
        .bg-red-light { background: #fee2e2; color: #dc2626; }
        .bg-yellow-light { background: #fef3c7; color: #d97706; }
        .bg-blue-light { background: #dbeafe; color: #0a6286; }
        .bg-gray-light { background: #f1f5f9; color: #475569; }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                
                <!-- SMS Header -->
                <div class="sms-header">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                        <div>
                            <h1><i class="fas fa-comments me-2"></i>SMS Dashboard</h1>
                            <p>Manage SMS communications, templates, and automated reminders</p>
                        </div>
                        <div>
                            <?php if ($provider_status): ?>
                                <span class="provider-badge connected">
                                    <i class="fas fa-check-circle"></i>
                                    <?php echo htmlspecialchars($provider_status['display_name'] ?? 'Provider'); ?> Connected
                                </span>
                            <?php else: ?>
                                <span class="provider-badge disconnected">
                                    <i class="fas fa-exclamation-circle"></i>
                                    No Provider Configured
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!$provider_status): ?>
                    <div class="setup-alert">
                        <h5><i class="fas fa-cog me-2"></i>Setup Required</h5>
                        <p>Configure an SMS provider (Twilio, Textlocal, etc.) to start sending messages. Go to <a href="settings.php" class="fw-bold">SMS Settings</a> to add your provider credentials.</p>
                    </div>
                <?php endif; ?>
                
                <!-- Stats Grid -->
                <div class="row g-3 mb-4">
                    <!-- Today Sent -->
                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="sms-stat-card">
                            <div class="sms-stat-icon bg-primary-light">
                                <i class="fas fa-paper-plane"></i>
                            </div>
                            <div class="sms-stat-value text-primary"><?php echo number_format($stats['today_sent']); ?></div>
                            <div class="sms-stat-label">Sent Today</div>
                        </div>
                    </div>
                    
                    <!-- Delivered -->
                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="sms-stat-card">
                            <div class="sms-stat-icon bg-green-light">
                                <i class="fas fa-check-double"></i>
                            </div>
                            <div class="sms-stat-value text-green"><?php echo number_format($stats['today_delivered']); ?></div>
                            <div class="sms-stat-label">Delivered</div>
                            <?php if ($stats['today_sent'] > 0): ?>
                                <div class="sms-stat-trend text-success">
                                    <i class="fas fa-chart-line"></i> <?php echo $stats['delivery_rate']; ?>% rate
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Failed -->
                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="sms-stat-card">
                            <div class="sms-stat-icon bg-red-light">
                                <i class="fas fa-times-circle"></i>
                            </div>
                            <div class="sms-stat-value text-red"><?php echo number_format($stats['today_failed']); ?></div>
                            <div class="sms-stat-label">Failed</div>
                        </div>
                    </div>
                    
                    <!-- Pending Queue -->
                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="sms-stat-card">
                            <div class="sms-stat-icon bg-yellow-light">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="sms-stat-value text-yellow"><?php echo number_format($stats['pending_queue']); ?></div>
                            <div class="sms-stat-label">In Queue</div>
                        </div>
                    </div>
                    
                    <!-- Today Cost -->
                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="sms-stat-card">
                            <div class="sms-stat-icon bg-blue-light">
                                <i class="fas fa-pound-sign"></i>
                            </div>
                            <div class="sms-stat-value text-blue"><?php echo $today_cost_display; ?></div>
                            <div class="sms-stat-label">Today's Cost</div>
                        </div>
                    </div>
                    
                    <!-- Month Stats -->
                    <div class="col-6 col-md-4 col-lg-2">
                        <div class="sms-stat-card">
                            <div class="sms-stat-icon bg-primary-light">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="sms-stat-value text-primary"><?php echo number_format($stats['month_sent']); ?></div>
                            <div class="sms-stat-label">This Month</div>
                            <div class="sms-stat-trend text-muted">
                                <i class="fas fa-pound-sign"></i> <?php echo $month_cost_display; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row g-3 g-lg-4">
                    <!-- Quick Actions -->
                    <div class="col-12 col-lg-4">
                        <div class="action-card h-100">
                            <div class="action-header">
                                <h2 class="action-title">
                                    <i class="fas fa-bolt text-warning me-2"></i>Quick Actions
                                </h2>
                            </div>
                            <div class="action-list">
                                <a href="send.php" class="action-item">
                                    <div class="action-icon bg-primary-light">
                                        <i class="fas fa-paper-plane"></i>
                                    </div>
                                    <div class="action-content">
                                        <p class="action-name">Send SMS</p>
                                        <p class="action-desc">Send a message to a donor</p>
                                    </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                </a>
                                
                                <a href="templates.php" class="action-item">
                                    <div class="action-icon bg-blue-light">
                                        <i class="fas fa-file-alt"></i>
                                    </div>
                                    <div class="action-content">
                                        <p class="action-name">Templates</p>
                                        <p class="action-desc"><?php echo $stats['templates_active']; ?> active templates</p>
                                    </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                </a>
                                
                                <a href="queue.php" class="action-item">
                                    <div class="action-icon bg-yellow-light">
                                        <i class="fas fa-list-ol"></i>
                                    </div>
                                    <div class="action-content">
                                        <p class="action-name">Message Queue</p>
                                        <p class="action-desc"><?php echo $stats['pending_queue']; ?> pending</p>
                                    </div>
                                    <?php if ($stats['pending_queue'] > 0): ?>
                                        <span class="badge bg-warning text-dark"><?php echo $stats['pending_queue']; ?></span>
                                    <?php endif; ?>
                                    <i class="fas fa-chevron-right text-muted ms-1"></i>
                                </a>
                                
                                <a href="history.php" class="action-item">
                                    <div class="action-icon bg-gray-light">
                                        <i class="fas fa-history"></i>
                                    </div>
                                    <div class="action-content">
                                        <p class="action-name">SMS History</p>
                                        <p class="action-desc">View all sent messages</p>
                                    </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                </a>
                                
                                <a href="blacklist.php" class="action-item">
                                    <div class="action-icon bg-red-light">
                                        <i class="fas fa-ban"></i>
                                    </div>
                                    <div class="action-content">
                                        <p class="action-name">Blacklist</p>
                                        <p class="action-desc"><?php echo $stats['blacklisted']; ?> blocked numbers</p>
                                    </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                </a>
                                
                                <a href="settings.php" class="action-item">
                                    <div class="action-icon bg-primary-light">
                                        <i class="fas fa-cog"></i>
                                    </div>
                                    <div class="action-content">
                                        <p class="action-name">Settings</p>
                                        <p class="action-desc">Provider & configuration</p>
                                    </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent SMS Activity -->
                    <div class="col-12 col-lg-8">
                        <div class="action-card h-100">
                            <div class="action-header">
                                <h2 class="action-title">
                                    <i class="fas fa-stream text-primary me-2"></i>Recent Activity
                                </h2>
                                <?php if (!empty($recent_sms)): ?>
                                    <a href="history.php" class="btn btn-sm btn-outline-primary">View All</a>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (empty($recent_sms)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-inbox empty-icon"></i>
                                    <h3 class="empty-title">No SMS Sent Yet</h3>
                                    <p class="empty-desc">When you send SMS messages, they'll appear here.</p>
                                    <a href="send.php" class="btn btn-primary">
                                        <i class="fas fa-paper-plane me-2"></i>Send First SMS
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="sms-log-list">
                                    <?php foreach ($recent_sms as $sms): ?>
                                        <div class="sms-log-item">
                                            <div class="d-flex align-items-start gap-3">
                                                <div>
                                                    <span class="sms-status <?php echo strtolower($sms['status']); ?>"></span>
                                                </div>
                                                <div class="flex-grow-1 min-width-0">
                                                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
                                                        <div>
                                                            <span class="fw-semibold">
                                                                <?php echo htmlspecialchars($sms['donor_name'] ?? 'Unknown'); ?>
                                                            </span>
                                                            <span class="text-muted ms-2" style="font-size: 0.8125rem;">
                                                                <?php echo htmlspecialchars($sms['phone_number']); ?>
                                                            </span>
                                                        </div>
                                                        <div class="text-end">
                                                            <span class="badge bg-<?php 
                                                                echo match(strtolower($sms['status'])) {
                                                                    'delivered' => 'success',
                                                                    'sent' => 'info',
                                                                    'failed' => 'danger',
                                                                    default => 'secondary'
                                                                };
                                                            ?>" style="font-size: 0.7rem;">
                                                                <?php echo ucfirst($sms['status']); ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <p class="text-muted mb-1 text-truncate" style="font-size: 0.8125rem;">
                                                        <?php echo htmlspecialchars(substr($sms['message_content'] ?? '', 0, 80)); ?>...
                                                    </p>
                                                    <div class="d-flex flex-wrap gap-2 align-items-center" style="font-size: 0.75rem;">
                                                        <span class="text-muted">
                                                            <i class="far fa-clock me-1"></i>
                                                            <?php echo date('M j, g:i A', strtotime($sms['sent_at'])); ?>
                                                        </span>
                                                        <?php if (!empty($sms['source_type'])): ?>
                                                            <span class="badge bg-light text-dark">
                                                                <?php echo ucwords(str_replace('_', ' ', $sms['source_type'])); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($sms['cost_pence'])): ?>
                                                            <span class="text-muted">
                                                                <i class="fas fa-pound-sign me-1"></i><?php echo number_format($sms['cost_pence'] / 100, 2); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Additional Info Row -->
                <div class="row g-3 mt-3">
                    <!-- Donors Overview -->
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="action-card">
                            <div class="action-header">
                                <h2 class="action-title">
                                    <i class="fas fa-users text-success me-2"></i>Donor SMS Status
                                </h2>
                            </div>
                            <div class="p-3">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="text-muted">SMS Opt-In</span>
                                    <span class="fw-bold text-success"><?php echo number_format($stats['donors_with_sms']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="text-muted">Blacklisted</span>
                                    <span class="fw-bold text-danger"><?php echo number_format($stats['blacklisted']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted">Reminders Due (3-day)</span>
                                    <span class="fw-bold text-warning"><?php echo number_format($stats['reminders_due_today']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pending Queue Preview -->
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="action-card">
                            <div class="action-header">
                                <h2 class="action-title">
                                    <i class="fas fa-hourglass-half text-warning me-2"></i>Pending Queue
                                </h2>
                                <?php if (!empty($queue_items)): ?>
                                    <a href="queue.php" class="btn btn-sm btn-outline-warning">View All</a>
                                <?php endif; ?>
                            </div>
                            <?php if (empty($queue_items)): ?>
                                <div class="p-4 text-center text-muted">
                                    <i class="fas fa-check-circle fa-2x mb-2 text-success"></i>
                                    <p class="mb-0">Queue is empty</p>
                                </div>
                            <?php else: ?>
                                <div class="p-2">
                                    <?php foreach ($queue_items as $item): ?>
                                        <div class="d-flex align-items-center gap-2 p-2 border-bottom">
                                            <div class="flex-grow-1">
                                                <div class="fw-semibold" style="font-size: 0.875rem;">
                                                    <?php echo htmlspecialchars($item['donor_name'] ?? $item['phone_number']); ?>
                                                </div>
                                                <div class="text-muted" style="font-size: 0.75rem;">
                                                    <?php echo ucwords(str_replace('_', ' ', $item['source_type'] ?? 'manual')); ?>
                                                </div>
                                            </div>
                                            <span class="badge bg-warning text-dark">P<?php echo $item['priority'] ?? 5; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Templates Overview -->
                    <div class="col-12 col-lg-4">
                        <div class="action-card">
                            <div class="action-header">
                                <h2 class="action-title">
                                    <i class="fas fa-file-alt text-info me-2"></i>Templates
                                </h2>
                                <a href="templates.php" class="btn btn-sm btn-outline-info">Manage</a>
                            </div>
                            <div class="p-3">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="text-muted">Active Templates</span>
                                    <span class="fw-bold text-primary"><?php echo $stats['templates_active']; ?></span>
                                </div>
                                <div class="text-center py-3">
                                    <a href="templates.php?action=new" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-plus me-1"></i>Create Template
                                    </a>
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
<script src="../../assets/admin.js"></script>
</body>
</html>

