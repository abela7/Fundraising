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
$page_title = 'Paid Towards Pledges - Detail';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paid Towards Pledges - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/reports.css">

    <style>
        .filter-card { background: #f8f9fa; border-radius: 8px; }
        .kpi-card .kpi-value { font-size: 1.5rem; font-weight: 700; }
        .kpi-card .kpi-label { font-size: 0.85rem; opacity: 0.9; }
        .table-payments td { vertical-align: middle; }
        .clickable-card { cursor: pointer; transition: transform 0.15s ease, box-shadow 0.15s ease; }
        .clickable-card:hover { transform: translateY(-2px); box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.1) !important; }
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
                    <div class="alert alert-danger m-3">
                        <strong><i class="fas fa-exclamation-triangle me-2"></i>Database Error:</strong>
                        <?php echo htmlspecialchars($db_error_message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="mb-0"><i class="fas fa-money-bill-transfer text-success me-2"></i>Paid Towards Pledges</h4>
                        <div class="text-muted small">
                            Detailed breakdown of all confirmed pledge payments. Use filters to drill down.
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="financial-dashboard.php#tab-pledge"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
                        <button class="btn btn-success" id="exportCsvBtn" type="button"><i class="fas fa-file-csv me-1"></i>Export CSV</button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card border-0 shadow-sm mb-3 filter-card">
                    <div class="card-body">
                        <h6 class="mb-3"><i class="fas fa-filter me-2 text-primary"></i>Filters</h6>
                        <div class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label small">From Date</label>
                                <input type="date" class="form-control form-control-sm" id="filterDateFrom" placeholder="All">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">To Date</label>
                                <input type="date" class="form-control form-control-sm" id="filterDateTo" placeholder="All">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Donor (name, phone, ref)</label>
                                <input type="text" class="form-control form-control-sm" id="filterDonor" placeholder="Search...">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Payment Method</label>
                                <select class="form-select form-select-sm" id="filterMethod">
                                    <option value="">All</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="card">Card</option>
                                    <option value="cash">Cash</option>
                                    <option value="cheque">Cheque</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button class="btn btn-primary btn-sm me-2" id="applyFilters"><i class="fas fa-search me-1"></i>Apply</button>
                                <button class="btn btn-outline-secondary btn-sm" id="clearFilters"><i class="fas fa-times me-1"></i>Clear</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary KPI -->
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100 kpi-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="icon-circle bg-success text-white"><i class="fas fa-pound-sign"></i></div>
                                    <div class="ms-3 flex-grow-1">
                                        <div class="kpi-label text-success fw-bold">Total Amount (filtered)</div>
                                        <div class="kpi-value" id="summaryTotal">—</div>
                                        <div class="kpi-sub text-muted" id="summarySub">—</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Data Table -->
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0"><i class="fas fa-list me-2 text-primary"></i>Pledge Payments</h6>
                            <div class="d-flex align-items-center gap-2">
                                <select class="form-select form-select-sm" id="perPage" style="width: auto;">
                                    <option value="10">10 per page</option>
                                    <option value="25" selected>25 per page</option>
                                    <option value="50">50 per page</option>
                                    <option value="100">100 per page</option>
                                </select>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover table-payments align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Donor</th>
                                        <th class="text-end">Amount</th>
                                        <th>Method</th>
                                        <th>Payment Date</th>
                                        <th>Reference</th>
                                        <th>Approved By</th>
                                    </tr>
                                </thead>
                                <tbody id="dataBody">
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">
                                            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                            Loading...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <nav class="mt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="text-muted small" id="paginationInfo">—</div>
                            <ul class="pagination pagination-sm mb-0" id="pagination"></ul>
                        </nav>
                    </div>
                </div>

                <div class="alert alert-warning d-none mt-3" id="noDataAlert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>No pledge payments found.</strong> Try adjusting your filters or ensure the pledge_payments table has confirmed records.
                </div>

                <div class="alert alert-danger d-none mt-3" id="errorAlert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
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
    const df = document.getElementById('filterDateFrom').value;
    const dt = document.getElementById('filterDateTo').value;
    const donor = document.getElementById('filterDonor').value.trim();
    const method = document.getElementById('filterMethod').value;
    if (df) params.set('date_from', df);
    if (dt) params.set('date_to', dt);
    if (donor) params.set('donor', donor);
    if (method) params.set('payment_method', method);
    return 'api/paid-towards-pledges.php?' + params.toString();
  }

  function renderTable(data) {
    const body = document.getElementById('dataBody');
    const noDataAlert = document.getElementById('noDataAlert');
    const errorAlert = document.getElementById('errorAlert');

    if (!data || !data.enabled) {
      body.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">Pledge payments are not enabled on this system.</td></tr>';
      noDataAlert.classList.add('d-none');
      errorAlert.classList.add('d-none');
      return;
    }

    const rows = data.rows || [];
    if (rows.length === 0) {
      body.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">No pledge payments match your filters.</td></tr>';
      noDataAlert.classList.remove('d-none');
      errorAlert.classList.add('d-none');
      return;
    }

    noDataAlert.classList.add('d-none');
    errorAlert.classList.add('d-none');

    const startNum = (data.page - 1) * data.per_page + 1;
    body.innerHTML = rows.map((r, i) => {
      const num = startNum + i;
      const donorLink = r.donor_id ? `<a href="../donor-management/view-donor.php?id=${r.donor_id}">${escapeHtml(r.donor_name || 'Unknown')}</a>` : escapeHtml(r.donor_name || 'Unknown');
      return `
        <tr>
          <td>${num}</td>
          <td>
            <div>${donorLink}</div>
            ${r.donor_phone ? '<small class="text-muted">' + escapeHtml(r.donor_phone) + '</small>' : ''}
          </td>
          <td class="text-end fw-semibold">${escapeHtml(fmtMoney(r.amount))}</td>
          <td>${escapeHtml(r.payment_method)}</td>
          <td class="text-nowrap">${escapeHtml(r.payment_date ? new Date(r.payment_date).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '')}</td>
          <td><code class="small">${escapeHtml(r.reference_number || '—')}</code></td>
          <td class="text-muted small">${escapeHtml(r.approved_by || '—')}</td>
        </tr>
      `;
    }).join('');
  }

  function renderPagination(data) {
    const ul = document.getElementById('pagination');
    const info = document.getElementById('paginationInfo');
    if (!data || data.total_pages <= 1) {
      ul.innerHTML = '';
      info.textContent = data ? `Showing ${data.rows.length} of ${data.total_count} records` : '—';
      return;
    }

    const page = data.page;
    const total = data.total_pages;
    const totalCount = data.total_count;
    const from = (page - 1) * data.per_page + 1;
    const to = Math.min(page * data.per_page, totalCount);
    info.textContent = `Showing ${from}–${to} of ${totalCount} records`;

    let html = '';
    if (page > 1) {
      html += `<li class="page-item"><a class="page-link" href="#" data-page="1">First</a></li>`;
      html += `<li class="page-item"><a class="page-link" href="#" data-page="${page - 1}">Prev</a></li>`;
    }
    const start = Math.max(1, page - 2);
    const end = Math.min(total, page + 2);
    for (let i = start; i <= end; i++) {
      html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
    }
    if (page < total) {
      html += `<li class="page-item"><a class="page-link" href="#" data-page="${page + 1}">Next</a></li>`;
      html += `<li class="page-item"><a class="page-link" href="#" data-page="${total}">Last</a></li>`;
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
    if (!data || !data.enabled) {
      el.textContent = '—';
      sub.textContent = '—';
      return;
    }
    el.textContent = fmtMoney(data.total_amount);
    sub.textContent = data.total_count + ' payment(s)';
  }

  async function load(page = 1) {
    const body = document.getElementById('dataBody');
    const errorAlert = document.getElementById('errorAlert');
    const errorMsg = document.getElementById('errorMessage');

    body.innerHTML = '<tr><td colspan="7" class="text-center py-4"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading...</td></tr>';
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
      body.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-danger">Failed to load data.</td></tr>';
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
    const df = document.getElementById('filterDateFrom').value;
    const dt = document.getElementById('filterDateTo').value;
    const donor = document.getElementById('filterDonor').value.trim();
    const method = document.getElementById('filterMethod').value;
    if (df) params.set('date_from', df);
    if (dt) params.set('date_to', dt);
    if (donor) params.set('donor', donor);
    if (method) params.set('payment_method', method);

    fetch('api/paid-towards-pledges.php?' + params.toString(), { method: 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(r => r.json())
      .then(data => {
        if (!data.enabled || !data.rows || data.rows.length === 0) {
          alert('No data to export.');
          return;
        }
        const headers = ['#', 'Donor', 'Phone', 'Amount', 'Method', 'Payment Date', 'Reference', 'Approved By'];
        const rows = data.rows.map((r, i) => [
          i + 1,
          r.donor_name || '',
          r.donor_phone || '',
          r.amount,
          r.payment_method || '',
          r.payment_date || '',
          r.reference_number || '',
          r.approved_by || ''
        ]);
        const csv = [headers.join(','), ...rows.map(row => row.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(','))].join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'paid-towards-pledges-' + new Date().toISOString().slice(0, 10) + '.csv';
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
    document.getElementById('filterMethod').value = '';
    load(1);
  });
  document.getElementById('perPage').addEventListener('change', () => load(1));
  document.getElementById('exportCsvBtn').addEventListener('click', exportCsv);

  // Apply on Enter in filter inputs
  ['filterDonor', 'filterDateFrom', 'filterDateTo'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('keydown', (e) => { if (e.key === 'Enter') load(1); });
  });

  load(1);
})();
</script>
</body>
</html>
