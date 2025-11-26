<?php
/**
 * Financial Dashboard - Real-time Financial Analytics
 * Mobile-first, fully responsive, 100% database-driven
 */
require_once '../../config/db.php';
require_once '../../shared/auth.php';
require_once '../../shared/FinancialCalculator.php';

require_login();
require_admin();

$current_user = current_user();
$db = db();

// Get settings
$settings = $db->query("SELECT target_amount, currency_code FROM settings WHERE id=1")->fetch_assoc() 
    ?: ['target_amount' => 0, 'currency_code' => 'GBP'];
$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
$currencySymbol = $currency === 'GBP' ? '£' : $currency;
$targetAmount = (float)($settings['target_amount'] ?? 0);

// Get financial totals using centralized calculator
$calculator = new FinancialCalculator();
$totals = $calculator->getTotals();

$totalPaid = $totals['total_paid'];
$outstandingPledged = $totals['outstanding_pledged'];
$grandTotal = $totals['grand_total'];
$instantPayments = $totals['instant_payments'];
$pledgePayments = $totals['pledge_payments'];

// Collection rate
$collectionRate = $grandTotal > 0 ? round(($totalPaid / $grandTotal) * 100, 1) : 0;

// Goal progress
$goalProgress = $targetAmount > 0 ? round(($grandTotal / $targetAmount) * 100, 1) : 0;

// Total donors (unique)
$donorCount = $db->query("SELECT COUNT(DISTINCT id) AS c FROM donors WHERE total_pledged > 0 OR total_paid > 0")->fetch_assoc()['c'] ?? 0;

// Total payment transactions
$paymentTransactions = $totals['total_payment_count'];

// Average payment amount
$avgPayment = $paymentTransactions > 0 ? $totalPaid / $paymentTransactions : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Financial Dashboard - Fundraising</title>
    
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/financial-dashboard.css">
    
    <!-- Favicon -->
    <link rel="icon" href="../../assets/favicon.svg" type="image/svg+xml">
</head>
<body>
    <div class="admin-wrapper">
        <?php include '../includes/sidebar.php'; ?>
        <div class="admin-content">
            <?php include '../includes/topbar.php'; ?>
            <main class="main-content">
                <div class="container-fluid px-2 px-md-4">
                    
                    <!-- Page Header -->
                    <div class="dashboard-header">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h1 class="h4 mb-1">
                                    <i class="fas fa-chart-line me-2"></i>Financial Dashboard
                                </h1>
                                <p class="text-muted mb-0 small">Real-time financial analytics</p>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-primary" onclick="refreshAllData()" id="refreshBtn">
                                    <i class="fas fa-sync-alt"></i>
                                    <span class="d-none d-sm-inline ms-1">Refresh</span>
                                </button>
                                <select class="form-select form-select-sm" id="dateRange" style="width: auto;">
                                    <option value="all">All Time</option>
                                    <option value="today">Today</option>
                                    <option value="week">This Week</option>
                                    <option value="month" selected>This Month</option>
                                    <option value="quarter">This Quarter</option>
                                    <option value="year">This Year</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Main KPI Cards - Mobile First Grid -->
                    <div class="kpi-grid">
                        <!-- Grand Total -->
                        <div class="kpi-card kpi-primary">
                            <div class="kpi-icon">
                                <i class="fas fa-coins"></i>
                            </div>
                            <div class="kpi-content">
                                <span class="kpi-label">Total Raised</span>
                                <span class="kpi-value" id="kpiGrandTotal"><?php echo $currencySymbol . number_format($grandTotal, 0); ?></span>
                                <span class="kpi-sub">
                                    <span class="text-success" id="kpiGoalProgress"><?php echo $goalProgress; ?>%</span> of goal
                                </span>
                            </div>
                        </div>
                        
                        <!-- Total Paid -->
                        <div class="kpi-card kpi-success">
                            <div class="kpi-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="kpi-content">
                                <span class="kpi-label">Cash Collected</span>
                                <span class="kpi-value" id="kpiTotalPaid"><?php echo $currencySymbol . number_format($totalPaid, 0); ?></span>
                                <span class="kpi-sub" id="kpiPaymentCount"><?php echo number_format($paymentTransactions); ?> payments</span>
                            </div>
                        </div>
                        
                        <!-- Outstanding -->
                        <div class="kpi-card kpi-warning">
                            <div class="kpi-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="kpi-content">
                                <span class="kpi-label">Outstanding</span>
                                <span class="kpi-value" id="kpiOutstanding"><?php echo $currencySymbol . number_format($outstandingPledged, 0); ?></span>
                                <span class="kpi-sub">Awaiting payment</span>
                            </div>
                        </div>
                        
                        <!-- Collection Rate -->
                        <div class="kpi-card kpi-info">
                            <div class="kpi-icon">
                                <i class="fas fa-percentage"></i>
                            </div>
                            <div class="kpi-content">
                                <span class="kpi-label">Collection Rate</span>
                                <span class="kpi-value" id="kpiCollectionRate"><?php echo $collectionRate; ?>%</span>
                                <div class="kpi-progress">
                                    <div class="kpi-progress-bar" id="kpiCollectionBar" style="width: <?php echo min($collectionRate, 100); ?>%"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Donors -->
                        <div class="kpi-card kpi-secondary">
                            <div class="kpi-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="kpi-content">
                                <span class="kpi-label">Total Donors</span>
                                <span class="kpi-value" id="kpiDonorCount"><?php echo number_format($donorCount); ?></span>
                                <span class="kpi-sub">Contributors</span>
                            </div>
                        </div>
                        
                        <!-- Average Payment -->
                        <div class="kpi-card kpi-accent">
                            <div class="kpi-icon">
                                <i class="fas fa-calculator"></i>
                            </div>
                            <div class="kpi-content">
                                <span class="kpi-label">Avg Payment</span>
                                <span class="kpi-value" id="kpiAvgPayment"><?php echo $currencySymbol . number_format($avgPayment, 0); ?></span>
                                <span class="kpi-sub">Per transaction</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Charts Section -->
                    <div class="charts-grid">
                        
                        <!-- Payment Trend Chart -->
                        <div class="chart-card chart-card-wide">
                            <div class="chart-header">
                                <h3 class="chart-title">
                                    <i class="fas fa-chart-area me-2"></i>Payment Trend
                                </h3>
                                <div class="chart-legend" id="trendLegend"></div>
                            </div>
                            <div class="chart-body">
                                <canvas id="paymentTrendChart"></canvas>
                            </div>
                        </div>
                        
                        <!-- Payment Methods Pie Chart -->
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">
                                    <i class="fas fa-wallet me-2"></i>Payment Methods
                                </h3>
                            </div>
                            <div class="chart-body chart-body-pie">
                                <canvas id="paymentMethodsChart"></canvas>
                            </div>
                            <div class="chart-footer" id="methodsLegend"></div>
                        </div>
                        
                        <!-- Income Sources -->
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">
                                    <i class="fas fa-layer-group me-2"></i>Income Sources
                                </h3>
                            </div>
                            <div class="chart-body chart-body-pie">
                                <canvas id="incomeSourceChart"></canvas>
                            </div>
                            <div class="chart-footer" id="sourceLegend"></div>
                        </div>
                        
                        <!-- Monthly Comparison -->
                        <div class="chart-card chart-card-wide">
                            <div class="chart-header">
                                <h3 class="chart-title">
                                    <i class="fas fa-chart-bar me-2"></i>Monthly Collections
                                </h3>
                            </div>
                            <div class="chart-body">
                                <canvas id="monthlyChart"></canvas>
                            </div>
                        </div>
                        
                        <!-- Pledges vs Payments -->
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">
                                    <i class="fas fa-balance-scale me-2"></i>Pledges vs Collected
                                </h3>
                            </div>
                            <div class="chart-body chart-body-pie">
                                <canvas id="pledgeVsPaymentChart"></canvas>
                            </div>
                            <div class="chart-footer" id="pledgePaymentLegend"></div>
                        </div>
                        
                        <!-- Weekly Pattern -->
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">
                                    <i class="fas fa-calendar-week me-2"></i>Weekly Pattern
                                </h3>
                            </div>
                            <div class="chart-body">
                                <canvas id="weeklyPatternChart"></canvas>
                            </div>
                        </div>
                        
                    </div>
                    
                    <!-- Quick Stats Summary -->
                    <div class="stats-summary">
                        <div class="stat-item">
                            <span class="stat-label">Instant Payments</span>
                            <span class="stat-value" id="statInstant"><?php echo $currencySymbol . number_format($instantPayments, 0); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Pledge Payments</span>
                            <span class="stat-value" id="statPledgePay"><?php echo $currencySymbol . number_format($pledgePayments, 0); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Target Goal</span>
                            <span class="stat-value"><?php echo $currencySymbol . number_format($targetAmount, 0); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Last Updated</span>
                            <span class="stat-value" id="lastUpdated"><?php echo date('H:i'); ?></span>
                        </div>
                    </div>
                    
                </div>
            </main>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="../assets/admin.js"></script>
    <script>
        // Pass PHP data to JavaScript
        window.dashboardConfig = {
            currency: '<?php echo $currencySymbol; ?>',
            apiUrl: 'api/financial-data.php'
        };
    </script>
    <script src="assets/financial-dashboard.js"></script>
</body>
</html>

