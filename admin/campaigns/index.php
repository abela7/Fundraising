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
    $settingsTable = $db->query("SHOW TABLES LIKE 'settings'");
    if ($settingsTable && $settingsTable->num_rows > 0) {
        $row = $db->query('SELECT currency_code FROM settings WHERE id = 1')->fetch_assoc();
        if (is_array($row) && isset($row['currency_code'])) {
            $settings['currency_code'] = (string)$row['currency_code'];
        }
    }
} catch (Exception $e) {
    $db_error_message = 'Database connection failed.';
}

$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
$page_title = 'WhatsApp Donor Campaign';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Donor Campaign - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/campaigns.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <?php if ($db_error_message !== ''): ?>
                    <div class="alert alert-danger mb-3" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($db_error_message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <div class="dvc-page-header animate-fade-in">
                    <div>
                        <h1>
                            <i class="fab fa-whatsapp me-2" style="color: var(--success);"></i>
                            WhatsApp Donor Campaign
                        </h1>
                        <p>All donors, grouped by how they actually paid — ready for Amharic WhatsApp contact.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="../reports/financial-dashboard.php">
                            <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                        </a>
                    </div>
                </div>

                <div class="dvc-note animate-fade-in">
                    <i class="fas fa-info-circle me-1" style="color: var(--primary);"></i>
                    Groups use pledged, paid, and remaining amounts — not the stored status label.
                    WhatsApp messages will be in Amharic. Templates come next.
                </div>

                <div class="dvc-stat-row animate-fade-in">
                    <a href="#" class="dvc-stat-chip active" id="chipImmediate" data-group="immediate">
                        <div class="dvc-stat-icon immediate"><i class="fas fa-bolt"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="countImmediate">—</div>
                            <div class="dvc-stat-label">Immediate payers</div>
                            <div class="dvc-stat-meta" id="metaImmediate">Paid on the spot</div>
                        </div>
                    </a>
                    <a href="#" class="dvc-stat-chip" id="chipCompleted" data-group="pledge_completed">
                        <div class="dvc-stat-icon completed"><i class="fas fa-flag-checkered"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="countCompleted">—</div>
                            <div class="dvc-stat-label">Pledge completed</div>
                            <div class="dvc-stat-meta" id="metaCompleted">Promised and paid in full</div>
                        </div>
                    </a>
                    <a href="#" class="dvc-stat-chip" id="chipPaying" data-group="pledge_paying">
                        <div class="dvc-stat-icon paying"><i class="fas fa-person-walking"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="countPaying">—</div>
                            <div class="dvc-stat-label">Pledge paying</div>
                            <div class="dvc-stat-meta" id="metaPaying">Started, still owing</div>
                        </div>
                    </a>
                    <a href="#" class="dvc-stat-chip" id="chipNotStarted" data-group="pledge_not_started">
                        <div class="dvc-stat-icon not-started"><i class="fas fa-hourglass-start"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="countNotStarted">—</div>
                            <div class="dvc-stat-label">Pledge not started</div>
                            <div class="dvc-stat-meta" id="metaNotStarted">Promised, paid nothing</div>
                        </div>
                    </a>
                    <a href="#" class="dvc-stat-chip" id="chipReview" data-group="unclassified">
                        <div class="dvc-stat-icon review"><i class="fas fa-clipboard-check"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="countReview">—</div>
                            <div class="dvc-stat-label">Needs review</div>
                            <div class="dvc-stat-meta" id="metaReview">No pledge and no payment</div>
                        </div>
                    </a>
                </div>
                <div class="text-muted small mb-3" id="summaryLine">—</div>

                <ul class="nav nav-tabs dvc-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" type="button" id="tabImmediateBtn" data-family="immediate">
                            <i class="fas fa-bolt me-1"></i>Immediate payers
                            <span class="dvc-tab-count" id="tabCountImmediate">0</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" type="button" id="tabPledgeBtn" data-family="pledge">
                            <i class="fas fa-hand-holding-heart me-1"></i>Pledge donors
                            <span class="dvc-tab-count" id="tabCountPledge">0</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" type="button" id="tabReviewBtn" data-family="review">
                            <i class="fas fa-clipboard-check me-1"></i>Needs review
                            <span class="dvc-tab-count" id="tabCountReview">0</span>
                        </button>
                    </li>
                </ul>

                <div class="dvc-subtabs d-none" id="pledgeSubtabs">
                    <button type="button" class="dvc-subtab" data-group="pledge_completed">Completed</button>
                    <button type="button" class="dvc-subtab" data-group="pledge_paying">Still paying</button>
                    <button type="button" class="dvc-subtab active" data-group="pledge_not_started">Not started</button>
                </div>

                <div class="dvc-filter-bar">
                    <div class="form-label mb-2"><i class="fas fa-filter me-1"></i>Filters</div>
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="filterDonor">Donor (name, phone, reference)</label>
                            <input type="text" class="form-control form-control-sm" id="filterDonor" placeholder="Search...">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label" for="filterSource">Data source</label>
                            <select class="form-select form-select-sm" id="filterSource">
                                <option value="">All (old and new)</option>
                                <option value="old_system">Old system (imported)</option>
                                <option value="new_system">New system</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3 d-flex gap-2">
                            <button class="btn btn-primary btn-sm flex-fill" type="button" id="applyFilters">
                                <i class="fas fa-search me-1"></i>Apply
                            </button>
                            <button class="btn btn-outline-secondary btn-sm" type="button" id="clearFilters">
                                <i class="fas fa-times me-1"></i>Clear
                            </button>
                        </div>
                    </div>
                </div>

                <div class="dvc-data-card">
                    <div class="dvc-table-header">
                        <h6 id="tableTitle"><i class="fas fa-users me-2" style="color: var(--primary);"></i>Immediate payers</h6>
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0" for="perPage">Per page</label>
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
                                    <th class="dvc-col-num">#</th>
                                    <th class="dvc-sortable dvc-sort-active" data-sort-by="name">Donor<span class="dvc-sort-icon"><i class="fas fa-sort-up"></i></span></th>
                                    <th>Reference</th>
                                    <th class="dvc-sortable" data-sort-by="source">Source<span class="dvc-sort-icon"></span></th>
                                    <th class="text-end dvc-sortable" data-sort-by="pledged">Pledged<span class="dvc-sort-icon"></span></th>
                                    <th class="text-end dvc-sortable" data-sort-by="paid">Paid<span class="dvc-sort-icon"></span></th>
                                    <th class="text-end dvc-sortable" data-sort-by="balance">Remaining<span class="dvc-sort-icon"></span></th>
                                </tr>
                            </thead>
                            <tbody id="dataBody">
                                <tr>
                                    <td colspan="7" class="text-center py-4" style="color: var(--gray-500);">
                                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                        Loading...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="dvc-pagination-wrapper">
                        <div class="dvc-pagination-info" id="paginationInfo">—</div>
                        <ul class="pagination pagination-sm mb-0" id="pagination"></ul>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
(function () {
  const CURRENCY = <?php echo json_encode($currency); ?>;
  const TITLES = {
    immediate: 'Immediate payers',
    pledge_completed: 'Pledge donors — completed',
    pledge_paying: 'Pledge donors — still paying',
    pledge_not_started: 'Pledge donors — not started',
    unclassified: 'Needs review'
  };
  let currentGroup = 'immediate';
  let sortState = { sortBy: 'name', sortOrder: 'asc' };

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

  function familyFromGroup(group) {
    if (group === 'immediate') return 'immediate';
    if (group === 'unclassified') return 'review';
    return 'pledge';
  }

  function buildUrl(page) {
    const params = new URLSearchParams();
    params.set('group', currentGroup);
    params.set('page', String(page));
    params.set('per_page', document.getElementById('perPage').value);
    params.set('sort_by', sortState.sortBy);
    params.set('sort_order', sortState.sortOrder);
    const donor = document.getElementById('filterDonor').value.trim();
    const source = document.getElementById('filterSource').value;
    if (donor) params.set('donor', donor);
    if (source) params.set('source', source);
    return 'api/donors.php?' + params.toString();
  }

  function updateChrome() {
    document.querySelectorAll('.dvc-stat-chip').forEach((chip) => {
      chip.classList.toggle('active', chip.dataset.group === currentGroup);
    });
    const family = familyFromGroup(currentGroup);
    document.getElementById('tabImmediateBtn').classList.toggle('active', family === 'immediate');
    document.getElementById('tabPledgeBtn').classList.toggle('active', family === 'pledge');
    document.getElementById('tabReviewBtn').classList.toggle('active', family === 'review');
    document.getElementById('pledgeSubtabs').classList.toggle('d-none', family !== 'pledge');
    document.querySelectorAll('#pledgeSubtabs .dvc-subtab').forEach((btn) => {
      btn.classList.toggle('active', btn.dataset.group === currentGroup);
    });
    document.getElementById('tableTitle').innerHTML =
      '<i class="fas fa-users me-2" style="color: var(--primary);"></i>' + (TITLES[currentGroup] || 'Donors');
  }

  function renderSummary(summary) {
    const immediate = summary.immediate || {};
    const completed = summary.pledge_completed || {};
    const paying = summary.pledge_paying || {};
    const notStarted = summary.pledge_not_started || {};
    document.getElementById('countImmediate').textContent = Number(immediate.donors || 0).toLocaleString();
    document.getElementById('countCompleted').textContent = Number(completed.donors || 0).toLocaleString();
    document.getElementById('countPaying').textContent = Number(paying.donors || 0).toLocaleString();
    document.getElementById('countNotStarted').textContent = Number(notStarted.donors || 0).toLocaleString();
    document.getElementById('metaImmediate').textContent = fmtMoney(immediate.paid) + ' received';
    document.getElementById('metaCompleted').textContent = fmtMoney(completed.paid) + ' received';
    document.getElementById('metaPaying').textContent = fmtMoney(paying.remaining) + ' remaining';
    document.getElementById('metaNotStarted').textContent = fmtMoney(notStarted.pledged) + ' pledged';
    const review = summary.unclassified || {};
    document.getElementById('countReview').textContent = Number(review.donors || 0).toLocaleString();
    document.getElementById('tabCountImmediate').textContent = Number(immediate.donors || 0).toLocaleString();
    document.getElementById('tabCountPledge').textContent = Number(summary.pledge_donors || 0).toLocaleString();
    document.getElementById('tabCountReview').textContent = Number(review.donors || 0).toLocaleString();
    document.getElementById('summaryLine').textContent =
      Number(summary.total_donors || 0).toLocaleString() + ' donors in total (old and new).';
  }

  function sourceBadge(source) {
    if (source === 'old_system') {
      return '<span class="dvc-badge dvc-badge-old">Old</span>';
    }
    return '<span class="dvc-badge dvc-badge-new">New</span>';
  }

  function renderTable(data) {
    const body = document.getElementById('dataBody');
    const rows = data.rows || [];
    if (rows.length === 0) {
      body.innerHTML = '<tr><td colspan="7"><div class="dvc-empty-state"><i class="fas fa-inbox"></i><p>No donors in this group.</p></div></td></tr>';
      return;
    }
    const startNum = (data.page - 1) * data.per_page + 1;
    body.innerHTML = rows.map((r, i) => {
      const name = escapeHtml(r.name || 'Unknown');
      const link = r.donor_id
        ? `<a class="dvc-donor-link" href="../donor-management/view-donor.php?id=${r.donor_id}">${name}</a>`
        : name;
      return `<tr>
        <td class="dvc-col-num" data-label="#">${startNum + i}</td>
        <td data-label="Donor"><div>${link}</div><small class="text-muted">${escapeHtml(r.phone || '')}</small></td>
        <td data-label="Reference"><code class="small">${escapeHtml(r.reference || '—')}</code></td>
        <td data-label="Source">${sourceBadge(r.data_source)}</td>
        <td class="text-end" data-label="Pledged">${escapeHtml(fmtMoney(r.pledged))}</td>
        <td class="text-end text-success" data-label="Paid">${escapeHtml(fmtMoney(r.paid))}</td>
        <td class="text-end fw-semibold" data-label="Remaining">${escapeHtml(fmtMoney(r.balance))}</td>
      </tr>`;
    }).join('');
  }

  function renderPagination(data) {
    const ul = document.getElementById('pagination');
    const info = document.getElementById('paginationInfo');
    const total = data.total_count || 0;
    const from = total === 0 ? 0 : (data.page - 1) * data.per_page + 1;
    const to = Math.min(data.page * data.per_page, total);
    info.innerHTML = `Showing <strong>${from}</strong>–<strong>${to}</strong> of <strong>${total}</strong>`;
    if (data.total_pages <= 1) {
      ul.innerHTML = '';
      return;
    }
    let html = '';
    const page = data.page;
    if (page > 1) {
      html += `<li class="page-item"><a class="page-link" href="#" data-page="${page - 1}" aria-label="Previous"><i class="fas fa-angle-left"></i></a></li>`;
    }
    const start = Math.max(1, page - 2);
    const end = Math.min(data.total_pages, page + 2);
    for (let i = start; i <= end; i++) {
      html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
    }
    if (page < data.total_pages) {
      html += `<li class="page-item"><a class="page-link" href="#" data-page="${page + 1}" aria-label="Next"><i class="fas fa-angle-right"></i></a></li>`;
    }
    ul.innerHTML = html;
    ul.querySelectorAll('a[data-page]').forEach((a) => {
      a.addEventListener('click', (e) => {
        e.preventDefault();
        load(parseInt(a.dataset.page, 10));
      });
    });
  }

  async function load(page) {
    const body = document.getElementById('dataBody');
    body.innerHTML = '<tr><td colspan="7" class="text-center py-4" style="color:var(--gray-500)"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading...</td></tr>';
    try {
      const res = await fetch(buildUrl(page), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Request failed');
      renderSummary(data.summary || {});
      renderTable(data);
      renderPagination(data);
    } catch (err) {
      body.innerHTML = '<tr><td colspan="7" class="text-center py-4" style="color:var(--danger)">Failed to load donors.</td></tr>';
    }
  }

  function setGroup(group) {
    currentGroup = group;
    updateChrome();
    load(1);
  }

  document.querySelectorAll('.dvc-stat-chip').forEach((chip) => {
    chip.addEventListener('click', (e) => {
      e.preventDefault();
      setGroup(chip.dataset.group);
    });
  });
  document.getElementById('tabImmediateBtn').addEventListener('click', () => setGroup('immediate'));
  document.getElementById('tabReviewBtn').addEventListener('click', () => setGroup('unclassified'));
  document.getElementById('tabPledgeBtn').addEventListener('click', () => {
    if (familyFromGroup(currentGroup) !== 'pledge') {
      setGroup('pledge_not_started');
    }
  });
  document.querySelectorAll('#pledgeSubtabs .dvc-subtab').forEach((btn) => {
    btn.addEventListener('click', () => setGroup(btn.dataset.group));
  });
  document.querySelectorAll('.dvc-sortable').forEach((th) => {
    th.addEventListener('click', () => {
      const key = th.dataset.sortBy;
      if (sortState.sortBy === key) {
        sortState.sortOrder = sortState.sortOrder === 'asc' ? 'desc' : 'asc';
      } else {
        sortState.sortBy = key;
        sortState.sortOrder = ['pledged', 'paid', 'balance'].includes(key) ? 'desc' : 'asc';
      }
      document.querySelectorAll('.dvc-sortable').forEach((el) => el.classList.remove('dvc-sort-active'));
      th.classList.add('dvc-sort-active');
      const icon = th.querySelector('.dvc-sort-icon');
      if (icon) {
        icon.innerHTML = sortState.sortOrder === 'asc' ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>';
      }
      load(1);
    });
  });
  document.getElementById('applyFilters').addEventListener('click', () => load(1));
  document.getElementById('clearFilters').addEventListener('click', () => {
    document.getElementById('filterDonor').value = '';
    document.getElementById('filterSource').value = '';
    load(1);
  });
  document.getElementById('perPage').addEventListener('change', () => load(1));
  document.getElementById('filterDonor').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') load(1);
  });

  updateChrome();
  load(1);
})();
</script>
</body>
</html>
