<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

$db_error_message = '';
$settings = ['currency_code' => 'GBP'];
$activeStatus = isset($_GET['status']) && $_GET['status'] === 'completed' ? 'completed' : 'paying';

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
$page_title = ($activeStatus === 'paying' ? 'Donors Paying' : 'Donors Completed') . ' - Detail';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $activeStatus === 'paying' ? 'Donors Paying' : 'Donors Completed'; ?> - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/reports.css">
    <style>
        .dbs-page-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .dbs-page-header h1 { font-size: 1.5rem; font-weight: 700; color: var(--gray-900); margin: 0; }
        .dbs-page-header p { color: var(--gray-500); font-size: 0.875rem; margin: 4px 0 0; }
        .dbs-tabs .nav-link { font-weight: 500; color: var(--gray-600); }
        .dbs-tabs .nav-link:hover { color: var(--primary); background: var(--gray-50); }
        .dbs-tabs .nav-link.active { font-weight: 600; color: var(--primary); }
        .dbs-filter-bar { background: var(--white); border: 1px solid var(--gray-200); border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; box-shadow: var(--shadow-sm); }
        .dbs-stat-chip { display: flex; align-items: center; gap: 10px; background: var(--white); border: 1px solid var(--gray-200); border-radius: 10px; padding: 12px 18px; box-shadow: var(--shadow-sm); flex: 1; min-width: 150px; }
        .dbs-stat-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; flex-shrink: 0; }
        .dbs-stat-icon.paying { background: rgba(31, 41, 55, 0.1); color: var(--gray-800); }
        .dbs-stat-icon.completed { background: rgba(107, 114, 128, 0.15); color: var(--gray-600); }
        .dbs-stat-value { font-size: 1.25rem; font-weight: 700; color: var(--gray-900); }
        .dbs-stat-label { font-size: 0.6875rem; font-weight: 500; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.4px; }
        .dbs-data-card { background: var(--white); border: 1px solid var(--gray-200); border-radius: 12px; box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 16px; }
        .dbs-table-header { padding: 14px 20px; background: var(--gray-50); border-bottom: 1px solid var(--gray-200); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; }
        .dbs-data-card .table thead th { font-size: 0.75rem; font-weight: 600; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.3px; padding: 10px 16px; }
        .dbs-data-card .table tbody td { padding: 10px 16px; font-size: 0.875rem; }
        .dbs-data-card .table tbody tr:hover { background: var(--gray-50); }
        .dbs-donor-link { font-weight: 600; color: var(--primary); text-decoration: none; }
        .dbs-donor-link:hover { color: var(--primary-dark); text-decoration: underline; }
        .dbs-pagination-wrapper { padding: 1rem 20px; border-top: 1px solid var(--gray-100); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; }
        .dbs-empty-state { text-align: center; padding: 48px 20px; color: var(--gray-500); }
        .dbs-empty-state i { font-size: 2.5rem; color: var(--gray-300); margin-bottom: 12px; display: block; }
        .dbs-sortable { cursor: pointer; user-select: none; }
        .dbs-sortable:hover { color: var(--primary) !important; }
        .dbs-sortable .dbs-sort-icon { margin-left: 4px; opacity: 0.5; font-size: 0.65rem; }
        .dbs-sortable.dbs-sort-active .dbs-sort-icon { opacity: 1; color: var(--primary); }
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

                <div class="dbs-page-header">
                    <div>
                        <h1>
                            <i class="fas fa-users me-2" style="color:var(--primary)"></i>
                            Donors by Status
                        </h1>
                        <p>Pledge donors currently paying or completed (paid in full).</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="financial-dashboard.php#tab-pledge"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
                        <button class="btn btn-primary" id="exportCsvBtn" type="button"><i class="fas fa-file-csv me-1"></i>Export CSV</button>
                    </div>
                </div>

                <ul class="nav nav-tabs dbs-tabs mb-3" id="statusTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?php echo $activeStatus === 'paying' ? 'active' : ''; ?>" href="donors-by-status.php?status=paying" role="tab">
                            <i class="fas fa-person-walking me-1"></i>Donors Paying
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?php echo $activeStatus === 'completed' ? 'active' : ''; ?>" href="donors-by-status.php?status=completed" role="tab">
                            <i class="fas fa-flag-checkered me-1"></i>Donors Completed
                        </a>
                    </li>
                </ul>

                <div class="d-flex mb-3" style="gap: 12px; flex-wrap: wrap;">
                    <div class="dbs-stat-chip">
                        <div class="dbs-stat-icon <?php echo $activeStatus; ?>"><i class="fas fa-<?php echo $activeStatus === 'paying' ? 'person-walking' : 'flag-checkered'; ?>"></i></div>
                        <div>
                            <div class="dbs-stat-value" id="summaryCount">—</div>
                            <div class="dbs-stat-label"><?php echo $activeStatus === 'paying' ? 'Pledge donors currently paying' : 'Pledge donors paid in full'; ?></div>
                        </div>
                    </div>
                </div>

                <div class="dbs-filter-bar">
                    <div class="form-label mb-2" style="font-size:0.75rem; font-weight:600; color:var(--gray-500)"><i class="fas fa-filter me-1"></i>Filters</div>
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-3">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:var(--gray-500)">Donor (name, phone)</label>
                            <input type="text" class="form-control form-control-sm" id="filterDonor" placeholder="Search...">
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:var(--gray-500)">Pledged min</label>
                            <input type="number" class="form-control form-control-sm" id="filterPledgedMin" placeholder="Min" step="0.01" min="0">
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:var(--gray-500)">Pledged max</label>
                            <input type="number" class="form-control form-control-sm" id="filterPledgedMax" placeholder="Max" step="0.01" min="0">
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:var(--gray-500)">Paid min</label>
                            <input type="number" class="form-control form-control-sm" id="filterPaidMin" placeholder="Min" step="0.01" min="0">
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:var(--gray-500)">Paid max</label>
                            <input type="number" class="form-control form-control-sm" id="filterPaidMax" placeholder="Max" step="0.01" min="0">
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:var(--gray-500)">Balance min</label>
                            <input type="number" class="form-control form-control-sm" id="filterBalanceMin" placeholder="Min" step="0.01" min="0">
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label" style="font-size:0.75rem; font-weight:600; color:var(--gray-500)">Balance max</label>
                            <input type="number" class="form-control form-control-sm" id="filterBalanceMax" placeholder="Max" step="0.01" min="0">
                        </div>
                        <div class="col-12 col-md-2 d-flex gap-2">
                            <button class="btn btn-primary btn-sm flex-fill" id="applyFilters"><i class="fas fa-search me-1"></i>Apply</button>
                            <button class="btn btn-outline-secondary btn-sm" id="clearFilters"><i class="fas fa-times me-1"></i>Clear</button>
                        </div>
                    </div>
                </div>

                <div class="dbs-data-card">
                    <div class="dbs-table-header">
                        <h6 class="mb-0"><i class="fas fa-list me-2"></i><?php echo $activeStatus === 'paying' ? 'Donors Paying' : 'Donors Completed'; ?></h6>
                        <select class="form-select form-select-sm" id="perPage" style="width: auto;">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th class="dbs-sortable" data-sort-by="donor">Donor<span class="dbs-sort-icon"></span></th>
                                    <th class="text-end dbs-sortable" data-sort-by="pledged">Pledged<span class="dbs-sort-icon"></span></th>
                                    <th class="text-end dbs-sortable" data-sort-by="paid">Paid<span class="dbs-sort-icon"></span></th>
                                    <th class="text-end dbs-sortable" data-sort-by="balance">Outstanding<span class="dbs-sort-icon"></span></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="dataBody">
                                <tr><td colspan="6" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="dbs-pagination-wrapper">
                        <div class="small text-muted" id="paginationInfo">—</div>
                        <ul class="pagination pagination-sm mb-0" id="pagination"></ul>
                    </div>
                </div>

                <div class="alert alert-warning d-none mt-3" id="noDataAlert">
                    <i class="fas fa-info-circle me-2"></i>
                    <span id="noDataMessage">No donors found.</span>
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
  const STATUS = <?php echo json_encode($activeStatus); ?>;
  let sortState = { sortBy: STATUS === 'paying' ? 'balance' : 'paid', sortOrder: 'desc' };

  function fmtMoney(n) {
    try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: CURRENCY, maximumFractionDigits: 2 }).format(Number(n) || 0); } catch(_) { return CURRENCY + ' ' + (Number(n) || 0).toFixed(2); }
  }
  function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  function buildUrl(page) {
    const p = new URLSearchParams();
    p.set('status', STATUS);
    p.set('page', String(page || 1));
    p.set('per_page', document.getElementById('perPage').value);
    p.set('sort_by', sortState.sortBy);
    p.set('sort_order', sortState.sortOrder);
    const donor = document.getElementById('filterDonor').value.trim();
    if (donor) p.set('donor', donor);
    const pledgedMin = document.getElementById('filterPledgedMin').value.trim();
    if (pledgedMin) p.set('pledged_min', pledgedMin);
    const pledgedMax = document.getElementById('filterPledgedMax').value.trim();
    if (pledgedMax) p.set('pledged_max', pledgedMax);
    const paidMin = document.getElementById('filterPaidMin').value.trim();
    if (paidMin) p.set('paid_min', paidMin);
    const paidMax = document.getElementById('filterPaidMax').value.trim();
    if (paidMax) p.set('paid_max', paidMax);
    const balanceMin = document.getElementById('filterBalanceMin').value.trim();
    if (balanceMin) p.set('balance_min', balanceMin);
    const balanceMax = document.getElementById('filterBalanceMax').value.trim();
    if (balanceMax) p.set('balance_max', balanceMax);
    return 'api/donors-by-status.php?' + p.toString();
  }

  function updateSortHeaders() {
    document.querySelectorAll('.dbs-sortable').forEach(th => {
      const col = th.dataset.sortBy;
      th.classList.remove('dbs-sort-active');
      const icon = th.querySelector('.dbs-sort-icon');
      if (icon) {
        if (col === sortState.sortBy) {
          th.classList.add('dbs-sort-active');
          icon.innerHTML = sortState.sortOrder === 'asc' ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>';
        } else {
          icon.innerHTML = '<i class="fas fa-sort" style="opacity:0.4"></i>';
        }
      }
    });
  }

  function load(page) {
    const body = document.getElementById('dataBody');
    body.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading...</td></tr>';
    fetch(buildUrl(page), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => {
        const ct = r.headers.get('Content-Type') || '';
        if (!ct.includes('application/json')) throw new Error('Server returned non-JSON. Check if you are logged in.');
        return r.json();
      })
      .then(d => {
        if (d.error) {
          body.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger">' + esc(d.message || d.error) + '</td></tr>';
          document.getElementById('paginationInfo').textContent = '—';
          document.getElementById('pagination').innerHTML = '';
          return;
        }
        const rows = d.rows || [];
        document.getElementById('summaryCount').textContent = d.total_count ?? 0;

        if (rows.length === 0) {
          const msg = STATUS === 'paying' ? 'No donors currently paying match your filters.' : 'No donors completed match your filters.';
          body.innerHTML = '<tr><td colspan="6"><div class="dbs-empty-state"><i class="fas fa-inbox"></i><p>' + msg + '</p><button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="document.getElementById(\'clearFilters\').click()"><i class="fas fa-times me-1"></i>Clear Filters</button></div></td></tr>';
          document.getElementById('noDataAlert').classList.remove('d-none');
          document.getElementById('noDataMessage').textContent = msg;
        } else {
          document.getElementById('noDataAlert').classList.add('d-none');
          const start = (d.page - 1) * d.per_page + 1;
          body.innerHTML = rows.map((r, i) => {
            const link = '../donor-management/view-donor.php?id=' + r.id;
            return '<tr>' +
              '<td>' + (start + i) + '</td>' +
              '<td><div><a href="' + link + '" class="dbs-donor-link">' + esc(r.donor_name || 'Unknown') + '</a></div>' + (r.donor_phone ? '<small style="color:var(--gray-400)">' + esc(r.donor_phone) + '</small>' : '') + '</td>' +
              '<td class="text-end">' + esc(fmtMoney(r.total_pledged)) + '</td>' +
              '<td class="text-end text-success">' + esc(fmtMoney(r.total_paid)) + '</td>' +
              '<td class="text-end fw-semibold" style="color:var(--warning)">' + esc(fmtMoney(r.balance)) + '</td>' +
              '<td><a href="' + link + '" class="btn btn-sm btn-outline-primary"><i class="fas fa-user"></i></a></td>' +
            '</tr>';
          }).join('');
        }

        const from = (d.page - 1) * d.per_page + 1;
        const to = Math.min(d.page * d.per_page, d.total_count || 0);
        document.getElementById('paginationInfo').innerHTML = 'Showing <strong>' + from + '</strong>–<strong>' + to + '</strong> of <strong>' + (d.total_count || 0) + '</strong>';

        const totalPages = d.total_pages || 1;
        if (totalPages <= 1) {
          document.getElementById('pagination').innerHTML = '';
        } else {
          let html = '';
          const p = d.page;
          if (p > 1) html += '<li class="page-item"><a class="page-link" href="#" data-p="1"><i class="fas fa-angles-left"></i></a></li><li class="page-item"><a class="page-link" href="#" data-p="' + (p-1) + '"><i class="fas fa-angle-left"></i></a></li>';
          for (let i = Math.max(1, p-2); i <= Math.min(totalPages, p+2); i++) {
            html += '<li class="page-item ' + (i === p ? 'active' : '') + '"><a class="page-link" href="#" data-p="' + i + '">' + i + '</a></li>';
          }
          if (p < totalPages) html += '<li class="page-item"><a class="page-link" href="#" data-p="' + (p+1) + '"><i class="fas fa-angle-right"></i></a></li><li class="page-item"><a class="page-link" href="#" data-p="' + totalPages + '"><i class="fas fa-angles-right"></i></a></li>';
          document.getElementById('pagination').innerHTML = html;
          document.getElementById('pagination').querySelectorAll('a[data-p]').forEach(a => {
            a.addEventListener('click', e => { e.preventDefault(); load(parseInt(a.dataset.p, 10)); });
          });
        }
      })
      .catch(err => {
        document.getElementById('dataBody').innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger">' + esc(err && err.message ? err.message : 'Failed to load.') + '</td></tr>';
      });
  }

  document.getElementById('applyFilters').addEventListener('click', () => load(1));
  document.getElementById('clearFilters').addEventListener('click', () => {
    document.getElementById('filterDonor').value = '';
    document.getElementById('filterPledgedMin').value = '';
    document.getElementById('filterPledgedMax').value = '';
    document.getElementById('filterPaidMin').value = '';
    document.getElementById('filterPaidMax').value = '';
    document.getElementById('filterBalanceMin').value = '';
    document.getElementById('filterBalanceMax').value = '';
    load(1);
  });
  document.getElementById('perPage').addEventListener('change', () => load(1));
  document.getElementById('filterDonor').addEventListener('keydown', e => { if (e.key === 'Enter') load(1); });

  document.getElementById('exportCsvBtn').addEventListener('click', () => {
    const donor = document.getElementById('filterDonor').value.trim();
    const pledgedMin = document.getElementById('filterPledgedMin').value.trim();
    const pledgedMax = document.getElementById('filterPledgedMax').value.trim();
    const paidMin = document.getElementById('filterPaidMin').value.trim();
    const paidMax = document.getElementById('filterPaidMax').value.trim();
    const balanceMin = document.getElementById('filterBalanceMin').value.trim();
    const balanceMax = document.getElementById('filterBalanceMax').value.trim();
    const p = new URLSearchParams();
    p.set('status', STATUS);
    p.set('page', '1');
    p.set('per_page', '99999');
    p.set('sort_by', sortState.sortBy);
    p.set('sort_order', sortState.sortOrder);
    if (donor) p.set('donor', donor);
    if (pledgedMin) p.set('pledged_min', pledgedMin);
    if (pledgedMax) p.set('pledged_max', pledgedMax);
    if (paidMin) p.set('paid_min', paidMin);
    if (paidMax) p.set('paid_max', paidMax);
    if (balanceMin) p.set('balance_min', balanceMin);
    if (balanceMax) p.set('balance_max', balanceMax);
    fetch('api/donors-by-status.php?' + p.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => r.json())
      .then(d => {
        if (!d.rows || d.rows.length === 0) { alert('No data to export.'); return; }
        const headers = ['#', 'Donor', 'Phone', 'Pledged', 'Paid', 'Outstanding'];
        const rows = d.rows.map((r, i) => [i + 1, r.donor_name || '', r.donor_phone || '', r.total_pledged, r.total_paid, r.balance]);
        const csv = [headers.join(','), ...rows.map(row => row.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(','))].join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'donors-' + STATUS + '-' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
        URL.revokeObjectURL(a.href);
      })
      .catch(() => alert('Export failed.'));
  });

  document.querySelectorAll('.dbs-sortable').forEach(th => {
    th.addEventListener('click', () => {
      const col = th.dataset.sortBy;
      if (sortState.sortBy === col) sortState.sortOrder = sortState.sortOrder === 'asc' ? 'desc' : 'asc';
      else { sortState.sortBy = col; sortState.sortOrder = ['balance','pledged','paid'].includes(col) ? 'desc' : 'asc'; }
      updateSortHeaders();
      load(1);
    });
  });

  updateSortHeaders();
  load(1);
})();
</script>
</body>
</html>
