<?php
/**
 * Financial Dashboard - Comprehensive Visual Analytics
 * 
 * 100% database-driven financial reporting with interactive charts.
 * Mobile-first responsive design.
 * 
 * @author Fundraising System
 */

declare(strict_types=1);

require_once '../../config/db.php';
require_once '../../shared/auth.php';
require_once '../../shared/FinancialCalculator.php';

require_login();
require_admin();

$current_user = current_user();
$db = db();

// Get settings for currency
$settings = $db->query("SELECT target_amount, currency_code FROM settings WHERE id=1")->fetch_assoc() 
    ?: ['target_amount' => 0, 'currency_code' => 'GBP'];
$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
$currencySymbol = $currency === 'GBP' ? '£' : $currency;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#2563eb">
    <title>Financial Dashboard - Fundraising System</title>
    
    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/financial-dashboard.css">
    
    <!-- Favicon -->
    <link rel="icon" href="../../assets/favicon.svg" type="image/svg+xml">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="admin-wrapper">
        <?php include '../includes/sidebar.php'; ?>
        <div class="admin-content">
            <?php include '../includes/topbar.php'; ?>
            
            <main class="financial-dashboard">
                <div class="fd-container">
                    
                    <!-- Header -->
                    <header class="fd-header">
                        <h1 class="fd-title">
                            <span class="fd-title-icon">
                                <i class="fas fa-chart-line"></i>
                            </span>
                            Financial Dashboard
                        </h1>
                        <p class="fd-subtitle">Real-time financial analytics and insights</p>
                        <p class="fd-updated">Last updated: <span id="last-updated">Loading...</span></p>
                    </header>
                    
                    <!-- Controls Bar -->
                    <div class="fd-controls">
                        <div class="fd-date-filters">
                            <button class="fd-filter-btn active" data-range="all">All Time</button>
                            <button class="fd-filter-btn" data-range="today">Today</button>
                            <button class="fd-filter-btn" data-range="week">This Week</button>
                            <button class="fd-filter-btn" data-range="month">This Month</button>
                            <button class="fd-filter-btn" data-range="quarter">Quarter</button>
                            <button class="fd-filter-btn" data-range="year">This Year</button>
                        </div>
                        <div class="fd-custom-dates">
                            <input type="date" class="fd-date-input" id="date-from" placeholder="From">
                            <input type="date" class="fd-date-input" id="date-to" placeholder="To">
                            <button class="fd-btn fd-btn-primary" id="btn-apply-dates">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                        </div>
                        <div class="fd-action-buttons">
                            <button class="fd-btn fd-btn-outline" id="btn-refresh">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                            <button class="fd-btn fd-btn-outline active" id="btn-auto-refresh">
                                <i class="fas fa-sync-alt fa-spin"></i> Auto
                            </button>
                            <a href="index.php?report=financial&format=csv" class="fd-btn fd-btn-success" data-export="csv">
                                <i class="fas fa-download"></i> Export
                            </a>
                        </div>
                    </div>
                    
                    <!-- KPI Cards -->
                    <div class="fd-kpi-grid">
                        <div class="fd-kpi-card kpi-paid" id="kpi-paid">
                            <div class="fd-kpi-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="fd-kpi-label">Total Paid</div>
                            <div class="fd-kpi-value">
                                <div class="fd-skeleton fd-skeleton-value"></div>
                            </div>
                            <div class="fd-kpi-sub">Loading...</div>
                        </div>
                        
                        <div class="fd-kpi-card kpi-pledged" id="kpi-pledged">
                            <div class="fd-kpi-icon">
                                <i class="fas fa-hand-holding-heart"></i>
                            </div>
                            <div class="fd-kpi-label">Total Pledged</div>
                            <div class="fd-kpi-value">
                                <div class="fd-skeleton fd-skeleton-value"></div>
                            </div>
                            <div class="fd-kpi-sub">Loading...</div>
                        </div>
                        
                        <div class="fd-kpi-card kpi-outstanding" id="kpi-outstanding">
                            <div class="fd-kpi-icon">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                            <div class="fd-kpi-label">Outstanding</div>
                            <div class="fd-kpi-value">
                                <div class="fd-skeleton fd-skeleton-value"></div>
                            </div>
                            <div class="fd-kpi-sub">Loading...</div>
                        </div>
                        
                        <div class="fd-kpi-card kpi-total" id="kpi-total">
                            <div class="fd-kpi-icon">
                                <i class="fas fa-coins"></i>
                            </div>
                            <div class="fd-kpi-label">Grand Total</div>
                            <div class="fd-kpi-value">
                                <div class="fd-skeleton fd-skeleton-value"></div>
                            </div>
                            <div class="fd-kpi-sub">Loading...</div>
                        </div>
                        
                        <div class="fd-kpi-card kpi-donors" id="kpi-donors">
                            <div class="fd-kpi-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="fd-kpi-label">Total Donors</div>
                            <div class="fd-kpi-value">
                                <div class="fd-skeleton fd-skeleton-value"></div>
                            </div>
                            <div class="fd-kpi-sub">Loading...</div>
                        </div>
                        
                        <div class="fd-kpi-card kpi-rate" id="kpi-rate">
                            <div class="fd-kpi-icon">
                                <i class="fas fa-percentage"></i>
                            </div>
                            <div class="fd-kpi-label">Collection Rate</div>
                            <div class="fd-kpi-value">
                                <div class="fd-skeleton fd-skeleton-value"></div>
                            </div>
                            <div class="fd-kpi-sub">Loading...</div>
                        </div>
                    </div>
                    
                    <!-- Progress Bar -->
                    <div class="fd-progress-section">
                        <div class="fd-progress-header">
                            <span class="fd-progress-title">
                                <i class="fas fa-bullseye me-2"></i>Campaign Progress
                            </span>
                            <span class="fd-progress-value">0%</span>
                        </div>
                        <div class="fd-progress-bar">
                            <div class="fd-progress-fill" style="width: 0%"></div>
                        </div>
                        <div class="fd-progress-labels">
                            <span id="progress-current"><?php echo $currencySymbol; ?>0</span>
                            <span id="progress-target">Target: <?php echo $currencySymbol . number_format((float)$settings['target_amount'], 0); ?></span>
                        </div>
                    </div>
                    
                    <!-- Charts Grid -->
                    <div class="fd-charts-grid">
                        
                        <!-- Monthly Trends -->
                        <div class="fd-chart-card fd-chart-full">
                            <div class="fd-chart-header">
                                <h3 class="fd-chart-title">
                                    <i class="fas fa-chart-line"></i>
                                    Monthly Trends
                                </h3>
                                <div class="fd-chart-actions">
                                    <button class="fd-chart-action" title="Fullscreen">
                                        <i class="fas fa-expand"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="fd-chart-body">
                                <canvas id="chart-monthly-trends" class="fd-chart-canvas"></canvas>
                            </div>
                        </div>
                        
                        <!-- Daily Trends -->
                        <div class="fd-chart-card fd-chart-full">
                            <div class="fd-chart-header">
                                <h3 class="fd-chart-title">
                                    <i class="fas fa-chart-bar"></i>
                                    Last 30 Days
                                </h3>
                            </div>
                            <div class="fd-chart-body">
                                <canvas id="chart-daily-trends" class="fd-chart-canvas"></canvas>
                            </div>
                        </div>
                        
                        <!-- Payment Methods -->
                        <div class="fd-chart-card">
                            <div class="fd-chart-header">
                                <h3 class="fd-chart-title">
                                    <i class="fas fa-credit-card"></i>
                                    Payment Methods
                                </h3>
                            </div>
                            <div class="fd-chart-body">
                                <canvas id="chart-payment-methods" class="fd-chart-canvas"></canvas>
                            </div>
                        </div>
                        
                        <!-- Package Distribution -->
                        <div class="fd-chart-card">
                            <div class="fd-chart-header">
                                <h3 class="fd-chart-title">
                                    <i class="fas fa-box"></i>
                                    Donation Packages
                                </h3>
                            </div>
                            <div class="fd-chart-body">
                                <canvas id="chart-packages" class="fd-chart-canvas"></canvas>
                            </div>
                        </div>
                        
                        <!-- Pledge Status -->
                        <div class="fd-chart-card">
                            <div class="fd-chart-header">
                                <h3 class="fd-chart-title">
                                    <i class="fas fa-file-invoice"></i>
                                    Pledge Status
                                </h3>
                            </div>
                            <div class="fd-chart-body">
                                <canvas id="chart-pledge-status" class="fd-chart-canvas"></canvas>
                            </div>
                        </div>
                        
                        <!-- Donor Payment Status -->
                        <div class="fd-chart-card">
                            <div class="fd-chart-header">
                                <h3 class="fd-chart-title">
                                    <i class="fas fa-user-check"></i>
                                    Donor Status
                                </h3>
                            </div>
                            <div class="fd-chart-body">
                                <canvas id="chart-donor-status" class="fd-chart-canvas"></canvas>
                            </div>
                        </div>
                        
                        <!-- Grid Allocation -->
                        <div class="fd-chart-card">
                            <div class="fd-chart-header">
                                <h3 class="fd-chart-title">
                                    <i class="fas fa-th"></i>
                                    Floor Grid Status
                                </h3>
                            </div>
                            <div class="fd-chart-body">
                                <canvas id="chart-grid-status" class="fd-chart-canvas"></canvas>
                            </div>
                        </div>
                        
                        <!-- Payment Plans -->
                        <div class="fd-chart-card">
                            <div class="fd-chart-header">
                                <h3 class="fd-chart-title">
                                    <i class="fas fa-calendar-alt"></i>
                                    Payment Plans
                                </h3>
                            </div>
                            <div class="fd-chart-body">
                                <canvas id="chart-payment-plans" class="fd-chart-canvas"></canvas>
                            </div>
                        </div>
                        
                        <!-- Top Donors Table -->
                        <div class="fd-chart-card">
                            <div class="fd-chart-header">
                                <h3 class="fd-chart-title">
                                    <i class="fas fa-trophy"></i>
                                    Top 10 Donors
                                </h3>
                            </div>
                            <div class="fd-chart-body" id="table-top-donors">
                                <div class="fd-loading">
                                    <div class="fd-spinner"></div>
                                    <div class="fd-loading-text">Loading donors...</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Recent Transactions -->
                        <div class="fd-chart-card">
                            <div class="fd-chart-header">
                                <h3 class="fd-chart-title">
                                    <i class="fas fa-clock"></i>
                                    Recent Transactions
                                </h3>
                            </div>
                            <div class="fd-chart-body" id="table-recent">
                                <div class="fd-loading">
                                    <div class="fd-spinner"></div>
                                    <div class="fd-loading-text">Loading transactions...</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Church Distribution -->
                        <div class="fd-chart-card">
                            <div class="fd-chart-header">
                                <h3 class="fd-chart-title">
                                    <i class="fas fa-church"></i>
                                    By Church
                                </h3>
                            </div>
                            <div class="fd-chart-body" id="table-churches">
                                <div class="fd-loading">
                                    <div class="fd-spinner"></div>
                                    <div class="fd-loading-text">Loading churches...</div>
                                </div>
                            </div>
                        </div>
                        
                    </div>
                    
                </div>
            </main>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/admin.js"></script>
    <script src="assets/financial-dashboard.js"></script>
</body>
</html>

