<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

$settings = ['currency_code' => 'GBP'];
try {
    $db = db();
    $stCheck = $db->query("SHOW TABLES LIKE 'settings'");
    if ($stCheck && $stCheck->num_rows > 0) {
        $row = $db->query('SELECT currency_code FROM settings WHERE id = 1')->fetch_assoc();
        if (is_array($row) && isset($row['currency_code'])) {
            $settings['currency_code'] = (string)$row['currency_code'];
        }
    }
} catch (Exception $e) {}

$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Comparison - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <style>
        .dc-page-header { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
        .dc-page-header h1 { font-size:1.5rem; font-weight:700; color:var(--gray-900); margin:0; }
        .dc-page-header p { color:var(--gray-500); font-size:0.875rem; margin:4px 0 0; }

        .dc-upload-zone { border:2px dashed var(--gray-300); border-radius:12px; padding:40px 20px; text-align:center; background:var(--gray-50); transition:all 0.2s; cursor:pointer; }
        .dc-upload-zone:hover, .dc-upload-zone.dragover { border-color:var(--primary); background:rgba(10,98,134,0.04); }
        .dc-upload-zone i { font-size:2.5rem; color:var(--gray-400); margin-bottom:12px; display:block; }
        .dc-upload-zone p { color:var(--gray-500); margin:0; }

        .dc-card { background:var(--white); border:1px solid var(--gray-200); border-radius:12px; box-shadow:var(--shadow-sm); overflow:hidden; margin-bottom:16px; }
        .dc-card:hover { box-shadow:var(--shadow-md); }
        .dc-card-header { padding:14px 20px; background:var(--gray-50); border-bottom:1px solid var(--gray-200); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.75rem; }
        .dc-card-header h6 { font-weight:600; color:var(--gray-800); margin:0; font-size:0.9375rem; }

        .dc-stat { display:flex; align-items:center; gap:10px; background:var(--white); border:1px solid var(--gray-200); border-radius:10px; padding:12px 18px; box-shadow:var(--shadow-sm); flex:1; min-width:140px; }
        .dc-stat:hover { box-shadow:var(--shadow-md); }
        .dc-stat-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:0.9rem; flex-shrink:0; }
        .dc-stat-value { font-size:1.15rem; font-weight:700; color:var(--gray-900); line-height:1.2; }
        .dc-stat-label { font-size:0.6875rem; font-weight:500; color:var(--gray-500); text-transform:uppercase; letter-spacing:0.4px; }

        .dc-table thead th { background:var(--white); font-size:0.7rem; font-weight:600; color:var(--gray-500); text-transform:uppercase; letter-spacing:0.3px; border-bottom:1px solid var(--gray-200); padding:8px 12px; white-space:nowrap; position:sticky; top:0; z-index:2; }
        .dc-table tbody td { padding:8px 12px; vertical-align:middle; font-size:0.8125rem; border-bottom:1px solid var(--gray-50); }
        .dc-table tbody tr:hover { background:var(--gray-50); }

        .dc-match { background:rgba(16,185,129,0.06); }
        .dc-mismatch { background:rgba(239,68,68,0.06); }
        .dc-xl-only { background:rgba(245,158,11,0.06); }
        .dc-db-only { background:rgba(59,130,246,0.06); }

        .dc-diff { font-weight:600; color:var(--danger); }
        .dc-same { color:var(--success); }

        .dc-filter-bar { background:var(--white); border:1px solid var(--gray-200); border-radius:12px; padding:14px 20px; margin-bottom:16px; box-shadow:var(--shadow-sm); }
        .dc-filter-bar .form-label { font-size:0.7rem; font-weight:600; color:var(--gray-500); text-transform:uppercase; letter-spacing:0.3px; margin-bottom:4px; }
        .dc-filter-bar .form-control, .dc-filter-bar .form-select { border:1px solid var(--gray-200); border-radius:8px; font-size:0.8125rem; padding:6px 10px; }

        .dc-badge-match { background:rgba(16,185,129,0.12); color:#065f46; }
        .dc-badge-mismatch { background:rgba(239,68,68,0.12); color:#991b1b; }
        .dc-badge-xl { background:rgba(245,158,11,0.12); color:#92400e; }
        .dc-badge-db { background:rgba(59,130,246,0.12); color:#1e40af; }

        .dc-progress-section { display:none; }
        .dc-results-section { display:none; }

        .table-scroll-wrapper { max-height:600px; overflow:auto; }

        .dc-col-map { background:var(--white); border:1px solid var(--gray-200); border-radius:12px; padding:16px 20px; margin-bottom:16px; box-shadow:var(--shadow-sm); }
        .dc-col-map .form-label { font-size:0.7rem; font-weight:600; color:var(--gray-500); text-transform:uppercase; }

        @media (max-width:768px) {
            .dc-page-header { flex-direction:column; }
            .dc-stat { min-width:auto; }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">

                <div class="dc-page-header">
                    <div>
                        <h1><i class="fas fa-code-compare me-2" style="color:var(--primary);"></i>Data Comparison Tool</h1>
                        <p>Upload an Excel file and compare it against the live database. Identify mismatches, missing records, and discrepancies.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="financial-dashboard.php#tab-pledge"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
                    </div>
                </div>

                <!-- Step 1: Upload -->
                <div id="uploadSection">
                    <div class="dc-card">
                        <div class="dc-card-header">
                            <h6><i class="fas fa-file-excel me-2 text-success"></i>Step 1: Upload Excel File</h6>
                        </div>
                        <div class="p-4">
                            <div class="dc-upload-zone" id="dropZone">
                                <i class="fas fa-cloud-arrow-up"></i>
                                <p class="fw-semibold mb-1">Drop your Excel file here or click to browse</p>
                                <p class="small text-muted">Supports .xlsx and .xls files</p>
                                <input type="file" id="fileInput" accept=".xlsx,.xls,.csv" style="display:none">
                            </div>
                            <div class="mt-3 d-none" id="fileInfo">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="fas fa-file-excel text-success fs-4"></i>
                                    <div>
                                        <div class="fw-semibold" id="fileName">—</div>
                                        <div class="text-muted small" id="fileDetails">—</div>
                                    </div>
                                    <button class="btn btn-sm btn-outline-danger ms-auto" id="removeFileBtn"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Column Mapping -->
                <div id="mappingSection" style="display:none">
                    <div class="dc-card">
                        <div class="dc-card-header">
                            <h6><i class="fas fa-columns me-2 text-primary"></i>Step 2: Map Columns</h6>
                            <span class="badge bg-light text-dark border" id="xlRowCount">—</span>
                        </div>
                        <div class="p-4">
                            <p class="text-muted small mb-3">Map Excel columns to database fields. We auto-detect common headers.</p>
                            <div class="row g-3" id="columnMapRow"></div>
                            <div class="mt-3 text-end">
                                <button class="btn btn-primary" id="runComparisonBtn"><i class="fas fa-play me-1"></i>Run Comparison</button>
                            </div>
                        </div>
                    </div>

                    <!-- Preview -->
                    <div class="dc-card">
                        <div class="dc-card-header">
                            <h6><i class="fas fa-table me-2 text-secondary"></i>Excel Preview (first 10 rows)</h6>
                        </div>
                        <div class="table-scroll-wrapper">
                            <table class="table table-sm dc-table mb-0" id="previewTable"></table>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Results -->
                <div id="resultsSection" style="display:none">

                    <!-- Summary Stats -->
                    <div class="d-flex mb-3 flex-wrap" style="gap:12px;" id="summaryStats"></div>

                    <!-- Filters -->
                    <div class="dc-filter-bar" id="resultFilters">
                        <div class="row g-2 align-items-end">
                            <div class="col-12 col-md-2">
                                <label class="form-label">Status</label>
                                <select class="form-select form-select-sm" id="filterStatus">
                                    <option value="">All</option>
                                    <option value="match">Matched (exact)</option>
                                    <option value="mismatch">Mismatched (amount differs)</option>
                                    <option value="xl_only">In Excel Only</option>
                                    <option value="db_only">In Database Only</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-2">
                                <label class="form-label">Amount Diff</label>
                                <select class="form-select form-select-sm" id="filterAmountDiff">
                                    <option value="">Any</option>
                                    <option value="pledge_diff">Pledge differs</option>
                                    <option value="paid_diff">Paid differs</option>
                                    <option value="both_diff">Both differ</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-2">
                                <label class="form-label">Data Source</label>
                                <select class="form-select form-select-sm" id="filterDataSource">
                                    <option value="">All</option>
                                    <option value="old_system">Old System</option>
                                    <option value="new_system">New System</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" class="form-control form-control-sm" id="filterSearch" placeholder="Name or phone...">
                            </div>
                            <div class="col-12 col-md-3 d-flex gap-2">
                                <button class="btn btn-primary btn-sm flex-fill" id="applyResultFilters"><i class="fas fa-filter me-1"></i>Filter</button>
                                <button class="btn btn-outline-secondary btn-sm" id="clearResultFilters"><i class="fas fa-times me-1"></i>Clear</button>
                                <button class="btn btn-success btn-sm" id="exportResultsBtn"><i class="fas fa-file-csv me-1"></i>Export</button>
                            </div>
                        </div>
                    </div>

                    <!-- Comparison Table -->
                    <div class="dc-card">
                        <div class="dc-card-header">
                            <h6><i class="fas fa-code-compare me-2" style="color:var(--primary);"></i>Comparison Results</h6>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge dc-badge-match">Match</span>
                                <span class="badge dc-badge-mismatch">Mismatch</span>
                                <span class="badge dc-badge-xl">Excel Only</span>
                                <span class="badge dc-badge-db">DB Only</span>
                                <span class="text-muted small ms-2" id="resultCount">—</span>
                            </div>
                        </div>
                        <div class="table-scroll-wrapper">
                            <table class="table table-sm dc-table mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Status</th>
                                        <th>Name (Excel)</th>
                                        <th>Name (DB)</th>
                                        <th>Phone</th>
                                        <th class="text-end">Pledged (XL)</th>
                                        <th class="text-end">Pledged (DB)</th>
                                        <th class="text-end">Diff</th>
                                        <th class="text-end">Paid (XL)</th>
                                        <th class="text-end">Paid (DB)</th>
                                        <th class="text-end">Diff</th>
                                        <th>City (XL)</th>
                                        <th>Source</th>
                                        <th>DB Status</th>
                                    </tr>
                                </thead>
                                <tbody id="comparisonBody">
                                    <tr><td colspan="14" class="text-center text-muted py-4">Run comparison to see results</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Financial Summary -->
                    <div class="dc-card">
                        <div class="dc-card-header">
                            <h6><i class="fas fa-calculator me-2 text-warning"></i>Financial Summary</h6>
                        </div>
                        <div class="p-4" id="financialSummary">—</div>
                    </div>

                    <!-- Back / Re-upload -->
                    <div class="text-center mb-4">
                        <button class="btn btn-outline-secondary" id="reuploadBtn"><i class="fas fa-redo me-1"></i>Upload Different File</button>
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

  function fmtMoney(n) {
    n = Number(n || 0);
    try { return new Intl.NumberFormat(undefined, { style:'currency', currency:CURRENCY, maximumFractionDigits:2 }).format(n); }
    catch(_) { return CURRENCY + ' ' + n.toFixed(2); }
  }

  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function normalizePhone(raw) {
    let p = String(raw || '').replace(/[^0-9]/g, '');
    if (p.startsWith('44') && p.length >= 12) p = p.substring(2);
    if (p.length === 10 && p.startsWith('7')) p = '0' + p;
    return p;
  }

  function normalizeName(n) {
    return String(n || '').toLowerCase().replace(/[^a-z0-9]/g, ' ').replace(/\s+/g, ' ').trim();
  }

  function parseAmount(v) {
    if (v === null || v === undefined) return 0;
    const s = String(v).trim().toLowerCase();
    if (s === '' || s === 'nil' || s === 'cancelled' || s === '-' || s === 'n/a') return 0;
    const n = parseFloat(s.replace(/[^0-9.\-]/g, ''));
    return isNaN(n) ? 0 : n;
  }

  let xlData = [];
  let dbData = [];
  let comparisonResults = [];
  let filteredResults = [];
  let columnMap = { name: '', phone: '', amount: '', paid: '', city: '', method: '', sn: '' };

  const el = id => document.getElementById(id);

  // --- Upload ---
  const dropZone = el('dropZone');
  const fileInput = el('fileInput');

  dropZone.addEventListener('click', () => fileInput.click());
  dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
  dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
  dropZone.addEventListener('drop', e => { e.preventDefault(); dropZone.classList.remove('dragover'); handleFile(e.dataTransfer.files[0]); });
  fileInput.addEventListener('change', () => { if (fileInput.files[0]) handleFile(fileInput.files[0]); });
  el('removeFileBtn').addEventListener('click', resetUpload);

  function handleFile(file) {
    if (!file) return;
    el('fileName').textContent = file.name;
    el('fileDetails').textContent = (file.size / 1024).toFixed(1) + ' KB';
    el('fileInfo').classList.remove('d-none');

    const reader = new FileReader();
    reader.onload = function(e) {
      try {
        const wb = XLSX.read(e.target.result, { type: 'array' });
        const ws = wb.Sheets[wb.SheetNames[0]];
        const json = XLSX.utils.sheet_to_json(ws, { defval: '' });
        xlData = json;
        showMapping(json);
      } catch(err) {
        alert('Failed to parse Excel file: ' + err.message);
      }
    };
    reader.readAsArrayBuffer(file);
  }

  function resetUpload() {
    xlData = [];
    fileInput.value = '';
    el('fileInfo').classList.add('d-none');
    el('mappingSection').style.display = 'none';
    el('resultsSection').style.display = 'none';
  }

  // --- Column Mapping ---
  const DB_FIELDS = [
    { key: 'name', label: 'Donor Name', required: true },
    { key: 'phone', label: 'Phone Number', required: true },
    { key: 'amount', label: 'Pledged Amount', required: false },
    { key: 'paid', label: 'Amount Paid', required: false },
    { key: 'city', label: 'City', required: false },
    { key: 'method', label: 'Payment Method', required: false },
    { key: 'sn', label: 'Serial Number', required: false },
  ];

  const AUTO_MAP = {
    name: ['name', 'donor', 'full name', 'donor name', 'fullname'],
    phone: ['mobile', 'phone', 'telephone', 'tel', 'cell', 'contact', 'phone number', 'mobile number'],
    amount: ['amount', 'pledged', 'pledge', 'total', 'pledge amount', 'pledged amount'],
    paid: ['paid', 'amount paid', 'total paid', 'payment', 'received'],
    city: ['city', 'town', 'location', 'address'],
    method: ['method', 'payment method', 'pay method', 'type'],
    sn: ['s.n.', 'sn', 'serial', 'no.', 'no', '#', 'id', 'serial number'],
  };

  function showMapping(data) {
    if (!data || data.length === 0) { alert('No data found in file.'); return; }

    const headers = Object.keys(data[0]);
    el('xlRowCount').textContent = data.length + ' rows detected';

    // Auto-map
    for (const [field, keywords] of Object.entries(AUTO_MAP)) {
      columnMap[field] = '';
      for (const h of headers) {
        const hLow = h.toLowerCase().trim();
        if (keywords.includes(hLow)) { columnMap[field] = h; break; }
      }
    }

    let mapHtml = '';
    DB_FIELDS.forEach(f => {
      const req = f.required ? ' <span class="text-danger">*</span>' : '';
      mapHtml += `
        <div class="col-12 col-md-3 col-lg-2">
          <label class="form-label">${f.label}${req}</label>
          <select class="form-select form-select-sm dc-map-select" data-field="${f.key}">
            <option value="">— skip —</option>
            ${headers.map(h => `<option value="${esc(h)}" ${columnMap[f.key] === h ? 'selected' : ''}>${esc(h)}</option>`).join('')}
          </select>
        </div>
      `;
    });
    el('columnMapRow').innerHTML = mapHtml;

    document.querySelectorAll('.dc-map-select').forEach(sel => {
      sel.addEventListener('change', () => { columnMap[sel.dataset.field] = sel.value; });
    });

    // Preview
    const previewRows = data.slice(0, 10);
    let previewHtml = '<thead><tr>' + headers.map(h => `<th>${esc(h)}</th>`).join('') + '</tr></thead><tbody>';
    previewRows.forEach(row => {
      previewHtml += '<tr>' + headers.map(h => `<td>${esc(String(row[h] ?? ''))}</td>`).join('') + '</tr>';
    });
    previewHtml += '</tbody>';
    el('previewTable').innerHTML = previewHtml;

    el('mappingSection').style.display = '';
  }

  // --- Run Comparison ---
  el('runComparisonBtn').addEventListener('click', runComparison);

  async function runComparison() {
    if (!columnMap.name && !columnMap.phone) {
      alert('Please map at least Name or Phone to run comparison.');
      return;
    }

    el('runComparisonBtn').disabled = true;
    el('runComparisonBtn').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading DB data...';

    try {
      const res = await fetch('api/data-comparison.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
      const json = await res.json();
      if (!json.success) throw new Error(json.error || 'API error');
      dbData = json.donors;

      compare();
      el('resultsSection').style.display = '';
    } catch(err) {
      alert('Failed to load database data: ' + err.message);
    } finally {
      el('runComparisonBtn').disabled = false;
      el('runComparisonBtn').innerHTML = '<i class="fas fa-play me-1"></i>Run Comparison';
    }
  }

  function compare() {
    const results = [];
    const dbByPhone = {};
    const dbByName = {};
    const dbMatched = new Set();

    dbData.forEach((d, i) => {
      if (d.phone_normalized) {
        if (!dbByPhone[d.phone_normalized]) dbByPhone[d.phone_normalized] = [];
        dbByPhone[d.phone_normalized].push(i);
      }
      const nName = normalizeName(d.name);
      if (nName) {
        if (!dbByName[nName]) dbByName[nName] = [];
        dbByName[nName].push(i);
      }
    });

    xlData.forEach((xlRow, xlIdx) => {
      const xlName = columnMap.name ? String(xlRow[columnMap.name] || '') : '';
      const xlPhone = columnMap.phone ? normalizePhone(xlRow[columnMap.phone]) : '';
      const xlAmount = columnMap.amount ? parseAmount(xlRow[columnMap.amount]) : null;
      const xlPaid = columnMap.paid ? parseAmount(xlRow[columnMap.paid]) : null;
      const xlCity = columnMap.city ? String(xlRow[columnMap.city] || '') : '';
      const xlMethod = columnMap.method ? String(xlRow[columnMap.method] || '') : '';
      const xlSn = columnMap.sn ? String(xlRow[columnMap.sn] || '') : '';

      if (!xlName && !xlPhone) return;

      let dbIdx = -1;
      let matchType = '';

      // Match by phone first (most reliable)
      if (xlPhone && dbByPhone[xlPhone]) {
        dbIdx = dbByPhone[xlPhone][0];
        matchType = 'phone';
      }

      // Fallback: match by name
      if (dbIdx === -1 && xlName) {
        const nName = normalizeName(xlName);
        if (dbByName[nName]) {
          dbIdx = dbByName[nName][0];
          matchType = 'name';
        } else {
          // Fuzzy: check if XL name is contained in DB name or vice versa
          for (let i = 0; i < dbData.length; i++) {
            if (dbMatched.has(i)) continue;
            const dbNorm = normalizeName(dbData[i].name);
            if (dbNorm && nName && (dbNorm.includes(nName) || nName.includes(dbNorm))) {
              dbIdx = i;
              matchType = 'fuzzy_name';
              break;
            }
          }
        }
      }

      const dbRow = dbIdx >= 0 ? dbData[dbIdx] : null;
      if (dbIdx >= 0) dbMatched.add(dbIdx);

      let status = 'xl_only';
      let pledgeDiff = null;
      let paidDiff = null;

      if (dbRow) {
        const pledgeMatch = xlAmount === null || Math.abs(xlAmount - dbRow.total_pledged) < 0.01;
        const paidMatch = xlPaid === null || Math.abs(xlPaid - dbRow.total_paid) < 0.01;
        pledgeDiff = xlAmount !== null ? xlAmount - dbRow.total_pledged : null;
        paidDiff = xlPaid !== null ? xlPaid - dbRow.total_paid : null;

        status = (pledgeMatch && paidMatch) ? 'match' : 'mismatch';
      }

      results.push({
        status,
        matchType,
        xlIdx: xlIdx + 1,
        xlName, xlPhone, xlAmount, xlPaid, xlCity, xlMethod, xlSn,
        dbId: dbRow ? dbRow.id : null,
        dbName: dbRow ? dbRow.name : '',
        dbPhone: dbRow ? dbRow.phone : '',
        dbPledged: dbRow ? dbRow.total_pledged : null,
        dbPaid: dbRow ? dbRow.total_paid : null,
        dbBalance: dbRow ? dbRow.balance : null,
        dbStatus: dbRow ? dbRow.payment_status : '',
        dbSource: dbRow ? dbRow.data_source : '',
        dbCity: dbRow ? dbRow.city : '',
        pledgeDiff,
        paidDiff,
      });
    });

    // DB-only records
    dbData.forEach((d, i) => {
      if (!dbMatched.has(i)) {
        results.push({
          status: 'db_only',
          matchType: '',
          xlIdx: null,
          xlName: '', xlPhone: '', xlAmount: null, xlPaid: null, xlCity: '', xlMethod: '', xlSn: '',
          dbId: d.id,
          dbName: d.name,
          dbPhone: d.phone,
          dbPledged: d.total_pledged,
          dbPaid: d.total_paid,
          dbBalance: d.balance,
          dbStatus: d.payment_status,
          dbSource: d.data_source,
          dbCity: d.city,
          pledgeDiff: null,
          paidDiff: null,
        });
      }
    });

    comparisonResults = results;
    applyFilters();
    renderSummary();
    renderFinancialSummary();
  }

  function applyFilters() {
    const status = el('filterStatus').value;
    const amountDiff = el('filterAmountDiff').value;
    const dataSource = el('filterDataSource').value;
    const search = el('filterSearch').value.toLowerCase().trim();

    filteredResults = comparisonResults.filter(r => {
      if (status && r.status !== status) return false;
      if (dataSource && r.dbSource !== dataSource) return false;
      if (search) {
        const hay = [r.xlName, r.dbName, r.xlPhone, r.dbPhone].join(' ').toLowerCase();
        if (!hay.includes(search)) return false;
      }
      if (amountDiff === 'pledge_diff' && (r.pledgeDiff === null || Math.abs(r.pledgeDiff) < 0.01)) return false;
      if (amountDiff === 'paid_diff' && (r.paidDiff === null || Math.abs(r.paidDiff) < 0.01)) return false;
      if (amountDiff === 'both_diff') {
        if ((r.pledgeDiff === null || Math.abs(r.pledgeDiff) < 0.01) && (r.paidDiff === null || Math.abs(r.paidDiff) < 0.01)) return false;
      }
      return true;
    });

    renderTable();
  }

  el('applyResultFilters').addEventListener('click', applyFilters);
  el('clearResultFilters').addEventListener('click', () => {
    el('filterStatus').value = '';
    el('filterAmountDiff').value = '';
    el('filterDataSource').value = '';
    el('filterSearch').value = '';
    applyFilters();
  });
  el('filterSearch').addEventListener('keydown', e => { if (e.key === 'Enter') applyFilters(); });

  function renderSummary() {
    const counts = { match: 0, mismatch: 0, xl_only: 0, db_only: 0 };
    comparisonResults.forEach(r => counts[r.status]++);

    const stats = [
      { icon: 'fas fa-check-circle', bg: 'rgba(16,185,129,0.1)', color: '#065f46', value: counts.match, label: 'Matched' },
      { icon: 'fas fa-exclamation-triangle', bg: 'rgba(239,68,68,0.1)', color: '#991b1b', value: counts.mismatch, label: 'Mismatched' },
      { icon: 'fas fa-file-excel', bg: 'rgba(245,158,11,0.1)', color: '#92400e', value: counts.xl_only, label: 'Excel Only' },
      { icon: 'fas fa-database', bg: 'rgba(59,130,246,0.1)', color: '#1e40af', value: counts.db_only, label: 'DB Only' },
      { icon: 'fas fa-list', bg: 'rgba(107,114,128,0.1)', color: '#374151', value: comparisonResults.length, label: 'Total Records' },
    ];

    el('summaryStats').innerHTML = stats.map(s => `
      <div class="dc-stat">
        <div class="dc-stat-icon" style="background:${s.bg};color:${s.color};"><i class="${s.icon}"></i></div>
        <div>
          <div class="dc-stat-value">${s.value}</div>
          <div class="dc-stat-label">${s.label}</div>
        </div>
      </div>
    `).join('');
  }

  function renderTable() {
    const body = el('comparisonBody');
    el('resultCount').textContent = filteredResults.length + ' of ' + comparisonResults.length + ' records';

    if (filteredResults.length === 0) {
      body.innerHTML = '<tr><td colspan="14" class="text-center text-muted py-4">No records match your filters.</td></tr>';
      return;
    }

    const statusBadge = {
      match: '<span class="badge dc-badge-match"><i class="fas fa-check me-1"></i>Match</span>',
      mismatch: '<span class="badge dc-badge-mismatch"><i class="fas fa-times me-1"></i>Mismatch</span>',
      xl_only: '<span class="badge dc-badge-xl"><i class="fas fa-file-excel me-1"></i>XL Only</span>',
      db_only: '<span class="badge dc-badge-db"><i class="fas fa-database me-1"></i>DB Only</span>',
    };

    const rowClass = { match: 'dc-match', mismatch: 'dc-mismatch', xl_only: 'dc-xl-only', db_only: 'dc-db-only' };

    body.innerHTML = filteredResults.map((r, i) => {
      const pledgeDiffHtml = r.pledgeDiff !== null && Math.abs(r.pledgeDiff) >= 0.01
        ? `<span class="dc-diff">${r.pledgeDiff > 0 ? '+' : ''}${fmtMoney(r.pledgeDiff)}</span>`
        : (r.pledgeDiff !== null ? '<span class="dc-same">—</span>' : '');
      const paidDiffHtml = r.paidDiff !== null && Math.abs(r.paidDiff) >= 0.01
        ? `<span class="dc-diff">${r.paidDiff > 0 ? '+' : ''}${fmtMoney(r.paidDiff)}</span>`
        : (r.paidDiff !== null ? '<span class="dc-same">—</span>' : '');

      const srcBadge = r.dbSource === 'old_system' ? '<span class="badge" style="background:#fff3cd;color:#856404;font-size:0.65rem;">Old</span>'
        : r.dbSource === 'new_system' ? '<span class="badge" style="background:#d1e7dd;color:#0f5132;font-size:0.65rem;">New</span>'
        : '';

      const dbStatusBadge = r.dbStatus ? `<span class="badge bg-light text-dark border" style="font-size:0.65rem;">${esc(r.dbStatus)}</span>` : '';

      return `<tr class="${rowClass[r.status] || ''}">
        <td>${r.xlIdx || (r.dbId ? 'DB#' + r.dbId : '')}</td>
        <td>${statusBadge[r.status] || ''}</td>
        <td>${esc(r.xlName)}</td>
        <td>${esc(r.dbName)}${r.matchType === 'fuzzy_name' ? ' <i class="fas fa-question-circle text-warning" title="Fuzzy match"></i>' : ''}</td>
        <td class="text-nowrap">${esc(r.xlPhone || r.dbPhone)}</td>
        <td class="text-end">${r.xlAmount !== null ? fmtMoney(r.xlAmount) : ''}</td>
        <td class="text-end">${r.dbPledged !== null ? fmtMoney(r.dbPledged) : ''}</td>
        <td class="text-end">${pledgeDiffHtml}</td>
        <td class="text-end">${r.xlPaid !== null ? fmtMoney(r.xlPaid) : ''}</td>
        <td class="text-end">${r.dbPaid !== null ? fmtMoney(r.dbPaid) : ''}</td>
        <td class="text-end">${paidDiffHtml}</td>
        <td>${esc(r.xlCity)}</td>
        <td>${srcBadge}</td>
        <td>${dbStatusBadge}</td>
      </tr>`;
    }).join('');
  }

  function renderFinancialSummary() {
    const matched = comparisonResults.filter(r => r.status === 'match' || r.status === 'mismatch');
    const xlOnly = comparisonResults.filter(r => r.status === 'xl_only');
    const dbOnly = comparisonResults.filter(r => r.status === 'db_only');
    const mismatched = comparisonResults.filter(r => r.status === 'mismatch');

    const sumXlPledge = matched.reduce((s, r) => s + (r.xlAmount || 0), 0) + xlOnly.reduce((s, r) => s + (r.xlAmount || 0), 0);
    const sumDbPledge = matched.reduce((s, r) => s + (r.dbPledged || 0), 0) + dbOnly.reduce((s, r) => s + (r.dbPledged || 0), 0);
    const sumXlPaid = matched.reduce((s, r) => s + (r.xlPaid || 0), 0) + xlOnly.reduce((s, r) => s + (r.xlPaid || 0), 0);
    const sumDbPaid = matched.reduce((s, r) => s + (r.dbPaid || 0), 0) + dbOnly.reduce((s, r) => s + (r.dbPaid || 0), 0);

    const mismatchPledgeDiff = mismatched.reduce((s, r) => s + (r.pledgeDiff || 0), 0);
    const mismatchPaidDiff = mismatched.reduce((s, r) => s + (r.paidDiff || 0), 0);

    const xlOnlyPledge = xlOnly.reduce((s, r) => s + (r.xlAmount || 0), 0);
    const xlOnlyPaid = xlOnly.reduce((s, r) => s + (r.xlPaid || 0), 0);
    const dbOnlyPledge = dbOnly.reduce((s, r) => s + (r.dbPledged || 0), 0);
    const dbOnlyPaid = dbOnly.reduce((s, r) => s + (r.dbPaid || 0), 0);

    el('financialSummary').innerHTML = `
      <div class="row g-3">
        <div class="col-md-6">
          <h6 class="fw-bold mb-2"><i class="fas fa-chart-bar me-1 text-primary"></i>Totals Comparison</h6>
          <table class="table table-sm table-bordered mb-0">
            <thead class="table-light"><tr><th></th><th class="text-end">Excel</th><th class="text-end">Database</th><th class="text-end">Difference</th></tr></thead>
            <tbody>
              <tr><td class="fw-semibold">Total Pledged</td><td class="text-end">${fmtMoney(sumXlPledge)}</td><td class="text-end">${fmtMoney(sumDbPledge)}</td><td class="text-end ${Math.abs(sumXlPledge - sumDbPledge) > 0.01 ? 'dc-diff' : 'dc-same'}">${fmtMoney(sumXlPledge - sumDbPledge)}</td></tr>
              <tr><td class="fw-semibold">Total Paid</td><td class="text-end">${fmtMoney(sumXlPaid)}</td><td class="text-end">${fmtMoney(sumDbPaid)}</td><td class="text-end ${Math.abs(sumXlPaid - sumDbPaid) > 0.01 ? 'dc-diff' : 'dc-same'}">${fmtMoney(sumXlPaid - sumDbPaid)}</td></tr>
            </tbody>
          </table>
        </div>
        <div class="col-md-6">
          <h6 class="fw-bold mb-2"><i class="fas fa-exclamation-circle me-1 text-danger"></i>Discrepancy Breakdown</h6>
          <table class="table table-sm table-bordered mb-0">
            <tbody>
              <tr><td>Mismatched records (pledge diff total)</td><td class="text-end fw-semibold">${fmtMoney(mismatchPledgeDiff)}</td></tr>
              <tr><td>Mismatched records (paid diff total)</td><td class="text-end fw-semibold">${fmtMoney(mismatchPaidDiff)}</td></tr>
              <tr><td>Excel-only records (pledge total)</td><td class="text-end">${fmtMoney(xlOnlyPledge)}</td></tr>
              <tr><td>Excel-only records (paid total)</td><td class="text-end">${fmtMoney(xlOnlyPaid)}</td></tr>
              <tr><td>DB-only records (pledge total)</td><td class="text-end">${fmtMoney(dbOnlyPledge)}</td></tr>
              <tr><td>DB-only records (paid total)</td><td class="text-end">${fmtMoney(dbOnlyPaid)}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    `;
  }

  // --- Export ---
  el('exportResultsBtn').addEventListener('click', () => {
    if (filteredResults.length === 0) { alert('No data to export.'); return; }
    const headers = ['#','Status','Name (XL)','Name (DB)','Phone','Pledged (XL)','Pledged (DB)','Pledge Diff','Paid (XL)','Paid (DB)','Paid Diff','City (XL)','Data Source','DB Status'];
    const rows = filteredResults.map(r => [
      r.xlIdx || (r.dbId ? 'DB#' + r.dbId : ''),
      r.status,
      r.xlName, r.dbName,
      r.xlPhone || r.dbPhone,
      r.xlAmount !== null ? r.xlAmount : '',
      r.dbPledged !== null ? r.dbPledged : '',
      r.pledgeDiff !== null ? r.pledgeDiff : '',
      r.xlPaid !== null ? r.xlPaid : '',
      r.dbPaid !== null ? r.dbPaid : '',
      r.paidDiff !== null ? r.paidDiff : '',
      r.xlCity,
      r.dbSource,
      r.dbStatus,
    ]);
    const csv = [headers.join(','), ...rows.map(row => row.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(','))].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'data-comparison-' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  });

  // --- Re-upload ---
  el('reuploadBtn').addEventListener('click', () => {
    el('resultsSection').style.display = 'none';
    el('mappingSection').style.display = 'none';
    resetUpload();
  });

})();
</script>
</body>
</html>
