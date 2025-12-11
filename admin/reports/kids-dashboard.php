<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

// Settings (currency + target)
$db_error_message = '';
$settings = [ 'currency_code' => 'GBP', 'target_amount' => 0 ];
$db = null;
try {
    $db = db();
    $settings_table_exists = $db->query("SHOW TABLES LIKE 'settings'")->num_rows > 0;
    if ($settings_table_exists) {
        $settings = $db->query('SELECT target_amount, currency_code FROM settings WHERE id = 1')->fetch_assoc() ?: $settings;
    } else {
        $db_error_message = '`settings` table not found.';
    }
} catch (Exception $e) {
    $db_error_message = 'Database connection failed: ' . $e->getMessage();
}

$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
$targetAmount = (float)($settings['target_amount'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Easy Charts - Fundraising</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/reports.css">

    <style>
        /* Mobile-first layout tuned for touch */
        .page-title {
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .hint-pill {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .35rem .65rem;
            border-radius: 999px;
            background: rgba(13, 110, 253, 0.08);
            color: #0d6efd;
            font-weight: 600;
            font-size: .85rem;
        }

        .kpi-card .label {
            font-size: .85rem;
            font-weight: 700;
            opacity: .85;
        }
        .kpi-card .value {
            font-size: 1.6rem;
            font-weight: 800;
            line-height: 1.1;
        }
        .kpi-card .sub {
            font-size: .85rem;
            color: #6c757d;
        }

        .chart-card {
            border-radius: 12px;
        }
        .chart-title {
            font-weight: 800;
            letter-spacing: -0.01em;
        }
        .chart-subtitle {
            color: #6c757d;
            font-size: .9rem;
        }

        /* Responsive chart heights */
        .chart-box {
            width: 100%;
            height: clamp(240px, 34vh, 360px);
        }
        .chart-box.tall {
            height: clamp(280px, 42vh, 420px);
        }

        /* Big touch targets */
        .btn {
            min-height: 44px;
        }

        /* Compact spacing on small screens */
        @media (max-width: 576px) {
            .kpi-card .value { font-size: 1.35rem; }
            .chart-subtitle { font-size: .85rem; }
            .container-fluid { padding-left: .6rem !important; padding-right: .6rem !important; }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>

        <main class="main-content">
            <div class="container-fluid">

                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <div>
                        <div class="hint-pill mb-2">
                            <i class="fas fa-chart-pie"></i>
                            Easy visual dashboard
                        </div>
                        <h4 class="page-title mb-0">Simple Charts</h4>
                        <div class="text-muted small">Made to be understandable on mobile.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back
                        </a>
                        <button id="refreshBtn" class="btn btn-primary">
                            <i class="fas fa-sync-alt me-2"></i>Refresh
                        </button>
                    </div>
                </div>

                <?php if ($db_error_message !== ''): ?>
                    <div class="alert alert-warning">
                        <?php echo htmlspecialchars($db_error_message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <!-- KPI cards -->
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card border-0 shadow-sm kpi-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <div class="label text-primary">Total Raised</div>
                                        <div class="value" id="kpiTotalRaised"><?php echo $currency; ?> 0</div>
                                        <div class="sub">Paid + Remaining</div>
                                    </div>
                                    <div class="icon-circle bg-primary" style="width:52px;height:52px;">
                                        <i class="fas fa-chart-line text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card border-0 shadow-sm kpi-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <div class="label text-success">Paid</div>
                                        <div class="value" id="kpiPaid"><?php echo $currency; ?> 0</div>
                                        <div class="sub">Money received</div>
                                    </div>
                                    <div class="icon-circle bg-success" style="width:52px;height:52px;">
                                        <i class="fas fa-coins text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card border-0 shadow-sm kpi-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <div class="label text-warning">Remaining</div>
                                        <div class="value" id="kpiRemaining"><?php echo $currency; ?> 0</div>
                                        <div class="sub">Still to collect</div>
                                    </div>
                                    <div class="icon-circle bg-warning" style="width:52px;height:52px;">
                                        <i class="fas fa-hourglass-half text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="card border-0 shadow-sm kpi-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <div class="label text-info">Donors</div>
                                        <div class="value" id="kpiDonors">0</div>
                                        <div class="sub">People helped</div>
                                    </div>
                                    <div class="icon-circle bg-info" style="width:52px;height:52px;">
                                        <i class="fas fa-users text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="row g-3">
                    <div class="col-12 col-lg-6">
                        <div class="card border-0 shadow-sm chart-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div>
                                        <div class="chart-title">Progress</div>
                                        <div class="chart-subtitle">How close we are to the target</div>
                                    </div>
                                </div>
                                <div id="chartProgress" class="chart-box"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <div class="card border-0 shadow-sm chart-card">
                            <div class="card-body">
                                <div class="chart-title">Paid vs Remaining</div>
                                <div class="chart-subtitle">Simple split of the campaign value</div>
                                <div id="chartSplit" class="chart-box"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <div class="card border-0 shadow-sm chart-card">
                            <div class="card-body">
                                <div class="chart-title">How people pay</div>
                                <div class="chart-subtitle">Bank / Card / Cash</div>
                                <div id="chartMethods" class="chart-box"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <div class="card border-0 shadow-sm chart-card">
                            <div class="card-body">
                                <div class="chart-title">Last 12 months</div>
                                <div class="chart-subtitle">Activity trend (pledges + payments)</div>
                                <div id="chartTrend" class="chart-box tall"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3 small text-muted">
                    Tip: “Paid” is money received. “Remaining” is what’s still promised.
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/echarts@5.5.0/dist/echarts.min.js"></script>

<script>
  const CURRENCY = <?php echo json_encode($currency); ?>;
  const TARGET_AMOUNT = <?php echo json_encode($targetAmount); ?>;
  const API_URL = 'api/financial-data.php';

  const fmtMoney = (n) => {
    const num = Number(n || 0);
    return `${CURRENCY} ${num.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  };

  const els = {
    totalRaised: document.getElementById('kpiTotalRaised'),
    paid: document.getElementById('kpiPaid'),
    remaining: document.getElementById('kpiRemaining'),
    donors: document.getElementById('kpiDonors'),
    refreshBtn: document.getElementById('refreshBtn'),
  };

  const charts = {};

  function initChart(id) {
    const el = document.getElementById(id);
    if (!el || !window.echarts) return null;
    const c = echarts.init(el, null, { renderer: 'canvas' });
    return c;
  }

  function safeSetOption(chart, option) {
    if (!chart) return;
    chart.setOption(option, true);
  }

  function resizeAll() {
    Object.values(charts).forEach((c) => c && c.resize());
  }

  window.addEventListener('resize', () => {
    // debounce-ish
    clearTimeout(window.__echartsResizeTimer);
    window.__echartsResizeTimer = setTimeout(resizeAll, 100);
  });

  // Use ResizeObserver for better mobile rotations / container changes
  const ro = new ResizeObserver(() => resizeAll());
  ['chartProgress', 'chartSplit', 'chartMethods', 'chartTrend'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) ro.observe(el);
  });

  async function loadData() {
    els.refreshBtn.classList.add('loading');
    try {
      const res = await fetch(API_URL, { credentials: 'same-origin' });
      const data = await res.json();

      const kpi = data.kpi || {};
      const paid = Number(kpi.total_paid || 0);
      const remaining = Number(kpi.outstanding || 0);
      const totalRaised = paid + remaining;
      const donors = Number(kpi.total_donors || 0);

      els.totalRaised.textContent = fmtMoney(totalRaised);
      els.paid.textContent = fmtMoney(paid);
      els.remaining.textContent = fmtMoney(remaining);
      els.donors.textContent = donors.toLocaleString('en-GB');

      // Progress gauge: totalRaised vs target (if target set), else paid ratio
      const target = Number(TARGET_AMOUNT || 0);
      const progressPct = target > 0 ? Math.min(100, (totalRaised / target) * 100) : (totalRaised > 0 ? (paid / totalRaised) * 100 : 0);

      safeSetOption(charts.progress, {
        series: [{
          type: 'gauge',
          startAngle: 200,
          endAngle: -20,
          min: 0,
          max: 100,
          splitNumber: 5,
          axisLine: {
            lineStyle: {
              width: 18,
              color: [
                [0.4, '#ef4444'],
                [0.7, '#f59e0b'],
                [1, '#10b981'],
              ]
            }
          },
          pointer: { show: true, width: 5, length: '60%' },
          axisTick: { distance: -18, length: 6 },
          splitLine: { distance: -18, length: 14 },
          axisLabel: { distance: -30, fontSize: 11 },
          detail: {
            valueAnimation: true,
            fontSize: 28,
            fontWeight: 800,
            formatter: (v) => `${Math.round(v)}%`,
          },
          title: { show: false },
          data: [{ value: progressPct }],
        }]
      });

      // Split bar
      safeSetOption(charts.split, {
        grid: { left: 10, right: 10, top: 25, bottom: 10, containLabel: true },
        xAxis: { type: 'value', axisLabel: { formatter: (v) => v >= 1000 ? `${Math.round(v/1000)}k` : v } },
        yAxis: { type: 'category', data: ['Campaign'], axisTick: { show: false } },
        series: [
          { name: 'Paid', type: 'bar', stack: 'total', data: [paid], itemStyle: { color: '#10b981', borderRadius: [8,0,0,8] }, label: { show: true, position: 'inside', formatter: () => 'Paid' } },
          { name: 'Remaining', type: 'bar', stack: 'total', data: [remaining], itemStyle: { color: '#f59e0b', borderRadius: [0,8,8,0] }, label: { show: true, position: 'inside', formatter: () => 'Remaining' } },
        ],
        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' }, valueFormatter: (v) => fmtMoney(v) },
      });

      // Methods pie
      const methods = (data.payment_methods || []).map((m) => ({
        name: m.method,
        value: Number(m.total || 0),
      })).filter((x) => x.value > 0);

      safeSetOption(charts.methods, {
        tooltip: { trigger: 'item', valueFormatter: (v) => fmtMoney(v) },
        series: [{
          type: 'pie',
          radius: ['35%', '70%'],
          avoidLabelOverlap: true,
          itemStyle: { borderRadius: 10, borderColor: '#fff', borderWidth: 2 },
          label: { show: true, formatter: '{b}\n{d}%', fontWeight: 700 },
          labelLine: { show: true },
          data: methods.length ? methods : [{ name: 'No data', value: 1 }],
        }]
      });

      // Trend line (last 12 months from API)
      const trends = data.trends || [];
      const labels = trends.map((t) => t.label);
      const pledged = trends.map((t) => Number(t.pledged || 0));
      const paidSeries = trends.map((t) => Number(t.paid || 0));

      safeSetOption(charts.trend, {
        grid: { left: 10, right: 10, top: 30, bottom: 35, containLabel: true },
        tooltip: { trigger: 'axis', valueFormatter: (v) => fmtMoney(v) },
        legend: { bottom: 0, data: ['Paid', 'Pledges'] },
        xAxis: { type: 'category', data: labels, axisLabel: { interval: 2 } },
        yAxis: { type: 'value', axisLabel: { formatter: (v) => v >= 1000 ? `${Math.round(v/1000)}k` : v } },
        series: [
          { name: 'Paid', type: 'line', smooth: true, data: paidSeries, symbolSize: 6, lineStyle: { width: 3, color: '#10b981' }, itemStyle: { color: '#10b981' }, areaStyle: { color: 'rgba(16,185,129,0.10)' } },
          { name: 'Pledges', type: 'line', smooth: true, data: pledged, symbolSize: 6, lineStyle: { width: 3, color: '#3b82f6' }, itemStyle: { color: '#3b82f6' }, areaStyle: { color: 'rgba(59,130,246,0.08)' } },
        ],
      });

    } catch (e) {
      console.error(e);
      alert('Failed to load chart data. Please refresh.');
    } finally {
      els.refreshBtn.classList.remove('loading');
      resizeAll();
    }
  }

  // Init charts
  charts.progress = initChart('chartProgress');
  charts.split = initChart('chartSplit');
  charts.methods = initChart('chartMethods');
  charts.trend = initChart('chartTrend');

  els.refreshBtn.addEventListener('click', () => loadData());

  loadData();
</script>
</body>
</html>

