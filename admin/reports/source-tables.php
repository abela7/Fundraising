<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

$db_error_message = '';
$settings = ['currency_code' => 'GBP'];
$totals = ['total_pledged' => 0, 'paid_towards_pledges' => 0, 'outstanding' => 0, 'grand_total' => 0];

try {
    $db = db();
    $settings_table_exists = $db->query("SHOW TABLES LIKE 'settings'")->num_rows > 0;
    if ($settings_table_exists) {
        $row = $db->query('SELECT currency_code FROM settings WHERE id = 1')->fetch_assoc();
        if (is_array($row) && isset($row['currency_code'])) {
            $settings['currency_code'] = (string)$row['currency_code'];
        }
    }

    $hasPledgePayments = $db->query("SHOW TABLES LIKE 'pledge_payments'")->num_rows > 0;
    $totals['total_pledged'] = (float)($db->query("SELECT COALESCE(SUM(amount),0) FROM pledges WHERE status = 'approved'")->fetch_row()[0] ?? 0);
    $totals['paid_towards_pledges'] = $hasPledgePayments
        ? (float)($db->query("SELECT COALESCE(SUM(amount),0) FROM pledge_payments WHERE status = 'confirmed'")->fetch_row()[0] ?? 0)
        : 0;
    $totals['outstanding'] = max(0, $totals['total_pledged'] - $totals['paid_towards_pledges']);
    $totalPaidDirect = (float)($db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'approved'")->fetch_row()[0] ?? 0);
    $totals['grand_total'] = $totalPaidDirect + $totals['paid_towards_pledges'] + $totals['outstanding'];
} catch (Exception $e) {
    $db_error_message = 'Database connection failed: ' . $e->getMessage();
}

$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
$page_title = 'Source Tables - Pledges & Pledge Payments';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Source Tables - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .st-tabs .nav-link { font-weight: 500; color: #495057; }
        .st-tabs .nav-link:hover { color: #212529; background: #f8f9fa; }
        .st-tabs .nav-link.active { font-weight: 600; color: #0d6efd; }
        .st-formula { font-family: var(--bs-font-monospace); background: #f8f9fa; padding: 0.5rem 0.75rem; border-radius: 6px; font-size: 0.9rem; }
        .st-totals-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; }
        .st-totals-card { background: #fff; border: 1px solid #dee2e6; border-radius: 10px; padding: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .st-totals-card .value { font-size: 1.25rem; font-weight: 700; }
        .st-totals-card .label { font-size: 0.75rem; color: #6c757d; text-transform: uppercase; }
        .ptp-data-card { background: #fff; border: 1px solid #dee2e6; border-radius: 12px; overflow: hidden; margin-bottom: 1rem; }
        .ptp-table-header { padding: 14px 20px; background: #f8f9fa; border-bottom: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
        .ptp-data-card .table { margin: 0; }
        .ptp-data-card .table thead th { font-size: 0.75rem; font-weight: 600; color: #6c757d; text-transform: uppercase; padding: 10px 16px; }
        .ptp-data-card .table tbody td { padding: 10px 16px; font-size: 0.875rem; }
        .ptp-data-card .table tbody tr:hover { background: #f8f9fa; }
        .ptp-pagination-wrapper { padding: 1rem 20px; border-top: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
        .st-filter-bar { background: #fff; border: 1px solid #dee2e6; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .st-sortable { cursor: pointer; user-select: none; }
        .st-sortable:hover { color: var(--primary) !important; }
        .st-sortable .st-sort-icon { margin-left: 4px; opacity: 0.5; font-size: 0.65rem; }
        .st-sortable.st-sort-active .st-sort-icon { opacity: 1; color: var(--primary); }
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

                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="mb-0"><i class="fas fa-database text-primary me-2"></i>Source Tables</h4>
                        <div class="text-muted small">View pledges and pledge_payments — the source of truth for totals.</div>
                    </div>
                    <a class="btn btn-outline-secondary" href="financial-dashboard.php#tab-pledge"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
                </div>

                <ul class="nav nav-tabs st-tabs mb-3" id="sourceTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-summary-btn" data-bs-toggle="tab" data-bs-target="#tab-summary" type="button" role="tab"><i class="fas fa-calculator me-1"></i>Summary & Totals</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-pledges-btn" data-bs-toggle="tab" data-bs-target="#tab-pledges" type="button" role="tab"><i class="fas fa-hand-holding-usd me-1"></i>pledges</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-pp-btn" data-bs-toggle="tab" data-bs-target="#tab-pledge-payments" type="button" role="tab"><i class="fas fa-money-bill-transfer me-1"></i>pledge_payments</button>
                    </li>
                </ul>

                <div class="tab-content" id="sourceTabsContent">
                    <!-- Summary -->
                    <div class="tab-pane fade show active" id="tab-summary" role="tabpanel">
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <h5 class="mb-3"><i class="fas fa-calculator me-2 text-primary"></i>How Totals Are Calculated</h5>
                                <div class="st-formula mb-3">
                                    Total Pledged = SUM(pledges.amount) WHERE status = 'approved'<br>
                                    Paid Towards Pledges = SUM(pledge_payments.amount) WHERE status = 'confirmed'<br>
                                    Outstanding = Total Pledged − Paid Towards Pledges<br>
                                    Grand Total = Direct Payments + Paid Towards Pledges + Outstanding
                                </div>
                                <div class="st-totals-grid">
                                    <div class="st-totals-card">
                                        <div class="label">Total Pledged</div>
                                        <div class="value text-primary"><?php echo $currency . ' ' . number_format($totals['total_pledged'], 2); ?></div>
                                        <small class="text-muted">from pledges (approved)</small>
                                    </div>
                                    <div class="st-totals-card">
                                        <div class="label">Paid Towards Pledges</div>
                                        <div class="value text-success"><?php echo $currency . ' ' . number_format($totals['paid_towards_pledges'], 2); ?></div>
                                        <small class="text-muted">from pledge_payments (confirmed)</small>
                                    </div>
                                    <div class="st-totals-card">
                                        <div class="label">Outstanding</div>
                                        <div class="value text-warning"><?php echo $currency . ' ' . number_format($totals['outstanding'], 2); ?></div>
                                        <small class="text-muted">pledged − paid</small>
                                    </div>
                                    <div class="st-totals-card">
                                        <div class="label">Grand Total</div>
                                        <div class="value"><?php echo $currency . ' ' . number_format($totals['grand_total'], 2); ?></div>
                                        <small class="text-muted">direct + pledge payments + outstanding</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="../donor-management/index.php" class="btn btn-primary"><i class="fas fa-user-plus me-1"></i>Add Donor / Pledge</a>
                            <a href="../donations/index.php" class="btn btn-success"><i class="fas fa-plus me-1"></i>Add Pledge Payment</a>
                        </div>
                    </div>

                    <!-- Pledges table -->
                    <div class="tab-pane fade" id="tab-pledges" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0"><i class="fas fa-table me-2"></i>pledges (status = 'approved')</h6>
                            <a href="../donor-management/index.php" class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i>Add Pledge</a>
                        </div>
                        <div class="st-filter-bar mb-3">
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-md-3">
                                    <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#6c757d">Donor</label>
                                    <input type="text" class="form-control form-control-sm" id="pledgeSearch" placeholder="Search donor...">
                                </div>
                                <div class="col-12 col-md-2">
                                    <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#6c757d">Date from</label>
                                    <input type="date" class="form-control form-control-sm" id="pledgeDateFrom">
                                </div>
                                <div class="col-12 col-md-2">
                                    <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#6c757d">Date to</label>
                                    <input type="date" class="form-control form-control-sm" id="pledgeDateTo">
                                </div>
                                <div class="col-12 col-md-2">
                                    <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#6c757d">Per page</label>
                                    <select class="form-select form-select-sm" id="pledgePerPage">
                                        <option value="10">10</option><option value="25" selected>25</option><option value="50">50</option><option value="100">100</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-2 d-flex gap-2">
                                    <button class="btn btn-primary btn-sm flex-fill" id="pledgeApply"><i class="fas fa-search me-1"></i>Apply</button>
                                    <button class="btn btn-outline-secondary btn-sm" id="pledgeClear"><i class="fas fa-times me-1"></i>Clear</button>
                                </div>
                            </div>
                        </div>
                        <div class="ptp-data-card">
                            <div class="ptp-table-header">
                                <span>Source: pledges</span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th class="st-sortable" data-sort-by="donor" data-tab="pledges">Donor<span class="st-sort-icon"></span></th>
                                            <th class="text-end st-sortable" data-sort-by="amount" data-tab="pledges">Amount<span class="st-sort-icon"></span></th>
                                            <th class="st-sortable" data-sort-by="pledge_date" data-tab="pledges">Date<span class="st-sort-icon"></span></th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="pledgesBody"><tr><td colspan="5" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading...</td></tr></tbody>
                                </table>
                            </div>
                            <div class="ptp-pagination-wrapper">
                                <div class="small text-muted" id="pledgesInfo">—</div>
                                <ul class="pagination pagination-sm mb-0" id="pledgesPagination"></ul>
                            </div>
                        </div>
                    </div>

                    <!-- Pledge payments table -->
                    <div class="tab-pane fade" id="tab-pledge-payments" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0"><i class="fas fa-table me-2"></i>pledge_payments (status = 'confirmed')</h6>
                            <a href="../donations/index.php" class="btn btn-sm btn-success"><i class="fas fa-plus me-1"></i>Add Payment</a>
                        </div>
                        <div class="st-filter-bar mb-3">
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-md-3">
                                    <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#6c757d">Donor</label>
                                    <input type="text" class="form-control form-control-sm" id="ppSearch" placeholder="Search donor...">
                                </div>
                                <div class="col-12 col-md-2">
                                    <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#6c757d">Date from</label>
                                    <input type="date" class="form-control form-control-sm" id="ppDateFrom">
                                </div>
                                <div class="col-12 col-md-2">
                                    <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#6c757d">Date to</label>
                                    <input type="date" class="form-control form-control-sm" id="ppDateTo">
                                </div>
                                <div class="col-12 col-md-2">
                                    <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#6c757d">Method</label>
                                    <select class="form-select form-select-sm" id="ppMethod">
                                        <option value="">All</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="card">Card</option>
                                        <option value="cash">Cash</option>
                                        <option value="cheque">Cheque</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-2">
                                    <label class="form-label" style="font-size:0.75rem; font-weight:600; color:#6c757d">Per page</label>
                                    <select class="form-select form-select-sm" id="ppPerPage">
                                        <option value="10">10</option><option value="25" selected>25</option><option value="50">50</option><option value="100">100</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-2 d-flex gap-2">
                                    <button class="btn btn-primary btn-sm flex-fill" id="ppApply"><i class="fas fa-search me-1"></i>Apply</button>
                                    <button class="btn btn-outline-secondary btn-sm" id="ppClear"><i class="fas fa-times me-1"></i>Clear</button>
                                </div>
                            </div>
                        </div>
                        <div class="ptp-data-card">
                            <div class="ptp-table-header">
                                <span>Source: pledge_payments</span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th class="st-sortable" data-sort-by="donor" data-tab="pp">Donor<span class="st-sort-icon"></span></th>
                                            <th class="text-end st-sortable" data-sort-by="amount" data-tab="pp">Amount<span class="st-sort-icon"></span></th>
                                            <th class="st-sortable" data-sort-by="method" data-tab="pp">Method<span class="st-sort-icon"></span></th>
                                            <th class="st-sortable" data-sort-by="date" data-tab="pp">Date<span class="st-sort-icon"></span></th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="ppBody"><tr><td colspan="6" class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading...</td></tr></tbody>
                                </table>
                            </div>
                            <div class="ptp-pagination-wrapper">
                                <div class="small text-muted" id="ppInfo">—</div>
                                <ul class="pagination pagination-sm mb-0" id="ppPagination"></ul>
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
  const sortState = { pledges: { sortBy: 'pledge_date', sortOrder: 'desc' }, pp: { sortBy: 'date', sortOrder: 'desc' } };
  function fmtMoney(n) {
    try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: CURRENCY, maximumFractionDigits: 2 }).format(Number(n) || 0); } catch(_) { return CURRENCY + ' ' + (Number(n) || 0).toFixed(2); }
  }
  function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  function buildPledgeParams(page) {
    const p = new URLSearchParams({ page: page || 1, per_page: document.getElementById('pledgePerPage').value, sort_by: sortState.pledges.sortBy, sort_order: sortState.pledges.sortOrder });
    const donor = document.getElementById('pledgeSearch').value.trim();
    if (donor) p.set('donor', donor);
    const dateFrom = document.getElementById('pledgeDateFrom').value.trim();
    if (dateFrom) p.set('date_from', dateFrom);
    const dateTo = document.getElementById('pledgeDateTo').value.trim();
    if (dateTo) p.set('date_to', dateTo);
    return p;
  };

  function buildPPParams(page) {
    const p = new URLSearchParams({ page: page || 1, per_page: document.getElementById('ppPerPage').value, sort_by: sortState.pp.sortBy, sort_order: sortState.pp.sortOrder });
    const donor = document.getElementById('ppSearch').value.trim();
    if (donor) p.set('donor', donor);
    const dateFrom = document.getElementById('ppDateFrom').value.trim();
    if (dateFrom) p.set('date_from', dateFrom);
    const dateTo = document.getElementById('ppDateTo').value.trim();
    if (dateTo) p.set('date_to', dateTo);
    const method = document.getElementById('ppMethod').value;
    if (method) p.set('payment_method', method);
    return p;
  };

  function updateSortHeaders(tab) {
    document.querySelectorAll('.st-sortable[data-tab="' + tab + '"]').forEach(th => {
      const col = th.dataset.sortBy;
      th.classList.remove('st-sort-active');
      const icon = th.querySelector('.st-sort-icon');
      if (icon) {
        if (col === sortState[tab].sortBy) {
          th.classList.add('st-sort-active');
          icon.innerHTML = sortState[tab].sortOrder === 'asc' ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>';
        } else {
          icon.innerHTML = '<i class="fas fa-sort" style="opacity:0.4"></i>';
        }
      }
    });
  }

  function loadPledges(page) {
    const params = buildPledgeParams(page);
    fetch('api/total-pledged-approved.php?' + params, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => {
        const ct = r.headers.get('Content-Type') || '';
        if (!ct.includes('application/json')) {
          throw new Error('Server returned non-JSON (status ' + r.status + '). Check if you are logged in.');
        }
        return r.json();
      })
      .then(d => {
        if (d.error) {
          document.getElementById('pledgesBody').innerHTML = '<tr><td colspan="5" class="text-center py-4 text-danger">' + esc(d.message || d.error) + '</td></tr>';
          document.getElementById('pledgesInfo').textContent = '—';
          document.getElementById('pledgesPagination').innerHTML = '';
          return;
        }
        const body = document.getElementById('pledgesBody');
        const rows = d.rows || [];
        const start = (d.page - 1) * d.per_page + 1;
        if (rows.length === 0) {
          body.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No approved pledges found.</td></tr>';
        } else {
          body.innerHTML = rows.map((r, i) => {
            const link = r.donor_id ? `../donor-management/view-donor.php?id=${r.donor_id}` : '#';
            return `<tr>
              <td>${start + i}</td>
              <td><a href="${link}" class="text-primary">${esc(r.donor_name || '—')}</a></td>
              <td class="text-end fw-semibold">${esc(fmtMoney(r.amount))}</td>
              <td>${esc(r.pledge_date || r.created_at || '—')}</td>
              <td>${r.donor_id ? `<a href="${link}" class="btn btn-sm btn-outline-primary"><i class="fas fa-user"></i></a>` : ''}</td>
            </tr>`;
          }).join('');
        }
        document.getElementById('pledgesInfo').textContent = rows.length ? `Showing ${start}–${start + rows.length - 1} of ${d.total_count || 0}` : `0 of ${d.total_count || 0}`;
        renderPagination('pledgesPagination', d.page, d.total_pages, loadPledges);
      })
      .catch(err => {
        document.getElementById('pledgesBody').innerHTML = '<tr><td colspan="5" class="text-center py-4 text-danger">' + esc(err && err.message ? err.message : 'Failed to load.') + '</td></tr>';
        document.getElementById('pledgesInfo').textContent = '—';
        document.getElementById('pledgesPagination').innerHTML = '';
      });
  }

  function loadPP(page) {
    const params = buildPPParams(page);
    fetch('api/paid-towards-pledges.php?' + params, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => {
        const ct = r.headers.get('Content-Type') || '';
        if (!ct.includes('application/json')) {
          throw new Error('Server returned non-JSON (status ' + r.status + '). Check if you are logged in.');
        }
        return r.json();
      })
      .then(d => {
        if (d.error) {
          document.getElementById('ppBody').innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">' + esc(d.error) + '</td></tr>';
          document.getElementById('ppInfo').textContent = '—';
          document.getElementById('ppPagination').innerHTML = '';
          return;
        }
        const body = document.getElementById('ppBody');
        const rows = d.rows || [];
        const start = (d.page - 1) * d.per_page + 1;
        if (rows.length === 0) {
          body.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">No confirmed pledge payments found.</td></tr>';
        } else {
          body.innerHTML = rows.map((r, i) => {
            const link = r.donor_id ? `../donor-management/view-donor.php?id=${r.donor_id}` : '#';
            return `<tr>
              <td>${start + i}</td>
              <td><a href="${link}" class="text-primary">${esc(r.donor_name || '—')}</a></td>
              <td class="text-end fw-semibold text-success">${esc(fmtMoney(r.amount))}</td>
              <td>${esc(r.payment_method || '—')}</td>
              <td>${esc(r.payment_date || '—')}</td>
              <td>${r.donor_id ? `<a href="${link}" class="btn btn-sm btn-outline-primary"><i class="fas fa-user"></i></a>` : ''}</td>
            </tr>`;
          }).join('');
        }
        document.getElementById('ppInfo').textContent = rows.length ? `Showing ${start}–${start + rows.length - 1} of ${d.total_count || 0}` : `0 of ${d.total_count || 0}`;
        renderPagination('ppPagination', d.page, d.total_pages, loadPP);
      })
      .catch(err => {
        document.getElementById('ppBody').innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger">' + esc(err && err.message ? err.message : 'Failed to load.') + '</td></tr>';
        document.getElementById('ppInfo').textContent = '—';
        document.getElementById('ppPagination').innerHTML = '';
      });
  }

  function renderPagination(id, page, total, fn) {
    const ul = document.getElementById(id);
    if (!total || total <= 1) { ul.innerHTML = ''; return; }
    let html = '';
    if (page > 1) html += `<li class="page-item"><a class="page-link" href="#" data-p="${page-1}"><i class="fas fa-angle-left"></i></a></li>`;
    for (let i = Math.max(1, page - 2); i <= Math.min(total, page + 2); i++) {
      html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" data-p="${i}">${i}</a></li>`;
    }
    if (page < total) html += `<li class="page-item"><a class="page-link" href="#" data-p="${page+1}"><i class="fas fa-angle-right"></i></a></li>`;
    ul.innerHTML = html;
    ul.querySelectorAll('a[data-p]').forEach(a => { a.addEventListener('click', e => { e.preventDefault(); fn(parseInt(a.dataset.p, 10)); }); });
  }

  document.getElementById('pledgeApply').addEventListener('click', () => loadPledges(1));
  document.getElementById('pledgeClear').addEventListener('click', () => {
    document.getElementById('pledgeSearch').value = '';
    document.getElementById('pledgeDateFrom').value = '';
    document.getElementById('pledgeDateTo').value = '';
    document.getElementById('pledgePerPage').value = '25';
    loadPledges(1);
  });
  document.getElementById('ppApply').addEventListener('click', () => loadPP(1));
  document.getElementById('ppClear').addEventListener('click', () => {
    document.getElementById('ppSearch').value = '';
    document.getElementById('ppDateFrom').value = '';
    document.getElementById('ppDateTo').value = '';
    document.getElementById('ppMethod').value = '';
    document.getElementById('ppPerPage').value = '25';
    loadPP(1);
  });
  document.getElementById('pledgePerPage').addEventListener('change', () => loadPledges(1));
  document.getElementById('ppPerPage').addEventListener('change', () => loadPP(1));
  document.getElementById('pledgeSearch').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); loadPledges(1); } });
  document.getElementById('ppSearch').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); loadPP(1); } });

  document.querySelectorAll('.st-sortable').forEach(th => {
    th.addEventListener('click', () => {
      const tab = th.dataset.tab;
      const col = th.dataset.sortBy;
      if (sortState[tab].sortBy === col) sortState[tab].sortOrder = sortState[tab].sortOrder === 'asc' ? 'desc' : 'asc';
      else { sortState[tab].sortBy = col; sortState[tab].sortOrder = ['amount','pledge_date','date'].includes(col) ? 'desc' : 'asc'; }
      updateSortHeaders(tab);
      if (tab === 'pledges') loadPledges(1); else loadPP(1);
    });
  });

  document.querySelectorAll('#sourceTabs button[data-bs-toggle="tab"]').forEach(btn => {
    btn.addEventListener('shown.bs.tab', e => {
      if (e.target.id === 'tab-pledges-btn') loadPledges(1);
      if (e.target.id === 'tab-pp-btn') loadPP(1);
    });
  });

  updateSortHeaders('pledges');
  updateSortHeaders('pp');
  loadPledges(1);
  loadPP(1);
})();
</script>
</body>
</html>
