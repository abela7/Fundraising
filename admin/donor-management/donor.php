<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

// Resiliently load settings and check for DB errors
require_once __DIR__ . '/../includes/resilient_db_loader.php';

$page_title = 'Donor Database Report';
$current_user = current_user();
$db = db();

// Check if donors table exists
$donors_table_exists = $db->query("SHOW TABLES LIKE 'donors'")->num_rows > 0;

if (!$donors_table_exists) {
    $error_message = "The 'donors' table does not exist. Please run the database migration first.";
    $show_migration_link = true;
}

// Initialize statistics arrays
$stats = [
    'total_donors' => 0,
    'donors_with_phone' => 0,
    'donors_without_phone' => 0,
    'donors_with_pledges' => 0,
    'donors_without_pledges' => 0,
    'donors_with_payments' => 0,
    'donors_without_payments' => 0,
    'total_pledged' => 0.0,
    'total_paid' => 0.0,
    'total_balance' => 0.0,
    'donors_with_active_plans' => 0,
    'donors_with_portal_access' => 0,
    'donors_sms_opted_in' => 0,
    'donors_flagged' => 0,
];

$payment_status_breakdown = [];
$achievement_badge_breakdown = [];
$source_breakdown = [];
$language_breakdown = [];
$payment_method_breakdown = [];
$recent_donors = [];

if ($donors_table_exists) {
    // Get basic donor statistics
    $result = $db->query("
        SELECT 
            COUNT(*) as total_donors,
            COUNT(CASE WHEN phone IS NOT NULL AND phone != '' THEN 1 END) as donors_with_phone,
            COUNT(CASE WHEN phone IS NULL OR phone = '' THEN 1 END) as donors_without_phone,
            COUNT(CASE WHEN total_pledged > 0 THEN 1 END) as donors_with_pledges,
            COUNT(CASE WHEN total_pledged = 0 THEN 1 END) as donors_without_pledges,
            COUNT(CASE WHEN total_paid > 0 THEN 1 END) as donors_with_payments,
            COUNT(CASE WHEN total_paid = 0 THEN 1 END) as donors_without_payments,
            COALESCE(SUM(total_pledged), 0) as total_pledged,
            COALESCE(SUM(total_paid), 0) as total_paid,
            COALESCE(SUM(balance), 0) as total_balance,
            COUNT(CASE WHEN has_active_plan = 1 THEN 1 END) as donors_with_active_plans,
            COUNT(CASE WHEN portal_token IS NOT NULL THEN 1 END) as donors_with_portal_access,
            COUNT(CASE WHEN sms_opt_in = 1 THEN 1 END) as donors_sms_opted_in,
            COUNT(CASE WHEN flagged_for_followup = 1 THEN 1 END) as donors_flagged
        FROM donors
    ");
    
    if ($result) {
        $stats = array_merge($stats, $result->fetch_assoc());
    }
    
    // Payment status breakdown
    $result = $db->query("
        SELECT payment_status, COUNT(*) as count 
        FROM donors 
        GROUP BY payment_status 
        ORDER BY FIELD(payment_status, 'no_pledge', 'not_started', 'paying', 'overdue', 'completed', 'defaulted')
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $payment_status_breakdown[] = $row;
        }
    }
    
    // Achievement badge breakdown
    $result = $db->query("
        SELECT achievement_badge, COUNT(*) as count 
        FROM donors 
        GROUP BY achievement_badge 
        ORDER BY FIELD(achievement_badge, 'pending', 'started', 'on_track', 'fast_finisher', 'completed', 'champion')
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $achievement_badge_breakdown[] = $row;
        }
    }
    
    // Source breakdown
    $result = $db->query("
        SELECT source, COUNT(*) as count 
        FROM donors 
        GROUP BY source 
        ORDER BY count DESC
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $source_breakdown[] = $row;
        }
    }
    
    // Language preferences
    $result = $db->query("
        SELECT preferred_language, COUNT(*) as count 
        FROM donors 
        GROUP BY preferred_language 
        ORDER BY count DESC
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $language_breakdown[] = $row;
        }
    }
    
    // Payment method preferences
    $result = $db->query("
        SELECT preferred_payment_method, COUNT(*) as count 
        FROM donors 
        GROUP BY preferred_payment_method 
        ORDER BY count DESC
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $payment_method_breakdown[] = $row;
        }
    }
    
    // Recent donors
    $result = $db->query("
        SELECT 
            id, name, phone, total_pledged, total_paid, balance, 
            payment_status, achievement_badge, created_at 
        FROM donors 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    if ($result) {
        $recent_donors = $result->fetch_all(MYSQLI_ASSOC);
    }
}

// Get currency from settings
$settings = $db->query('SELECT currency_code FROM settings WHERE id=1')->fetch_assoc();
$currency = $settings['currency_code'] ?? 'GBP';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Donor Database Report - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/donor-management.css">
    <style>
        .stat-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        .stat-icon {
            font-size: 2rem;
            opacity: 0.8;
        }
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .chart-bar {
            height: 30px;
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        .data-quality-item {
            padding: 1rem;
            border-left: 4px solid #dee2e6;
            margin-bottom: 1rem;
            background: #f8f9fa;
        }
        .data-quality-item.good { border-left-color: #28a745; }
        .data-quality-item.warning { border-left-color: #ffc107; }
        .data-quality-item.danger { border-left-color: #dc3545; }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                <?php include '../includes/db_error_banner.php'; ?>
                
                <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Error:</strong> <?php echo htmlspecialchars($error_message); ?>
                    <?php if (isset($show_migration_link)): ?>
                    <br><a href="../tools/migrate_donors_system.php" class="alert-link">Click here to run the migration</a>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1 text-primary">
                            <i class="fas fa-database me-2"></i>Donor Database Report
                        </h1>
                        <p class="text-muted mb-0">Comprehensive overview and data quality verification</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-primary" onclick="window.print()">
                            <i class="fas fa-print me-2"></i>Print Report
                        </button>
                        <button class="btn btn-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt me-2"></i>Refresh Data
                        </button>
                    </div>
                </div>

                <?php if ($donors_table_exists): ?>
                
                <!-- Summary Statistics -->
                <div class="row g-3 mb-4">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card stat-card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="text-muted mb-2">Total Donors</h6>
                                        <h2 class="mb-0"><?php echo number_format((int)$stats['total_donors']); ?></h2>
                                    </div>
                                    <div class="stat-icon text-primary">
                                        <i class="fas fa-users"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card stat-card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="text-muted mb-2">Total Pledged</h6>
                                        <h2 class="mb-0 text-warning"><?php echo $currency; ?><?php echo number_format((float)$stats['total_pledged'], 2); ?></h2>
                                    </div>
                                    <div class="stat-icon text-warning">
                                        <i class="fas fa-hand-holding-usd"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card stat-card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="text-muted mb-2">Total Paid</h6>
                                        <h2 class="mb-0 text-success"><?php echo $currency; ?><?php echo number_format((float)$stats['total_paid'], 2); ?></h2>
                                    </div>
                                    <div class="stat-icon text-success">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card stat-card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="text-muted mb-2">Outstanding Balance</h6>
                                        <h2 class="mb-0 text-danger"><?php echo $currency; ?><?php echo number_format((float)$stats['total_balance'], 2); ?></h2>
                                    </div>
                                    <div class="stat-icon text-danger">
                                        <i class="fas fa-exclamation-circle"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <!-- Payment Status Breakdown -->
                    <div class="col-12 col-lg-6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-chart-pie text-primary me-2"></i>
                                    Payment Status Breakdown
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php 
                                $status_icons = [
                                    'no_pledge' => ['icon' => 'fa-minus-circle', 'color' => 'secondary'],
                                    'not_started' => ['icon' => 'fa-hourglass-start', 'color' => 'info'],
                                    'paying' => ['icon' => 'fa-spinner', 'color' => 'primary'],
                                    'overdue' => ['icon' => 'fa-exclamation-triangle', 'color' => 'warning'],
                                    'completed' => ['icon' => 'fa-check-circle', 'color' => 'success'],
                                    'defaulted' => ['icon' => 'fa-times-circle', 'color' => 'danger']
                                ];
                                foreach ($payment_status_breakdown as $status): 
                                    $count = (int)$status['count'];
                                    $percentage = $stats['total_donors'] > 0 ? ($count / $stats['total_donors']) * 100 : 0;
                                    $status_name = $status['payment_status'];
                                    $status_config = $status_icons[$status_name] ?? ['icon' => 'fa-circle', 'color' => 'secondary'];
                                ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span>
                                            <i class="fas <?php echo $status_config['icon']; ?> text-<?php echo $status_config['color']; ?> me-2"></i>
                                            <?php echo ucwords(str_replace('_', ' ', $status_name)); ?>
                                        </span>
                                        <span class="fw-bold"><?php echo number_format($count); ?> (<?php echo number_format($percentage, 1); ?>%)</span>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-<?php echo $status_config['color']; ?>" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Achievement Badge Breakdown -->
                    <div class="col-12 col-lg-6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-trophy text-warning me-2"></i>
                                    Achievement Badges
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php 
                                $badge_config = [
                                    'pending' => ['icon' => '🔴', 'color' => 'secondary'],
                                    'started' => ['icon' => '🟡', 'color' => 'info'],
                                    'on_track' => ['icon' => '🔵', 'color' => 'primary'],
                                    'fast_finisher' => ['icon' => '🟢', 'color' => 'success'],
                                    'completed' => ['icon' => '✅', 'color' => 'success'],
                                    'champion' => ['icon' => '⭐', 'color' => 'warning']
                                ];
                                foreach ($achievement_badge_breakdown as $badge): 
                                    $count = (int)$badge['count'];
                                    $percentage = $stats['total_donors'] > 0 ? ($count / $stats['total_donors']) * 100 : 0;
                                    $badge_name = $badge['achievement_badge'];
                                    $badge_data = $badge_config[$badge_name] ?? ['icon' => '●', 'color' => 'secondary'];
                                ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span>
                                            <span class="me-2"><?php echo $badge_data['icon']; ?></span>
                                            <?php echo ucwords(str_replace('_', ' ', $badge_name)); ?>
                                        </span>
                                        <span class="fw-bold"><?php echo number_format($count); ?> (<?php echo number_format($percentage, 1); ?>%)</span>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-<?php echo $badge_data['color']; ?>" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Data Quality Metrics -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-lg-6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-clipboard-check text-success me-2"></i>
                                    Data Quality Metrics
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php
                                $phone_percentage = $stats['total_donors'] > 0 ? ((int)$stats['donors_with_phone'] / $stats['total_donors']) * 100 : 0;
                                $phone_quality = $phone_percentage >= 95 ? 'good' : ($phone_percentage >= 80 ? 'warning' : 'danger');
                                ?>
                                <div class="data-quality-item <?php echo $phone_quality; ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1">Phone Numbers</h6>
                                            <p class="mb-0 small text-muted">
                                                <?php echo number_format((int)$stats['donors_with_phone']); ?> of <?php echo number_format((int)$stats['total_donors']); ?> donors have phone numbers
                                            </p>
                                        </div>
                                        <div class="text-end">
                                            <h4 class="mb-0"><?php echo number_format($phone_percentage, 1); ?>%</h4>
                                        </div>
                                    </div>
                                </div>

                                <?php
                                $pledge_percentage = $stats['total_donors'] > 0 ? ((int)$stats['donors_with_pledges'] / $stats['total_donors']) * 100 : 0;
                                ?>
                                <div class="data-quality-item <?php echo $pledge_percentage > 0 ? 'good' : 'warning'; ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1">Donors with Pledges</h6>
                                            <p class="mb-0 small text-muted">
                                                <?php echo number_format((int)$stats['donors_with_pledges']); ?> donors have made pledges
                                            </p>
                                        </div>
                                        <div class="text-end">
                                            <h4 class="mb-0"><?php echo number_format($pledge_percentage, 1); ?>%</h4>
                                        </div>
                                    </div>
                                </div>

                                <?php
                                $payment_percentage = $stats['total_donors'] > 0 ? ((int)$stats['donors_with_payments'] / $stats['total_donors']) * 100 : 0;
                                ?>
                                <div class="data-quality-item <?php echo $payment_percentage > 0 ? 'good' : 'warning'; ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1">Donors with Payments</h6>
                                            <p class="mb-0 small text-muted">
                                                <?php echo number_format((int)$stats['donors_with_payments']); ?> donors have made payments
                                            </p>
                                        </div>
                                        <div class="text-end">
                                            <h4 class="mb-0"><?php echo number_format($payment_percentage, 1); ?>%</h4>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Statistics -->
                    <div class="col-12 col-lg-6">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-chart-bar text-info me-2"></i>
                                    Additional Statistics
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-6">
                                        <div class="text-center p-3 bg-light rounded">
                                            <h3 class="mb-1 text-primary"><?php echo number_format((int)$stats['donors_with_active_plans']); ?></h3>
                                            <small class="text-muted">Active Payment Plans</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center p-3 bg-light rounded">
                                            <h3 class="mb-1 text-success"><?php echo number_format((int)$stats['donors_with_portal_access']); ?></h3>
                                            <small class="text-muted">Portal Access</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center p-3 bg-light rounded">
                                            <h3 class="mb-1 text-info"><?php echo number_format((int)$stats['donors_sms_opted_in']); ?></h3>
                                            <small class="text-muted">SMS Opt-In</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center p-3 bg-light rounded">
                                            <h3 class="mb-1 text-warning"><?php echo number_format((int)$stats['donors_flagged']); ?></h3>
                                            <small class="text-muted">Flagged for Follow-up</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- More Detailed Breakdowns -->
                <div class="row g-4 mb-4">
                    <!-- Source Breakdown -->
                    <div class="col-12 col-md-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-source text-primary me-2"></i>
                                    Registration Source
                                </h6>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <?php foreach ($source_breakdown as $source): ?>
                                    <li class="mb-2 d-flex justify-content-between">
                                        <span class="text-capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $source['source'])); ?></span>
                                        <strong><?php echo number_format((int)$source['count']); ?></strong>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Language Preferences -->
                    <div class="col-12 col-md-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-language text-success me-2"></i>
                                    Language Preferences
                                </h6>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <?php 
                                    $lang_names = ['en' => 'English', 'am' => 'Amharic', 'ti' => 'Tigrinya'];
                                    foreach ($language_breakdown as $lang): 
                                    ?>
                                    <li class="mb-2 d-flex justify-content-between">
                                        <span><?php echo $lang_names[$lang['preferred_language']] ?? $lang['preferred_language']; ?></span>
                                        <strong><?php echo number_format((int)$lang['count']); ?></strong>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Method Preferences -->
                    <div class="col-12 col-md-4">
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-credit-card text-warning me-2"></i>
                                    Payment Method Preferences
                                </h6>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <?php foreach ($payment_method_breakdown as $method): ?>
                                    <li class="mb-2 d-flex justify-content-between">
                                        <span class="text-capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $method['preferred_payment_method'])); ?></span>
                                        <strong><?php echo number_format((int)$method['count']); ?></strong>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Donors Table -->
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">
                            <i class="fas fa-clock text-info me-2"></i>
                            Recent Donors (Last 10)
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Phone</th>
                                        <th>Pledged</th>
                                        <th>Paid</th>
                                        <th>Balance</th>
                                        <th>Status</th>
                                        <th>Badge</th>
                                        <th>Registered</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_donors as $donor): ?>
                                    <tr>
                                        <td><?php echo (int)$donor['id']; ?></td>
                                        <td><?php echo htmlspecialchars($donor['name']); ?></td>
                                        <td><?php echo htmlspecialchars($donor['phone']); ?></td>
                                        <td><?php echo $currency; ?><?php echo number_format((float)$donor['total_pledged'], 2); ?></td>
                                        <td><?php echo $currency; ?><?php echo number_format((float)$donor['total_paid'], 2); ?></td>
                                        <td><?php echo $currency; ?><?php echo number_format((float)$donor['balance'], 2); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $status_icons[$donor['payment_status']]['color'] ?? 'secondary'; ?>">
                                                <?php echo ucwords(str_replace('_', ' ', $donor['payment_status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span><?php echo $badge_config[$donor['achievement_badge']]['icon'] ?? '●'; ?></span>
                                        </td>
                                        <td><?php echo date('Y-m-d', strtotime($donor['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php else: ?>
                
                <!-- Show migration prompt if table doesn't exist -->
                <div class="card shadow-sm">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-database fa-4x text-muted mb-3"></i>
                        <h3 class="mb-3">Donor Database Not Found</h3>
                        <p class="text-muted mb-4">
                            The donor management system has not been set up yet. Run the database migration to create the necessary tables.
                        </p>
                        <a href="../tools/migrate_donors_system.php" class="btn btn-primary">
                            <i class="fas fa-play me-2"></i>Run Migration Now
                        </a>
                    </div>
                </div>
                
                <?php endif; ?>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/donor-management.js"></script>

<script>
// Fallback for sidebar toggle
if (typeof window.toggleSidebar !== 'function') {
  window.toggleSidebar = function() {
    var body = document.body;
    var sidebar = document.getElementById('sidebar');
    var overlay = document.querySelector('.sidebar-overlay');
    if (window.innerWidth <= 991.98) {
      if (sidebar) sidebar.classList.toggle('active');
      if (overlay) overlay.classList.toggle('active');
      body.style.overflow = (sidebar && sidebar.classList.contains('active')) ? 'hidden' : '';
    } else {
      body.classList.toggle('sidebar-collapsed');
    }
  };
}
</script>
</body>
</html>

