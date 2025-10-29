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
// Note: This is for PLEDGE donors only - people who promised to pay later
// Direct payment users are not tracked here as they already paid immediately
$stats = [
    'total_donors' => 0,
    'donors_with_phone' => 0,
    'donors_without_phone' => 0,
    'donors_with_pledges' => 0,
    'donors_fully_paid' => 0,
    'donors_partially_paid' => 0,
    'donors_not_paid' => 0,
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
    // Get basic donor statistics - PLEDGE DONORS ONLY
    // These are people who made pledges and need tracking
    $result = $db->query("
        SELECT 
            COUNT(*) as total_donors,
            COUNT(CASE WHEN phone IS NOT NULL AND phone != '' THEN 1 END) as donors_with_phone,
            COUNT(CASE WHEN phone IS NULL OR phone = '' THEN 1 END) as donors_without_phone,
            COUNT(CASE WHEN total_pledged > 0 THEN 1 END) as donors_with_pledges,
            COUNT(CASE WHEN total_paid >= total_pledged AND total_pledged > 0 THEN 1 END) as donors_fully_paid,
            COUNT(CASE WHEN total_paid > 0 AND total_paid < total_pledged THEN 1 END) as donors_partially_paid,
            COUNT(CASE WHEN total_paid = 0 AND total_pledged > 0 THEN 1 END) as donors_not_paid,
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
                            <i class="fas fa-database me-2"></i>Pledge Donor Tracking Report
                        </h1>
                        <p class="text-muted mb-0">Track donors who made pledges and monitor their payment progress</p>
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Note: Direct payment users are not shown here as they already paid immediately
                        </small>
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
                <div class="row g-4 mb-4">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card animate-fade-in">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="flex-grow-1">
                                        <div class="text-muted text-uppercase small fw-semibold mb-1">Pledge Donors</div>
                                        <div class="h2 mb-0 fw-bold"><?php echo number_format((int)$stats['total_donors']); ?></div>
                                        <small class="text-muted">Need tracking</small>
                                    </div>
                                    <div class="ms-3">
                                        <div class="text-primary" style="font-size: 2.5rem;">
                                            <i class="fas fa-users"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card animate-fade-in" style="animation-delay: 0.1s;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="flex-grow-1">
                                        <div class="text-muted text-uppercase small fw-semibold mb-1">Total Pledged</div>
                                        <div class="h2 mb-0 fw-bold text-warning"><?php echo $currency; ?><?php echo number_format((float)$stats['total_pledged'], 2); ?></div>
                                    </div>
                                    <div class="ms-3">
                                        <div class="text-warning" style="font-size: 2.5rem;">
                                            <i class="fas fa-hand-holding-usd"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card animate-fade-in" style="animation-delay: 0.2s;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="flex-grow-1">
                                        <div class="text-muted text-uppercase small fw-semibold mb-1">Total Paid</div>
                                        <div class="h2 mb-0 fw-bold text-success"><?php echo $currency; ?><?php echo number_format((float)$stats['total_paid'], 2); ?></div>
                                    </div>
                                    <div class="ms-3">
                                        <div class="text-success" style="font-size: 2.5rem;">
                                            <i class="fas fa-check-circle"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="card animate-fade-in" style="animation-delay: 0.3s;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="flex-grow-1">
                                        <div class="text-muted text-uppercase small fw-semibold mb-1">Outstanding Balance</div>
                                        <div class="h2 mb-0 fw-bold text-danger"><?php echo $currency; ?><?php echo number_format((float)$stats['total_balance'], 2); ?></div>
                                    </div>
                                    <div class="ms-3">
                                        <div class="text-danger" style="font-size: 2.5rem;">
                                            <i class="fas fa-exclamation-circle"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <!-- Payment Status Breakdown -->
                    <div class="col-12 col-lg-6">
                        <div class="card animate-fade-in" style="animation-delay: 0.4s;">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
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
                        <div class="card animate-fade-in" style="animation-delay: 0.5s;">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-trophy text-warning me-2"></i>
                                    Achievement Badges
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php 
                                $badge_config = [
                                    'pending' => ['icon' => 'fa-circle', 'color' => 'secondary'],
                                    'started' => ['icon' => 'fa-play-circle', 'color' => 'info'],
                                    'on_track' => ['icon' => 'fa-sync-alt', 'color' => 'primary'],
                                    'fast_finisher' => ['icon' => 'fa-forward', 'color' => 'success'],
                                    'completed' => ['icon' => 'fa-check-circle', 'color' => 'success'],
                                    'champion' => ['icon' => 'fa-trophy', 'color' => 'warning']
                                ];
                                foreach ($achievement_badge_breakdown as $badge): 
                                    $count = (int)$badge['count'];
                                    $percentage = $stats['total_donors'] > 0 ? ($count / $stats['total_donors']) * 100 : 0;
                                    $badge_name = $badge['achievement_badge'];
                                    $badge_data = $badge_config[$badge_name] ?? ['icon' => 'fa-circle', 'color' => 'secondary'];
                                ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span>
                                            <i class="fas <?php echo $badge_data['icon']; ?> text-<?php echo $badge_data['color']; ?> me-2"></i>
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
                        <div class="card animate-fade-in" style="animation-delay: 0.6s;">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-clipboard-check text-success me-2"></i>
                                    Data Quality Metrics
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php
                                $phone_percentage = $stats['total_donors'] > 0 ? ((int)$stats['donors_with_phone'] / $stats['total_donors']) * 100 : 0;
                                $phone_color = $phone_percentage >= 95 ? 'success' : ($phone_percentage >= 80 ? 'warning' : 'danger');
                                ?>
                                <div class="border-start border-4 border-<?php echo $phone_color; ?> ps-3 mb-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1 fw-semibold">Phone Numbers</h6>
                                            <p class="mb-0 small text-muted">
                                                <?php echo number_format((int)$stats['donors_with_phone']); ?> of <?php echo number_format((int)$stats['total_donors']); ?> pledge donors have phone numbers
                                            </p>
                                        </div>
                                        <div class="text-end">
                                            <h4 class="mb-0 text-<?php echo $phone_color; ?>"><?php echo number_format($phone_percentage, 1); ?>%</h4>
                                        </div>
                                    </div>
                                </div>

                                <?php
                                $fully_paid_percentage = $stats['total_donors'] > 0 ? ((int)$stats['donors_fully_paid'] / $stats['total_donors']) * 100 : 0;
                                ?>
                                <div class="border-start border-4 border-success ps-3 mb-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1 fw-semibold">Fully Paid Pledges</h6>
                                            <p class="mb-0 small text-muted">
                                                <?php echo number_format((int)$stats['donors_fully_paid']); ?> pledge donors have paid in full
                                            </p>
                                        </div>
                                        <div class="text-end">
                                            <h4 class="mb-0 text-success"><?php echo number_format($fully_paid_percentage, 1); ?>%</h4>
                                        </div>
                                    </div>
                                </div>

                                <?php
                                $not_paid_percentage = $stats['total_donors'] > 0 ? ((int)$stats['donors_not_paid'] / $stats['total_donors']) * 100 : 0;
                                ?>
                                <div class="border-start border-4 border-danger ps-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1 fw-semibold">Not Yet Paid</h6>
                                            <p class="mb-0 small text-muted">
                                                <?php echo number_format((int)$stats['donors_not_paid']); ?> pledge donors haven't made any payment yet
                                            </p>
                                        </div>
                                        <div class="text-end">
                                            <h4 class="mb-0 text-danger"><?php echo number_format($not_paid_percentage, 1); ?>%</h4>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Statistics -->
                    <div class="col-12 col-lg-6">
                        <div class="card animate-fade-in" style="animation-delay: 0.7s;">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-chart-bar text-info me-2"></i>
                                    Additional Statistics
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-6">
                                        <div class="text-center p-3 border rounded">
                                            <h3 class="mb-1 text-primary fw-bold"><?php echo number_format((int)$stats['donors_with_active_plans']); ?></h3>
                                            <div class="text-muted small">Active Payment Plans</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center p-3 border rounded">
                                            <h3 class="mb-1 text-success fw-bold"><?php echo number_format((int)$stats['donors_with_portal_access']); ?></h3>
                                            <div class="text-muted small">Portal Access</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center p-3 border rounded">
                                            <h3 class="mb-1 text-info fw-bold"><?php echo number_format((int)$stats['donors_sms_opted_in']); ?></h3>
                                            <div class="text-muted small">SMS Opt-In</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center p-3 border rounded">
                                            <h3 class="mb-1 text-warning fw-bold"><?php echo number_format((int)$stats['donors_flagged']); ?></h3>
                                            <div class="text-muted small">Flagged for Follow-up</div>
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
                        <div class="card animate-fade-in" style="animation-delay: 0.8s;">
                            <div class="card-header">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-door-open text-primary me-2"></i>
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
                        <div class="card animate-fade-in" style="animation-delay: 0.9s;">
                            <div class="card-header">
                                <h6 class="card-title mb-0">
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
                        <div class="card animate-fade-in" style="animation-delay: 1s;">
                            <div class="card-header">
                                <h6 class="card-title mb-0">
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
                <div class="card animate-fade-in" style="animation-delay: 1.1s;">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-clock text-info me-2"></i>
                            Recent Pledge Donors (Last 10)
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
                                            <?php 
                                            $donor_badge = $badge_config[$donor['achievement_badge']] ?? ['icon' => 'fa-circle', 'color' => 'secondary'];
                                            ?>
                                            <i class="fas <?php echo $donor_badge['icon']; ?> text-<?php echo $donor_badge['color']; ?>"></i>
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
                <div class="card">
                    <div class="card-body text-center py-5">
                        <div class="mb-4">
                            <i class="fas fa-database text-muted" style="font-size: 4rem;"></i>
                        </div>
                        <h3 class="mb-3">Pledge Donor System Not Found</h3>
                        <p class="text-muted mb-4">
                            The pledge donor tracking system has not been set up yet. Run the database migration to create the necessary tables.
                        </p>
                        <div class="alert alert-info text-start mb-4">
                            <h6 class="alert-heading">
                                <i class="fas fa-info-circle me-2"></i>What is this system for?
                            </h6>
                            <p class="mb-0 small">
                                This system tracks <strong>pledge donors</strong> - people who promised to pay later and need payment tracking, reminders, and payment plans.
                                <br>
                                Direct payment users who pay immediately are NOT tracked here as they're already completed transactions.
                            </p>
                        </div>
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

