<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

// Resilient settings (used for currency display)
$db_error_message = '';
$settings = [
    'currency_code' => 'GBP',
];

try {
    $db = db();
    $settings_table_exists = $db->query("SHOW TABLES LIKE 'settings'")->num_rows > 0;
    if ($settings_table_exists) {
        $row = $db->query('SELECT currency_code FROM settings WHERE id = 1')->fetch_assoc();
        if (is_array($row) && isset($row['currency_code'])) {
            $settings['currency_code'] = (string)$row['currency_code'];
        }
    }
} catch (Exception $e) {
    $db_error_message = 'Database connection failed: ' . $e->getMessage();
}

$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
$page_title = 'Financial Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Dashboard - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/reports.css">
    <link rel="stylesheet" href="assets/financial-dashboard.css">

    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.0/dist/echarts.min.js"></script>

    <style>
        .chart-box { height: 340px; }
        .kpi-card .kpi-value { font-size: 1.35rem; font-weight: 700; }
        .kpi-card .kpi-label { font-size: 0.85rem; opacity: 0.9; }
        .kpi-card .kpi-sub { font-size: 0.8rem; color: #6c757d; }
        .skeleton { background: linear-gradient(90deg, #f1f3f5 25%, #e9ecef 37%, #f1f3f5 63%); background-size: 400% 100%; animation: skeleton 1.2s ease-in-out infinite; border-radius: 8px; }
        @keyframes skeleton { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
        .table-sm td, .table-sm th { padding: .4rem .5rem; }
        
        /* Tab switcher visibility fixes */
        #financialDashboardTabs .nav-link {
            color: #495057 !important;
            font-weight: 500;
        }
        #financialDashboardTabs .nav-link:hover {
            color: #212529 !important;
            background-color: #f8f9fa;
        }
        #financialDashboardTabs .nav-link.active {
            color: #0d6efd !important;
            font-weight: 600;
            background-color: #fff;
            border-bottom-color: #fff;
        }
        #financialDashboardTabs .nav-link i {
            color: inherit;
        }
        .clickable-card { cursor: pointer; transition: transform 0.15s ease, box-shadow 0.15s ease; }
        .clickable-card:hover { transform: translateY(-2px); box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.1) !important; }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>

    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>

        <main class="main-content">
            <div class="container-fluid">
                <?php if ($db_error_message !== ''): ?>
                    <div class="alert alert-danger m-3">
                        <strong><i class="fas fa-exclamation-triangle me-2"></i>Database Error:</strong>
                        <?php echo htmlspecialchars($db_error_message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="mb-0"><i class="fas fa-tachometer-alt text-success me-2"></i>Financial Dashboard</h4>
                        <div class="text-muted small">
                            Totals are all-time; trends show last 12 months.
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="index.php"><i class="fas fa-arrow-left me-1"></i>Back to Reports</a>
                        <button class="btn btn-success" id="refreshBtn" type="button"><i class="fas fa-rotate me-1"></i>Refresh</button>
                    </div>
                </div>

                <ul class="nav nav-tabs mb-3" id="financialDashboardTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-overview-btn" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button" role="tab" aria-controls="tab-overview" aria-selected="true">
                            <i class="fas fa-chart-line me-1"></i>Overview
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-pledge-btn" data-bs-toggle="tab" data-bs-target="#tab-pledge" type="button" role="tab" aria-controls="tab-pledge" aria-selected="false">
                            <i class="fas fa-hand-holding-usd me-1"></i>Pledge Payments
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="financialDashboardTabsContent">
                    <div class="tab-pane fade show active" id="tab-overview" role="tabpanel" aria-labelledby="tab-overview-btn" tabindex="0">
                <!-- KPI cards -->
                <div class="row g-3 mb-3" id="kpiRow">
                    <div class="col-xl-3 col-md-6">
                        <div class="card border-0 shadow-sm h-100 kpi-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="icon-circle bg-primary text-white"><i class="fas fa-hand-holding-heart"></i></div>
                                    <div class="ms-3 flex-grow-1">
                                        <div class="kpi-label text-primary fw-bold">Total Pledged</div>
                                        <div class="kpi-value" id="kpiTotalPledged">—</div>
                                        <div class="kpi-sub">Approved pledges</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card border-0 shadow-sm h-100 kpi-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="icon-circle bg-success text-white"><i class="fas fa-check-circle"></i></div>
                                    <div class="ms-3 flex-grow-1">
                                        <div class="kpi-label text-success fw-bold">Total Paid</div>
                                        <div class="kpi-value" id="kpiTotalPaid">—</div>
                                        <div class="kpi-sub">Direct + pledge payments</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card border-0 shadow-sm h-100 kpi-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="icon-circle bg-warning text-white"><i class="fas fa-hourglass-half"></i></div>
                                    <div class="ms-3 flex-grow-1">
                                        <div class="kpi-label text-warning fw-bold">Outstanding (approved)</div>
                                        <div class="kpi-value" id="kpiOutstanding">—</div>
                                        <div class="kpi-sub">Approved pledges − confirmed pledge payments</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card border-0 shadow-sm h-100 kpi-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="icon-circle bg-info text-white"><i class="fas fa-percentage"></i></div>
                                    <div class="ms-3 flex-grow-1">
                                        <div class="kpi-label text-info fw-bold">Collection Rate</div>
                                        <div class="kpi-value" id="kpiCollectionRate">—</div>
                                        <div class="kpi-sub">Paid ÷ pledged</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card border-0 shadow-sm h-100 kpi-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="icon-circle bg-dark text-white"><i class="fas fa-users"></i></div>
                                    <div class="ms-3 flex-grow-1">
                                        <div class="kpi-label fw-bold">Total Donors</div>
                                        <div class="kpi-value" id="kpiTotalDonors">—</div>
                                        <div class="kpi-sub">Registered donors</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card border-0 shadow-sm h-100 kpi-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="icon-circle bg-secondary text-white"><i class="fas fa-calendar-check"></i></div>
                                    <div class="ms-3 flex-grow-1">
                                        <div class="kpi-label fw-bold">Active Plans</div>
                                        <div class="kpi-value" id="kpiActivePlans">—</div>
                                        <div class="kpi-sub">Active payment plans</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6 col-md-12">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="small fw-bold text-muted mb-1">Data Status</div>
                                        <div class="fw-semibold" id="dataStatus">Loading…</div>
                                        <div class="text-muted small" id="dataTimestamp">—</div>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-light text-dark border" id="apiBadge">API: waiting</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <h6 class="mb-2"><i class="fas fa-chart-line me-2 text-success"></i>Monthly Trends (Last 12 Months)</h6>
                                <div id="chartTrends" class="chart-box"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <h6 class="mb-2"><i class="fas fa-receipt me-2 text-primary"></i>Payment Methods</h6>
                                <div id="chartMethods" class="chart-box"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <h6 class="mb-2"><i class="fas fa-layer-group me-2 text-warning"></i>Pledge Status Breakdown</h6>
                                <div id="chartPledgeStatus" class="chart-box"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <h6 class="mb-2"><i class="fas fa-clipboard-list me-2 text-secondary"></i>Payment Plan Status</h6>
                                <div id="chartPlanStatus" class="chart-box"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <h6 class="mb-2"><i class="fas fa-crown me-2 text-dark"></i>Top Donors (By Paid)</h6>
                                <div id="chartTopDonors" class="chart-box"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0"><i class="fas fa-clock me-2 text-info"></i>Recent Transactions</h6>
                                    <small class="text-muted">Last 10 approved / confirmed</small>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Type</th>
                                                <th>Donor</th>
                                                <th class="text-end">Amount</th>
                                                <th>Method</th>
                                                <th>Date</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody id="recentTransactionsBody">
                                            <tr>
                                                <td colspan="6">
                                                    <div class="skeleton" style="height: 26px;"></div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                    </div><!-- /tab-overview -->

                    <div class="tab-pane fade" id="tab-pledge" role="tabpanel" aria-labelledby="tab-pledge-btn" tabindex="0">
                        <div class="alert alert-warning d-none" id="pledgePaymentsDisabled">
                            <strong><i class="fas fa-triangle-exclamation me-2"></i>Pledge payments are not enabled</strong><br>
                            This installation does not have the <code>pledge_payments</code> table, so pledge-payment analytics can’t be displayed.
                        </div>

                        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-2">
                            <div>
                                <h5 class="mb-0"><i class="fas fa-hand-holding-usd me-2" style="color:var(--success)"></i>Pledge Payments</h5>
                                <div class="text-muted small" style="color:var(--gray-500); font-size:0.875rem">
                                    This tab focuses only on pledges and pledge payments: who is paying, who is completed, and how collection is trending.
                                </div>
                            </div>
                            <div class="d-flex gap-2 align-items-center">
                                <a href="source-tables.php" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-database me-1"></i>Source Tables
                                </a>
                                <a href="data-comparison.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-code-compare me-1"></i>Compare with Excel
                                </a>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-xl-3 col-md-6">
                                <a href="total-pledged-approved.php" class="text-decoration-none text-body d-block">
                                    <div class="card border-0 shadow-sm h-100 kpi-card clickable-card">
                                        <div class="card-body">
                                            <div class="d-flex align-items-center">
                                                <div class="icon-circle bg-primary text-white"><i class="fas fa-hand-holding-heart"></i></div>
                                                <div class="ms-3 flex-grow-1">
                                                    <div class="kpi-label text-primary fw-bold">Total Pledged (approved)</div>
                                                    <div class="kpi-value" id="pledgeTotalPledged">—</div>
                                                    <div class="kpi-sub">Approved pledges <i class="fas fa-external-link-alt ms-1 small opacity-75"></i></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            </div>

                            <div class="col-xl-3 col-md-6">
                                <a href="paid-towards-pledges.php" class="text-decoration-none text-body d-block">
                                    <div class="card border-0 shadow-sm h-100 kpi-card clickable-card">
                                        <div class="card-body">
                                            <div class="d-flex align-items-center">
                                                <div class="icon-circle bg-success text-white"><i class="fas fa-money-bill-transfer"></i></div>
                                                <div class="ms-3 flex-grow-1">
                                                    <div class="kpi-label text-success fw-bold">Paid Towards Pledges</div>
                                                    <div class="kpi-value" id="pledgePaidTowards">—</div>
                                                    <div class="kpi-sub">Confirmed pledge payments <i class="fas fa-external-link-alt ms-1 small opacity-75"></i></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            </div>

                            <div class="col-xl-3 col-md-6">
                                <a href="outstanding-pledged.php" class="text-decoration-none text-body d-block">
                                    <div class="card border-0 shadow-sm h-100 kpi-card clickable-card">
                                        <div class="card-body">
                                            <div class="d-flex align-items-center">
                                                <div class="icon-circle bg-warning text-white"><i class="fas fa-hourglass-half"></i></div>
                                                <div class="ms-3 flex-grow-1">
                                                    <div class="kpi-label text-warning fw-bold">Outstanding Pledged</div>
                                                    <div class="kpi-value" id="pledgeOutstanding">—</div>
                                                    <div class="kpi-sub">Approved pledges − confirmed pledge payments <i class="fas fa-external-link-alt ms-1 small opacity-75"></i></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            </div>

                            <div class="col-xl-3 col-md-6">
                                <div class="card border-0 shadow-sm h-100 kpi-card">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center">
                                            <div class="icon-circle bg-info text-white"><i class="fas fa-percentage"></i></div>
                                            <div class="ms-3 flex-grow-1">
                                                <div class="kpi-label text-info fw-bold">Pledge Collection Rate</div>
                                                <div class="kpi-value" id="pledgeCollectionRate">—</div>
                                                <div class="kpi-sub">Pledge payments ÷ pledges</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-3 col-md-6">
                                <a href="donors-by-status.php?status=paying" class="text-decoration-none text-body d-block">
                                    <div class="card border-0 shadow-sm h-100 kpi-card clickable-card">
                                        <div class="card-body">
                                            <div class="d-flex align-items-center">
                                                <div class="icon-circle bg-dark text-white"><i class="fas fa-person-walking"></i></div>
                                                <div class="ms-3 flex-grow-1">
                                                    <div class="kpi-label fw-bold">Donors Paying</div>
                                                    <div class="kpi-value" id="pledgeDonorsPaying">—</div>
                                                    <div class="kpi-sub">Pledge donors currently paying <i class="fas fa-external-link-alt ms-1 small opacity-75"></i></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            </div>

                            <div class="col-xl-3 col-md-6">
                                <a href="donors-by-status.php?status=completed" class="text-decoration-none text-body d-block">
                                    <div class="card border-0 shadow-sm h-100 kpi-card clickable-card">
                                        <div class="card-body">
                                            <div class="d-flex align-items-center">
                                                <div class="icon-circle bg-secondary text-white"><i class="fas fa-flag-checkered"></i></div>
                                                <div class="ms-3 flex-grow-1">
                                                    <div class="kpi-label fw-bold">Donors Completed</div>
                                                    <div class="kpi-value" id="pledgeDonorsCompleted">—</div>
                                                    <div class="kpi-sub">Pledge donors paid in full <i class="fas fa-external-link-alt ms-1 small opacity-75"></i></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        </div>

                        <!-- Data Source Comparison (Old vs New System) -->
                        <div class="row g-3 mb-3 d-none" id="dataSourceSection">
                            <div class="col-12">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h6 class="mb-0"><i class="fas fa-code-branch me-2 text-info"></i>Old System vs New System</h6>
                                            <span class="badge bg-light text-dark border"><i class="fas fa-database me-1"></i>Data Source Comparison</span>
                                        </div>
                                        <div class="row g-3" id="dataSourceCards"></div>
                                        <div class="row g-3 mt-2">
                                            <div class="col-xl-6">
                                                <div id="chartDataSourcePledged" style="height: 280px;"></div>
                                            </div>
                                            <div class="col-xl-6">
                                                <div id="chartDataSourceDonors" style="height: 280px;"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-12">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-body">
                                        <h6 class="mb-2"><i class="fas fa-chart-line me-2 text-success"></i>Pledge Payments Collected (Last 12 Months)</h6>
                                        <div id="chartPledgeMonthly" class="chart-box"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-6">
                                <div class="card border-0 shadow-sm h-100">
                                    <div class="card-body">
                                        <h6 class="mb-2"><i class="fas fa-chart-pie me-2 text-primary"></i>Pledge Donor Status</h6>
                                        <div id="chartPledgeDonorStatus" class="chart-box"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-6">
                                <div class="card border-0 shadow-sm h-100">
                                    <div class="card-body">
                                        <h6 class="mb-2"><i class="fas fa-chart-bar me-2 text-warning"></i>Top Outstanding Balances (Paying)</h6>
                                        <div id="chartPledgeTopOutstanding" class="chart-box"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h6 class="mb-0"><i class="fas fa-users me-2 text-dark"></i>Pledge Donors (Paying / Completed)</h6>
                                            <small class="text-muted">Top 50 by balance / paid</small>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover align-middle mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Donor</th>
                                                        <th class="text-end">Pledged</th>
                                                        <th class="text-end">Paid</th>
                                                        <th class="text-end">Outstanding</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="pledgeDonorsBody">
                                                    <tr>
                                                        <td colspan="5">
                                                            <div class="skeleton" style="height: 26px;"></div>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div><!-- /tab-pledge -->
                </div><!-- /tab-content -->

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/financial-dashboard.js"></script>

<script>
(function(){
  const CURRENCY = <?php echo json_encode($currency); ?>;
  const CHARTS = {};
  let resizeBound = false;
  let LAST_DATA = null;

  const el = (id) => document.getElementById(id);

  function fmtMoney(amount) {
    const n = Number(amount || 0);
    try {
      return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: CURRENCY,
        maximumFractionDigits: 2
      }).format(n);
    } catch (_) {
      return CURRENCY + ' ' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
  }

  function fmtInt(v) {
    return Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
  }

  function setStatus(ok, msg) {
    el('dataStatus').textContent = msg;
    const badge = el('apiBadge');
    if (!badge) return;
    badge.className = 'badge ' + (ok ? 'bg-success' : 'bg-danger');
    badge.textContent = ok ? 'API: OK' : 'API: ERROR';
  }

  function setTimestamp(text) {
    el('dataTimestamp').textContent = text;
  }

  function renderRecent(rows) {
    const body = el('recentTransactionsBody');
    body.innerHTML = '';

    if (!Array.isArray(rows) || rows.length === 0) {
      const tr = document.createElement('tr');
      tr.innerHTML = '<td colspan="6" class="text-muted">No recent transactions found.</td>';
      body.appendChild(tr);
      return;
    }

    rows.forEach(r => {
      const tr = document.createElement('tr');
      const type = String(r.type || '');
      const donor = String(r.donor || '');
      const amount = fmtMoney(r.amount);
      const method = String(r.method || '');
      const date = String(r.date || '');
      const status = String(r.status || '');

      tr.innerHTML = `
        <td><span class="badge ${type === 'Direct' ? 'bg-primary' : 'bg-secondary'}">${type}</span></td>
        <td>${escapeHtml(donor)}</td>
        <td class="text-end fw-semibold">${escapeHtml(amount)}</td>
        <td>${escapeHtml(method)}</td>
        <td class="text-muted">${escapeHtml(date)}</td>
        <td>${escapeHtml(status)}</td>
      `;
      body.appendChild(tr);
    });
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function buildCharts(data) {
    if (!window.echarts) return;

    function init(id) {
      const node = el(id);
      if (!node) return null;
      // Avoid initializing charts in hidden tabs (0x0 size)
      if (node.offsetWidth === 0 || node.offsetHeight === 0) return null;
      let chart = echarts.getInstanceByDom(node);
      if (!chart) {
        chart = echarts.init(node);
      }
      CHARTS[id] = chart;
      return CHARTS[id];
    }

    const trend = init('chartTrends');
    if (trend) {
      const items = Array.isArray(data.trends) ? data.trends : [];
      const labels = items.map(x => x.label);
      const pledged = items.map(x => Number(x.pledged || 0));
      const paid = items.map(x => Number(x.paid || 0));

      trend.setOption({
        tooltip: {
          trigger: 'axis',
          valueFormatter: (v) => fmtMoney(v)
        },
        legend: { data: ['Pledged', 'Paid'] },
        grid: { left: 24, right: 24, top: 40, bottom: 24, containLabel: true },
        xAxis: { type: 'category', data: labels },
        yAxis: { type: 'value' },
        series: [
          { name: 'Pledged', type: 'line', smooth: true, data: pledged, areaStyle: { opacity: 0.08 } },
          { name: 'Paid', type: 'line', smooth: true, data: paid, areaStyle: { opacity: 0.08 } }
        ]
      });
    }

    const methods = init('chartMethods');
    if (methods) {
      const items = Array.isArray(data.payment_methods) ? data.payment_methods : [];
      const seriesData = items.map(x => ({ name: String(x.method || 'Other'), value: Number(x.total || 0) }));
      methods.setOption({
        tooltip: {
          trigger: 'item',
          formatter: (p) => `${escapeHtml(p.name)}: ${escapeHtml(fmtMoney(p.value))} (${p.percent}%)`
        },
        legend: { top: 'bottom' },
        series: [{
          name: 'Payment Methods',
          type: 'pie',
          radius: ['40%', '70%'],
          avoidLabelOverlap: true,
          itemStyle: { borderRadius: 8, borderColor: '#fff', borderWidth: 2 },
          label: { show: false },
          emphasis: { label: { show: true, fontSize: 14, fontWeight: 'bold' } },
          labelLine: { show: false },
          data: seriesData
        }]
      });
    }

    const pledgeStatus = init('chartPledgeStatus');
    if (pledgeStatus) {
      const items = Array.isArray(data.pledge_status) ? data.pledge_status : [];
      const labels = items.map(x => String(x.status || ''));
      const totals = items.map(x => Number(x.total || 0));

      pledgeStatus.setOption({
        tooltip: { trigger: 'axis', valueFormatter: (v) => fmtMoney(v) },
        grid: { left: 24, right: 24, top: 20, bottom: 24, containLabel: true },
        xAxis: { type: 'category', data: labels },
        yAxis: { type: 'value' },
        series: [{ name: 'Total', type: 'bar', data: totals, itemStyle: { borderRadius: [6, 6, 0, 0] } }]
      });
    }

    const planStatus = init('chartPlanStatus');
    if (planStatus) {
      const items = Array.isArray(data.plan_status) ? data.plan_status : [];
      const labels = items.map(x => String(x.status || ''));
      const counts = items.map(x => Number(x.count || 0));

      planStatus.setOption({
        tooltip: { trigger: 'axis' },
        grid: { left: 24, right: 24, top: 20, bottom: 24, containLabel: true },
        xAxis: { type: 'category', data: labels },
        yAxis: { type: 'value' },
        series: [{ name: 'Plans', type: 'bar', data: counts, itemStyle: { borderRadius: [6, 6, 0, 0] } }]
      });
    }

    const topDonors = init('chartTopDonors');
    if (topDonors) {
      const items = Array.isArray(data.top_donors) ? data.top_donors : [];
      const names = items.map(x => String(x.name || '')).reverse();
      const values = items.map(x => Number(x.paid || 0)).reverse();

      topDonors.setOption({
        tooltip: { trigger: 'axis', valueFormatter: (v) => fmtMoney(v) },
        grid: { left: 24, right: 24, top: 20, bottom: 24, containLabel: true },
        xAxis: { type: 'value' },
        yAxis: { type: 'category', data: names },
        series: [{ name: 'Paid', type: 'bar', data: values, itemStyle: { borderRadius: [0, 6, 6, 0] } }]
      });
    }

    // --- Data Source Charts ---
    const pledgeBlockForDS = data && data.pledge_payments ? data.pledge_payments : {};
    if (pledgeBlockForDS.has_data_source) {
      buildDataSourceCharts(pledgeBlockForDS);
    }

    // --- Pledge Payments Tab Charts ---
    const pledgeBlock = data && data.pledge_payments ? data.pledge_payments : {};

    const pledgeMonthly = init('chartPledgeMonthly');
    if (pledgeMonthly) {
      const items = Array.isArray(pledgeBlock.monthly) ? pledgeBlock.monthly : [];
      const labels = items.map(x => x.label);
      const paid = items.map(x => Number(x.paid || 0));

      pledgeMonthly.setOption({
        tooltip: { trigger: 'axis', valueFormatter: (v) => fmtMoney(v) },
        grid: { left: 24, right: 24, top: 20, bottom: 24, containLabel: true },
        xAxis: { type: 'category', data: labels },
        yAxis: { type: 'value' },
        series: [
          { name: 'Paid Towards Pledges', type: 'line', smooth: true, data: paid, areaStyle: { opacity: 0.10 } }
        ]
      });
    }

    const pledgeDonorStatus = init('chartPledgeDonorStatus');
    if (pledgeDonorStatus) {
      const items = Array.isArray(pledgeBlock.donor_status) ? pledgeBlock.donor_status : [];
      const seriesData = items.map(x => ({ name: String(x.status || 'Unknown'), value: Number(x.count || 0) }));

      pledgeDonorStatus.setOption({
        tooltip: { trigger: 'item' },
        legend: { top: 'bottom' },
        series: [{
          name: 'Pledge Donor Status',
          type: 'pie',
          radius: ['40%', '70%'],
          itemStyle: { borderRadius: 8, borderColor: '#fff', borderWidth: 2 },
          label: { show: false },
          emphasis: { label: { show: true, fontSize: 14, fontWeight: 'bold' } },
          labelLine: { show: false },
          data: seriesData
        }]
      });
    }

    const pledgeTopOutstanding = init('chartPledgeTopOutstanding');
    if (pledgeTopOutstanding) {
      const items = Array.isArray(pledgeBlock.top_outstanding) ? pledgeBlock.top_outstanding : [];
      const names = items.map(x => String(x.name || '')).reverse();
      const values = items.map(x => Number(x.balance || 0)).reverse();

      pledgeTopOutstanding.setOption({
        tooltip: { trigger: 'axis', valueFormatter: (v) => fmtMoney(v) },
        grid: { left: 24, right: 24, top: 20, bottom: 24, containLabel: true },
        xAxis: { type: 'value' },
        yAxis: { type: 'category', data: names },
        series: [{ name: 'Outstanding', type: 'bar', data: values, itemStyle: { borderRadius: [0, 6, 6, 0] } }]
      });
    }

    if (!resizeBound) {
      resizeBound = true;
      window.addEventListener('resize', () => {
        Object.values(CHARTS).forEach(c => { try { c.resize(); } catch (_) {} });
      });
    }
  }

  function renderDataSourceBreakdown(pledgeData) {
    const section = el('dataSourceSection');
    const cardsContainer = el('dataSourceCards');
    if (!section || !cardsContainer) return;

    const breakdown = Array.isArray(pledgeData.data_source_breakdown) ? pledgeData.data_source_breakdown : [];
    if (!pledgeData.has_data_source || breakdown.length === 0) {
      section.classList.add('d-none');
      return;
    }

    section.classList.remove('d-none');

    const colorMap = {
      'old_system': { bg: '#fff3cd', border: '#ffc107', icon: 'fas fa-archive', iconBg: '#ffc107', text: '#856404' },
      'new_system': { bg: '#d1e7dd', border: '#198754', icon: 'fas fa-rocket', iconBg: '#198754', text: '#0f5132' }
    };

    let cardsHtml = '';
    breakdown.forEach(src => {
      const c = colorMap[src.source] || colorMap['new_system'];
      const pct = Number(src.collection_rate || 0).toFixed(1);
      const progressWidth = Math.min(100, Number(src.collection_rate || 0));

      cardsHtml += `
        <div class="col-xl-6 col-md-6">
          <div class="card h-100" style="border-left: 4px solid ${c.border}; background: ${c.bg};">
            <div class="card-body p-3">
              <div class="d-flex align-items-center mb-2">
                <div class="rounded-circle d-flex align-items-center justify-content-center me-2" style="width:36px;height:36px;background:${c.iconBg};color:#fff;font-size:0.85rem;">
                  <i class="${c.icon}"></i>
                </div>
                <div>
                  <div class="fw-bold" style="color:${c.text};font-size:0.95rem;">${escapeHtml(src.label)}</div>
                  <div class="text-muted" style="font-size:0.75rem;">${fmtInt(src.donor_count)} donors</div>
                </div>
              </div>
              <div class="row g-2 mb-2">
                <div class="col-4 text-center">
                  <div class="fw-bold" style="font-size:1.05rem;">${escapeHtml(fmtMoney(src.total_pledged))}</div>
                  <div class="text-muted" style="font-size:0.7rem;text-transform:uppercase;">Pledged</div>
                </div>
                <div class="col-4 text-center">
                  <div class="fw-bold text-success" style="font-size:1.05rem;">${escapeHtml(fmtMoney(src.total_paid))}</div>
                  <div class="text-muted" style="font-size:0.7rem;text-transform:uppercase;">Paid</div>
                </div>
                <div class="col-4 text-center">
                  <div class="fw-bold text-danger" style="font-size:1.05rem;">${escapeHtml(fmtMoney(src.total_balance))}</div>
                  <div class="text-muted" style="font-size:0.7rem;text-transform:uppercase;">Balance</div>
                </div>
              </div>
              <div class="progress" style="height:6px;background:rgba(0,0,0,0.1);">
                <div class="progress-bar" style="width:${progressWidth}%;background:${c.border};" role="progressbar"></div>
              </div>
              <div class="d-flex justify-content-between mt-1" style="font-size:0.7rem;">
                <span style="color:${c.text};">${pct}% collected</span>
                <span class="text-muted">${fmtInt(src.donors_paying)} paying · ${fmtInt(src.donors_completed)} completed · ${fmtInt(src.donors_not_started)} not started</span>
              </div>
            </div>
          </div>
        </div>
      `;
    });
    cardsContainer.innerHTML = cardsHtml;
  }

  function buildDataSourceCharts(pledgeData) {
    if (!window.echarts) return;
    const breakdown = Array.isArray(pledgeData.data_source_breakdown) ? pledgeData.data_source_breakdown : [];
    if (breakdown.length === 0) return;

    const labels = breakdown.map(s => s.label);
    const pledgedVals = breakdown.map(s => Number(s.total_pledged || 0));
    const paidVals = breakdown.map(s => Number(s.total_paid || 0));
    const balanceVals = breakdown.map(s => Number(s.total_balance || 0));
    const donorCounts = breakdown.map(s => Number(s.donor_count || 0));
    const payingCounts = breakdown.map(s => Number(s.donors_paying || 0));
    const completedCounts = breakdown.map(s => Number(s.donors_completed || 0));
    const notStartedCounts = breakdown.map(s => Number(s.donors_not_started || 0));

    const colors = ['#ffc107', '#198754'];

    const pledgedNode = el('chartDataSourcePledged');
    if (pledgedNode && pledgedNode.offsetWidth > 0) {
      let chart = echarts.getInstanceByDom(pledgedNode) || echarts.init(pledgedNode);
      CHARTS['chartDataSourcePledged'] = chart;
      chart.setOption({
        title: { text: 'Financial Comparison', textStyle: { fontSize: 13 } },
        tooltip: { trigger: 'axis', valueFormatter: v => fmtMoney(v) },
        legend: { top: 'bottom' },
        grid: { left: 24, right: 24, top: 40, bottom: 50, containLabel: true },
        xAxis: { type: 'category', data: labels },
        yAxis: { type: 'value' },
        series: [
          { name: 'Pledged', type: 'bar', data: pledgedVals, itemStyle: { borderRadius: [6,6,0,0], color: '#6c757d' } },
          { name: 'Paid', type: 'bar', data: paidVals, itemStyle: { borderRadius: [6,6,0,0], color: '#198754' } },
          { name: 'Balance', type: 'bar', data: balanceVals, itemStyle: { borderRadius: [6,6,0,0], color: '#dc3545' } }
        ]
      });
    }

    const donorsNode = el('chartDataSourceDonors');
    if (donorsNode && donorsNode.offsetWidth > 0) {
      let chart = echarts.getInstanceByDom(donorsNode) || echarts.init(donorsNode);
      CHARTS['chartDataSourceDonors'] = chart;
      chart.setOption({
        title: { text: 'Donor Status Comparison', textStyle: { fontSize: 13 } },
        tooltip: { trigger: 'axis' },
        legend: { top: 'bottom' },
        grid: { left: 24, right: 24, top: 40, bottom: 50, containLabel: true },
        xAxis: { type: 'category', data: labels },
        yAxis: { type: 'value' },
        series: [
          { name: 'Paying', type: 'bar', stack: 'status', data: payingCounts, itemStyle: { borderRadius: [0,0,0,0], color: '#0d6efd' } },
          { name: 'Completed', type: 'bar', stack: 'status', data: completedCounts, itemStyle: { borderRadius: [0,0,0,0], color: '#198754' } },
          { name: 'Not Started', type: 'bar', stack: 'status', data: notStartedCounts, itemStyle: { borderRadius: [6,6,0,0], color: '#ffc107' } }
        ]
      });
    }
  }

  function renderPledgeTab(data) {
    const p = data && data.pledge_payments ? data.pledge_payments : {};

    const enabled = !!p.enabled;
    const disabledAlert = el('pledgePaymentsDisabled');
    if (disabledAlert) {
      disabledAlert.classList.toggle('d-none', enabled);
    }

    const t = p.totals || {};
    const d = p.donors || {};

    if (el('pledgeTotalPledged')) el('pledgeTotalPledged').textContent = fmtMoney(t.total_pledged);
    if (el('pledgePaidTowards')) el('pledgePaidTowards').textContent = fmtMoney(t.paid_towards_pledges);
    if (el('pledgeOutstanding')) el('pledgeOutstanding').textContent = fmtMoney(t.outstanding_pledged);
    if (el('pledgeCollectionRate')) el('pledgeCollectionRate').textContent = (Number(t.collection_rate || 0).toFixed(1)) + '%';

    if (el('pledgeDonorsPaying')) el('pledgeDonorsPaying').textContent = fmtInt(d.paying);
    if (el('pledgeDonorsCompleted')) el('pledgeDonorsCompleted').textContent = fmtInt(d.completed);

    renderDataSourceBreakdown(p);

    const body = el('pledgeDonorsBody');
    if (body) {
      const rows = Array.isArray(p.donors_list) ? p.donors_list : [];
      body.innerHTML = '';

      if (!enabled) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td colspan="5" class="text-muted">Pledge payments are not enabled on this system.</td>';
        body.appendChild(tr);
        return;
      }

      if (rows.length === 0) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td colspan="5" class="text-muted">No pledge donors (paying/completed) found.</td>';
        body.appendChild(tr);
        return;
      }

      rows.forEach(r => {
        const tr = document.createElement('tr');
        const name = String(r.name || 'Anonymous');
        const pledged = fmtMoney(r.pledged);
        const paid = fmtMoney(r.paid);
        const bal = fmtMoney(r.balance);
        const status = String(r.status || '');

        const badgeClass = status.toLowerCase() === 'completed' ? 'bg-success' : 'bg-warning text-dark';
        tr.innerHTML = `
          <td>${escapeHtml(name)}</td>
          <td class="text-end">${escapeHtml(pledged)}</td>
          <td class="text-end">${escapeHtml(paid)}</td>
          <td class="text-end fw-semibold">${escapeHtml(bal)}</td>
          <td><span class="badge ${badgeClass}">${escapeHtml(status)}</span></td>
        `;
        body.appendChild(tr);
      });
    }
  }

  function render(data) {
    const k = data && data.kpi ? data.kpi : {};
    el('kpiTotalPledged').textContent = fmtMoney(k.total_pledged);
    el('kpiTotalPaid').textContent = fmtMoney(k.total_paid);
    el('kpiOutstanding').textContent = fmtMoney(k.outstanding);
    el('kpiCollectionRate').textContent = (Number(k.collection_rate || 0).toFixed(1)) + '%';
    el('kpiTotalDonors').textContent = fmtInt(k.total_donors);
    el('kpiActivePlans').textContent = fmtInt(k.active_plans);

    renderRecent(data.recent_transactions);
    renderPledgeTab(data);
    buildCharts(data);
  }

  async function load() {
    setStatus(true, 'Loading…');
    setTimestamp('—');

    const url = 'api/financial-data.php';
    let res;

    try {
      res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });

      let data;
      try {
        data = await res.json();
      } catch (_) {
        throw new Error('API did not return valid JSON.');
      }

      if (!res.ok) {
        const msg = (data && (data.message || data.error)) ? (data.message || data.error) : ('Request failed (' + res.status + ')');
        throw new Error(msg);
      }

      LAST_DATA = data;
      render(data);
      setStatus(true, 'Loaded');
      setTimestamp('Updated: ' + new Date().toLocaleString());

    } catch (err) {
      setStatus(false, 'Failed to load dashboard data');
      setTimestamp('Updated: ' + new Date().toLocaleString());

      const body = el('recentTransactionsBody');
      body.innerHTML = '<tr><td colspan="6" class="text-danger">' + escapeHtml(String(err && err.message ? err.message : err)) + '</td></tr>';

      console.error(err);
    }
  }

  const refreshBtn = el('refreshBtn');
  if (refreshBtn) refreshBtn.addEventListener('click', load);

  // Re-render charts when switching tabs (ECharts needs a visible container)
  document.querySelectorAll('#financialDashboardTabs button[data-bs-toggle="tab"]').forEach(btn => {
    btn.addEventListener('shown.bs.tab', () => {
      if (!LAST_DATA) return;
      buildCharts(LAST_DATA);
      Object.values(CHARTS).forEach(c => { try { c.resize(); } catch (_) {} });
    });
  });

  load();
})();
</script>
</body>
</html>
