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

$total_cells     = (int)($stats['total_cells'] ?? 0);
$pledged_cells   = (int)($stats['pledged_cells'] ?? 0);
$paid_cells      = (int)($stats['paid_cells'] ?? 0);
$available_cells = (int)($stats['available_cells'] ?? 0);
$allocated_cells = $pledged_cells + $paid_cells;
$total_area      = (float)($stats['total_possible_area'] ?? 0);
$allocated_area  = (float)($stats['total_allocated_area'] ?? 0);
$pledged_area    = $pledged_cells * 0.25;
$paid_area       = $paid_cells * 0.25;
$available_area  = $available_cells * 0.25;
$progress        = $total_area > 0 ? ($allocated_area / $total_area) * 100 : 0;

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
    .report-page { max-width: 1300px; margin: 0 auto; padding: 1rem; }

    /* Filter bar */
    .filter-bar {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .filter-bar .filter-btn {
        padding: 0.5rem 1.5rem;
        border-radius: 8px;
        border: 2px solid #e2e8f0;
        font-weight: 600;
        font-size: 0.875rem;
        cursor: pointer;
        background: #fff;
        color: #64748b;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .filter-bar .filter-btn:hover { border-color: #94a3b8; color: #1e293b; }
    .filter-btn.active[data-filter="all"]     { border-color: #ffd700; background: #fffbeb; color: #92400e; }
    .filter-btn.active[data-filter="pledged"] { border-color: #f97316; background: #fff7ed; color: #c2410c; }
    .filter-btn.active[data-filter="paid"]    { border-color: #22c55e; background: #f0fdf4; color: #15803d; }
    .filter-dot { width: 10px; height: 10px; border-radius: 50%; }
    .filter-btn[data-filter="all"] .filter-dot     { background: #ffd700; }
    .filter-btn[data-filter="pledged"] .filter-dot  { background: #f97316; }
    .filter-btn[data-filter="paid"] .filter-dot     { background: #22c55e; }

    /* Stats row */
    .stat-box {
        text-align: center;
        padding: 1rem 0.75rem;
        border-radius: 10px;
        background: #fff;
        border: 1px solid #e2e8f0;
    }
    .stat-box .stat-num { font-size: 1.5rem; font-weight: 700; line-height: 1.2; }
    .stat-box .stat-lbl { font-size: 0.75rem; color: #64748b; margin-top: 0.125rem; }
    .stat-box .stat-sub { font-size: 0.6875rem; color: #94a3b8; }

    /* Floor map container */
    .grid-frame-container {
        background: #131A2D;
        border-radius: 12px;
        overflow: hidden;
        border: 2px solid #1e293b;
        position: relative;
    }
    .grid-frame-container iframe {
        width: 100%;
        border: none;
        display: block;
        height: 60vh;
        min-height: 400px;
        max-height: 700px;
    }

    /* Legend */
    .legend {
        display: flex;
        gap: 1.5rem;
        flex-wrap: wrap;
    }
    .legend-item {
        display: flex;
        align-items: center;
        gap: 0.375rem;
        font-size: 0.8125rem;
        color: #475569;
        font-weight: 500;
    }
    .legend-swatch {
        width: 14px;
        height: 14px;
        border-radius: 3px;
        display: inline-block;
    }

    /* Progress bar */
    .alloc-bar { height: 10px; background: #f1f5f9; border-radius: 5px; overflow: hidden; display: flex; }
    .alloc-bar-paid { height: 100%; background: #22c55e; transition: width 0.4s; }
    .alloc-bar-pledged { height: 100%; background: #f97316; transition: width 0.4s; }

    @media (max-width: 768px) {
        .grid-frame-container iframe { height: 45vh; min-height: 300px; }
        .stat-box .stat-num { font-size: 1.25rem; }
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

                <!-- Header -->
                <div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
                    <div>
                        <h1 class="h4 fw-bold mb-1"><i class="fas fa-th me-2" style="color:#0a6286;"></i>Floor Grid Report</h1>
                        <p class="text-muted small mb-0">Visual allocation breakdown — pledged vs paid</p>
                    </div>
                    <a href="index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>Reports
                    </a>
                </div>

                <!-- Filter + Stats row -->
                <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
                    <div class="filter-bar" id="filterBar">
                        <button class="filter-btn active" data-filter="all">
                            <span class="filter-dot"></span> All
                        </button>
                        <button class="filter-btn" data-filter="pledged">
                            <span class="filter-dot"></span> Pledged Only
                        </button>
                        <button class="filter-btn" data-filter="paid">
                            <span class="filter-dot"></span> Paid Only
                        </button>
                    </div>
                    <div class="legend">
                        <div class="legend-item"><span class="legend-swatch" style="background:#f97316;"></span> Pledged</div>
                        <div class="legend-item"><span class="legend-swatch" style="background:#22c55e;"></span> Paid</div>
                        <div class="legend-item"><span class="legend-swatch" style="background:#8B8680;"></span> Available</div>
                    </div>
                </div>

                <!-- Stats cards -->
                <div class="row g-2 mb-3">
                    <div class="col-6 col-md-3">
                        <div class="stat-box" id="statAll">
                            <div class="stat-num" style="color:#1e293b;"><?php echo number_format($allocated_area, 2); ?><small class="fw-normal" style="font-size:0.6em;">m²</small></div>
                            <div class="stat-lbl">Allocated</div>
                            <div class="stat-sub"><?php echo $allocated_cells; ?> cells</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-box">
                            <div class="stat-num" style="color:#f97316;"><?php echo number_format($pledged_area, 2); ?><small class="fw-normal" style="font-size:0.6em;">m²</small></div>
                            <div class="stat-lbl">Pledged</div>
                            <div class="stat-sub"><?php echo $pledged_cells; ?> cells</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-box">
                            <div class="stat-num" style="color:#22c55e;"><?php echo number_format($paid_area, 2); ?><small class="fw-normal" style="font-size:0.6em;">m²</small></div>
                            <div class="stat-lbl">Paid</div>
                            <div class="stat-sub"><?php echo $paid_cells; ?> cells</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-box">
                            <div class="stat-num" style="color:#94a3b8;"><?php echo number_format($available_area, 2); ?><small class="fw-normal" style="font-size:0.6em;">m²</small></div>
                            <div class="stat-lbl">Available</div>
                            <div class="stat-sub"><?php echo $available_cells; ?> cells</div>
                        </div>
                    </div>
                </div>

                <!-- Progress bar -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="small text-muted fw-bold">Progress</span>
                        <span class="small fw-bold" id="progressPct"><?php echo number_format($progress, 1); ?>%</span>
                    </div>
                    <div class="alloc-bar">
                        <div class="alloc-bar-paid" id="barPaid" style="width:<?php echo $total_cells > 0 ? ($paid_cells / $total_cells) * 100 : 0; ?>%"></div>
                        <div class="alloc-bar-pledged" id="barPledged" style="width:<?php echo $total_cells > 0 ? ($pledged_cells / $total_cells) * 100 : 0; ?>%"></div>
                    </div>
                </div>

                <!-- Floor Map Grid -->
                <div class="grid-frame-container mb-3">
                    <iframe src="../../public/projector/floor/index.php?filter=all" id="gridFrame"></iframe>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const frame = document.getElementById('gridFrame');
    const buttons = document.querySelectorAll('#filterBar .filter-btn');
    const barPaid = document.getElementById('barPaid');
    const barPledged = document.getElementById('barPledged');
    const progressPct = document.getElementById('progressPct');
    const statAll = document.getElementById('statAll');

    const data = {
        total_cells: <?php echo $total_cells; ?>,
        pledged_cells: <?php echo $pledged_cells; ?>,
        paid_cells: <?php echo $paid_cells; ?>,
        available_cells: <?php echo $available_cells; ?>,
        pledged_area: <?php echo $pledged_area; ?>,
        paid_area: <?php echo $paid_area; ?>,
        allocated_area: <?php echo $allocated_area; ?>,
        total_area: <?php echo $total_area; ?>
    };

    let currentFilter = 'all';

    buttons.forEach(btn => {
        btn.addEventListener('click', function() {
            const filter = this.dataset.filter;
            if (filter === currentFilter) return;
            currentFilter = filter;

            buttons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            // Send filter to iframe
            frame.contentWindow.postMessage({ type: 'gridFilter', filter: filter }, '*');

            // Update stats
            updateStats(filter);
        });
    });

    function updateStats(filter) {
        let area, cells, pct, color;
        if (filter === 'pledged') {
            area = data.pledged_area; cells = data.pledged_cells; color = '#f97316';
            barPaid.style.width = '0%';
            barPledged.style.width = (data.total_cells > 0 ? (data.pledged_cells / data.total_cells) * 100 : 0) + '%';
        } else if (filter === 'paid') {
            area = data.paid_area; cells = data.paid_cells; color = '#22c55e';
            barPledged.style.width = '0%';
            barPaid.style.width = (data.total_cells > 0 ? (data.paid_cells / data.total_cells) * 100 : 0) + '%';
        } else {
            area = data.allocated_area; cells = data.pledged_cells + data.paid_cells; color = '#1e293b';
            barPaid.style.width = (data.total_cells > 0 ? (data.paid_cells / data.total_cells) * 100 : 0) + '%';
            barPledged.style.width = (data.total_cells > 0 ? (data.pledged_cells / data.total_cells) * 100 : 0) + '%';
        }
        pct = data.total_area > 0 ? (area / data.total_area) * 100 : 0;
        progressPct.textContent = pct.toFixed(1) + '%';

        const label = filter === 'pledged' ? 'Pledged' : filter === 'paid' ? 'Paid' : 'Allocated';
        statAll.innerHTML =
            '<div class="stat-num" style="color:' + color + ';">' + area.toFixed(2) + '<small class="fw-normal" style="font-size:0.6em;">m²</small></div>' +
            '<div class="stat-lbl">' + label + '</div>' +
            '<div class="stat-sub">' + cells + ' cells</div>';
    }
});
</script>
</body>
</html>
