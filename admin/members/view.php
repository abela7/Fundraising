<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$page_title = 'Donor Report';
$db = db();

// ─── Aggregated Stats ───
$stats = [
    'total_donors'      => 0,
    'pledge_donors'     => 0,
    'immediate_donors'  => 0,
    'total_pledged'     => 0,
    'total_paid'        => 0,
    'total_balance'     => 0,
    'with_active_plan'  => 0,
    // Payment status counts
    'status_no_pledge'  => 0,
    'status_not_started'=> 0,
    'status_paying'     => 0,
    'status_overdue'    => 0,
    'status_completed'  => 0,
    'status_defaulted'  => 0,
    // Source counts
    'src_public_form'   => 0,
    'src_registrar'     => 0,
    'src_imported'      => 0,
    'src_admin'         => 0,
];

$statsRow = $db->query("
    SELECT
        COUNT(*)                                                     AS total_donors,
        SUM(donor_type='pledge')                                     AS pledge_donors,
        SUM(donor_type='immediate_payment')                          AS immediate_donors,
        COALESCE(SUM(total_pledged),0)                               AS total_pledged,
        COALESCE(SUM(total_paid),0)                                  AS total_paid,
        COALESCE(SUM(balance),0)                                     AS total_balance,
        SUM(has_active_plan=1)                                       AS with_active_plan,
        SUM(payment_status='no_pledge')                              AS status_no_pledge,
        SUM(payment_status='not_started')                            AS status_not_started,
        SUM(payment_status='paying')                                 AS status_paying,
        SUM(payment_status='overdue')                                AS status_overdue,
        SUM(payment_status='completed')                              AS status_completed,
        SUM(payment_status='defaulted')                              AS status_defaulted,
        SUM(source='public_form')                                    AS src_public_form,
        SUM(source='registrar')                                      AS src_registrar,
        SUM(source='imported' OR is_imported=1)                      AS src_imported,
        SUM(source='admin')                                          AS src_admin
    FROM donors
")?->fetch_assoc();

if ($statsRow) {
    foreach ($stats as $k => &$v) {
        $v = (float)($statsRow[$k] ?? 0);
    }
    unset($v);
}

// ─── Filters ───
$filterStatus = $_GET['status'] ?? 'all';
$filterType   = $_GET['type']   ?? 'all';
$filterSource = $_GET['source'] ?? 'all';
$search       = trim($_GET['q'] ?? '');
$sort         = $_GET['sort']   ?? 'name_asc';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;

$where = [];
$params = [];
$types  = '';

if ($filterStatus !== 'all') {
    $where[]  = 'd.payment_status = ?';
    $params[] = $filterStatus;
    $types   .= 's';
}
if ($filterType !== 'all') {
    $where[]  = 'd.donor_type = ?';
    $params[] = $filterType;
    $types   .= 's';
}
if ($filterSource !== 'all') {
    if ($filterSource === 'imported') {
        $where[] = "(d.source = 'imported' OR d.is_imported = 1)";
    } else {
        $where[]  = 'd.source = ?';
        $params[] = $filterSource;
        $types   .= 's';
    }
}
if ($search !== '') {
    $where[]  = '(d.name LIKE ? OR d.phone LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$orderMap = [
    'name_asc'      => 'd.name ASC',
    'name_desc'     => 'd.name DESC',
    'paid_desc'     => 'd.total_paid DESC',
    'paid_asc'      => 'd.total_paid ASC',
    'pledged_desc'  => 'd.total_pledged DESC',
    'balance_desc'  => 'd.balance DESC',
    'newest'        => 'd.created_at DESC',
    'oldest'        => 'd.created_at ASC',
];
$orderSQL = $orderMap[$sort] ?? 'd.name ASC';

// Count
$countSQL = "SELECT COUNT(*) AS cnt FROM donors d $whereSQL";
if ($types) {
    $st = $db->prepare($countSQL);
    $st->bind_param($types, ...$params);
    $st->execute();
    $totalRows = (int)$st->get_result()->fetch_assoc()['cnt'];
    $st->close();
} else {
    $totalRows = (int)$db->query($countSQL)->fetch_assoc()['cnt'];
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
$offset     = ($page - 1) * $perPage;

// Fetch donors
$sql = "SELECT d.id, d.name, d.phone, d.donor_type, d.total_pledged, d.total_paid,
               d.balance, d.payment_status, d.achievement_badge, d.source,
               d.has_active_plan, d.created_at, d.last_payment_date
        FROM donors d
        $whereSQL
        ORDER BY $orderSQL
        LIMIT $perPage OFFSET $offset";

if ($types) {
    $st = $db->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $donors = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} else {
    $donors = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
}

// Helper: build query string preserving filters
function qsReplace(array $overrides): string {
    $qs = array_merge($_GET, $overrides);
    return '?' . http_build_query($qs);
}

$currency = '£';

// Status config
$statusConfig = [
    'no_pledge'   => ['label'=>'No Pledge',   'color'=>'#94a3b8', 'bg'=>'#f1f5f9', 'icon'=>'fa-minus-circle'],
    'not_started' => ['label'=>'Not Started',  'color'=>'#f59e0b', 'bg'=>'#fffbeb', 'icon'=>'fa-hourglass-start'],
    'paying'      => ['label'=>'Paying',       'color'=>'#3b82f6', 'bg'=>'#eff6ff', 'icon'=>'fa-spinner'],
    'overdue'     => ['label'=>'Overdue',      'color'=>'#ef4444', 'bg'=>'#fef2f2', 'icon'=>'fa-exclamation-triangle'],
    'completed'   => ['label'=>'Completed',    'color'=>'#10b981', 'bg'=>'#ecfdf5', 'icon'=>'fa-check-circle'],
    'defaulted'   => ['label'=>'Defaulted',    'color'=>'#6b7280', 'bg'=>'#f3f4f6', 'icon'=>'fa-times-circle'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $page_title ?> - Fundraising Admin</title>
<link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/admin.css">
<style>
/* ═══ Donor Report — Mobile-First ═══ */

.dr-hero {
    background: linear-gradient(135deg, var(--primary, #0a6286) 0%, #0b78a6 60%, rgba(226,202,24,.85) 100%);
    border-radius: 16px;
    padding: 20px;
    color: #fff;
    margin-bottom: 20px;
}
.dr-hero-title {
    font-size: 1.25rem;
    font-weight: 800;
    margin-bottom: 4px;
}
.dr-hero-sub {
    font-size: .8rem;
    opacity: .8;
}
.dr-hero-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-top: 16px;
}
.dr-hero-stat {
    background: rgba(255,255,255,.15);
    backdrop-filter: blur(8px);
    border-radius: 10px;
    padding: 12px;
    text-align: center;
}
.dr-hero-stat .val {
    font-size: 1.4rem;
    font-weight: 800;
    line-height: 1.1;
}
.dr-hero-stat .lbl {
    font-size: .65rem;
    text-transform: uppercase;
    letter-spacing: .5px;
    opacity: .85;
    margin-top: 2px;
}

/* Status Breakdown */
.dr-status-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 20px;
}
.dr-status-card {
    border-radius: 12px;
    padding: 14px;
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    transition: transform .15s, box-shadow .15s;
    text-decoration: none;
    border: 2px solid transparent;
}
.dr-status-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,.08);
}
.dr-status-card.active-filter {
    border-color: currentColor;
    box-shadow: 0 2px 12px rgba(0,0,0,.12);
}
.dr-status-card .sc-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .9rem;
    flex-shrink: 0;
}
.dr-status-card .sc-count {
    font-size: 1.25rem;
    font-weight: 800;
    line-height: 1;
}
.dr-status-card .sc-label {
    font-size: .7rem;
    text-transform: uppercase;
    letter-spacing: .3px;
    opacity: .7;
}

/* Filters bar */
.dr-filters {
    background: #fff;
    border-radius: 12px;
    padding: 14px;
    margin-bottom: 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.dr-search {
    position: relative;
}
.dr-search input {
    width: 100%;
    border: 1.5px solid var(--gray-200, #e5e7eb);
    border-radius: 10px;
    padding: 10px 12px 10px 38px;
    font-size: .85rem;
    transition: border .2s;
}
.dr-search input:focus {
    outline: none;
    border-color: var(--primary, #0a6286);
    box-shadow: 0 0 0 3px rgba(10,98,134,.1);
}
.dr-search i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--gray-400, #9ca3af);
    font-size: .85rem;
}
.dr-filter-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.dr-filter-row select {
    border: 1.5px solid var(--gray-200, #e5e7eb);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: .8rem;
    color: var(--gray-700, #374151);
    background: #fff;
}
.dr-filter-row select:focus {
    outline: none;
    border-color: var(--primary, #0a6286);
}

/* Results header */
.dr-results-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    padding: 0 2px;
}
.dr-results-count {
    font-size: .8rem;
    color: var(--gray-500, #6b7280);
    font-weight: 600;
}

/* Donor cards (mobile) */
.dr-donor-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.dr-donor-card {
    background: #fff;
    border-radius: 12px;
    padding: 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    transition: box-shadow .2s;
    text-decoration: none;
    color: inherit;
    display: block;
}
.dr-donor-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,.1);
    color: inherit;
}
.dr-dc-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 10px;
}
.dr-dc-name {
    font-weight: 700;
    font-size: .95rem;
    line-height: 1.2;
}
.dr-dc-phone {
    font-size: .75rem;
    color: var(--gray-500, #6b7280);
}
.dr-dc-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: .65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
    white-space: nowrap;
    flex-shrink: 0;
}
.dr-dc-money {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 10px;
}
.dr-dc-money-item {
    text-align: center;
    padding: 6px 4px;
    border-radius: 8px;
    background: var(--gray-50, #f9fafb);
}
.dr-dc-money-item .mv {
    font-size: .95rem;
    font-weight: 800;
    line-height: 1;
}
.dr-dc-money-item .ml {
    font-size: .6rem;
    text-transform: uppercase;
    letter-spacing: .3px;
    color: var(--gray-500, #6b7280);
    margin-top: 2px;
}
.dr-dc-bar {
    height: 6px;
    background: var(--gray-200, #e5e7eb);
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 8px;
}
.dr-dc-bar-fill {
    height: 100%;
    border-radius: 3px;
    transition: width .4s ease;
}
.dr-dc-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: .7rem;
    color: var(--gray-400, #9ca3af);
}
.dr-dc-type-badge {
    font-size: .6rem;
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: 600;
    text-transform: uppercase;
}

/* Pagination */
.dr-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 4px;
    margin-top: 20px;
    flex-wrap: wrap;
}
.dr-pagination a, .dr-pagination span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    border-radius: 8px;
    font-size: .8rem;
    font-weight: 600;
    text-decoration: none;
    color: var(--gray-600, #4b5563);
    background: #fff;
    border: 1.5px solid var(--gray-200, #e5e7eb);
    transition: all .15s;
}
.dr-pagination a:hover {
    background: var(--gray-50, #f9fafb);
    border-color: var(--primary, #0a6286);
    color: var(--primary, #0a6286);
}
.dr-pagination .active {
    background: var(--primary, #0a6286);
    color: #fff;
    border-color: var(--primary, #0a6286);
}
.dr-pagination .disabled {
    opacity: .4;
    pointer-events: none;
}

/* Source breakdown mini bar */
.dr-source-bar {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}
.dr-source-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 8px;
    font-size: .72rem;
    font-weight: 600;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    text-decoration: none;
    color: var(--gray-700, #374151);
    transition: all .15s;
    border: 1.5px solid transparent;
}
.dr-source-chip:hover {
    border-color: var(--primary, #0a6286);
    color: var(--primary, #0a6286);
}
.dr-source-chip.active-filter {
    border-color: var(--primary, #0a6286);
    background: rgba(10,98,134,.06);
    color: var(--primary, #0a6286);
}
.dr-source-chip .sc-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

/* ─── Tablet (≥ 768px) ─── */
@media (min-width: 768px) {
    .dr-hero-row { grid-template-columns: repeat(3, 1fr); }
    .dr-status-grid { grid-template-columns: repeat(3, 1fr); }
    .dr-filters { flex-direction: row; align-items: center; flex-wrap: wrap; }
    .dr-search { flex: 1; min-width: 200px; }
    .dr-filter-row { grid-template-columns: repeat(3, 1fr); flex: 2; }
}

/* ─── Desktop (≥ 1200px) ─── */
@media (min-width: 1200px) {
    .dr-hero-row { grid-template-columns: repeat(6, 1fr); }
    .dr-hero-stat .val { font-size: 1.6rem; }
    .dr-status-grid { grid-template-columns: repeat(6, 1fr); }
    .dr-donor-list {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
}
@media (min-width: 1600px) {
    .dr-donor-list { grid-template-columns: repeat(3, 1fr); }
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

        <!-- ══ Hero Stats ══ -->
        <div class="dr-hero">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="dr-hero-title"><i class="fas fa-chart-pie me-2"></i>Donor Report</div>
              <div class="dr-hero-sub">Comprehensive overview of all registered donors</div>
            </div>
            <a href="./" class="btn btn-sm btn-light" style="font-size:.75rem"><i class="fas fa-arrow-left me-1"></i>Members</a>
          </div>
          <div class="dr-hero-row">
            <div class="dr-hero-stat">
              <div class="val"><?= number_format($stats['total_donors']) ?></div>
              <div class="lbl">Total Donors</div>
            </div>
            <div class="dr-hero-stat">
              <div class="val"><?= number_format($stats['pledge_donors']) ?></div>
              <div class="lbl">Pledge Donors</div>
            </div>
            <div class="dr-hero-stat">
              <div class="val"><?= number_format($stats['immediate_donors']) ?></div>
              <div class="lbl">Immediate</div>
            </div>
            <div class="dr-hero-stat">
              <div class="val"><?= $currency . number_format($stats['total_pledged']) ?></div>
              <div class="lbl">Total Pledged</div>
            </div>
            <div class="dr-hero-stat">
              <div class="val"><?= $currency . number_format($stats['total_paid']) ?></div>
              <div class="lbl">Total Paid</div>
            </div>
            <div class="dr-hero-stat">
              <div class="val"><?= $currency . number_format($stats['total_balance']) ?></div>
              <div class="lbl">Outstanding</div>
            </div>
          </div>
        </div>

        <!-- ══ Status Breakdown ══ -->
        <div class="dr-status-grid">
          <?php foreach ($statusConfig as $key => $cfg):
              $cnt = (int)$stats['status_' . $key];
              $isActive = ($filterStatus === $key);
              $href = $isActive ? qsReplace(['status'=>'all','page'=>1]) : qsReplace(['status'=>$key,'page'=>1]);
          ?>
          <a href="<?= htmlspecialchars($href) ?>"
             class="dr-status-card <?= $isActive ? 'active-filter' : '' ?>"
             style="background:<?= $cfg['bg'] ?>; color:<?= $cfg['color'] ?>;">
            <div class="sc-icon" style="background:<?= $cfg['color'] ?>1a; color:<?= $cfg['color'] ?>;">
              <i class="fas <?= $cfg['icon'] ?>"></i>
            </div>
            <div>
              <div class="sc-count"><?= $cnt ?></div>
              <div class="sc-label"><?= $cfg['label'] ?></div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>

        <!-- ══ Source Breakdown ══ -->
        <div class="dr-source-bar">
          <span style="font-size:.72rem;font-weight:700;color:var(--gray-500);align-self:center;">Source:</span>
          <?php
          $sources = [
              'public_form' => ['label'=>'Public Form', 'color'=>'#3b82f6', 'count'=>$stats['src_public_form']],
              'registrar'   => ['label'=>'Registrar',   'color'=>'#8b5cf6', 'count'=>$stats['src_registrar']],
              'imported'    => ['label'=>'Imported',     'color'=>'#f59e0b', 'count'=>$stats['src_imported']],
              'admin'       => ['label'=>'Admin',        'color'=>'#10b981', 'count'=>$stats['src_admin']],
          ];
          foreach ($sources as $sk => $sv):
              $isActive = ($filterSource === $sk);
              $href = $isActive ? qsReplace(['source'=>'all','page'=>1]) : qsReplace(['source'=>$sk,'page'=>1]);
          ?>
          <a href="<?= htmlspecialchars($href) ?>" class="dr-source-chip <?= $isActive?'active-filter':'' ?>">
            <span class="sc-dot" style="background:<?= $sv['color'] ?>"></span>
            <?= $sv['label'] ?> <strong>(<?= (int)$sv['count'] ?>)</strong>
          </a>
          <?php endforeach; ?>
          <?php if ($filterSource !== 'all'): ?>
          <a href="<?= htmlspecialchars(qsReplace(['source'=>'all','page'=>1])) ?>" class="dr-source-chip" style="color:var(--danger, #ef4444);">
            <i class="fas fa-times" style="font-size:.65rem"></i> Clear
          </a>
          <?php endif; ?>
        </div>

        <!-- ══ Filters ══ -->
        <form method="get" class="dr-filters" id="filterForm">
          <!-- preserve existing filters -->
          <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
          <input type="hidden" name="source" value="<?= htmlspecialchars($filterSource) ?>">
          <div class="dr-search">
            <i class="fas fa-search"></i>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or phone...">
          </div>
          <div class="dr-filter-row">
            <select name="type" onchange="this.form.submit()">
              <option value="all" <?= $filterType==='all'?'selected':'' ?>>All Types</option>
              <option value="pledge" <?= $filterType==='pledge'?'selected':'' ?>>Pledge</option>
              <option value="immediate_payment" <?= $filterType==='immediate_payment'?'selected':'' ?>>Immediate</option>
            </select>
            <select name="sort" onchange="this.form.submit()">
              <option value="name_asc"     <?= $sort==='name_asc'?'selected':'' ?>>Name A-Z</option>
              <option value="name_desc"    <?= $sort==='name_desc'?'selected':'' ?>>Name Z-A</option>
              <option value="paid_desc"    <?= $sort==='paid_desc'?'selected':'' ?>>Most Paid</option>
              <option value="paid_asc"     <?= $sort==='paid_asc'?'selected':'' ?>>Least Paid</option>
              <option value="pledged_desc" <?= $sort==='pledged_desc'?'selected':'' ?>>Most Pledged</option>
              <option value="balance_desc" <?= $sort==='balance_desc'?'selected':'' ?>>Highest Balance</option>
              <option value="newest"       <?= $sort==='newest'?'selected':'' ?>>Newest First</option>
              <option value="oldest"       <?= $sort==='oldest'?'selected':'' ?>>Oldest First</option>
            </select>
            <button type="submit" class="btn btn-sm" style="background:var(--primary,#0a6286);color:#fff;border-radius:8px;font-size:.8rem;">
              <i class="fas fa-filter me-1"></i>Apply
            </button>
          </div>
        </form>

        <!-- ══ Results ══ -->
        <div class="dr-results-header">
          <div class="dr-results-count">
            Showing <?= count($donors) ?> of <?= number_format($totalRows) ?> donor<?= $totalRows !== 1 ? 's' : '' ?>
            <?php if ($filterStatus !== 'all' || $filterType !== 'all' || $filterSource !== 'all' || $search): ?>
              <a href="?" style="font-size:.72rem;margin-left:8px;color:var(--danger,#ef4444)"><i class="fas fa-times"></i> Clear all</a>
            <?php endif; ?>
          </div>
        </div>

        <?php if (empty($donors)): ?>
        <div class="text-center py-5">
          <i class="fas fa-inbox fa-3x mb-3" style="color:var(--gray-300)"></i>
          <p class="text-muted">No donors match your filters.</p>
        </div>
        <?php else: ?>
        <div class="dr-donor-list">
          <?php foreach ($donors as $d):
              $pledged = (float)$d['total_pledged'];
              $paid    = (float)$d['total_paid'];
              $bal     = (float)$d['balance'];
              $pct     = $pledged > 0 ? min(100, round(($paid / $pledged) * 100)) : ($paid > 0 ? 100 : 0);
              $sc      = $statusConfig[$d['payment_status']] ?? $statusConfig['no_pledge'];
              $isPledge = $d['donor_type'] === 'pledge';
              $barColor = $pct >= 100 ? '#10b981' : ($pct >= 50 ? '#3b82f6' : ($pct > 0 ? '#f59e0b' : '#e5e7eb'));
          ?>
          <a href="../donor-management/view-donor.php?id=<?= $d['id'] ?>" class="dr-donor-card">
            <div class="dr-dc-top">
              <div>
                <div class="dr-dc-name"><?= htmlspecialchars($d['name']) ?></div>
                <div class="dr-dc-phone"><i class="fas fa-phone me-1"></i><?= htmlspecialchars($d['phone']) ?></div>
              </div>
              <div class="dr-dc-badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
                <i class="fas <?= $sc['icon'] ?>" style="font-size:.55rem"></i>
                <?= $sc['label'] ?>
              </div>
            </div>
            <div class="dr-dc-money">
              <div class="dr-dc-money-item">
                <div class="mv" style="color:#1a73e8"><?= $currency . number_format($pledged) ?></div>
                <div class="ml">Pledged</div>
              </div>
              <div class="dr-dc-money-item">
                <div class="mv" style="color:#10b981"><?= $currency . number_format($paid) ?></div>
                <div class="ml">Paid</div>
              </div>
              <div class="dr-dc-money-item">
                <div class="mv" style="color:<?= $bal > 0 ? '#ef4444' : '#10b981' ?>"><?= $currency . number_format($bal) ?></div>
                <div class="ml">Balance</div>
              </div>
            </div>
            <?php if ($pledged > 0): ?>
            <div class="dr-dc-bar">
              <div class="dr-dc-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
            </div>
            <?php endif; ?>
            <div class="dr-dc-meta">
              <div>
                <span class="dr-dc-type-badge" style="background:<?= $isPledge ? '#eff6ff' : '#fef3c7' ?>;color:<?= $isPledge ? '#3b82f6' : '#d97706' ?>">
                  <?= $isPledge ? 'Pledge' : 'Immediate' ?>
                </span>
                <?php if ($d['has_active_plan']): ?>
                <span class="dr-dc-type-badge" style="background:#ecfdf5;color:#10b981;margin-left:4px">
                  <i class="fas fa-calendar-check" style="font-size:.55rem"></i> Plan
                </span>
                <?php endif; ?>
              </div>
              <div>
                <?php if ($d['last_payment_date']): ?>
                  Last paid: <?= date('d M Y', strtotime($d['last_payment_date'])) ?>
                <?php else: ?>
                  Joined: <?= date('d M Y', strtotime($d['created_at'])) ?>
                <?php endif; ?>
              </div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>

        <!-- ══ Pagination ══ -->
        <?php if ($totalPages > 1): ?>
        <div class="dr-pagination">
          <a href="<?= htmlspecialchars(qsReplace(['page' => max(1, $page-1)])) ?>"
             class="<?= $page <= 1 ? 'disabled' : '' ?>"><i class="fas fa-chevron-left"></i></a>
          <?php
          $startP = max(1, $page - 2);
          $endP   = min($totalPages, $page + 2);
          if ($startP > 1) echo '<a href="' . htmlspecialchars(qsReplace(['page'=>1])) . '">1</a>';
          if ($startP > 2) echo '<span class="disabled">...</span>';
          for ($i = $startP; $i <= $endP; $i++):
          ?>
            <a href="<?= htmlspecialchars(qsReplace(['page'=>$i])) ?>"
               class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor;
          if ($endP < $totalPages - 1) echo '<span class="disabled">...</span>';
          if ($endP < $totalPages) echo '<a href="' . htmlspecialchars(qsReplace(['page'=>$totalPages])) . '">' . $totalPages . '</a>';
          ?>
          <a href="<?= htmlspecialchars(qsReplace(['page' => min($totalPages, $page+1)])) ?>"
             class="<?= $page >= $totalPages ? 'disabled' : '' ?>"><i class="fas fa-chevron-right"></i></a>
        </div>
        <?php endif; ?>
        <?php endif; ?>

      </div>
    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>
