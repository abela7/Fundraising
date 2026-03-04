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
        /* §5 Page Header */
        .dc-header { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
        .dc-header h1 { font-size:1.5rem; font-weight:700; color:var(--gray-900); margin:0; }
        .dc-header p { color:var(--gray-500); font-size:0.875rem; margin:4px 0 0; }

        /* Upload Zone */
        .dc-upload { border:2px dashed var(--gray-300); border-radius:12px; padding:48px 24px; text-align:center; background:var(--gray-50); transition:all 0.2s ease; cursor:pointer; }
        .dc-upload:hover, .dc-upload.dc-dragover { border-color:var(--primary); background:rgba(10,98,134,0.04); transform:translateY(-1px); }
        .dc-upload-icon { width:56px; height:56px; border-radius:14px; background:rgba(10,98,134,0.1); color:var(--primary); display:inline-flex; align-items:center; justify-content:center; font-size:1.4rem; margin-bottom:12px; }
        .dc-upload-title { font-size:0.9375rem; font-weight:600; color:var(--gray-800); margin-bottom:4px; }
        .dc-upload-sub { font-size:0.8125rem; color:var(--gray-400); }

        /* File info bar */
        .dc-file-bar { display:flex; align-items:center; gap:12px; background:var(--white); border:1px solid var(--gray-200); border-radius:10px; padding:12px 16px; margin-top:16px; }
        .dc-file-icon { width:40px; height:40px; border-radius:10px; background:rgba(16,185,129,0.1); color:var(--success); display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
        .dc-file-name { font-size:0.875rem; font-weight:600; color:var(--gray-800); }
        .dc-file-meta { font-size:0.75rem; color:var(--gray-400); }
        .dc-file-badge { font-size:0.6875rem; font-weight:600; padding:4px 10px; border-radius:6px; background:rgba(16,185,129,0.1); color:var(--success); }

        /* Progress */
        .dc-progress { display:flex; align-items:center; gap:10px; margin-top:16px; }
        .dc-progress-text { font-size:0.8125rem; color:var(--gray-500); white-space:nowrap; }
        .dc-progress-bar { flex:1; height:4px; border-radius:4px; background:var(--gray-100); overflow:hidden; }
        .dc-progress-fill { height:100%; border-radius:4px; background:var(--primary); transition:width 0.3s ease; }

        /* §8 Data Card */
        .dc-card { background:var(--white); border:1px solid var(--gray-200); border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.05); overflow:hidden; margin-bottom:16px; transition:box-shadow 0.15s ease; }
        .dc-card:hover { box-shadow:0 4px 12px rgba(0,0,0,0.08); }
        .dc-card-head { padding:14px 20px; background:var(--gray-50); border-bottom:1px solid var(--gray-200); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.75rem; }
        .dc-card-head h6 { font-weight:600; color:var(--gray-800); margin:0; font-size:0.9375rem; }

        /* §6 Stat Chips */
        .dc-stats { display:flex; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
        .dc-chip { display:flex; align-items:center; gap:10px; background:var(--white); border:1px solid var(--gray-200); border-radius:10px; padding:12px 18px; box-shadow:0 1px 2px rgba(0,0,0,0.05); flex:1; min-width:140px; cursor:pointer; transition:all 0.15s ease; }
        .dc-chip:hover { box-shadow:0 4px 12px rgba(0,0,0,0.08); transform:translateY(-1px); }
        .dc-chip.dc-chip-active { border-color:var(--primary); box-shadow:0 0 0 2px rgba(10,98,134,0.15); }
        .dc-chip-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:0.9rem; flex-shrink:0; }
        .dc-chip-val { font-size:1.25rem; font-weight:700; color:var(--gray-900); line-height:1.2; }
        .dc-chip-lbl { font-size:0.6875rem; font-weight:600; color:var(--gray-500); text-transform:uppercase; letter-spacing:0.4px; }

        /* §7 Filter Bar */
        .dc-filters { background:var(--white); border:1px solid var(--gray-200); border-radius:12px; padding:16px 20px; margin-bottom:16px; box-shadow:0 1px 2px rgba(0,0,0,0.05); }
        .dc-filters .form-label { font-size:0.75rem; font-weight:600; color:var(--gray-500); text-transform:uppercase; letter-spacing:0.3px; margin-bottom:4px; }
        .dc-filters .form-control,
        .dc-filters .form-select { border:1px solid var(--gray-200); border-radius:8px; font-size:0.875rem; padding:8px 12px; }
        .dc-filters .form-control:focus,
        .dc-filters .form-select:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(10,98,134,0.1); }

        /* §8 Table */
        .dc-table thead th { background:var(--white); font-size:0.7rem; font-weight:600; color:var(--gray-500); text-transform:uppercase; letter-spacing:0.3px; border-bottom:1px solid var(--gray-200); padding:10px 14px; white-space:nowrap; position:sticky; top:0; z-index:2; }
        .dc-table tbody td { padding:10px 14px; vertical-align:middle; font-size:0.8125rem; color:var(--gray-600); border-bottom:1px solid var(--gray-50); }
        .dc-table tbody tr:hover { background:var(--gray-50); }
        .dc-table tbody tr:last-child td { border-bottom:none; }
        .dc-scroll { max-height:600px; overflow:auto; }

        /* Row status tints */
        .dc-row-match { background:rgba(16,185,129,0.04); }
        .dc-row-mismatch { background:rgba(239,68,68,0.04); }
        .dc-row-xl { background:rgba(245,158,11,0.04); }
        .dc-row-db { background:rgba(59,130,246,0.04); }

        /* Status badges */
        .dc-badge { font-size:0.6875rem; font-weight:600; padding:3px 8px; border-radius:6px; display:inline-flex; align-items:center; gap:4px; }
        .dc-badge-match { background:rgba(16,185,129,0.1); color:#065f46; }
        .dc-badge-mismatch { background:rgba(239,68,68,0.1); color:#991b1b; }
        .dc-badge-xl { background:rgba(245,158,11,0.1); color:#92400e; }
        .dc-badge-db { background:rgba(59,130,246,0.1); color:#1e40af; }
        .dc-badge-old { background:rgba(245,158,11,0.1); color:#92400e; }
        .dc-badge-new { background:rgba(16,185,129,0.1); color:#065f46; }
        .dc-badge-status { background:var(--gray-100); color:var(--gray-600); }

        /* Diff values */
        .dc-val-diff { font-weight:600; color:var(--danger); }
        .dc-val-ok { color:var(--success); }

        /* Financial summary */
        .dc-summary-table { font-size:0.8125rem; }
        .dc-summary-table th { font-size:0.7rem; font-weight:600; color:var(--gray-500); text-transform:uppercase; letter-spacing:0.3px; background:var(--gray-50); }
        .dc-summary-table td { color:var(--gray-600); }

        /* Tabs */
        .dc-tabs { border-bottom:1px solid var(--gray-200); margin-bottom:0; padding:0 20px; background:var(--gray-50); }
        .dc-tabs .nav-link { font-size:0.875rem; font-weight:600; color:var(--gray-500); border:none; border-bottom:3px solid transparent; border-radius:0; padding:12px 16px; margin-bottom:-1px; transition:all 0.15s ease; }
        .dc-tabs .nav-link:hover { color:var(--gray-700); }
        .dc-tabs .nav-link.active { color:var(--primary); border-bottom-color:var(--primary); background:transparent; }
        .dc-tabs .nav-link i { margin-right:6px; opacity:0.8; }

        /* Finance tab */
        .dc-finance-hero { background:linear-gradient(135deg, rgba(10,98,134,0.06) 0%, rgba(10,98,134,0.02) 100%); border:1px solid var(--gray-200); border-radius:12px; padding:24px; margin-bottom:20px; }
        .dc-finance-kpi { display:flex; align-items:center; gap:16px; padding:16px 20px; background:var(--white); border:1px solid var(--gray-200); border-radius:10px; margin-bottom:12px; }
        .dc-finance-kpi-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
        .dc-finance-kpi-val { font-size:1.35rem; font-weight:700; color:var(--gray-900); }
        .dc-finance-kpi-lbl { font-size:0.75rem; color:var(--gray-500); }
        .dc-finance-diff { font-size:0.875rem; font-weight:600; padding:4px 10px; border-radius:6px; }
        .dc-finance-diff-higher { background:rgba(245,158,11,0.1); color:#92400e; }
        .dc-finance-diff-lower { background:rgba(59,130,246,0.1); color:#1e40af; }
        .dc-finance-diff-same { background:rgba(16,185,129,0.1); color:#065f46; }
        .dc-finance-section { font-size:0.75rem; font-weight:600; color:var(--gray-500); text-transform:uppercase; letter-spacing:0.3px; margin-bottom:10px; }
        .dc-finance-bar { height:8px; border-radius:4px; background:var(--gray-100); overflow:hidden; margin-top:6px; }
        .dc-finance-bar-fill { height:100%; border-radius:4px; transition:width 0.3s ease; }

        /* §16 Responsive */
        @media (max-width:768px) {
            .dc-header { flex-direction:column; }
            .dc-chip { min-width:auto; }
            .dc-filters { padding:12px 16px; }
            .dc-card-head { padding:12px 16px; }
            .dc-table thead th, .dc-table tbody td { padding:8px 10px; }
        }
        @media (max-width:576px) {
            .dc-stats { gap:8px; }
            .dc-chip { padding:10px 14px; }
            .dc-chip-val { font-size:1.05rem; }
        }

        /* Fade-in */
        .dc-fade { animation:dcFadeIn 0.3s ease forwards; }
        @keyframes dcFadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">

                <!-- §5 Page Header -->
                <div class="dc-header">
                    <div>
                        <h1><i class="fas fa-code-compare me-2" style="color:var(--primary);"></i>Data Comparison</h1>
                        <p>Upload your Excel file to compare against the live database instantly.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="financial-dashboard.php#tab-pledge"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
                    </div>
                </div>

                <!-- Upload Card -->
                <div class="dc-card" id="uploadCard">
                    <div class="dc-card-head">
                        <h6><i class="fas fa-file-excel me-2" style="color:var(--success);"></i>Upload Excel File</h6>
                        <span style="font-size:0.75rem; color:var(--gray-400);">Comparison runs automatically</span>
                    </div>
                    <div style="padding:20px;">
                        <div class="dc-upload" id="dropZone">
                            <div class="dc-upload-icon"><i class="fas fa-cloud-arrow-up"></i></div>
                            <div class="dc-upload-title">Drop your Excel file here or click to browse</div>
                            <div class="dc-upload-sub">Supports .xlsx and .xls — columns are detected automatically</div>
                            <input type="file" id="fileInput" accept=".xlsx,.xls,.csv" style="display:none">
                        </div>

                        <div class="dc-file-bar d-none" id="fileBar">
                            <div class="dc-file-icon"><i class="fas fa-file-excel"></i></div>
                            <div style="flex:1; min-width:0;">
                                <div class="dc-file-name" id="fileName">—</div>
                                <div class="dc-file-meta" id="fileMeta">—</div>
                            </div>
                            <span class="dc-file-badge d-none" id="detectBadge"><i class="fas fa-wand-magic-sparkles me-1"></i>Auto-detected</span>
                            <button class="btn btn-sm btn-outline-secondary" id="removeBtn" title="Remove file" style="border-radius:8px;"><i class="fas fa-times"></i></button>
                        </div>

                        <div class="dc-progress d-none" id="progressBar">
                            <div class="spinner-border spinner-border-sm" style="color:var(--primary); width:16px; height:16px;"></div>
                            <span class="dc-progress-text" id="progressText">Reading file...</span>
                            <div class="dc-progress-bar"><div class="dc-progress-fill" id="progressFill" style="width:0%"></div></div>
                        </div>
                    </div>
                </div>

                <!-- Results (hidden until comparison runs) -->
                <div id="resultsSection" style="display:none">

                    <!-- Stat Chips (clickable to filter) -->
                    <div class="dc-stats dc-fade" id="statsRow"></div>

                    <!-- Tabs -->
                    <ul class="nav dc-tabs dc-fade" id="dcTabs" role="tablist" style="animation-delay:0.03s;">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-comparison" data-bs-toggle="tab" data-bs-target="#pane-comparison" type="button" role="tab"><i class="fas fa-table-columns"></i>Comparison</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-finance" data-bs-toggle="tab" data-bs-target="#pane-finance" type="button" role="tab"><i class="fas fa-coins"></i>Finance</button>
                        </li>
                    </ul>

                    <div class="tab-content" id="dcTabContent">
                        <div class="tab-pane fade show active" id="pane-comparison" role="tabpanel">
                    <!-- Filter Bar -->
                    <div class="dc-filters" style="margin-top:16px;">
                        <div class="row g-2 align-items-end">
                            <div class="col-6 col-md-2">
                                <label class="form-label">Status</label>
                                <select class="form-select form-select-sm" id="filterStatus">
                                    <option value="">All</option>
                                    <option value="match">Matched</option>
                                    <option value="mismatch">Mismatched</option>
                                    <option value="xl_only">Excel Only</option>
                                    <option value="db_only">DB Only</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label">Discrepancy</label>
                                <select class="form-select form-select-sm" id="filterAmountDiff">
                                    <option value="">Any</option>
                                    <option value="pledge_diff">Pledge differs</option>
                                    <option value="paid_diff">Paid differs</option>
                                    <option value="both_diff">Either differs</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
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
                                <button class="btn btn-sm flex-fill" id="applyBtn" style="background:var(--primary); color:var(--white); border-radius:8px;"><i class="fas fa-search me-1"></i>Apply</button>
                                <button class="btn btn-sm btn-outline-secondary" id="clearBtn" style="border-radius:8px;"><i class="fas fa-times me-1"></i>Clear</button>
                                <button class="btn btn-sm" id="exportBtn" style="background:rgba(16,185,129,0.1); color:var(--success); border-radius:8px;"><i class="fas fa-file-csv me-1"></i>CSV</button>
                            </div>
                        </div>
                    </div>

                    <!-- Comparison Table -->
                    <div class="dc-card dc-fade" style="animation-delay:0.1s;">
                        <div class="dc-card-head">
                            <h6><i class="fas fa-table-columns me-2" style="color:var(--primary);"></i>Comparison Results</h6>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="dc-badge dc-badge-match"><i class="fas fa-check"></i>Match</span>
                                <span class="dc-badge dc-badge-mismatch"><i class="fas fa-xmark"></i>Mismatch</span>
                                <span class="dc-badge dc-badge-xl"><i class="fas fa-file-excel"></i>Excel Only</span>
                                <span class="dc-badge dc-badge-db"><i class="fas fa-database"></i>DB Only</span>
                                <span style="font-size:0.75rem; color:var(--gray-400); margin-left:4px;" id="resultCount">—</span>
                            </div>
                        </div>
                        <div class="dc-scroll">
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
                                        <th>City</th>
                                        <th>Source</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="tableBody">
                                    <tr><td colspan="14" class="text-center py-4" style="color:var(--gray-400);">Upload a file to begin</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                        </div><!-- /pane-comparison -->

                        <div class="tab-pane fade" id="pane-finance" role="tabpanel">
                            <div style="padding:20px 0;" id="financeTabContent"></div>
                        </div><!-- /pane-finance -->
                    </div><!-- /tab-content -->

                    <div class="text-center mb-4 dc-fade" style="animation-delay:0.2s;">
                        <button class="btn btn-outline-secondary" id="reuploadBtn" style="border-radius:8px;"><i class="fas fa-redo me-1"></i>Upload Different File</button>
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
  const el = id => document.getElementById(id);

  function fmtMoney(n) {
    n = Number(n || 0);
    try { return new Intl.NumberFormat(undefined, { style:'currency', currency:CURRENCY, maximumFractionDigits:2 }).format(n); }
    catch(_) { return CURRENCY + ' ' + n.toFixed(2); }
  }
  function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function normalizePhone(raw) {
    let p = String(raw || '').replace(/[^0-9]/g, '');
    if (p.startsWith('44') && p.length >= 12) p = p.substring(2);
    if (p.length === 10 && p.startsWith('7')) p = '0' + p;
    return p;
  }
  function normalizeName(n) { return String(n || '').toLowerCase().replace(/[^a-z0-9]/g, ' ').replace(/\s+/g, ' ').trim(); }
  function parseAmount(v) {
    if (v === null || v === undefined) return 0;
    const s = String(v).trim().toLowerCase();
    if (s === '' || s === 'nil' || s === 'cancelled' || s === '-' || s === 'n/a') return 0;
    const n = parseFloat(s.replace(/[^0-9.\-]/g, ''));
    return isNaN(n) ? 0 : n;
  }

  let xlData = [], dbData = [], comparisonResults = [], filteredResults = [];
  let columnMap = { name:'', phone:'', amount:'', paid:'', city:'', method:'', sn:'' };

  // Auto-detect columns
  const COL_MAP = {
    name:   ['Name','name','Donor','donor','Full Name','Donor Name'],
    phone:  ['Mobile','mobile','Phone','phone','Telephone','Tel','Contact','Phone Number'],
    amount: ['Amount','amount','Pledged','pledged','Pledge','Pledge Amount','Total'],
    paid:   ['Amount Paid','amount paid','Paid','paid','Total Paid','Payment','Received'],
    city:   ['City','city','Town','Location'],
    method: ['Payment Method','payment method','Method','method'],
    sn:     ['S.N.','s.n.','SN','No.','no.','No','#','Serial Number'],
  };

  function autoDetect(headers) {
    const m = {};
    for (const [field, cands] of Object.entries(COL_MAP)) {
      m[field] = '';
      for (const c of cands) {
        const found = headers.find(h => h === c || h.trim().toLowerCase() === c.toLowerCase());
        if (found) { m[field] = found; break; }
      }
    }
    return m;
  }

  // Smart Excel parser — finds header row automatically
  function findHeaderRow(ws) {
    const range = XLSX.utils.decode_range(ws['!ref'] || 'A1');
    for (let r = range.s.r; r <= Math.min(range.s.r + 15, range.e.r); r++) {
      let count = 0, hasKey = false;
      for (let c = range.s.c; c <= range.e.c; c++) {
        const cell = ws[XLSX.utils.encode_cell({r,c})];
        if (cell && cell.v != null && String(cell.v).trim()) {
          count++;
          const v = String(cell.v).trim().toLowerCase();
          if (['name','donor','mobile','phone','amount','paid','amount paid'].includes(v)) hasKey = true;
        }
      }
      if (count >= 3 && hasKey) return r;
    }
    return 0;
  }

  function parseSheet(ws) {
    const hIdx = findHeaderRow(ws);
    const range = XLSX.utils.decode_range(ws['!ref'] || 'A1');
    const headers = [];
    for (let c = range.s.c; c <= range.e.c; c++) {
      const cell = ws[XLSX.utils.encode_cell({r:hIdx, c})];
      headers.push((cell && cell.v != null) ? String(cell.v).trim() : ('Col_' + c));
    }
    const nameCol = headers.find(h => h.toLowerCase() === 'name' || h.toLowerCase() === 'donor');
    const phoneCol = headers.find(h => h.toLowerCase() === 'mobile' || h.toLowerCase() === 'phone');
    const rows = [];
    for (let r = hIdx + 1; r <= range.e.r; r++) {
      const row = {};
      let hasData = false;
      for (let c = range.s.c; c <= range.e.c; c++) {
        const cell = ws[XLSX.utils.encode_cell({r,c})];
        const val = cell ? (cell.v != null ? cell.v : '') : '';
        row[headers[c - range.s.c]] = val;
        if (val !== '' && val != null) hasData = true;
      }
      const hasName = nameCol && row[nameCol] && String(row[nameCol]).trim();
      const hasPhone = phoneCol && row[phoneCol] && String(row[phoneCol]).trim();
      if (hasData && (hasName || hasPhone)) rows.push(row);
    }
    return { headers, rows, headerRow: hIdx + 1 };
  }

  // --- Upload ---
  const dropZone = el('dropZone'), fileInput = el('fileInput');
  dropZone.addEventListener('click', () => fileInput.click());
  dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dc-dragover'); });
  dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dc-dragover'));
  dropZone.addEventListener('drop', e => { e.preventDefault(); dropZone.classList.remove('dc-dragover'); handleFile(e.dataTransfer.files[0]); });
  fileInput.addEventListener('change', () => { if (fileInput.files[0]) handleFile(fileInput.files[0]); });
  el('removeBtn').addEventListener('click', resetAll);

  function setProgress(show, text, pct) {
    const bar = el('progressBar');
    bar.classList.toggle('d-none', !show);
    if (show) { el('progressText').textContent = text || ''; el('progressFill').style.width = (pct||0)+'%'; }
  }

  function handleFile(file) {
    if (!file) return;
    el('fileName').textContent = file.name;
    el('fileMeta').textContent = (file.size / 1024).toFixed(1) + ' KB';
    el('fileBar').classList.remove('d-none');
    el('detectBadge').classList.add('d-none');
    dropZone.style.display = 'none';
    setProgress(true, 'Reading Excel file...', 10);

    const reader = new FileReader();
    reader.onload = async function(e) {
      try {
        setProgress(true, 'Parsing spreadsheet...', 30);
        const wb = XLSX.read(e.target.result, { type:'array' });
        const parsed = parseSheet(wb.Sheets[wb.SheetNames[0]]);
        if (!parsed.rows.length) { alert('No data rows found.'); setProgress(false); return; }

        xlData = parsed.rows;
        columnMap = autoDetect(parsed.headers);
        const detected = Object.entries(columnMap).filter(([,v]) => v).map(([k]) => k);
        if (!columnMap.name && !columnMap.phone) { alert('Could not find Name or Phone columns.\nHeaders found: ' + parsed.headers.join(', ')); setProgress(false); return; }

        el('detectBadge').classList.remove('d-none');
        el('fileMeta').textContent = (file.size/1024).toFixed(1) + ' KB · ' + parsed.rows.length + ' rows · Header row ' + parsed.headerRow;

        setProgress(true, 'Loading database...', 50);
        const res = await fetch('api/data-comparison.php', { credentials:'same-origin', headers:{'Accept':'application/json'} });
        const json = await res.json();
        if (!json.success) throw new Error(json.error || 'API error');
        dbData = json.donors;

        setProgress(true, 'Comparing ' + parsed.rows.length + ' rows vs ' + dbData.length + ' donors...', 80);
        await new Promise(r => setTimeout(r, 80));
        compare();

        setProgress(true, 'Done!', 100);
        await new Promise(r => setTimeout(r, 250));
        setProgress(false);

        el('resultsSection').style.display = '';
        el('resultsSection').scrollIntoView({ behavior:'smooth', block:'start' });
      } catch(err) {
        setProgress(false);
        alert('Error: ' + err.message);
      }
    };
    reader.readAsArrayBuffer(file);
  }

  function resetAll() {
    xlData = []; dbData = []; comparisonResults = []; filteredResults = [];
    fileInput.value = '';
    el('fileBar').classList.add('d-none');
    el('detectBadge').classList.add('d-none');
    el('resultsSection').style.display = 'none';
    dropZone.style.display = '';
    setProgress(false);
  }

  // --- Compare ---
  function compare() {
    const results = [];
    const dbByPhone = {}, dbByName = {}, dbMatched = new Set();

    dbData.forEach((d, i) => {
      if (d.phone_normalized) { (dbByPhone[d.phone_normalized] = dbByPhone[d.phone_normalized] || []).push(i); }
      const n = normalizeName(d.name);
      if (n) { (dbByName[n] = dbByName[n] || []).push(i); }
    });

    xlData.forEach((xlRow, xlIdx) => {
      const xlName = columnMap.name ? String(xlRow[columnMap.name] || '') : '';
      const xlPhone = columnMap.phone ? normalizePhone(xlRow[columnMap.phone]) : '';
      const xlAmount = columnMap.amount ? parseAmount(xlRow[columnMap.amount]) : null;
      const xlPaid = columnMap.paid ? parseAmount(xlRow[columnMap.paid]) : null;
      const xlCity = columnMap.city ? String(xlRow[columnMap.city] || '') : '';
      if (!xlName && !xlPhone) return;

      let dbIdx = -1, matchType = '';
      if (xlPhone && dbByPhone[xlPhone]) { dbIdx = dbByPhone[xlPhone][0]; matchType = 'phone'; }
      if (dbIdx === -1 && xlName) {
        const nn = normalizeName(xlName);
        if (dbByName[nn]) { dbIdx = dbByName[nn][0]; matchType = 'name'; }
        else {
          for (let i = 0; i < dbData.length; i++) {
            if (dbMatched.has(i)) continue;
            const dn = normalizeName(dbData[i].name);
            if (dn && nn && (dn.includes(nn) || nn.includes(dn))) { dbIdx = i; matchType = 'fuzzy'; break; }
          }
        }
      }

      const db = dbIdx >= 0 ? dbData[dbIdx] : null;
      if (dbIdx >= 0) dbMatched.add(dbIdx);

      let status = 'xl_only', pledgeDiff = null, paidDiff = null;
      if (db) {
        const pOk = xlAmount === null || Math.abs(xlAmount - db.total_pledged) < 0.01;
        const dOk = xlPaid === null || Math.abs(xlPaid - db.total_paid) < 0.01;
        pledgeDiff = xlAmount !== null ? xlAmount - db.total_pledged : null;
        paidDiff = xlPaid !== null ? xlPaid - db.total_paid : null;
        status = (pOk && dOk) ? 'match' : 'mismatch';
      }

      results.push({ status, matchType, xlIdx:xlIdx+1, xlName, xlPhone, xlAmount, xlPaid, xlCity,
        dbId:db?.id||null, dbName:db?.name||'', dbPhone:db?.phone||'',
        dbPledged:db?.total_pledged??null, dbPaid:db?.total_paid??null, dbBalance:db?.balance??null,
        dbStatus:db?.payment_status||'', dbSource:db?.data_source||'', dbCity:db?.city||'',
        pledgeDiff, paidDiff });
    });

    dbData.forEach((d, i) => {
      if (!dbMatched.has(i)) {
        results.push({ status:'db_only', matchType:'', xlIdx:null, xlName:'', xlPhone:'', xlAmount:null, xlPaid:null, xlCity:'',
          dbId:d.id, dbName:d.name, dbPhone:d.phone, dbPledged:d.total_pledged, dbPaid:d.total_paid, dbBalance:d.balance,
          dbStatus:d.payment_status, dbSource:d.data_source, dbCity:d.city, pledgeDiff:null, paidDiff:null });
      }
    });

    comparisonResults = results;
    applyFilters();
    renderStats();
    renderFinancials();
  }

  // --- Filters ---
  function applyFilters() {
    const st = el('filterStatus').value, ad = el('filterAmountDiff').value;
    const ds = el('filterDataSource').value, q = el('filterSearch').value.toLowerCase().trim();
    filteredResults = comparisonResults.filter(r => {
      if (st && r.status !== st) return false;
      if (ds && r.dbSource !== ds) return false;
      if (q && ![r.xlName, r.dbName, r.xlPhone, r.dbPhone].join(' ').toLowerCase().includes(q)) return false;
      if (ad === 'pledge_diff' && (r.pledgeDiff === null || Math.abs(r.pledgeDiff) < 0.01)) return false;
      if (ad === 'paid_diff' && (r.paidDiff === null || Math.abs(r.paidDiff) < 0.01)) return false;
      if (ad === 'both_diff' && (r.pledgeDiff === null || Math.abs(r.pledgeDiff) < 0.01) && (r.paidDiff === null || Math.abs(r.paidDiff) < 0.01)) return false;
      return true;
    });
    renderTable();
    updateChipStates();
  }

  el('applyBtn').addEventListener('click', applyFilters);
  el('clearBtn').addEventListener('click', () => {
    el('filterStatus').value = ''; el('filterAmountDiff').value = ''; el('filterDataSource').value = ''; el('filterSearch').value = '';
    applyFilters();
  });
  el('filterSearch').addEventListener('keydown', e => { if (e.key === 'Enter') applyFilters(); });

  // --- Stat Chips (clickable) ---
  function renderStats() {
    const c = { match:0, mismatch:0, xl_only:0, db_only:0 };
    comparisonResults.forEach(r => c[r.status]++);
    const chips = [
      { key:'match', icon:'fas fa-check-circle', bg:'rgba(16,185,129,0.1)', color:'#065f46', val:c.match, lbl:'Matched' },
      { key:'mismatch', icon:'fas fa-triangle-exclamation', bg:'rgba(239,68,68,0.1)', color:'#991b1b', val:c.mismatch, lbl:'Mismatched' },
      { key:'xl_only', icon:'fas fa-file-excel', bg:'rgba(245,158,11,0.1)', color:'#92400e', val:c.xl_only, lbl:'Excel Only' },
      { key:'db_only', icon:'fas fa-database', bg:'rgba(59,130,246,0.1)', color:'#1e40af', val:c.db_only, lbl:'DB Only' },
      { key:'', icon:'fas fa-layer-group', bg:'rgba(107,114,128,0.1)', color:'#374151', val:comparisonResults.length, lbl:'Total' },
    ];
    el('statsRow').innerHTML = chips.map(s => `
      <div class="dc-chip" data-filter="${s.key}" title="${s.key ? 'Click to filter by ' + s.lbl : 'Click to show all'}">
        <div class="dc-chip-icon" style="background:${s.bg};color:${s.color};"><i class="${s.icon}"></i></div>
        <div><div class="dc-chip-val">${s.val}</div><div class="dc-chip-lbl">${s.lbl}</div></div>
      </div>
    `).join('');

    el('statsRow').querySelectorAll('.dc-chip').forEach(chip => {
      chip.addEventListener('click', () => {
        const f = chip.dataset.filter;
        el('filterStatus').value = f;
        applyFilters();
      });
    });
  }

  function updateChipStates() {
    const active = el('filterStatus').value;
    el('statsRow').querySelectorAll('.dc-chip').forEach(chip => {
      chip.classList.toggle('dc-chip-active', chip.dataset.filter === active);
    });
  }

  // --- Table ---
  function renderTable() {
    const body = el('tableBody');
    el('resultCount').textContent = filteredResults.length + ' of ' + comparisonResults.length;
    if (!filteredResults.length) {
      body.innerHTML = '<tr><td colspan="14" class="text-center py-4" style="color:var(--gray-400);"><i class="fas fa-inbox me-2"></i>No records match your filters.</td></tr>';
      return;
    }
    const badges = {
      match:'<span class="dc-badge dc-badge-match"><i class="fas fa-check"></i> Match</span>',
      mismatch:'<span class="dc-badge dc-badge-mismatch"><i class="fas fa-xmark"></i> Mismatch</span>',
      xl_only:'<span class="dc-badge dc-badge-xl"><i class="fas fa-file-excel"></i> XL Only</span>',
      db_only:'<span class="dc-badge dc-badge-db"><i class="fas fa-database"></i> DB Only</span>',
    };
    const rowCls = { match:'dc-row-match', mismatch:'dc-row-mismatch', xl_only:'dc-row-xl', db_only:'dc-row-db' };

    body.innerHTML = filteredResults.map(r => {
      const pd = r.pledgeDiff !== null && Math.abs(r.pledgeDiff) >= 0.01
        ? `<span class="dc-val-diff">${r.pledgeDiff > 0 ? '+' : ''}${fmtMoney(r.pledgeDiff)}</span>`
        : (r.pledgeDiff !== null ? '<span class="dc-val-ok">—</span>' : '');
      const dd = r.paidDiff !== null && Math.abs(r.paidDiff) >= 0.01
        ? `<span class="dc-val-diff">${r.paidDiff > 0 ? '+' : ''}${fmtMoney(r.paidDiff)}</span>`
        : (r.paidDiff !== null ? '<span class="dc-val-ok">—</span>' : '');
      const src = r.dbSource === 'old_system' ? '<span class="dc-badge dc-badge-old">Old</span>'
        : r.dbSource === 'new_system' ? '<span class="dc-badge dc-badge-new">New</span>' : '';
      const st = r.dbStatus ? `<span class="dc-badge dc-badge-status">${esc(r.dbStatus)}</span>` : '';
      const fuzzy = r.matchType === 'fuzzy' ? ' <i class="fas fa-question-circle" style="color:var(--warning);font-size:0.7rem;" title="Fuzzy name match"></i>' : '';

      return `<tr class="${rowCls[r.status]||''}">
        <td style="color:var(--gray-400);font-size:0.75rem;">${r.xlIdx || (r.dbId ? '#'+r.dbId : '')}</td>
        <td>${badges[r.status]||''}</td>
        <td style="font-weight:500;color:var(--gray-800);">${esc(r.xlName)}</td>
        <td>${esc(r.dbName)}${fuzzy}</td>
        <td class="text-nowrap" style="font-family:monospace;font-size:0.75rem;color:var(--gray-500);">${esc(r.xlPhone || r.dbPhone)}</td>
        <td class="text-end">${r.xlAmount !== null ? fmtMoney(r.xlAmount) : ''}</td>
        <td class="text-end">${r.dbPledged !== null ? fmtMoney(r.dbPledged) : ''}</td>
        <td class="text-end">${pd}</td>
        <td class="text-end">${r.xlPaid !== null ? fmtMoney(r.xlPaid) : ''}</td>
        <td class="text-end">${r.dbPaid !== null ? fmtMoney(r.dbPaid) : ''}</td>
        <td class="text-end">${dd}</td>
        <td style="color:var(--gray-500);">${esc(r.xlCity || r.dbCity)}</td>
        <td>${src}</td>
        <td>${st}</td>
      </tr>`;
    }).join('');
  }

  // --- Finance Tab ---
  function renderFinancials() {
    const matched = comparisonResults.filter(r => r.status === 'match' || r.status === 'mismatch');
    const xlOnly = comparisonResults.filter(r => r.status === 'xl_only');
    const dbOnly = comparisonResults.filter(r => r.status === 'db_only');
    const mm = comparisonResults.filter(r => r.status === 'mismatch');
    const m = comparisonResults.filter(r => r.status === 'match');

    const xlP = matched.reduce((s,r) => s+(r.xlAmount||0), 0) + xlOnly.reduce((s,r) => s+(r.xlAmount||0), 0);
    const dbP = matched.reduce((s,r) => s+(r.dbPledged||0), 0) + dbOnly.reduce((s,r) => s+(r.dbPledged||0), 0);
    const xlD = matched.reduce((s,r) => s+(r.xlPaid||0), 0) + xlOnly.reduce((s,r) => s+(r.xlPaid||0), 0);
    const dbD = matched.reduce((s,r) => s+(r.dbPaid||0), 0) + dbOnly.reduce((s,r) => s+(r.dbPaid||0), 0);
    const xlB = xlP - xlD;
    const dbB = dbP - dbD;

    const diffP = xlP - dbP;
    const diffD = xlD - dbD;
    const diffB = xlB - dbB;

    const mmPD = mm.reduce((s,r) => s+(r.pledgeDiff||0), 0);
    const mmDD = mm.reduce((s,r) => s+(r.paidDiff||0), 0);
    const xlOnlyP = xlOnly.reduce((s,r) => s+(r.xlAmount||0), 0);
    const xlOnlyD = xlOnly.reduce((s,r) => s+(r.xlPaid||0), 0);
    const dbOnlyP = dbOnly.reduce((s,r) => s+(r.dbPledged||0), 0);
    const dbOnlyD = dbOnly.reduce((s,r) => s+(r.dbPaid||0), 0);

    const xlRate = xlP > 0 ? ((xlD / xlP) * 100).toFixed(1) : '0';
    const dbRate = dbP > 0 ? ((dbD / dbP) * 100).toFixed(1) : '0';

    function diffBadge(a, b, label) {
      const d = a - b;
      if (Math.abs(d) < 0.01) return `<span class="dc-finance-diff dc-finance-diff-same">Same</span>`;
      if (d > 0) return `<span class="dc-finance-diff dc-finance-diff-higher">Excel +${fmtMoney(d)}</span>`;
      return `<span class="dc-finance-diff dc-finance-diff-lower">DB +${fmtMoney(-d)}</span>`;
    }

    function diffRow(label, xlVal, dbVal) {
      const d = xlVal - dbVal;
      const cls = Math.abs(d) > 0.01 ? 'dc-val-diff' : 'dc-val-ok';
      return `<tr><td class="fw-semibold" style="color:var(--gray-800);">${label}</td><td class="text-end">${fmtMoney(xlVal)}</td><td class="text-end">${fmtMoney(dbVal)}</td><td class="text-end fw-semibold ${cls}">${fmtMoney(d)}</td></tr>`;
    }

    const maxP = Math.max(xlP, dbP, 1);
    const maxD = Math.max(xlD, dbD, 1);

    const bySource = {};
    comparisonResults.forEach(r => {
      const src = r.dbSource || 'unknown';
      if (!bySource[src]) bySource[src] = { count:0, xlP:0, xlD:0, dbP:0, dbD:0 };
      bySource[src].count++;
      bySource[src].xlP += r.xlAmount || 0;
      bySource[src].xlD += r.xlPaid || 0;
      bySource[src].dbP += r.dbPledged || 0;
      bySource[src].dbD += r.dbPaid || 0;
    });

    const sourceRows = Object.entries(bySource).filter(([k]) => k !== 'unknown').map(([src, v]) => {
      const lbl = src === 'old_system' ? 'Old System' : src === 'new_system' ? 'New System' : src;
      return `<tr><td><span class="dc-badge ${src === 'old_system' ? 'dc-badge-old' : 'dc-badge-new'}">${lbl}</span></td><td class="text-end">${v.count}</td><td class="text-end">${fmtMoney(v.xlP)}</td><td class="text-end">${fmtMoney(v.dbP)}</td><td class="text-end">${fmtMoney(v.xlD)}</td><td class="text-end">${fmtMoney(v.dbD)}</td></tr>`;
    }).join('');
    const excelOnlyRow = xlOnly.length ? `<tr><td><span class="dc-badge dc-badge-xl">Excel Only (no DB match)</span></td><td class="text-end">${xlOnly.length}</td><td class="text-end">${fmtMoney(xlOnlyP)}</td><td class="text-end">—</td><td class="text-end">${fmtMoney(xlOnlyD)}</td><td class="text-end">—</td></tr>` : '';

    el('financeTabContent').innerHTML = `
      <div class="dc-finance-hero">
        <div class="dc-finance-section"><i class="fas fa-scale-balanced me-1" style="color:var(--primary);"></i>Overall Difference</div>
        <div class="row g-3 mb-0">
          <div class="col-md-4">
            <div class="dc-finance-kpi">
              <div class="dc-finance-kpi-icon" style="background:rgba(10,98,134,0.1);color:var(--primary);"><i class="fas fa-hand-holding-heart"></i></div>
              <div style="flex:1;">
                <div class="dc-finance-kpi-val">${fmtMoney(Math.abs(diffP))}</div>
                <div class="dc-finance-kpi-lbl">Pledged difference (Excel − DB)</div>
                <div class="mt-1">${diffBadge(xlP, dbP, 'Pledged')}</div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="dc-finance-kpi">
              <div class="dc-finance-kpi-icon" style="background:rgba(16,185,129,0.1);color:var(--success);"><i class="fas fa-money-bill-transfer"></i></div>
              <div style="flex:1;">
                <div class="dc-finance-kpi-val">${fmtMoney(Math.abs(diffD))}</div>
                <div class="dc-finance-kpi-lbl">Paid difference (Excel − DB)</div>
                <div class="mt-1">${diffBadge(xlD, dbD, 'Paid')}</div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="dc-finance-kpi">
              <div class="dc-finance-kpi-icon" style="background:rgba(245,158,11,0.1);color:var(--warning);"><i class="fas fa-percentage"></i></div>
              <div style="flex:1;">
                <div class="dc-finance-kpi-val">${xlRate}% vs ${dbRate}%</div>
                <div class="dc-finance-kpi-lbl">Collection rate (Excel vs DB)</div>
                <div class="mt-1">${Number(xlRate) > Number(dbRate) ? '<span class="dc-finance-diff dc-finance-diff-higher">Excel higher</span>' : Number(xlRate) < Number(dbRate) ? '<span class="dc-finance-diff dc-finance-diff-lower">DB higher</span>' : '<span class="dc-finance-diff dc-finance-diff-same">Same</span>'}</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-lg-6">
          <div class="dc-card">
            <div class="dc-card-head"><h6><i class="fas fa-table me-2" style="color:var(--primary);"></i>Totals Comparison</h6></div>
            <div style="padding:20px;">
              <table class="table table-sm dc-summary-table mb-0">
                <thead><tr><th></th><th class="text-end">Excel</th><th class="text-end">Database</th><th class="text-end">Difference</th></tr></thead>
                <tbody>
                  ${diffRow('Total Pledged', xlP, dbP)}
                  ${diffRow('Total Paid', xlD, dbD)}
                  ${diffRow('Outstanding (Pledged − Paid)', xlB, dbB)}
                </tbody>
              </table>
              <div class="mt-3" style="font-size:0.8125rem;color:var(--gray-500);">
                ${Math.abs(diffP) > 0.01 ? (diffP > 0 ? `Excel shows ${fmtMoney(diffP)} more pledged than database.` : `Database shows ${fmtMoney(-diffP)} more pledged than Excel.`) : 'Pledged totals match.'}
                ${Math.abs(diffD) > 0.01 ? (diffD > 0 ? ` Excel shows ${fmtMoney(diffD)} more paid.` : ` Database shows ${fmtMoney(-diffD)} more paid.`) : ' Paid totals match.'}
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="dc-card">
            <div class="dc-card-head"><h6><i class="fas fa-chart-pie me-2" style="color:var(--warning);"></i>Visual Comparison</h6></div>
            <div style="padding:20px;">
              <div class="mb-3">
                <div class="d-flex justify-content-between mb-1" style="font-size:0.75rem;color:var(--gray-500);"><span>Pledged</span><span>Excel ${fmtMoney(xlP)} · DB ${fmtMoney(dbP)}</span></div>
                <div class="dc-finance-bar"><div class="dc-finance-bar-fill" style="width:${(xlP/maxP)*50}%;background:rgba(245,158,11,0.6);"></div></div>
                <div class="dc-finance-bar mt-1"><div class="dc-finance-bar-fill" style="width:${(dbP/maxP)*50}%;background:rgba(59,130,246,0.6);"></div></div>
                <div class="d-flex gap-2 mt-1" style="font-size:0.65rem;"><span style="color:#92400e;"><span style="display:inline-block;width:8px;height:8px;background:rgba(245,158,11,0.6);border-radius:2px;"></span> Excel</span><span style="color:#1e40af;"><span style="display:inline-block;width:8px;height:8px;background:rgba(59,130,246,0.6);border-radius:2px;"></span> Database</span></div>
              </div>
              <div>
                <div class="d-flex justify-content-between mb-1" style="font-size:0.75rem;color:var(--gray-500);"><span>Paid</span><span>Excel ${fmtMoney(xlD)} · DB ${fmtMoney(dbD)}</span></div>
                <div class="dc-finance-bar"><div class="dc-finance-bar-fill" style="width:${(xlD/maxD)*50}%;background:rgba(245,158,11,0.6);"></div></div>
                <div class="dc-finance-bar mt-1"><div class="dc-finance-bar-fill" style="width:${(dbD/maxD)*50}%;background:rgba(59,130,246,0.6);"></div></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4 mt-2">
        <div class="col-lg-6">
          <div class="dc-card">
            <div class="dc-card-head"><h6><i class="fas fa-magnifying-glass-chart me-2" style="color:var(--danger);"></i>Breakdown by Status</h6></div>
            <div style="padding:20px;">
              <table class="table table-sm dc-summary-table mb-0">
                <thead><tr><th>Status</th><th class="text-end">Count</th><th class="text-end">Pledged (XL)</th><th class="text-end">Pledged (DB)</th><th class="text-end">Paid (XL)</th><th class="text-end">Paid (DB)</th></tr></thead>
                <tbody>
                  <tr><td><span class="dc-badge dc-badge-match">Match</span></td><td class="text-end">${m.length}</td><td class="text-end">${fmtMoney(m.reduce((s,r)=>s+(r.xlAmount||0),0))}</td><td class="text-end">${fmtMoney(m.reduce((s,r)=>s+(r.dbPledged||0),0))}</td><td class="text-end">${fmtMoney(m.reduce((s,r)=>s+(r.xlPaid||0),0))}</td><td class="text-end">${fmtMoney(m.reduce((s,r)=>s+(r.dbPaid||0),0))}</td></tr>
                  <tr><td><span class="dc-badge dc-badge-mismatch">Mismatch</span></td><td class="text-end">${mm.length}</td><td class="text-end">${fmtMoney(mm.reduce((s,r)=>s+(r.xlAmount||0),0))}</td><td class="text-end">${fmtMoney(mm.reduce((s,r)=>s+(r.dbPledged||0),0))}</td><td class="text-end">${fmtMoney(mm.reduce((s,r)=>s+(r.xlPaid||0),0))}</td><td class="text-end">${fmtMoney(mm.reduce((s,r)=>s+(r.dbPaid||0),0))}</td></tr>
                  <tr><td><span class="dc-badge dc-badge-xl">Excel Only</span></td><td class="text-end">${xlOnly.length}</td><td class="text-end">${fmtMoney(xlOnlyP)}</td><td class="text-end">—</td><td class="text-end">${fmtMoney(xlOnlyD)}</td><td class="text-end">—</td></tr>
                  <tr><td><span class="dc-badge dc-badge-db">DB Only</span></td><td class="text-end">${dbOnly.length}</td><td class="text-end">—</td><td class="text-end">${fmtMoney(dbOnlyP)}</td><td class="text-end">—</td><td class="text-end">${fmtMoney(dbOnlyD)}</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="dc-card">
            <div class="dc-card-head"><h6><i class="fas fa-code-branch me-2" style="color:var(--info);"></i>Discrepancy Summary</h6></div>
            <div style="padding:20px;">
              <table class="table table-sm dc-summary-table mb-0">
                <tbody>
                  <tr><td>Mismatched records — pledge diff total</td><td class="text-end fw-semibold ${Math.abs(mmPD) > 0.01 ? 'dc-val-diff' : ''}">${fmtMoney(mmPD)}</td></tr>
                  <tr><td>Mismatched records — paid diff total</td><td class="text-end fw-semibold ${Math.abs(mmDD) > 0.01 ? 'dc-val-diff' : ''}">${fmtMoney(mmDD)}</td></tr>
                  <tr><td>Excel-only — total pledged</td><td class="text-end">${fmtMoney(xlOnlyP)}</td></tr>
                  <tr><td>Excel-only — total paid</td><td class="text-end">${fmtMoney(xlOnlyD)}</td></tr>
                  <tr><td>DB-only — total pledged</td><td class="text-end">${fmtMoney(dbOnlyP)}</td></tr>
                  <tr><td>DB-only — total paid</td><td class="text-end">${fmtMoney(dbOnlyD)}</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      ${(sourceRows || excelOnlyRow) ? `
      <div class="dc-card mt-4">
        <div class="dc-card-head"><h6><i class="fas fa-database me-2" style="color:var(--gray-600);"></i>By Data Source (Old vs New System)</h6></div>
        <div style="padding:20px;">
          <table class="table table-sm dc-summary-table mb-0">
            <thead><tr><th>Source</th><th class="text-end">Donors</th><th class="text-end">Pledged (XL)</th><th class="text-end">Pledged (DB)</th><th class="text-end">Paid (XL)</th><th class="text-end">Paid (DB)</th></tr></thead>
            <tbody>${sourceRows}${excelOnlyRow}</tbody>
          </table>
        </div>
      </div>
      ` : ''}
    `;
  }

  // --- Export ---
  el('exportBtn').addEventListener('click', () => {
    if (!filteredResults.length) { alert('No data to export.'); return; }
    const h = ['#','Status','Name (XL)','Name (DB)','Phone','Pledged (XL)','Pledged (DB)','Pledge Diff','Paid (XL)','Paid (DB)','Paid Diff','City','Data Source','DB Status'];
    const rows = filteredResults.map(r => [
      r.xlIdx||(r.dbId?'#'+r.dbId:''), r.status, r.xlName, r.dbName, r.xlPhone||r.dbPhone,
      r.xlAmount??'', r.dbPledged??'', r.pledgeDiff??'', r.xlPaid??'', r.dbPaid??'', r.paidDiff??'',
      r.xlCity||r.dbCity, r.dbSource, r.dbStatus
    ]);
    const csv = [h.join(','), ...rows.map(r => r.map(c => '"'+String(c).replace(/"/g,'""')+'"').join(','))].join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv'}));
    a.download = 'data-comparison-' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
  });

  el('reuploadBtn').addEventListener('click', () => { el('resultsSection').style.display = 'none'; resetAll(); });
})();
</script>
</body>
</html>
