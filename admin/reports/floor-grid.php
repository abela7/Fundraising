<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

require_once __DIR__ . '/../../shared/IntelligentGridAllocator.php';

$db = db();
$allocator = new IntelligentGridAllocator($db);
$stats = $allocator->getAllocationStats();

$total_cells   = (int)($stats['total_cells'] ?? 0);
$pledged_cells = (int)($stats['pledged_cells'] ?? 0);
$paid_cells    = (int)($stats['paid_cells'] ?? 0);
$available_cells = (int)($stats['available_cells'] ?? 0);
$allocated_cells = $pledged_cells + $paid_cells;
$total_area    = (float)($stats['total_possible_area'] ?? 0);
$allocated_area = (float)($stats['total_allocated_area'] ?? 0);
$pledged_area  = $pledged_cells * 0.25;
$paid_area     = $paid_cells * 0.25;
$available_area = $available_cells * 0.25;
$progress      = $total_area > 0 ? ($allocated_area / $total_area) * 100 : 0;

// Get detailed cell data grouped by status
$gridStatus = $allocator->getGridStatus();
$pledgedList = [];
$paidList = [];
foreach ($gridStatus as $cell) {
    if ($cell['status'] === 'pledged') {
        $key = $cell['donor_name'] ?? 'Unknown';
        if (!isset($pledgedList[$key])) $pledgedList[$key] = ['count' => 0, 'amount' => 0];
        $pledgedList[$key]['count']++;
        $pledgedList[$key]['amount'] += (float)$cell['amount'];
    } elseif ($cell['status'] === 'paid') {
        $key = $cell['donor_name'] ?? 'Unknown';
        if (!isset($paidList[$key])) $paidList[$key] = ['count' => 0, 'amount' => 0];
        $paidList[$key]['count']++;
        $paidList[$key]['amount'] += (float)$cell['amount'];
    }
}
arsort($pledgedList);
arsort($paidList);

$page_title = 'Floor Grid Report';
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
<style>
    .report-page { max-width: 1200px; margin: 0 auto; padding: 1rem; }

    /* Filter tabs */
    .filter-tabs {
        display: flex; gap: 0.5rem; margin-bottom: 1.5rem;
        background: #f1f5f9; padding: 0.375rem; border-radius: 10px; width: fit-content;
    }
    .filter-tab {
        padding: 0.5rem 1.25rem; border-radius: 8px; border: none;
        font-weight: 600; font-size: 0.875rem; cursor: pointer;
        background: transparent; color: #64748b; transition: all 0.2s;
        display: flex; align-items: center; gap: 0.5rem;
    }
    .filter-tab:hover { color: #1e293b; background: rgba(255,255,255,0.5); }
    .filter-tab.active { background: #fff; color: #1e293b; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .filter-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
    .filter-tab[data-filter="all"] .filter-dot { background: #ffd700; }
    .filter-tab[data-filter="pledged"] .filter-dot { background: #f97316; }
    .filter-tab[data-filter="paid"] .filter-dot { background: #22c55e; }

    /* Stat cards */
    .stat-card {
        background: #fff; border-radius: 12px; padding: 1.25rem;
        border: 1px solid #e2e8f0; transition: all 0.2s;
    }
    .stat-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    .stat-value { font-size: 1.75rem; font-weight: 700; line-height: 1.2; }
    .stat-label { font-size: 0.8125rem; color: #64748b; margin-top: 0.25rem; }
    .stat-sub { font-size: 0.75rem; color: #94a3b8; }

    /* Progress bar */
    .grid-progress { height: 28px; background: #f1f5f9; border-radius: 8px; overflow: hidden; position: relative; }
    .grid-progress-pledged { height: 100%; background: #f97316; float: left; transition: width 0.5s; }
    .grid-progress-paid { height: 100%; background: #22c55e; float: left; transition: width 0.5s; }
    .grid-progress-label {
        position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
        font-size: 0.8125rem; font-weight: 700; color: #1e293b; white-space: nowrap;
        text-shadow: 0 0 4px rgba(255,255,255,0.8);
    }

    /* Floor map iframe */
    .floor-frame-wrap {
        background: #131A2D; border-radius: 12px; overflow: hidden;
        border: 1px solid #e2e8f0; position: relative;
    }
    .floor-frame-wrap iframe {
        width: 100%; height: 500px; border: none; display: block;
    }
    .floor-frame-overlay {
        position: absolute; top: 0; left: 0; right: 0; bottom: 0;
        pointer-events: none; z-index: 10;
    }

    /* Donor table */
    .donor-table { font-size: 0.8125rem; }
    .donor-table th { font-weight: 600; color: #64748b; border-bottom: 2px solid #e2e8f0; }
    .donor-table td { vertical-align: middle; }
    .donor-cells-bar {
        height: 6px; border-radius: 3px; display: inline-block; min-width: 4px;
        vertical-align: middle; margin-right: 0.5rem;
    }

    /* Section headers */
    .section-title {
        font-size: 1rem; font-weight: 700; color: #1e293b;
        margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;
    }
    .section-title i { color: #64748b; }

    @media (max-width: 768px) {
        .floor-frame-wrap iframe { height: 350px; }
    }
</style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="report-page">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
                    <div>
                        <h1 class="h4 fw-bold mb-1"><i class="fas fa-th me-2 text-primary"></i>Floor Grid Report</h1>
                        <p class="text-muted small mb-0">Live allocation breakdown by pledge and payment status</p>
                    </div>
                    <a href="index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>Back to Reports
                    </a>
                </div>

                <!-- Filter Tabs -->
                <div class="filter-tabs" id="filterTabs">
                    <button class="filter-tab active" data-filter="all">
                        <span class="filter-dot"></span> All
                    </button>
                    <button class="filter-tab" data-filter="pledged">
                        <span class="filter-dot"></span> Pledged
                    </button>
                    <button class="filter-tab" data-filter="paid">
                        <span class="filter-dot"></span> Paid
                    </button>
                </div>

                <!-- Stats Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="stat-card" id="cardTotal">
                            <div class="stat-value text-dark" id="statValue"><?php echo number_format($allocated_area, 2); ?>m²</div>
                            <div class="stat-label" id="statLabel">Total Allocated</div>
                            <div class="stat-sub"><?php echo $allocated_cells; ?> of <?php echo $total_cells; ?> cells</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card" style="border-left: 3px solid #f97316;">
                            <div class="stat-value" style="color: #f97316;"><?php echo number_format($pledged_area, 2); ?>m²</div>
                            <div class="stat-label">Pledged</div>
                            <div class="stat-sub"><?php echo $pledged_cells; ?> cells</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card" style="border-left: 3px solid #22c55e;">
                            <div class="stat-value" style="color: #22c55e;"><?php echo number_format($paid_area, 2); ?>m²</div>
                            <div class="stat-label">Paid</div>
                            <div class="stat-sub"><?php echo $paid_cells; ?> cells</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card" style="border-left: 3px solid #94a3b8;">
                            <div class="stat-value text-secondary"><?php echo number_format($available_area, 2); ?>m²</div>
                            <div class="stat-label">Available</div>
                            <div class="stat-sub"><?php echo $available_cells; ?> cells</div>
                        </div>
                    </div>
                </div>

                <!-- Progress Bar -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small fw-bold text-muted">Allocation Progress</span>
                        <span class="small fw-bold" id="progressPercent"><?php echo number_format($progress, 1); ?>%</span>
                    </div>
                    <div class="grid-progress">
                        <div class="grid-progress-paid" id="barPaid" style="width: <?php echo $total_cells > 0 ? ($paid_cells / $total_cells) * 100 : 0; ?>%"></div>
                        <div class="grid-progress-pledged" id="barPledged" style="width: <?php echo $total_cells > 0 ? ($pledged_cells / $total_cells) * 100 : 0; ?>%"></div>
                        <div class="grid-progress-label" id="barLabel">
                            <span style="color:#22c55e;">&#9679;</span> Paid <?php echo $paid_cells; ?>
                            &nbsp;&middot;&nbsp;
                            <span style="color:#f97316;">&#9679;</span> Pledged <?php echo $pledged_cells; ?>
                            &nbsp;&middot;&nbsp;
                            <span style="color:#cbd5e1;">&#9679;</span> Available <?php echo $available_cells; ?>
                        </div>
                    </div>
                </div>

                <!-- Floor Map Visual -->
                <div class="mb-4">
                    <div class="section-title"><i class="fas fa-map"></i> Live Floor Map</div>
                    <div class="floor-frame-wrap">
                        <iframe src="../../public/projector/floor/index.php" id="floorFrame" loading="lazy"></iframe>
                    </div>
                    <div class="text-muted small mt-2">
                        <i class="fas fa-info-circle me-1"></i>Live view from projector — updates every 2 seconds
                    </div>
                </div>

                <!-- Donor Breakdown Tables -->
                <div class="row g-4" id="donorTables">
                    <!-- Pledged Donors -->
                    <div class="col-12 col-lg-6" id="pledgedSection">
                        <div class="section-title"><i class="fas fa-hand-holding-usd" style="color:#f97316;"></i> Pledged Donors</div>
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-0">
                                <?php if (empty($pledgedList)): ?>
                                    <div class="p-3 text-muted text-center small">No pledged allocations</div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover donor-table mb-0">
                                        <thead><tr><th>Donor</th><th class="text-center">Cells</th><th class="text-end">Amount</th></tr></thead>
                                        <tbody>
                                        <?php
                                        $maxPledgedCells = max(array_column($pledgedList, 'count'));
                                        foreach ($pledgedList as $name => $data):
                                            $barWidth = ($data['count'] / $maxPledgedCells) * 60;
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($name); ?></td>
                                                <td class="text-center">
                                                    <span class="donor-cells-bar" style="background:#f97316; width:<?php echo $barWidth; ?>px;"></span>
                                                    <?php echo $data['count']; ?>
                                                </td>
                                                <td class="text-end">£<?php echo number_format($data['amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Paid Donors -->
                    <div class="col-12 col-lg-6" id="paidSection">
                        <div class="section-title"><i class="fas fa-check-circle" style="color:#22c55e;"></i> Paid Donors</div>
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-0">
                                <?php if (empty($paidList)): ?>
                                    <div class="p-3 text-muted text-center small">No paid allocations</div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover donor-table mb-0">
                                        <thead><tr><th>Donor</th><th class="text-center">Cells</th><th class="text-end">Amount</th></tr></thead>
                                        <tbody>
                                        <?php
                                        $maxPaidCells = max(array_column($paidList, 'count'));
                                        foreach ($paidList as $name => $data):
                                            $barWidth = ($data['count'] / $maxPaidCells) * 60;
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($name); ?></td>
                                                <td class="text-center">
                                                    <span class="donor-cells-bar" style="background:#22c55e; width:<?php echo $barWidth; ?>px;"></span>
                                                    <?php echo $data['count']; ?>
                                                </td>
                                                <td class="text-end">£<?php echo number_format($data['amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
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
<script>
document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('.filter-tab');
    const pledgedSection = document.getElementById('pledgedSection');
    const paidSection = document.getElementById('paidSection');
    const statCard = document.getElementById('cardTotal');
    const statValue = document.getElementById('statValue');
    const statLabel = document.getElementById('statLabel');
    const barPaid = document.getElementById('barPaid');
    const barPledged = document.getElementById('barPledged');
    const barLabel = document.getElementById('barLabel');
    const progressPercent = document.getElementById('progressPercent');

    const data = {
        total_cells: <?php echo $total_cells; ?>,
        pledged_cells: <?php echo $pledged_cells; ?>,
        paid_cells: <?php echo $paid_cells; ?>,
        available_cells: <?php echo $available_cells; ?>,
        pledged_area: <?php echo $pledged_area; ?>,
        paid_area: <?php echo $paid_area; ?>,
        allocated_area: <?php echo $allocated_area; ?>,
        available_area: <?php echo $available_area; ?>,
        total_area: <?php echo $total_area; ?>
    };

    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const filter = this.dataset.filter;
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            applyFilter(filter);
        });
    });

    function applyFilter(filter) {
        // Update stat card
        if (filter === 'pledged') {
            statValue.textContent = data.pledged_area.toFixed(2) + 'm²';
            statValue.style.color = '#f97316';
            statLabel.textContent = 'Pledged Area';
            statCard.style.borderLeft = '3px solid #f97316';
        } else if (filter === 'paid') {
            statValue.textContent = data.paid_area.toFixed(2) + 'm²';
            statValue.style.color = '#22c55e';
            statLabel.textContent = 'Paid Area';
            statCard.style.borderLeft = '3px solid #22c55e';
        } else {
            statValue.textContent = data.allocated_area.toFixed(2) + 'm²';
            statValue.style.color = '#1e293b';
            statLabel.textContent = 'Total Allocated';
            statCard.style.borderLeft = 'none';
        }

        // Update progress bar
        if (filter === 'pledged') {
            barPaid.style.width = '0%';
            barPledged.style.width = (data.total_cells > 0 ? (data.pledged_cells / data.total_cells) * 100 : 0) + '%';
            const pct = data.total_area > 0 ? (data.pledged_area / data.total_area) * 100 : 0;
            progressPercent.textContent = pct.toFixed(1) + '%';
            barLabel.innerHTML = '<span style="color:#f97316;">&#9679;</span> Pledged ' + data.pledged_cells +
                ' &middot; <span style="color:#cbd5e1;">&#9679;</span> Other ' + (data.total_cells - data.pledged_cells);
        } else if (filter === 'paid') {
            barPledged.style.width = '0%';
            barPaid.style.width = (data.total_cells > 0 ? (data.paid_cells / data.total_cells) * 100 : 0) + '%';
            const pct = data.total_area > 0 ? (data.paid_area / data.total_area) * 100 : 0;
            progressPercent.textContent = pct.toFixed(1) + '%';
            barLabel.innerHTML = '<span style="color:#22c55e;">&#9679;</span> Paid ' + data.paid_cells +
                ' &middot; <span style="color:#cbd5e1;">&#9679;</span> Other ' + (data.total_cells - data.paid_cells);
        } else {
            barPaid.style.width = (data.total_cells > 0 ? (data.paid_cells / data.total_cells) * 100 : 0) + '%';
            barPledged.style.width = (data.total_cells > 0 ? (data.pledged_cells / data.total_cells) * 100 : 0) + '%';
            const pct = data.total_area > 0 ? (data.allocated_area / data.total_area) * 100 : 0;
            progressPercent.textContent = pct.toFixed(1) + '%';
            barLabel.innerHTML = '<span style="color:#22c55e;">&#9679;</span> Paid ' + data.paid_cells +
                ' &nbsp;&middot;&nbsp; <span style="color:#f97316;">&#9679;</span> Pledged ' + data.pledged_cells +
                ' &nbsp;&middot;&nbsp; <span style="color:#cbd5e1;">&#9679;</span> Available ' + data.available_cells;
        }

        // Show/hide donor tables
        if (filter === 'pledged') {
            pledgedSection.style.display = '';
            paidSection.style.display = 'none';
        } else if (filter === 'paid') {
            pledgedSection.style.display = 'none';
            paidSection.style.display = '';
        } else {
            pledgedSection.style.display = '';
            paidSection.style.display = '';
        }
    }
});
</script>
</body>
</html>
