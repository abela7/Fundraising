<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

$db_error_message = '';
$settings = ['currency_code' => 'GBP'];
$totals = ['direct' => 0, 'pledge' => 0, 'total' => 0];

try {
    $db = db();
    $settings_table_exists = $db->query("SHOW TABLES LIKE 'settings'")->num_rows > 0;
    if ($settings_table_exists) {
        $row = $db->query('SELECT currency_code FROM settings WHERE id = 1')->fetch_assoc();
        if (is_array($row) && isset($row['currency_code'])) {
            $settings['currency_code'] = (string)$row['currency_code'];
        }
    }

    $totals['direct'] = (float)($db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'approved'")->fetch_row()[0] ?? 0);
    $hasPP = $db->query("SHOW TABLES LIKE 'pledge_payments'")->num_rows > 0;
    $totals['pledge'] = $hasPP ? (float)($db->query("SELECT COALESCE(SUM(amount),0) FROM pledge_payments WHERE status = 'confirmed'")->fetch_row()[0] ?? 0) : 0;
    $totals['total'] = $totals['direct'] + $totals['pledge'];
} catch (Exception $e) {
    $db_error_message = 'Database connection failed: ' . $e->getMessage();
}

$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
$page_title = 'Total Paid - Detail';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Total Paid - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/reports.css">
    <style>
        .tp-page-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .tp-page-header h1 { font-size: 1.5rem; font-weight: 700; color: var(--gray-900); margin: 0; }
        .tp-page-header p { color: var(--gray-500); font-size: 0.875rem; margin: 4px 0 0; }
        .tp-tabs .nav-link { font-weight: 500; color: var(--gray-600); }
        .tp-tabs .nav-link:hover { color: var(--primary); background: var(--gray-50); }
        .tp-tabs .nav-link.active { font-weight: 600; color: var(--primary); }
        .tp-stat-row { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
        .tp-stat-chip { display: flex; align-items: center; gap: 10px; background: var(--white); border: 1px solid var(--gray-200); border-radius: 10px; padding: 12px 18px; box-shadow: var(--shadow-sm); flex: 1; min-width: 140px; }
        .tp-stat-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; flex-shrink: 0; }
        .tp-stat-icon.total { background: rgba(16, 185, 129, 0.15); color: var(--success); }
        .tp-stat-icon.direct { background: rgba(59, 130, 246, 0.1); color: var(--info); }
        .tp-stat-icon.pledge { background: rgba(10, 98, 134, 0.1); color: var(--primary); }
        .tp-stat-value { font-size: 1.25rem; font-weight: 700; color: var(--gray-900); }
        .tp-stat-label { font-size: 0.6875rem; font-weight: 500; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.4px; }
        .tp-filter-bar { background: var(--white); border: 1px solid var(--gray-200); border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; box-shadow: var(--shadow-sm); }
        .tp-data-card { background: var(--white); border: 1px solid var(--gray-200); border-radius: 12px; box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 16px; }
        .tp-table-header { padding: 14px 20px; background: var(--gray-50); border-bottom: 1px solid var(--gray-200); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; }
        .tp-data-card .table thead th { font-size: 0.75rem; font-weight: 600; color: var(--gray-500); text-transform: uppercase; padding: 10px 16px; }
        .tp-data-card .table tbody td { padding: 10px 16px; font-size: 0.875rem; }
        .tp-data-card .table tbody tr:hover { background: var(--gray-50); }
        .tp-donor-link { font-weight: 600; color: var(--primary); text-decoration: none; }
        .tp-donor-link:hover { color: var(--primary-dark); text-decoration: underline; }
        .tp-pagination-wrapper { padding: 1rem 20px; border-top: 1px solid var(--gray-100); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; }
        .tp-empty-state { text-align: center; padding: 48px 20px; color: var(--gray-500); }
        .tp-empty-state i { font-size: 2.5rem; color: var(--gray-300); margin-bottom: 12px; display: block; }
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
                    <div class="alert alert-danger mb-3"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($db_error_message); ?></div>
                <?php endif; ?>

                <div class="tp-page-header">
                    <div>
                        <h1><i class="fas fa-check-circle me-2" style="color:var(--success)"></i>Total Paid</h1>
                        <p>Direct payments + pledge payments. All approved/confirmed.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="financial-dashboard.php"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
                        <button class="btn btn-primary" id="exportCsvBtn" type="button"><i class="fas fa-file-csv me-1"></i>Export CSV</button>
                    </div>
                </div>

                <div class="tp-stat-row">
                    <div class="tp-stat-chip">
                        <div class="tp-stat-icon total"><i class="fas fa-check-circle"></i></div>
                        <div>
                            <div class="tp-stat-value"><?php echo $currency . ' ' . number_format($totals['total'], 2); ?></div>
                            <div class="tp-stat-label">Total Paid</div>
                        </div>
                    </div>
                    <div class="tp-stat-chip">
                        <div class="tp-stat-icon direct"><i class="fas fa-money-bill-wave"></i></div>
                        <div>
                            <div class="tp-stat-value"><?php echo $currency . ' ' . number_format($totals['direct'], 2); ?></div>
                            <div class="tp-stat-label">Direct payments</div>
                        </div>
                    </div>
                    <div class="tp-stat-chip">
                        <div class="tp-stat-icon pledge"><i class="fas fa-money-bill-transfer"></i></div>
                        <div>
                            <div class="tp-stat-value"><?php echo $currency . ' ' . number_format($totals['pledge'], 2); ?></div>
                            <div class="tp-stat-label">Pledge payments</div>
                        </div>
                    </div>
                </div>

                <ul class="nav nav-tabs tp-tabs mb-3" id="paidTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-direct-btn" data-bs-toggle="tab" data-bs-target="#tab-direct" type="button" role="tab"><i class="fas fa-money-bill-wave me-1"></i>Direct Payments</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-pledge-btn" data-bs-toggle="tab" data-bs-target="#tab-pledge" type="button" role="tab"><i class="fas fa-money-bill-transfer me-1"></i>Pledge Payments</button>
                    </li>
                </ul>

                <div class="tp-filter-bar">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-6">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:var(--gray-500)">Donor (name, phone)</label>
                            <input type="text" class="form-control form-control-sm" id="filterDonor" placeholder="Search...">
                        </div>
                        <div class="col-12 col-md-2">
                            <button class="btn btn-primary btn-sm w-100" id="applyFilters"><i class="fas fa-search me-1"></i>Apply</button>
                        </div>
                        <div class="col-12 col-md-2">
                            <button class="btn btn-outline-secondary btn-sm w-100" id="clearFilters"><i class="fas fa-times me-1"></i>Clear</button>
                        </div>
                    </div>
                </div>

                <div class="tab-content" id="paidTabsContent">
                    <div class="tab-pane fade show active" id="tab-direct" role="tabpanel">
                        <div class="tp-data-card">
                            <div class="tp-table-header">
                                <h6 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Direct Payments (approved)</h6>
                                <select class="form-select form-select-sm" id="perPageDirect" style="width: auto;">
                                    <option value="10">10</option><option value="25" selected>25</option><option value="50">50</option><option value="100">100</option>
                                </select>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr><th>#</th><th>Donor</th><th class="text-end">Amount</th><th>Method</th><th>Date</th><th></th></tr>
                                    </thead>
                                    <tbody id="directBody"><tr><td colspan="6" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading...</td></tr></tbody>
                                </table>
                            </div>
                            <div class="tp-pagination-wrapper">
                                <div class="small text-muted" id="directInfo">—</div>
                                <ul class="pagination pagination-sm mb-0" id="directPagination"></ul>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tab-pledge" role="tabpanel">
                        <div class="tp-data-card">
                            <div class="tp-table-header">
                                <h6 class="mb-0"><i class="fas fa-money-bill-transfer me-2"></i>Pledge Payments (confirmed)</h6>
                                <select class="form-select form-select-sm" id="perPagePledge" style="width: auto;">
                                    <option value="10">10</option><option value="25" selected>25</option><option value="50">50</option><option value="100">100</option>
                                </select>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr><th>#</th><th>Donor</th><th class="text-end">Amount</th><th>Method</th><th>Date</th><th></th></tr>
                                    </thead>
                                    <tbody id="pledgeBody"><tr><td colspan="6" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading...</td></tr></tbody>
                                </table>
                            </div>
                            <div class="tp-pagination-wrapper">
                                <div class="small text-muted" id="pledgeInfo">—</div>
                                <ul class="pagination pagination-sm mb-0" id="pledgePagination"></ul>
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
(function(){
  const CURRENCY = <?php echo json_encode($currency); ?>;
  function fmtMoney(n) { try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: CURRENCY, maximumFractionDigits: 2 }).format(Number(n) || 0); } catch(_) { return CURRENCY + ' ' + (Number(n) || 0).toFixed(2); } }
  function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  function paginate(ulId, page, total, fn) {
    const ul = document.getElementById(ulId);
    if (!total || total <= 1) { ul.innerHTML = ''; return; }
    let html = '';
    if (page > 1) html += '<li class="page-item"><a class="page-link" href="#" data-p="' + (page-1) + '"><i class="fas fa-angle-left"></i></a></li>';
    for (let i = Math.max(1, page-2); i <= Math.min(total, page+2); i++) html += '<li class="page-item ' + (i === page ? 'active' : '') + '"><a class="page-link" href="#" data-p="' + i + '">' + i + '</a></li>';
    if (page < total) html += '<li class="page-item"><a class="page-link" href="#" data-p="' + (page+1) + '"><i class="fas fa-angle-right"></i></a></li>';
    ul.innerHTML = html;
    ul.querySelectorAll('a[data-p]').forEach(a => { a.addEventListener('click', e => { e.preventDefault(); fn(parseInt(a.dataset.p, 10)); }); });
  }

  function loadDirect(page) {
    const donor = document.getElementById('filterDonor').value.trim();
    const perPage = document.getElementById('perPageDirect').value;
    const p = new URLSearchParams({ page: page || 1, per_page: perPage });
    if (donor) p.set('donor', donor);
    fetch('api/direct-payments.php?' + p.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => { if (!(r.headers.get('Content-Type') || '').includes('application/json')) throw new Error('Non-JSON response'); return r.json(); })
      .then(d => {
        const body = document.getElementById('directBody');
        const rows = d.rows || [];
        const start = (d.page - 1) * d.per_page + 1;
        if (rows.length === 0) {
          body.innerHTML = '<tr><td colspan="6"><div class="tp-empty-state"><i class="fas fa-inbox"></i><p>No direct payments found.</p></div></td></tr>';
        } else {
          body.innerHTML = rows.map((r, i) => {
            const link = r.donor_id ? '../donor-management/view-donor.php?id=' + r.donor_id : '#';
            return '<tr><td>' + (start + i) + '</td><td><a href="' + link + '" class="tp-donor-link">' + esc(r.donor_name || '—') + '</a>' + (r.donor_phone ? '<br><small style="color:var(--gray-400)">' + esc(r.donor_phone) + '</small>' : '') + '</td><td class="text-end fw-semibold text-success">' + esc(fmtMoney(r.amount)) + '</td><td>' + esc(r.payment_method || '—') + '</td><td>' + esc(r.payment_date ? r.payment_date.slice(0, 10) : '—') + '</td><td>' + (r.donor_id ? '<a href="' + link + '" class="btn btn-sm btn-outline-primary"><i class="fas fa-user"></i></a>' : '') + '</td></tr>';
          }).join('');
        }
        document.getElementById('directInfo').textContent = rows.length ? 'Showing ' + start + '–' + (start + rows.length - 1) + ' of ' + (d.total_count || 0) : '0 of ' + (d.total_count || 0);
        paginate('directPagination', d.page, d.total_pages || 1, loadDirect);
      })
      .catch(err => { document.getElementById('directBody').innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger">' + esc(err && err.message ? err.message : 'Failed to load.') + '</td></tr>'; });
  }

  function loadPledge(page) {
    const donor = document.getElementById('filterDonor').value.trim();
    const perPage = document.getElementById('perPagePledge').value;
    const p = new URLSearchParams({ page: page || 1, per_page: perPage });
    if (donor) p.set('donor', donor);
    fetch('api/paid-towards-pledges.php?' + p.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => { if (!(r.headers.get('Content-Type') || '').includes('application/json')) throw new Error('Non-JSON response'); return r.json(); })
      .then(d => {
        if (d.error) {
          document.getElementById('pledgeBody').innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">' + esc(d.error) + '</td></tr>';
          document.getElementById('pledgeInfo').textContent = '—';
          document.getElementById('pledgePagination').innerHTML = '';
          return;
        }
        const body = document.getElementById('pledgeBody');
        const rows = d.rows || [];
        const start = (d.page - 1) * d.per_page + 1;
        if (rows.length === 0) {
          body.innerHTML = '<tr><td colspan="6"><div class="tp-empty-state"><i class="fas fa-inbox"></i><p>No pledge payments found.</p></div></td></tr>';
        } else {
          body.innerHTML = rows.map((r, i) => {
            const link = r.donor_id ? '../donor-management/view-donor.php?id=' + r.donor_id : '#';
            return '<tr><td>' + (start + i) + '</td><td><a href="' + link + '" class="tp-donor-link">' + esc(r.donor_name || '—') + '</a>' + (r.donor_phone ? '<br><small style="color:var(--gray-400)">' + esc(r.donor_phone) + '</small>' : '') + '</td><td class="text-end fw-semibold text-success">' + esc(fmtMoney(r.amount)) + '</td><td>' + esc(r.payment_method || '—') + '</td><td>' + esc(r.payment_date ? r.payment_date.slice(0, 10) : '—') + '</td><td>' + (r.donor_id ? '<a href="' + link + '" class="btn btn-sm btn-outline-primary"><i class="fas fa-user"></i></a>' : '') + '</td></tr>';
          }).join('');
        }
        document.getElementById('pledgeInfo').textContent = rows.length ? 'Showing ' + start + '–' + (start + rows.length - 1) + ' of ' + (d.total_count || 0) : '0 of ' + (d.total_count || 0);
        paginate('pledgePagination', d.page, d.total_pages || 1, loadPledge);
      })
      .catch(err => { document.getElementById('pledgeBody').innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger">' + esc(err && err.message ? err.message : 'Failed to load.') + '</td></tr>'; });
  }

  document.getElementById('applyFilters').addEventListener('click', () => { loadDirect(1); loadPledge(1); });
  document.getElementById('clearFilters').addEventListener('click', () => { document.getElementById('filterDonor').value = ''; loadDirect(1); loadPledge(1); });
  document.getElementById('perPageDirect').addEventListener('change', () => loadDirect(1));
  document.getElementById('perPagePledge').addEventListener('change', () => loadPledge(1));
  document.getElementById('filterDonor').addEventListener('keydown', e => { if (e.key === 'Enter') { loadDirect(1); loadPledge(1); } });

  document.querySelectorAll('#paidTabs button[data-bs-toggle="tab"]').forEach(btn => {
    btn.addEventListener('shown.bs.tab', e => {
      if (e.target.id === 'tab-pledge-btn') loadPledge(1);
    });
  });

  document.getElementById('exportCsvBtn').addEventListener('click', () => {
    const activeTab = document.querySelector('#paidTabs .nav-link.active');
    const isDirect = activeTab && activeTab.id === 'tab-direct-btn';
    const donor = document.getElementById('filterDonor').value.trim();
    const p = new URLSearchParams({ page: 1, per_page: 99999 });
    if (donor) p.set('donor', donor);
    const url = isDirect ? 'api/direct-payments.php?' + p.toString() : 'api/paid-towards-pledges.php?' + p.toString();
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => r.json())
      .then(d => {
        if (!d.rows || d.rows.length === 0) { alert('No data to export.'); return; }
        const headers = ['#', 'Donor', 'Phone', 'Amount', 'Method', 'Date'];
        const rows = d.rows.map((r, i) => [i + 1, r.donor_name || '', r.donor_phone || '', r.amount, r.payment_method || '', r.payment_date ? r.payment_date.slice(0, 10) : '']);
        const csv = [headers.join(','), ...rows.map(row => row.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(','))].join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'total-paid-' + (isDirect ? 'direct' : 'pledge') + '-' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
        URL.revokeObjectURL(a.href);
      })
      .catch(() => alert('Export failed.'));
  });

  loadDirect(1);
  loadPledge(1);
})();
</script>
</body>
</html>
