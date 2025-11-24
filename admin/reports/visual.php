<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

// DB and settings
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
} catch (Exception $e) { $db_error_message = 'Database connection failed: '.$e->getMessage(); }

$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
$currentRange = isset($_GET['date']) ? (string)$_GET['date'] : 'month';

// Date Range
function resolve_range(): array {
    $range = $_GET['date'] ?? 'month';
    $from = $_GET['from'] ?? '';
    $to   = $_GET['to'] ?? '';
    $now = new DateTime('now');
    switch ($range) {
        case 'today':   $start = (clone $now)->setTime(0,0,0); $end = (clone $now)->setTime(23,59,59); break;
        case 'week':    $start = (clone $now)->modify('monday this week')->setTime(0,0,0); $end = (clone $now)->modify('sunday this week')->setTime(23,59,59); break;
        case 'quarter': $q = floor(((int)$now->format('n')-1)/3)+1; $start = new DateTime($now->format('Y').'-'.(1+($q-1)*3).'-01 00:00:00'); $end = (clone $start)->modify('+3 months -1 second'); break;
        case 'year':    $start = new DateTime($now->format('Y').'-01-01 00:00:00'); $end = new DateTime($now->format('Y').'-12-31 23:59:59'); break;
        case 'custom':  $start = DateTime::createFromFormat('Y-m-d', $from) ?: (clone $now); $start->setTime(0,0,0); $end = DateTime::createFromFormat('Y-m-d', $to) ?: (clone $now); $end->setTime(23,59,59); break;
        case 'all':     $start = new DateTime('1970-01-01 00:00:00'); $end = new DateTime('2100-01-01 00:00:00'); break;
        default:        $start = new DateTime(date('Y-m-01 00:00:00')); $end = (clone $start)->modify('+1 month -1 second'); break; // month
    }
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

[$fromDate, $toDate] = resolve_range();

// Aggregations
$data = [
    'metrics' => [ 'paid_total'=>0.0, 'pledged_total'=>0.0, 'grand_total'=>0.0 ],
    'timeseries' => [ 'dates'=>[], 'payments_amounts'=>[], 'pledges_amounts'=>[] ],
    'payments_by_method' => [],
    'donations_by_package' => [], // aggregated (payments + pledges)
    'payments_status' => [],
    'pledges_status' => [],
    'registrars_payments' => [],
];

if ($db && $db_error_message === '') {
    // Use centralized FinancialCalculator for consistency
    require_once __DIR__ . '/../../shared/FinancialCalculator.php';
    
    $calculator = new FinancialCalculator();
    $totals = $calculator->getTotals($fromDate, $toDate);
    
    $data['metrics']['paid_total'] = $totals['total_paid'];
    // For date-filtered activity reports, use RAW pledge total (activity semantic)
    $data['metrics']['pledged_total'] = $totals['total_pledges'];
    $data['metrics']['grand_total'] = $data['metrics']['paid_total'] + $data['metrics']['pledged_total'];
    
    $hasPledgePayments = $totals['has_pledge_payments'];

    // Time series per day
    $paymentsByDay = [];
    $pledgesByDay  = [];
    
    // Instant Payments by day
    $res = $db->query("SELECT DATE(received_at) d, COALESCE(SUM(amount),0) t FROM payments WHERE status='approved' AND received_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."' GROUP BY d ORDER BY d");
    while($r=$res->fetch_assoc()){ $paymentsByDay[$r['d']] = (float)$r['t']; }
    
    // Pledge Payments by day (add to paymentsByDay)
    if ($hasPledgePayments) {
        $res = $db->query("SELECT DATE(created_at) d, COALESCE(SUM(amount),0) t FROM pledge_payments WHERE status='confirmed' AND created_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."' GROUP BY d ORDER BY d");
        while($r=$res->fetch_assoc()){ 
            $day = $r['d'];
            $paymentsByDay[$day] = ($paymentsByDay[$day] ?? 0) + (float)$r['t']; 
        }
    }
    
    // Pledges by day
    $res = $db->query("SELECT DATE(created_at) d, COALESCE(SUM(amount),0) t FROM pledges WHERE status='approved' AND created_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."' GROUP BY d ORDER BY d");
    while($r=$res->fetch_assoc()){ $pledgesByDay[$r['d']] = (float)$r['t']; }
    // Build continuous date axis
    $startDate = new DateTime($fromDate); $startDate->setTime(0,0,0);
    $endDate = new DateTime($toDate); $endDate->setTime(0,0,0);
    for($d=(clone $startDate); $d <= $endDate; $d->modify('+1 day')){
        $key = $d->format('Y-m-d');
        $data['timeseries']['dates'][] = $key;
        $data['timeseries']['payments_amounts'][] = (float)($paymentsByDay[$key] ?? 0);
        $data['timeseries']['pledges_amounts'][]  = (float)($pledgesByDay[$key] ?? 0);
    }

    // Payments by method
    $stmt = $db->prepare("SELECT method, COUNT(*) c, COALESCE(SUM(amount),0) t FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ? GROUP BY method ORDER BY t DESC");
    $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $data['payments_by_method'][] = $r; }

    // Donations by package (aggregate payments + pledges totals by label)
    $byPackage = [];
    $stmt = $db->prepare("SELECT COALESCE(dp.label,'Custom') label, COALESCE(SUM(p.amount),0) t FROM payments p LEFT JOIN donation_packages dp ON dp.id=p.package_id WHERE p.status='approved' AND p.received_at BETWEEN ? AND ? GROUP BY label");
    $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $byPackage[(string)$r['label']] = ($byPackage[(string)$r['label']] ?? 0) + (float)$r['t']; }
    $stmt = $db->prepare("SELECT COALESCE(dp.label,'Custom') label, COALESCE(SUM(p.amount),0) t FROM pledges p LEFT JOIN donation_packages dp ON dp.id=p.package_id WHERE p.status='approved' AND p.created_at BETWEEN ? AND ? GROUP BY label");
    $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $byPackage[(string)$r['label']] = ($byPackage[(string)$r['label']] ?? 0) + (float)$r['t']; }
    arsort($byPackage);
    foreach($byPackage as $label=>$val){ $data['donations_by_package'][] = ['name'=>$label,'value'=>(float)$val]; }

    // Status distributions
    $res = $db->query("SELECT status, COUNT(*) c FROM payments WHERE received_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."' GROUP BY status");
    while($r=$res->fetch_assoc()){ $data['payments_status'][] = $r; }
    $res = $db->query("SELECT status, COUNT(*) c FROM pledges WHERE created_at BETWEEN '".$db->real_escape_string($fromDate)."' AND '".$db->real_escape_string($toDate)."' GROUP BY status");
    while($r=$res->fetch_assoc()){ $data['pledges_status'][] = $r; }

    // Registrar performance (payments by received_by_user)
    $stmt = $db->prepare("SELECT COALESCE(u.name,'Unknown') user_name, COALESCE(SUM(p.amount),0) t FROM payments p LEFT JOIN users u ON u.id=p.received_by_user_id WHERE p.status='approved' AND p.received_at BETWEEN ? AND ? GROUP BY user_name ORDER BY t DESC LIMIT 10");
    $stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $data['registrars_payments'][] = $r; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visual Report - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/reports.css">
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.0/dist/echarts.min.js"></script>
    <style>
      .chart-box { height: 340px; }
    </style>
    </head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <?php if (!empty($db_error_message)): ?>
                    <div class="alert alert-danger m-3">
                        <strong><i class="fas fa-exclamation-triangle me-2"></i>Database Error:</strong>
                        <?php echo htmlspecialchars($db_error_message); ?>
                    </div>
                <?php endif; ?>

                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                    <h4 class="mb-0"><i class="fas fa-chart-bar text-primary me-2"></i>Visual Report</h4>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary<?php echo $currentRange==='today'?' active':''; ?>" href="?date=today"><i class="fas fa-clock me-1"></i>Today</a>
                        <a class="btn btn-outline-secondary<?php echo $currentRange==='week'?' active':''; ?>" href="?date=week"><i class="fas fa-calendar-week me-1"></i>This Week</a>
                        <a class="btn btn-outline-secondary<?php echo in_array($currentRange,['','month'])?' active':''; ?>" href="?date=month"><i class="fas fa-calendar me-1"></i>This Month</a>
                        <a class="btn btn-outline-secondary<?php echo $currentRange==='quarter'?' active':''; ?>" href="?date=quarter"><i class="fas fa-calendar-alt me-1"></i>Quarter</a>
                        <a class="btn btn-outline-secondary<?php echo $currentRange==='year'?' active':''; ?>" href="?date=year"><i class="fas fa-calendar-day me-1"></i>This Year</a>
                        <a class="btn btn-outline-secondary<?php echo $currentRange==='all'?' active':''; ?>" href="?date=all"><i class="fas fa-infinity me-1"></i>All Time</a>
                        <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
                    </div>
                </div>

                <!-- KPI Summary -->
                <div class="row g-3 mb-3">
                    <div class="col-xl-4 col-md-6">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center">
                            <div class="icon-circle bg-primary text-white"><i class="fas fa-sack-dollar"></i></div>
                            <div class="ms-3">
                                <div class="small fw-bold text-primary mb-1">Grand Total Raised</div>
                                <div class="h5 mb-0"><?php echo $currency.' '.number_format((float)$data['metrics']['grand_total'], 2); ?></div>
                            </div>
                        </div></div>
                    </div>
                    <div class="col-xl-4 col-md-6">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center">
                            <div class="icon-circle bg-success text-white"><i class="fas fa-check-circle"></i></div>
                            <div class="ms-3">
                                <div class="small fw-bold text-success mb-1">Paid (approved)</div>
                                <div class="h5 mb-0"><?php echo $currency.' '.number_format((float)$data['metrics']['paid_total'], 2); ?></div>
                            </div>
                        </div></div>
                    </div>
                    <div class="col-xl-4 col-md-6">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center">
                            <div class="icon-circle bg-warning text-white"><i class="fas fa-hand-holding-heart"></i></div>
                            <div class="ms-3">
                                <div class="small fw-bold text-warning mb-1">Pledged (approved)</div>
                                <div class="h5 mb-0"><?php echo $currency.' '.number_format((float)$data['metrics']['pledged_total'], 2); ?></div>
                            </div>
                        </div></div>
                    </div>
                </div>

                <!-- Charts Grid -->
                <div class="row g-3">
                    <div class="col-xl-6">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                            <h6 class="mb-2"><i class="fas fa-chart-pie me-2 text-primary"></i>Donations by Package</h6>
                            <div id="chartPackage" class="chart-box"></div>
                        </div></div>
                    </div>
                    <div class="col-xl-6">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                            <h6 class="mb-2"><i class="fas fa-receipt me-2 text-success"></i>Payments by Method</h6>
                            <div id="chartMethod" class="chart-box"></div>
                        </div></div>
                    </div>
                    <div class="col-12">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                            <h6 class="mb-2"><i class="fas fa-chart-line me-2 text-info"></i>Cumulative Raised Over Time</h6>
                            <div id="chartCumulative" class="chart-box" style="height: 380px"></div>
                        </div></div>
                    </div>
                    <div class="col-xl-6">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                            <h6 class="mb-2"><i class="fas fa-list-check me-2 text-secondary"></i>Status Distribution (Payments)</h6>
                            <div id="chartPayStatus" class="chart-box"></div>
                        </div></div>
                    </div>
                    <div class="col-xl-6">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                            <h6 class="mb-2"><i class="fas fa-list-check me-2 text-secondary"></i>Status Distribution (Pledges)</h6>
                            <div id="chartPledgeStatus" class="chart-box"></div>
                        </div></div>
                    </div>
                    <div class="col-12">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body">
                            <h6 class="mb-2"><i class="fas fa-user-tie me-2 text-dark"></i>Top Registrars by Payments</h6>
                            <div id="chartRegistrars" class="chart-box"></div>
                        </div></div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
  window.VISUAL_DATA = <?php echo json_encode([
    'currency' => $currency,
    'from' => $fromDate,
    'to' => $toDate,
    'data' => $data,
  ]); ?>;

  (function(){
    if(!window.echarts) return;
    const D = window.VISUAL_DATA.data;
    const C = window.VISUAL_DATA.currency;

    function fmtValue(v){ return C + ' ' + Number(v).toLocaleString(undefined,{ minimumFractionDigits:2, maximumFractionDigits:2 }); }

    // Donations by Package (donut)
    (function(){
      const el = document.getElementById('chartPackage'); if(!el) return;
      const chart = echarts.init(el);
      chart.setOption({
        tooltip:{ trigger:'item', formatter: p => `${p.name}: ${fmtValue(p.value)} (${p.percent}%)` },
        legend:{ bottom: 0 },
        series:[{ type:'pie', name:'Package', radius:['40%','70%'], itemStyle:{ borderRadius:6, borderColor:'#fff', borderWidth:2 }, label:{ show:true, formatter:'{b}: {d}%' }, data: D.donations_by_package }]
      });
      window.addEventListener('resize', ()=>chart.resize());
    })();

    // Payments by Method (pie)
    (function(){
      const el = document.getElementById('chartMethod'); if(!el) return;
      const chart = echarts.init(el);
      const data = (D.payments_by_method||[]).map(r=>({ name: (r.method||'Unknown'), value: Number(r.t||0) }));
      chart.setOption({
        tooltip:{ trigger:'item', formatter: p => `${p.name}: ${fmtValue(p.value)} (${p.percent}%)` },
        legend:{ bottom: 0 },
        series:[{ type:'pie', radius:['40%','70%'], itemStyle:{ borderRadius:6, borderColor:'#fff', borderWidth:2 }, label:{ show:true, formatter:'{b}: {d}%' }, data }]
      });
      window.addEventListener('resize', ()=>chart.resize());
    })();

    // Cumulative Raised Over Time (lines)
    (function(){
      const el = document.getElementById('chartCumulative'); if(!el) return;
      const chart = echarts.init(el);
      const dates = D.timeseries.dates || [];
      const paid = (D.timeseries.payments_amounts||[]).map(v=>Number(v||0));
      const pledges = (D.timeseries.pledges_amounts||[]).map(v=>Number(v||0));
      const cumPaid = []; const cumPledge = []; const cumTotal = [];
      let a=0,b=0; for(let i=0;i<dates.length;i++){ a+=paid[i]||0; b+=pledges[i]||0; cumPaid.push(a); cumPledge.push(b); cumTotal.push(a+b); }
      chart.setOption({
        tooltip:{ trigger:'axis', valueFormatter: v => fmtValue(v) },
        legend:{ top: 0 },
        grid:{ left: 50, right: 20, top: 40, bottom: 40 },
        xAxis:{ type:'category', data: dates },
        yAxis:{ type:'value' },
        dataZoom:[{ type:'inside' },{ type:'slider' }],
        series:[
          { name:'Cumulative Total', type:'line', smooth:true, symbol:'none', lineStyle:{ width:3 }, areaStyle:{ opacity:0.08 }, data: cumTotal },
          { name:'Cumulative Paid', type:'line', smooth:true, symbol:'none', data: cumPaid },
          { name:'Cumulative Pledged', type:'line', smooth:true, symbol:'none', data: cumPledge }
        ]
      });
      window.addEventListener('resize', ()=>chart.resize());
    })();

    // Status distributions (payments)
    (function(){
      const el = document.getElementById('chartPayStatus'); if(!el) return;
      const chart = echarts.init(el);
      const cats = (D.payments_status||[]).map(r=>r.status);
      const vals = (D.payments_status||[]).map(r=>Number(r.c||0));
      chart.setOption({
        tooltip:{ trigger:'axis' }, xAxis:{ type:'category', data: cats }, yAxis:{ type:'value' },
        series:[{ type:'bar', data: vals, itemStyle:{ color:'#198754' } }]
      });
      window.addEventListener('resize', ()=>chart.resize());
    })();

    // Status distributions (pledges)
    (function(){
      const el = document.getElementById('chartPledgeStatus'); if(!el) return;
      const chart = echarts.init(el);
      const cats = (D.pledges_status||[]).map(r=>r.status);
      const vals = (D.pledges_status||[]).map(r=>Number(r.c||0));
      chart.setOption({
        tooltip:{ trigger:'axis' }, xAxis:{ type:'category', data: cats }, yAxis:{ type:'value' },
        series:[{ type:'bar', data: vals, itemStyle:{ color:'#ffc107' } }]
      });
      window.addEventListener('resize', ()=>chart.resize());
    })();

    // Top registrars by payment amount (horizontal bar)
    (function(){
      const el = document.getElementById('chartRegistrars'); if(!el) return;
      const chart = echarts.init(el);
      const cats = (D.registrars_payments||[]).map(r=>r.user_name);
      const vals = (D.registrars_payments||[]).map(r=>Number(r.t||0));
      chart.setOption({
        tooltip:{ trigger:'axis', valueFormatter: v => fmtValue(v) },
        grid:{ left: 120, right: 20, top: 20, bottom: 20 },
        xAxis:{ type:'value' },
        yAxis:{ type:'category', data: cats, inverse: true },
        series:[{ type:'bar', data: vals, itemStyle:{ color:'#0dcaf0' } }]
      });
      window.addEventListener('resize', ()=>chart.resize());
    })();

  })();
</script>
</body>
</html>


