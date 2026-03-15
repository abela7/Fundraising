<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_login();
require_admin();

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
                            <div class="stat-num" id="statAllNum" style="color:#1e293b;">--<small class="fw-normal" style="font-size:0.6em;">m²</small></div>
                            <div class="stat-lbl" id="statAllLabel">Allocated</div>
                            <div class="stat-sub" id="statAllSub">loading...</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-box" id="statPledged">
                            <div class="stat-num" id="statPledgedNum" style="color:#f97316;">--<small class="fw-normal" style="font-size:0.6em;">m²</small></div>
                            <div class="stat-lbl">Pledged</div>
                            <div class="stat-sub" id="statPledgedSub">loading...</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-box" id="statPaid">
                            <div class="stat-num" id="statPaidNum" style="color:#22c55e;">--<small class="fw-normal" style="font-size:0.6em;">m²</small></div>
                            <div class="stat-lbl">Paid</div>
                            <div class="stat-sub" id="statPaidSub">loading...</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-box">
                            <div class="stat-num" id="statAvailNum" style="color:#94a3b8;">--<small class="fw-normal" style="font-size:0.6em;">m²</small></div>
                            <div class="stat-lbl">Available</div>
                            <div class="stat-sub" id="statAvailSub">loading...</div>
                        </div>
                    </div>
                </div>

                <!-- Progress bar -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="small text-muted fw-bold">Progress</span>
                        <span class="small fw-bold" id="progressPct">0.0%</span>
                    </div>
                    <div class="alloc-bar">
                        <div class="alloc-bar-paid" id="barPaid" style="width:0%"></div>
                        <div class="alloc-bar-pledged" id="barPledged" style="width:0%"></div>
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
    const apiUrl = '../../api/grid_status.php';

    let currentFilter = 'all';
    let liveData = null; // { summary, grid_cells }

    // --- Fetch live data from API ---
    function fetchData() {
        fetch(apiUrl)
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.data) return;

                // Count effective pledged/paid from actual cell data (considers pledge payments)
                let pledgedCount = 0, paidCount = 0, blockedCount = 0;
                const gridCells = data.data.grid_cells || {};
                for (const rectId in gridCells) {
                    const cells = gridCells[rectId];
                    if (!Array.isArray(cells)) continue;
                    cells.forEach(c => {
                        if (c.status === 'pledged') pledgedCount++;
                        else if (c.status === 'paid') paidCount++;
                        else if (c.status === 'blocked') blockedCount++;
                    });
                }

                const summary = data.data.summary || {};
                const totalCells = parseInt(summary.total_cells || 0);
                const availableCells = parseInt(summary.available_cells || 0);
                const totalArea = parseFloat(summary.total_area_sqm || 0);
                const cellArea = 0.25;

                liveData = {
                    totalCells: totalCells,
                    pledgedCells: pledgedCount,
                    paidCells: paidCount,
                    availableCells: availableCells,
                    pledgedArea: pledgedCount * cellArea,
                    paidArea: paidCount * cellArea,
                    allocatedArea: (pledgedCount + paidCount) * cellArea,
                    totalArea: totalArea
                };

                renderStats(currentFilter);
            })
            .catch(err => console.error('Report: API fetch error', err));
    }

    // --- Render stats based on filter ---
    function renderStats(filter) {
        if (!liveData) return;
        const d = liveData;

        // Always update pledged/paid/available cards
        document.getElementById('statPledgedNum').innerHTML = d.pledgedArea.toFixed(2) + '<small class="fw-normal" style="font-size:0.6em;">m²</small>';
        document.getElementById('statPledgedSub').textContent = d.pledgedCells + ' cells';

        document.getElementById('statPaidNum').innerHTML = d.paidArea.toFixed(2) + '<small class="fw-normal" style="font-size:0.6em;">m²</small>';
        document.getElementById('statPaidSub').textContent = d.paidCells + ' cells';

        document.getElementById('statAvailNum').innerHTML = (d.totalArea - d.allocatedArea).toFixed(2) + '<small class="fw-normal" style="font-size:0.6em;">m²</small>';
        document.getElementById('statAvailSub').textContent = d.availableCells + ' cells';

        // First card changes based on filter
        const statAllNum = document.getElementById('statAllNum');
        const statAllLabel = document.getElementById('statAllLabel');
        const statAllSub = document.getElementById('statAllSub');
        const barPaid = document.getElementById('barPaid');
        const barPledged = document.getElementById('barPledged');
        const progressPct = document.getElementById('progressPct');

        let area, cells, color, label;

        if (filter === 'pledged') {
            area = d.pledgedArea; cells = d.pledgedCells; color = '#f97316'; label = 'Pledged';
            barPaid.style.width = '0%';
            barPledged.style.width = (d.totalCells > 0 ? (d.pledgedCells / d.totalCells) * 100 : 0) + '%';
        } else if (filter === 'paid') {
            area = d.paidArea; cells = d.paidCells; color = '#22c55e'; label = 'Paid';
            barPledged.style.width = '0%';
            barPaid.style.width = (d.totalCells > 0 ? (d.paidCells / d.totalCells) * 100 : 0) + '%';
        } else {
            area = d.allocatedArea; cells = d.pledgedCells + d.paidCells; color = '#1e293b'; label = 'Allocated';
            barPaid.style.width = (d.totalCells > 0 ? (d.paidCells / d.totalCells) * 100 : 0) + '%';
            barPledged.style.width = (d.totalCells > 0 ? (d.pledgedCells / d.totalCells) * 100 : 0) + '%';
        }

        const pct = d.totalArea > 0 ? (area / d.totalArea) * 100 : 0;
        progressPct.textContent = pct.toFixed(1) + '%';
        statAllNum.innerHTML = area.toFixed(2) + '<small class="fw-normal" style="font-size:0.6em;">m²</small>';
        statAllNum.style.color = color;
        statAllLabel.textContent = label;
        statAllSub.textContent = cells + ' cells';
    }

    // --- Filter button clicks ---
    buttons.forEach(btn => {
        btn.addEventListener('click', function() {
            const filter = this.dataset.filter;
            if (filter === currentFilter) return;
            currentFilter = filter;

            buttons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            // Send filter to iframe grid
            frame.contentWindow.postMessage({ type: 'gridFilter', filter: filter }, '*');

            // Update stats
            renderStats(filter);
        });
    });

    // --- Initial fetch + poll every 10s ---
    fetchData();
    setInterval(fetchData, 10000);
});
</script>
</body>
</html>
