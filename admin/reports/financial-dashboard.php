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
                                        <div class="kpi-label text-warning fw-bold">Outstanding</div>
                                        <div class="kpi-value" id="kpiOutstanding">—</div>
                                        <div class="kpi-sub">Sum of donor balances</div>
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

    if (!resizeBound) {
      resizeBound = true;
      window.addEventListener('resize', () => {
        Object.values(CHARTS).forEach(c => { try { c.resize(); } catch (_) {} });
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

  load();
})();
</script>
</body>
</html>
