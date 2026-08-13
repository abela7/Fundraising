<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

$db_error_message = '';
$settings = ['currency_code' => 'GBP'];

$source = strtolower(trim((string)($_GET['source'] ?? 'old_system')));
if (!in_array($source, ['old_system', 'new_system'], true)) {
    $source = 'old_system';
}

$view = strtolower(trim((string)($_GET['view'] ?? 'donors')));
if (!in_array($view, ['donors', 'pledges', 'payments', 'outstanding'], true)) {
    $view = 'donors';
}

$sourceLabel = $source === 'old_system' ? 'Old System (Imported)' : 'New System';
$page_title = $sourceLabel . ' - Detail';

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
    $db_error_message = 'Database connection failed.';
}

$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8'); ?> - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .dsd-page-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .dsd-page-header h1 { font-size: 1.5rem; font-weight: 700; color: var(--gray-900); margin: 0; }
        .dsd-page-header p { color: var(--gray-500); font-size: 0.875rem; margin: 4px 0 0; }
        .dsd-tabs .nav-link { font-weight: 500; color: var(--gray-600); }
        .dsd-tabs .nav-link:hover { color: var(--primary); background: var(--gray-50); }
        .dsd-tabs .nav-link.active { font-weight: 600; color: var(--primary); }
        .dsd-filter-bar { background: var(--white); border: 1px solid var(--gray-200); border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; box-shadow: var(--shadow-sm); }
        .dsd-filter-bar .form-label { font-size: 0.75rem; font-weight: 600; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 4px; }
        .dsd-stat-chip { display: flex; align-items: center; gap: 10px; background: var(--white); border: 1px solid var(--gray-200); border-radius: 10px; padding: 12px 18px; box-shadow: var(--shadow-sm); flex: 1; min-width: 150px; text-decoration: none; color: inherit; cursor: pointer; }
        .dsd-stat-chip:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); color: inherit; }
        .dsd-stat-chip.active { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(10, 98, 134, 0.15); }
        .dsd-stat-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; flex-shrink: 0; }
        .dsd-stat-icon.pledged { background: rgba(10, 98, 134, 0.1); color: var(--primary); }
        .dsd-stat-icon.paid { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .dsd-stat-icon.balance { background: rgba(245, 158, 11, 0.15); color: var(--warning); }
        .dsd-stat-icon.donors { background: rgba(31, 41, 55, 0.1); color: var(--gray-800); }
        .dsd-stat-value { font-size: 1.25rem; font-weight: 700; color: var(--gray-900); line-height: 1.2; }
        .dsd-stat-label { font-size: 0.6875rem; font-weight: 500; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.4px; }
        .dsd-data-card { background: var(--white); border: 1px solid var(--gray-200); border-radius: 12px; box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 16px; }
        .dsd-table-header { padding: 14px 20px; background: var(--gray-50); border-bottom: 1px solid var(--gray-200); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; }
        .dsd-table-header h6 { font-weight: 600; color: var(--gray-800); margin: 0; font-size: 0.9375rem; }
        .dsd-data-card .table thead th { background: var(--white); font-size: 0.75rem; font-weight: 600; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.3px; border-bottom: 1px solid var(--gray-200); padding: 10px 16px; white-space: nowrap; }
        .dsd-sortable { cursor: pointer; user-select: none; }
        .dsd-sortable:hover { color: var(--primary) !important; }
        .dsd-sortable .dsd-sort-icon { margin-left: 4px; opacity: 0.5; font-size: 0.65rem; }
        .dsd-sortable.dsd-sort-active .dsd-sort-icon { opacity: 1; color: var(--primary); }
        .dsd-data-card .table tbody td { padding: 10px 16px; vertical-align: middle; font-size: 0.875rem; border-bottom: 1px solid var(--gray-50); }
        .dsd-data-card .table tbody tr:hover { background: var(--gray-50); }
        .dsd-donor-link { font-weight: 600; color: var(--primary); text-decoration: none; }
        .dsd-donor-link:hover { color: var(--primary-dark); text-decoration: underline; }
        .dsd-pagination-wrapper { display: flex; align-items: center; justify-content: space-between; padding: 1rem 20px; border-top: 1px solid var(--gray-100); flex-wrap: wrap; gap: 0.75rem; }
        .dsd-pagination-info { font-size: 0.85rem; color: var(--gray-500); }
        .dsd-pagination-info strong { color: var(--gray-900); }
        .dsd-pagination .page-link { border: 1px solid var(--gray-200); border-radius: 8px; font-size: 0.8rem; font-weight: 600; padding: 0.4rem 0.75rem; color: var(--gray-600); }
        .dsd-pagination .page-item.active .page-link { background: var(--primary); border-color: var(--primary); color: var(--white); }
        .dsd-empty-state { text-align: center; padding: 48px 20px; color: var(--gray-500); }
        .dsd-empty-state i { font-size: 2.5rem; color: var(--gray-300); margin-bottom: 12px; display: block; }
        .dsd-alert { border: none; border-radius: 0.75rem; padding: 1rem 1.25rem; font-size: 0.875rem; font-weight: 500; display: flex; align-items: center; gap: 0.75rem; }
        .dsd-alert-warning { background: rgba(245, 158, 11, 0.08); color: #92400e; border-left: 4px solid var(--warning); }
        .dsd-alert-danger { background: rgba(239, 68, 68, 0.08); color: #991b1b; border-left: 4px solid var(--danger); }
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
                    <div class="alert dsd-alert dsd-alert-danger mb-3" role="alert">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span><?php echo htmlspecialchars($db_error_message, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>

                <div class="dsd-page-header">
                    <div>
                        <h1>
                            <i class="fas <?php echo $source === 'old_system' ? 'fa-archive' : 'fa-rocket'; ?> me-2" style="color: var(--primary);"></i>
                            <?php echo htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </h1>
                        <p>Donor list plus pledged, paid, and remaining amounts for this data source.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="financial-dashboard.php#tab-pledge"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
                        <button class="btn btn-primary" id="exportCsvBtn" type="button"><i class="fas fa-file-csv me-1"></i>Export CSV</button>
                    </div>
                </div>

                <ul class="nav nav-tabs dsd-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?php echo $source === 'old_system' ? 'active' : ''; ?>" href="data-source-detail.php?source=old_system&amp;view=<?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="fas fa-archive me-1"></i>Old System (Imported)
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?php echo $source === 'new_system' ? 'active' : ''; ?>" href="data-source-detail.php?source=new_system&amp;view=<?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="fas fa-rocket me-1"></i>New System
                        </a>
                    </li>
                </ul>

                <div class="d-flex mb-3" style="gap: 12px; flex-wrap: wrap;">
                    <a href="#" class="dsd-stat-chip" data-view="donors" id="chipDonors">
                        <div class="dsd-stat-icon donors"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="dsd-stat-value" id="summaryDonors">—</div>
                            <div class="dsd-stat-label">Donors</div>
                        </div>
                    </a>
                    <a href="#" class="dsd-stat-chip" data-view="pledges" id="chipPledges">
                        <div class="dsd-stat-icon pledged"><i class="fas fa-hand-holding-heart"></i></div>
                        <div>
                            <div class="dsd-stat-value" id="summaryPledged">—</div>
                            <div class="dsd-stat-label">Total Pledge</div>
                        </div>
                    </a>
                    <a href="#" class="dsd-stat-chip" data-view="payments" id="chipPayments">
                        <div class="dsd-stat-icon paid"><i class="fas fa-money-bill-transfer"></i></div>
                        <div>
                            <div class="dsd-stat-value" id="summaryPaid">—</div>
                            <div class="dsd-stat-label">Paid</div>
                        </div>
                    </a>
                    <a href="#" class="dsd-stat-chip" data-view="outstanding" id="chipOutstanding">
                        <div class="dsd-stat-icon balance"><i class="fas fa-hourglass-half"></i></div>
                        <div>
                            <div class="dsd-stat-value" id="summaryBalance">—</div>
                            <div class="dsd-stat-label">Remaining</div>
                        </div>
                    </a>
                </div>
                <div class="text-muted small mb-3" id="summaryMeta">—</div>

                <div class="dsd-filter-bar">
                    <div class="form-label mb-2"><i class="fas fa-filter me-1"></i>Filters</div>
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-3">
                            <label class="form-label">Donor (name, phone, ref)</label>
                            <input type="text" class="form-control form-control-sm" id="filterDonor" placeholder="Search...">
                        </div>
                        <div class="col-12 col-md-2 dsd-date-filter">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control form-control-sm" id="filterDateFrom">
                        </div>
                        <div class="col-12 col-md-2 dsd-date-filter">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control form-control-sm" id="filterDateTo">
                        </div>
                        <div class="col-12 col-md-2" id="methodFilterWrap">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select form-select-sm" id="filterMethod">
                                <option value="">All</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="card">Card</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2" id="statusFilterWrap">
                            <label class="form-label">Status</label>
                            <select class="form-select form-select-sm" id="filterStatus">
                                <option value="">All</option>
                                <option value="paying">Paying</option>
                                <option value="completed">Completed</option>
                                <option value="not_started">Not started</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2 d-flex gap-2">
                            <button class="btn btn-primary btn-sm flex-fill" id="applyFilters" type="button"><i class="fas fa-search me-1"></i>Apply</button>
                            <button class="btn btn-outline-secondary btn-sm" id="clearFilters" type="button"><i class="fas fa-times me-1"></i>Clear</button>
                        </div>
                    </div>
                </div>

                <div class="dsd-data-card">
                    <div class="dsd-table-header">
                        <h6 id="tableTitle"><i class="fas fa-list me-2" style="color: var(--primary);"></i>Donors</h6>
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
                                <tr id="tableHead"></tr>
                            </thead>
                            <tbody id="dataBody">
                                <tr>
                                    <td colspan="8" class="text-center py-4" style="color: var(--gray-500);">
                                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                        Loading...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="dsd-pagination-wrapper">
                        <div class="dsd-pagination-info" id="paginationInfo">—</div>
                        <ul class="pagination pagination-sm mb-0 dsd-pagination" id="pagination"></ul>
                    </div>
                </div>

                <div class="alert dsd-alert dsd-alert-warning d-none mt-3" id="noDataAlert" role="alert">
                    <i class="fas fa-info-circle"></i>
                    <span id="noDataMessage">No records found.</span>
                </div>
                <div class="alert dsd-alert dsd-alert-danger d-none mt-3" id="errorAlert" role="alert">
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
  const SOURCE = <?php echo json_encode($source); ?>;
  const VIEW_DEFAULTS = {
    donors: { sortBy: 'donor', sortOrder: 'asc' },
    pledges: { sortBy: 'created_at', sortOrder: 'desc' },
    payments: { sortBy: 'payment_date', sortOrder: 'desc' },
    outstanding: { sortBy: 'balance', sortOrder: 'desc' }
  };
  let currentView = <?php echo json_encode($view); ?>;
  let sortState = Object.assign({}, VIEW_DEFAULTS[currentView]);

  function fmtMoney(amount) {
    const n = Number(amount || 0);
    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency: CURRENCY, maximumFractionDigits: 2 }).format(n);
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

  function formatDate(value) {
    if (!value) return '';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return escapeHtml(String(value));
    return d.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
  }

  function statusBadge(status) {
    const key = String(status || '').toLowerCase();
    const map = {
      completed: 'bg-success',
      paying: 'bg-primary',
      overdue: 'bg-danger',
      not_started: 'bg-warning text-dark',
      approved: 'bg-success'
    };
    const cls = map[key] || 'bg-secondary';
    const label = key.replace(/_/g, ' ') || 'unknown';
    return `<span class="badge ${cls}">${escapeHtml(label)}</span>`;
  }

  function donorCell(row) {
    const name = escapeHtml(row.donor_name || 'Unknown');
    const phone = row.donor_phone ? `<small style="color:var(--gray-400)">${escapeHtml(row.donor_phone)}</small>` : '';
    const link = row.donor_id
      ? `<a href="../donor-management/view-donor.php?id=${row.donor_id}" class="dsd-donor-link">${name}</a>`
      : name;
    return `<div>${link}</div>${phone}`;
  }

  function sortHeader(label, key, extraClass = '') {
    return `<th class="dsd-sortable ${extraClass}" data-sort-by="${key}">${label}<span class="dsd-sort-icon"></span></th>`;
  }

  function renderHead() {
    const head = document.getElementById('tableHead');
    if (currentView === 'pledges') {
      head.innerHTML = `<th>#</th>${sortHeader('Donor', 'donor')}${sortHeader('Amount', 'amount', 'text-end')}${sortHeader('Pledge Date', 'pledge_date')}${sortHeader('Created', 'created_at')}`;
    } else if (currentView === 'payments') {
      head.innerHTML = `<th>#</th>${sortHeader('Donor', 'donor')}${sortHeader('Amount', 'amount', 'text-end')}${sortHeader('Method', 'method')}${sortHeader('Payment Date', 'payment_date')}${sortHeader('Reference', 'reference')}${sortHeader('Approved By', 'approved_by')}`;
    } else {
      head.innerHTML = `<th>#</th>${sortHeader('Donor', 'donor')}${sortHeader('Pledged', 'pledged', 'text-end')}${sortHeader('Paid', 'paid', 'text-end')}${sortHeader(currentView === 'outstanding' ? 'Outstanding' : 'Balance', 'balance', 'text-end')}${sortHeader('Status', 'status')}`;
    }
    updateSortHeaders();
    document.querySelectorAll('#tableHead .dsd-sortable').forEach((th) => {
      th.addEventListener('click', () => handleSortClick(th.dataset.sortBy));
    });
  }

  function updateSortHeaders() {
    document.querySelectorAll('#tableHead .dsd-sortable').forEach((th) => {
      const col = th.dataset.sortBy;
      th.classList.toggle('dsd-sort-active', col === sortState.sortBy);
      const icon = th.querySelector('.dsd-sort-icon');
      if (!icon) return;
      if (col === sortState.sortBy) {
        icon.innerHTML = sortState.sortOrder === 'asc' ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>';
      } else {
        icon.innerHTML = '<i class="fas fa-sort" style="opacity:0.4"></i>';
      }
    });
  }

  function handleSortClick(sortBy) {
    if (sortState.sortBy === sortBy) {
      sortState.sortOrder = sortState.sortOrder === 'asc' ? 'desc' : 'asc';
    } else {
      sortState.sortBy = sortBy;
      sortState.sortOrder = ['amount', 'payment_date', 'created_at', 'pledged', 'paid', 'balance'].includes(sortBy) ? 'desc' : 'asc';
    }
    updateSortHeaders();
    load(1);
  }

  function setView(view) {
    currentView = view;
    sortState = Object.assign({}, VIEW_DEFAULTS[view]);
    document.querySelectorAll('.dsd-stat-chip').forEach((chip) => {
      chip.classList.toggle('active', chip.dataset.view === view);
    });
    const titles = {
      donors: { icon: 'fa-users', text: 'Donors' },
      pledges: { icon: 'fa-hand-holding-heart', text: 'Approved Pledges' },
      payments: { icon: 'fa-money-bill-transfer', text: 'Confirmed Payments' },
      outstanding: { icon: 'fa-hourglass-half', text: 'Remaining Balance' }
    };
    const t = titles[view];
    document.getElementById('tableTitle').innerHTML = `<i class="fas ${t.icon} me-2" style="color: var(--primary);"></i>${t.text}`;
    document.getElementById('methodFilterWrap').classList.toggle('d-none', view !== 'payments');
    document.getElementById('statusFilterWrap').classList.toggle('d-none', view !== 'donors');
    document.querySelectorAll('.dsd-date-filter').forEach((el) => {
      el.classList.toggle('d-none', view !== 'pledges' && view !== 'payments');
    });
    const url = new URL(window.location.href);
    url.searchParams.set('source', SOURCE);
    url.searchParams.set('view', view);
    history.replaceState({}, '', url.toString());
    renderHead();
    load(1);
  }

  function buildUrl(page = 1, perPageOverride = null) {
    const params = new URLSearchParams();
    params.set('source', SOURCE);
    params.set('view', currentView);
    params.set('page', String(page));
    params.set('per_page', perPageOverride || document.getElementById('perPage').value);
    params.set('sort_by', sortState.sortBy);
    params.set('sort_order', sortState.sortOrder);
    const donor = document.getElementById('filterDonor').value.trim();
    if (donor) params.set('donor', donor);
    if (currentView === 'pledges' || currentView === 'payments') {
      const df = document.getElementById('filterDateFrom').value;
      const dt = document.getElementById('filterDateTo').value;
      if (df) params.set('date_from', df);
      if (dt) params.set('date_to', dt);
    }
    if (currentView === 'payments') {
      const method = document.getElementById('filterMethod').value;
      if (method) params.set('payment_method', method);
    }
    if (currentView === 'donors') {
      const status = document.getElementById('filterStatus').value;
      if (status) params.set('status', status);
    }
    return 'api/data-source-detail.php?' + params.toString();
  }

  function renderSummary(summary) {
    if (!summary) return;
    document.getElementById('summaryDonors').textContent = Number(summary.donor_count || 0).toLocaleString();
    document.getElementById('summaryPledged').textContent = fmtMoney(summary.total_pledged);
    document.getElementById('summaryPaid').textContent = fmtMoney(summary.total_paid);
    document.getElementById('summaryBalance').textContent = fmtMoney(summary.total_balance);
    document.getElementById('summaryMeta').textContent =
      Number(summary.collection_rate || 0).toFixed(1) + '% collected · '
      + Number(summary.donors_paying || 0).toLocaleString() + ' paying · '
      + Number(summary.donors_completed || 0).toLocaleString() + ' completed · '
      + Number(summary.donors_not_started || 0).toLocaleString() + ' not started';
  }

  function renderTable(data) {
    const body = document.getElementById('dataBody');
    const noDataAlert = document.getElementById('noDataAlert');
    const errorAlert = document.getElementById('errorAlert');
    const rows = data.rows || [];
    const colCount = currentView === 'payments' ? 7 : (currentView === 'pledges' ? 5 : 6);

    if (!data.enabled && currentView === 'payments') {
      body.innerHTML = `<tr><td colspan="${colCount}"><div class="dsd-empty-state"><i class="fas fa-ban"></i><p>Pledge payments are not enabled on this system.</p></div></td></tr>`;
      noDataAlert.classList.add('d-none');
      errorAlert.classList.add('d-none');
      return;
    }

    if (rows.length === 0) {
      body.innerHTML = `<tr><td colspan="${colCount}"><div class="dsd-empty-state"><i class="fas fa-inbox"></i><p>No records match your filters.</p></div></td></tr>`;
      document.getElementById('noDataMessage').textContent = 'No records found. Try adjusting your filters.';
      noDataAlert.classList.remove('d-none');
      errorAlert.classList.add('d-none');
      return;
    }

    noDataAlert.classList.add('d-none');
    errorAlert.classList.add('d-none');
    const startNum = (data.page - 1) * data.per_page + 1;
    body.innerHTML = rows.map((r, i) => {
      const num = startNum + i;
      if (currentView === 'pledges') {
        return `<tr>
          <td>${num}</td>
          <td>${donorCell(r)}</td>
          <td class="text-end fw-semibold">${escapeHtml(fmtMoney(r.amount))}</td>
          <td class="text-nowrap">${formatDate(r.pledge_date)}</td>
          <td class="text-nowrap">${formatDate(r.created_at)}</td>
        </tr>`;
      }
      if (currentView === 'payments') {
        return `<tr>
          <td>${num}</td>
          <td>${donorCell(r)}</td>
          <td class="text-end fw-semibold">${escapeHtml(fmtMoney(r.amount))}</td>
          <td>${escapeHtml(r.payment_method || '—')}</td>
          <td class="text-nowrap">${formatDate(r.payment_date)}</td>
          <td><code class="small">${escapeHtml(r.reference_number || '—')}</code></td>
          <td class="small" style="color:var(--gray-400)">${escapeHtml(r.approved_by || '—')}</td>
        </tr>`;
      }
      return `<tr>
        <td>${num}</td>
        <td>${donorCell(r)}</td>
        <td class="text-end">${escapeHtml(fmtMoney(r.pledged))}</td>
        <td class="text-end text-success">${escapeHtml(fmtMoney(r.paid))}</td>
        <td class="text-end fw-semibold">${escapeHtml(fmtMoney(r.balance))}</td>
        <td>${statusBadge(r.status)}</td>
      </tr>`;
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
    const totalCount = data.total_count || 0;
    const from = totalCount === 0 ? 0 : (data.page - 1) * data.per_page + 1;
    const to = Math.min(data.page * data.per_page, totalCount);
    const amountNote = (currentView === 'pledges' || currentView === 'payments' || currentView === 'outstanding')
      ? ` · ${escapeHtml(fmtMoney(data.total_amount))} ${escapeHtml(data.view_label || '')}`
      : '';
    info.innerHTML = `Showing <strong>${from}</strong>–<strong>${to}</strong> of <strong>${totalCount}</strong> records${amountNote}`;

    if (data.total_pages <= 1) {
      ul.innerHTML = '';
      return;
    }
    const page = data.page;
    const total = data.total_pages;
    let html = '';
    if (page > 1) {
      html += `<li class="page-item"><a class="page-link" href="#" data-page="1"><i class="fas fa-angles-left"></i></a></li>`;
      html += `<li class="page-item"><a class="page-link" href="#" data-page="${page - 1}"><i class="fas fa-angle-left"></i></a></li>`;
    }
    const start = Math.max(1, page - 2);
    const end = Math.min(total, page + 2);
    for (let i = start; i <= end; i++) {
      html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
    }
    if (page < total) {
      html += `<li class="page-item"><a class="page-link" href="#" data-page="${page + 1}"><i class="fas fa-angle-right"></i></a></li>`;
      html += `<li class="page-item"><a class="page-link" href="#" data-page="${total}"><i class="fas fa-angles-right"></i></a></li>`;
    }
    ul.innerHTML = html;
    ul.querySelectorAll('a[data-page]').forEach((a) => {
      a.addEventListener('click', (e) => {
        e.preventDefault();
        load(parseInt(a.dataset.page, 10));
      });
    });
  }

  async function load(page = 1) {
    const body = document.getElementById('dataBody');
    const errorAlert = document.getElementById('errorAlert');
    body.innerHTML = '<tr><td colspan="8" class="text-center py-4" style="color:var(--gray-500)"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading...</td></tr>';
    errorAlert.classList.add('d-none');
    try {
      const res = await fetch(buildUrl(page), { method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const data = await res.json();
      if (!res.ok) {
        throw new Error(data.message || data.error || 'Request failed');
      }
      renderSummary(data.summary);
      renderTable(data);
      renderPagination(data);
    } catch (err) {
      body.innerHTML = '<tr><td colspan="8" class="text-center py-4" style="color:var(--danger)">Failed to load data.</td></tr>';
      document.getElementById('errorMessage').textContent = String(err && err.message ? err.message : err);
      errorAlert.classList.remove('d-none');
      document.getElementById('paginationInfo').textContent = '—';
      document.getElementById('pagination').innerHTML = '';
    }
  }

  async function exportCsv() {
    try {
      const res = await fetch(buildUrl(1, '10000'), { method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || 'Export failed');
      const rows = data.rows || [];
      const headers = currentView === 'pledges'
        ? ['Donor', 'Phone', 'Amount', 'Pledge Date', 'Created']
        : currentView === 'payments'
          ? ['Donor', 'Phone', 'Amount', 'Method', 'Payment Date', 'Reference', 'Approved By']
          : ['Donor', 'Phone', 'Pledged', 'Paid', 'Balance', 'Status'];
      const lines = [headers.join(',')];
      rows.forEach((r) => {
        const cells = currentView === 'pledges'
          ? [r.donor_name, r.donor_phone, r.amount, r.pledge_date, r.created_at]
          : currentView === 'payments'
            ? [r.donor_name, r.donor_phone, r.amount, r.payment_method, r.payment_date, r.reference_number, r.approved_by]
            : [r.donor_name, r.donor_phone, r.pledged, r.paid, r.balance, r.status];
        lines.push(cells.map((v) => '"' + String(v ?? '').replace(/"/g, '""') + '"').join(','));
      });
      const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = SOURCE + '-' + currentView + '.csv';
      a.click();
      URL.revokeObjectURL(a.href);
    } catch (err) {
      document.getElementById('errorMessage').textContent = String(err && err.message ? err.message : err);
      document.getElementById('errorAlert').classList.remove('d-none');
    }
  }

  document.querySelectorAll('.dsd-stat-chip').forEach((chip) => {
    chip.addEventListener('click', (e) => {
      e.preventDefault();
      setView(chip.dataset.view);
    });
  });
  document.getElementById('applyFilters').addEventListener('click', () => load(1));
  document.getElementById('clearFilters').addEventListener('click', () => {
    document.getElementById('filterDonor').value = '';
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    document.getElementById('filterMethod').value = '';
    document.getElementById('filterStatus').value = '';
    load(1);
  });
  document.getElementById('perPage').addEventListener('change', () => load(1));
  document.getElementById('exportCsvBtn').addEventListener('click', exportCsv);
  document.getElementById('filterDonor').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') load(1);
  });

  setView(currentView);
})();
</script>
</body>
</html>
