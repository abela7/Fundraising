<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

$db_error_message = '';
$settings = ['currency_code' => 'GBP'];

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
$page_title = 'Total Pledged (Approved) - Detail';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Total Pledged (Approved) - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">

    <style>
        .ptp-page-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .ptp-page-header h1 { font-size: 1.5rem; font-weight: 700; color: var(--gray-900); margin: 0; }
        .ptp-page-header p { color: var(--gray-500); font-size: 0.875rem; margin: 4px 0 0; }
        .ptp-filter-bar { background: var(--white); border: 1px solid var(--gray-200); border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; box-shadow: var(--shadow-sm); }
        .ptp-filter-bar .form-label { font-size: 0.75rem; font-weight: 600; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 4px; }
        .ptp-filter-bar .form-control { border: 1px solid var(--gray-200); border-radius: 8px; font-size: 0.875rem; padding: 8px 12px; }
        .ptp-stat-chip { display: flex; align-items: center; gap: 10px; background: var(--white); border: 1px solid var(--gray-200); border-radius: 10px; padding: 12px 18px; box-shadow: var(--shadow-sm); flex: 1; min-width: 150px; }
        .ptp-stat-chip:hover { box-shadow: var(--shadow-md); }
        .ptp-stat-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; flex-shrink: 0; }
        .ptp-stat-icon.primary { background: rgba(10, 98, 134, 0.1); color: var(--primary); }
        .ptp-stat-value { font-size: 1.25rem; font-weight: 700; color: var(--gray-900); line-height: 1.2; }
        .ptp-stat-label { font-size: 0.6875rem; font-weight: 500; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.4px; }
        .ptp-data-card { background: var(--white); border: 1px solid var(--gray-200); border-radius: 12px; box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 16px; }
        .ptp-data-card:hover { box-shadow: var(--shadow-md); }
        .ptp-table-header { padding: 14px 20px; background: var(--gray-50); border-bottom: 1px solid var(--gray-200); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; }
        .ptp-table-header h6 { font-weight: 600; color: var(--gray-800); margin: 0; font-size: 0.9375rem; }
        .ptp-data-card .table thead th { background: var(--white); font-size: 0.75rem; font-weight: 600; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.3px; border-bottom: 1px solid var(--gray-200); padding: 10px 16px; white-space: nowrap; }
        .ptp-sortable { cursor: pointer; user-select: none; transition: color 0.15s ease; }
        .ptp-sortable:hover { color: var(--primary) !important; }
        .ptp-sortable .ptp-sort-icon { margin-left: 4px; opacity: 0.5; font-size: 0.65rem; }
        .ptp-sortable.ptp-sort-active .ptp-sort-icon { opacity: 1; color: var(--primary); }
        .ptp-data-card .table tbody td { padding: 10px 16px; vertical-align: middle; font-size: 0.875rem; border-bottom: 1px solid var(--gray-50); }
        .ptp-data-card .table tbody tr:hover { background: var(--gray-50); }
        .ptp-data-card .table tbody tr:last-child td { border-bottom: none; }
        .ptp-donor-link { font-weight: 600; color: var(--primary); text-decoration: none; }
        .ptp-donor-link:hover { color: var(--primary-dark); text-decoration: underline; }
        .ptp-pagination-wrapper { display: flex; align-items: center; justify-content: space-between; padding: 1rem 20px; border-top: 1px solid var(--gray-100); flex-wrap: wrap; gap: 0.75rem; }
        .ptp-pagination-info { font-size: 0.85rem; color: var(--gray-500); }
        .ptp-pagination-info strong { color: var(--gray-900); }
        .ptp-pagination .page-link { border: 1px solid var(--gray-200); border-radius: 8px; font-size: 0.8rem; font-weight: 600; padding: 0.4rem 0.75rem; color: var(--gray-600); transition: all var(--transition-fast); }
        .ptp-pagination .page-link:hover { background: var(--primary); border-color: var(--primary); color: var(--white); transform: translateY(-1px); }
        .ptp-pagination .page-item.active .page-link { background: var(--primary); border-color: var(--primary); color: var(--white); box-shadow: 0 2px 8px rgba(10, 98, 134, 0.3); }
        .ptp-alert-enhanced { border: none; border-radius: 0.75rem; padding: 1rem 1.25rem; font-size: 0.875rem; font-weight: 500; display: flex; align-items: center; gap: 0.75rem; }
        .ptp-alert-warning { background: rgba(245, 158, 11, 0.08); color: #92400e; border-left: 4px solid var(--warning); }
        .ptp-alert-danger { background: rgba(239, 68, 68, 0.08); color: #991b1b; border-left: 4px solid var(--danger); }
        .ptp-empty-state { text-align: center; padding: 48px 20px; color: var(--gray-500); }
        .ptp-empty-state i { font-size: 2.5rem; color: var(--gray-300); margin-bottom: 12px; display: block; }
        .ptp-empty-state p { font-size: 0.9375rem; margin-bottom: 0.75rem; }
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
                    <div class="alert ptp-alert-enhanced ptp-alert-danger mb-3" role="alert">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span><strong>Database Error:</strong> <?php echo htmlspecialchars($db_error_message, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>

                <div class="ptp-page-header">
                    <div>
                        <h1><i class="fas fa-hand-holding-heart me-2" style="color: var(--primary);"></i>Total Pledged (Approved)</h1>
                        <p>Detailed breakdown of all approved pledges. Use filters to drill down.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="financial-dashboard.php#tab-pledge"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
                        <button class="btn btn-primary" id="exportCsvBtn" type="button"><i class="fas fa-file-csv me-1"></i>Export CSV</button>
                    </div>
                </div>

                <div class="ptp-filter-bar animate-fade-in">
                    <div class="form-label mb-2"><i class="fas fa-filter me-1"></i>Filters</div>
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-2">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control form-control-sm" id="filterDateFrom">
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control form-control-sm" id="filterDateTo">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Donor (name, phone)</label>
                            <input type="text" class="form-control form-control-sm" id="filterDonor" placeholder="Search...">
                        </div>
                        <div class="col-12 col-md-2 d-flex gap-2">
                            <button class="btn btn-primary btn-sm flex-fill" id="applyFilters"><i class="fas fa-search me-1"></i>Apply</button>
                            <button class="btn btn-outline-secondary btn-sm" id="clearFilters"><i class="fas fa-times me-1"></i>Clear</button>
                        </div>
                    </div>
                </div>

                <div class="d-flex mb-3 animate-fade-in" style="gap: 12px; flex-wrap: wrap; animation-delay: 0.1s;">
                    <div class="ptp-stat-chip">
                        <div class="ptp-stat-icon primary"><i class="fas fa-pound-sign"></i></div>
                        <div>
                            <div class="ptp-stat-value" id="summaryTotal">—</div>
                            <div class="ptp-stat-label">Total Amount (filtered)</div>
                            <div class="ptp-stat-label mt-1" id="summarySub" style="font-size: 0.75rem; text-transform: none;">—</div>
                        </div>
                    </div>
                </div>

                <div class="ptp-data-card animate-fade-in" style="animation-delay: 0.1s;">
                    <div class="ptp-table-header">
                        <h6><i class="fas fa-hand-holding-heart me-2" style="color: var(--primary);"></i>Approved Pledges</h6>
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0 me-1" style="font-size: 0.75rem;">Per page:</label>
                            <select class="form-select form-select-sm" id="perPage" style="width: auto;">
                                <option value="10">10</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th class="ptp-sortable" data-sort-by="donor" title="Click to sort">Donor<span class="ptp-sort-icon"></span></th>
                                    <th class="text-end ptp-sortable" data-sort-by="amount" title="Click to sort">Amount<span class="ptp-sort-icon"></span></th>
                                    <th class="ptp-sortable" data-sort-by="pledge_date" title="Click to sort">Pledge Date<span class="ptp-sort-icon"></span></th>
                                    <th class="ptp-sortable ptp-sort-active" data-sort-by="created_at" title="Click to sort">Created<span class="ptp-sort-icon"><i class="fas fa-sort-down"></i></span></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="dataBody">
                                <tr>
                                    <td colspan="6" class="text-center py-4" style="color: var(--gray-500);">
                                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                        Loading...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="ptp-pagination-wrapper">
                        <div class="ptp-pagination-info" id="paginationInfo">—</div>
                        <ul class="pagination pagination-sm mb-0 ptp-pagination" id="pagination"></ul>
                    </div>
                </div>

                <div class="alert ptp-alert-enhanced ptp-alert-warning d-none mt-3" id="noDataAlert" role="alert">
                    <i class="fas fa-info-circle"></i>
                    <span><strong>No approved pledges found.</strong> Try adjusting your filters.</span>
                </div>

                <div class="alert ptp-alert-enhanced ptp-alert-danger d-none mt-3" id="errorAlert" role="alert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span id="errorMessage">Failed to load data.</span>
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
  let sortState = { sortBy: 'created_at', sortOrder: 'desc' };

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

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function buildUrl(page = 1) {
    const params = new URLSearchParams();
    params.set('page', String(page));
    params.set('per_page', document.getElementById('perPage').value);
    params.set('sort_by', sortState.sortBy);
    params.set('sort_order', sortState.sortOrder);
    const df = document.getElementById('filterDateFrom').value;
    const dt = document.getElementById('filterDateTo').value;
    const donor = document.getElementById('filterDonor').value.trim();
    if (df) params.set('date_from', df);
    if (dt) params.set('date_to', dt);
    if (donor) params.set('donor', donor);
    return 'api/total-pledged-approved.php?' + params.toString();
  }

  function updateSortHeaders() {
    document.querySelectorAll('.ptp-sortable').forEach(th => {
      const col = th.dataset.sortBy;
      th.classList.remove('ptp-sort-active');
      const icon = th.querySelector('.ptp-sort-icon');
      if (icon) {
        if (col === sortState.sortBy) {
          th.classList.add('ptp-sort-active');
          icon.innerHTML = sortState.sortOrder === 'asc' ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>';
        } else {
          icon.innerHTML = '<i class="fas fa-sort" style="opacity:0.4"></i>';
        }
      }
    });
  }

  function handleSortClick(sortBy) {
    if (sortState.sortBy === sortBy) {
      sortState.sortOrder = sortState.sortOrder === 'asc' ? 'desc' : 'asc';
    } else {
      sortState.sortBy = sortBy;
      sortState.sortOrder = ['amount', 'created_at', 'pledge_date'].includes(sortBy) ? 'desc' : 'asc';
    }
    updateSortHeaders();
    load(1);
  }

  function renderTable(data) {
    const body = document.getElementById('dataBody');
    const noDataAlert = document.getElementById('noDataAlert');
    const errorAlert = document.getElementById('errorAlert');

    const rows = data.rows || [];
    if (rows.length === 0) {
      body.innerHTML = '<tr><td colspan="6"><div class="ptp-empty-state"><i class="fas fa-inbox"></i><p>No approved pledges match your filters.</p><button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="document.getElementById(\'clearFilters\').click()"><i class="fas fa-times me-1"></i>Clear Filters</button></div></td></tr>';
      noDataAlert.classList.remove('d-none');
      errorAlert.classList.add('d-none');
      return;
    }

    noDataAlert.classList.add('d-none');
    errorAlert.classList.add('d-none');

    const startNum = (data.page - 1) * data.per_page + 1;
    body.innerHTML = rows.map((r, i) => {
      const num = startNum + i;
      const donorLink = r.donor_id
        ? `<a href="../donor-management/view-donor.php?id=${r.donor_id}" class="ptp-donor-link">${escapeHtml(r.donor_name || 'Unknown')}</a>`
        : escapeHtml(r.donor_name || 'Unknown');
      const pledgeDate = r.pledge_date ? new Date(r.pledge_date).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
      const createdDate = r.created_at ? new Date(r.created_at).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
      return `
        <tr>
          <td>${num}</td>
          <td>
            <div>${donorLink}</div>
            ${r.donor_phone ? '<small style="color:var(--gray-400)">' + escapeHtml(r.donor_phone) + '</small>' : ''}
          </td>
          <td class="text-end fw-semibold">${escapeHtml(fmtMoney(r.amount))}</td>
          <td class="text-nowrap">${escapeHtml(pledgeDate)}</td>
          <td class="text-nowrap text-muted">${escapeHtml(createdDate)}</td>
          <td>
            ${r.donor_id ? `<a href="../donor-management/view-donor.php?id=${r.donor_id}" class="btn btn-sm btn-outline-primary" title="View donor"><i class="fas fa-user"></i></a>` : ''}
          </td>
        </tr>
      `;
    }).join('');
  }

  function renderPagination(data) {
    const ul = document.getElementById('pagination');
    const info = document.getElementById('paginationInfo');
    if (!data) {
      ul.innerHTML = '';
      info.textContent = '—';
      return;
    }
    const totalCount = data.total_count;
    const from = (data.page - 1) * data.per_page + 1;
    const to = Math.min(data.page * data.per_page, totalCount);
    info.innerHTML = `Showing <strong>${from}</strong>–<strong>${to}</strong> of <strong>${totalCount}</strong> records`;

    if (data.total_pages <= 1) {
      ul.innerHTML = '';
      return;
    }

    const page = data.page;
    const total = data.total_pages;

    let html = '';
    if (page > 1) {
      html += `<li class="page-item"><a class="page-link" href="#" data-page="1" aria-label="First"><i class="fas fa-angles-left"></i></a></li>`;
      html += `<li class="page-item"><a class="page-link" href="#" data-page="${page - 1}" aria-label="Previous"><i class="fas fa-angle-left"></i></a></li>`;
    }
    const start = Math.max(1, page - 2);
    const end = Math.min(total, page + 2);
    for (let i = start; i <= end; i++) {
      html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
    }
    if (page < total) {
      html += `<li class="page-item"><a class="page-link" href="#" data-page="${page + 1}" aria-label="Next"><i class="fas fa-angle-right"></i></a></li>`;
      html += `<li class="page-item"><a class="page-link" href="#" data-page="${total}" aria-label="Last"><i class="fas fa-angles-right"></i></a></li>`;
    }
    ul.innerHTML = html;

    ul.querySelectorAll('a[data-page]').forEach(a => {
      a.addEventListener('click', (e) => {
        e.preventDefault();
        load(parseInt(a.dataset.page, 10));
      });
    });
  }

  function updateSummary(data) {
    const el = document.getElementById('summaryTotal');
    const sub = document.getElementById('summarySub');
    if (!data) {
      el.textContent = '—';
      sub.textContent = '—';
      return;
    }
    el.textContent = fmtMoney(data.total_amount);
    sub.textContent = data.total_count + ' pledge(s)';
  }

  async function load(page = 1) {
    const body = document.getElementById('dataBody');
    const errorAlert = document.getElementById('errorAlert');
    const errorMsg = document.getElementById('errorMessage');

    body.innerHTML = '<tr><td colspan="6" class="text-center py-4" style="color:var(--gray-500)"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading...</td></tr>';
    errorAlert.classList.add('d-none');

    try {
      const res = await fetch(buildUrl(page), { method: 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
      const data = await res.json();

      if (!res.ok) {
        throw new Error(data.message || data.error || 'Request failed');
      }

      renderTable(data);
      renderPagination(data);
      updateSummary(data);
    } catch (err) {
      body.innerHTML = '<tr><td colspan="6" class="text-center py-4" style="color:var(--danger)">Failed to load data.</td></tr>';
      errorMsg.textContent = String(err && err.message ? err.message : err);
      errorAlert.classList.remove('d-none');
      document.getElementById('paginationInfo').textContent = '—';
      document.getElementById('pagination').innerHTML = '';
    }
  }

  function exportCsv() {
    const params = new URLSearchParams();
    params.set('page', '1');
    params.set('per_page', '99999');
    params.set('sort_by', sortState.sortBy);
    params.set('sort_order', sortState.sortOrder);
    const df = document.getElementById('filterDateFrom').value;
    const dt = document.getElementById('filterDateTo').value;
    const donor = document.getElementById('filterDonor').value.trim();
    if (df) params.set('date_from', df);
    if (dt) params.set('date_to', dt);
    if (donor) params.set('donor', donor);

    fetch('api/total-pledged-approved.php?' + params.toString(), { method: 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(r => r.json())
      .then(data => {
        if (!data.rows || data.rows.length === 0) {
          alert('No data to export.');
          return;
        }
        const headers = ['#', 'Donor', 'Phone', 'Amount', 'Pledge Date', 'Created At'];
        const rows = data.rows.map((r, i) => [
          i + 1,
          r.donor_name || '',
          r.donor_phone || '',
          r.amount,
          r.pledge_date || '',
          r.created_at || ''
        ]);
        const csv = [headers.join(','), ...rows.map(row => row.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(','))].join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'total-pledged-approved-' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
        URL.revokeObjectURL(a.href);
      })
      .catch(() => alert('Export failed.'));
  }

  document.getElementById('applyFilters').addEventListener('click', () => load(1));
  document.getElementById('clearFilters').addEventListener('click', () => {
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    document.getElementById('filterDonor').value = '';
    load(1);
  });
  document.getElementById('perPage').addEventListener('change', () => load(1));
  document.getElementById('exportCsvBtn').addEventListener('click', exportCsv);

  ['filterDonor', 'filterDateFrom', 'filterDateTo'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('keydown', (e) => { if (e.key === 'Enter') load(1); });
  });

  document.querySelectorAll('.ptp-sortable').forEach(th => {
    th.addEventListener('click', () => handleSortClick(th.dataset.sortBy));
  });

  updateSortHeaders();
  load(1);
})();
</script>
</body>
</html>
