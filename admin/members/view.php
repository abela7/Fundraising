<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$db = db();

// ─── Load Member ───
$memberId = max(1, (int)($_GET['id'] ?? 0));
$stmt = $db->prepare('SELECT id, name, phone, email, role, active, created_at FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $memberId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$member) { http_response_code(404); echo 'Member not found'; exit; }

$page_title = htmlspecialchars($member['name']) . ' — Report';
$currency = '£';

// ══════════════════════════════════════════════════════════════════
// REAL DATA — We query the actual source tables, not cached donor fields
// ══════════════════════════════════════════════════════════════════

// ─── 1. Pledges logged by this member ───
$pl = $db->prepare("
    SELECT
        COUNT(*)                                           AS total,
        SUM(status='pending')                              AS pending,
        SUM(status='approved')                             AS approved,
        SUM(status='rejected')                             AS rejected,
        SUM(status='cancelled')                            AS cancelled,
        COALESCE(SUM(amount),0)                            AS total_amount,
        COALESCE(SUM(CASE WHEN status='approved' THEN amount ELSE 0 END),0) AS approved_amount,
        COALESCE(SUM(CASE WHEN status='pending'  THEN amount ELSE 0 END),0) AS pending_amount
    FROM pledges WHERE created_by_user_id = ?
");
$pl->bind_param('i', $memberId);
$pl->execute();
$pledgeStats = $pl->get_result()->fetch_assoc();
$pl->close();

// ─── 2. Immediate payments logged by this member ───
$py = $db->prepare("
    SELECT
        COUNT(*)                                           AS total,
        SUM(status='pending')                              AS pending,
        SUM(status='approved')                             AS approved,
        SUM(status='voided')                               AS voided,
        COALESCE(SUM(amount),0)                            AS total_amount,
        COALESCE(SUM(CASE WHEN status='approved' THEN amount ELSE 0 END),0) AS approved_amount,
        COALESCE(SUM(CASE WHEN status='pending'  THEN amount ELSE 0 END),0) AS pending_amount
    FROM payments WHERE received_by_user_id = ?
");
$py->bind_param('i', $memberId);
$py->execute();
$paymentStats = $py->get_result()->fetch_assoc();
$py->close();

// ─── 3. Unique donors linked to this member ───
// Path A: donor has a pledge created by this member (approved, so donor_id is set)
// Path B: donor has a payment received by this member (approved, so donor_id is set)
// Path C: donor.registered_by_user_id = memberId (direct registration)
$donorScope = "(
    d.id IN (SELECT DISTINCT pl.donor_id FROM pledges pl WHERE pl.created_by_user_id = ? AND pl.donor_id IS NOT NULL)
    OR d.id IN (SELECT DISTINCT pa.donor_id FROM payments pa WHERE pa.received_by_user_id = ? AND pa.donor_id IS NOT NULL)
    OR d.registered_by_user_id = ?
)";

$ds = $db->prepare("
    SELECT
        COUNT(*)                                           AS total_donors,
        SUM(donor_type='pledge')                           AS pledge_donors,
        SUM(donor_type='immediate_payment')                AS immediate_donors,
        SUM(payment_status='not_started')                  AS st_not_started,
        SUM(payment_status='paying')                       AS st_paying,
        SUM(payment_status='overdue')                      AS st_overdue,
        SUM(payment_status='completed')                    AS st_completed,
        SUM(payment_status='defaulted')                    AS st_defaulted,
        SUM(payment_status='no_pledge')                    AS st_no_pledge,
        COALESCE(SUM(total_pledged),0)                     AS sum_pledged,
        COALESCE(SUM(total_paid),0)                        AS sum_paid,
        COALESCE(SUM(balance),0)                           AS sum_balance
    FROM donors d WHERE $donorScope
");
$ds->bind_param('iii', $memberId, $memberId, $memberId);
$ds->execute();
$donorStats = $ds->get_result()->fetch_assoc();
$ds->close();

// Grand totals
$grandPledges  = (int)($pledgeStats['total'] ?? 0);
$grandPayments = (int)($paymentStats['total'] ?? 0);
$grandDonors   = (int)($donorStats['total_donors'] ?? 0);
$approvalRate  = $grandPledges > 0
    ? round(((int)$pledgeStats['approved'] / $grandPledges) * 100)
    : 0;
$collectionRate = (float)$donorStats['sum_pledged'] > 0
    ? round(((float)$donorStats['sum_paid'] / (float)$donorStats['sum_pledged']) * 100)
    : 0;

// ─── 4. Donor list with filters ───
$filterStatus = $_GET['status'] ?? 'all';
$filterType   = $_GET['type']   ?? 'all';
$search       = trim($_GET['q'] ?? '');
$sort         = $_GET['sort']   ?? 'newest';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;

$where  = [$donorScope];
$params = [$memberId, $memberId, $memberId];
$types  = 'iii';

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
if ($search !== '') {
    $where[]  = '(d.name LIKE ? OR d.phone LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$orderMap = [
    'name_asc'     => 'd.name ASC',
    'name_desc'    => 'd.name DESC',
    'paid_desc'    => 'd.total_paid DESC',
    'balance_desc' => 'd.balance DESC',
    'newest'       => 'd.created_at DESC',
    'oldest'       => 'd.created_at ASC',
];
$orderSQL = $orderMap[$sort] ?? 'd.created_at DESC';

$st = $db->prepare("SELECT COUNT(*) AS cnt FROM donors d $whereSQL");
$st->bind_param($types, ...$params);
$st->execute();
$totalRows = (int)$st->get_result()->fetch_assoc()['cnt'];
$st->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
$offset     = ($page - 1) * $perPage;

$st = $db->prepare("
    SELECT d.id, d.name, d.phone, d.donor_type, d.total_pledged, d.total_paid,
           d.balance, d.payment_status, d.has_active_plan, d.created_at, d.last_payment_date
    FROM donors d $whereSQL ORDER BY $orderSQL LIMIT $perPage OFFSET $offset
");
$st->bind_param($types, ...$params);
$st->execute();
$donors = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

function qsReplace(array $o): string {
    $q = array_merge($_GET, $o);
    return '?' . http_build_query($q);
}

$statusCfg = [
    'no_pledge'   => ['l'=>'No Pledge',  'c'=>'#94a3b8','bg'=>'#f1f5f9','ic'=>'fa-minus-circle'],
    'not_started' => ['l'=>'Not Started', 'c'=>'#f59e0b','bg'=>'#fffbeb','ic'=>'fa-hourglass-start'],
    'paying'      => ['l'=>'Paying',      'c'=>'#3b82f6','bg'=>'#eff6ff','ic'=>'fa-spinner'],
    'overdue'     => ['l'=>'Overdue',     'c'=>'#ef4444','bg'=>'#fef2f2','ic'=>'fa-exclamation-triangle'],
    'completed'   => ['l'=>'Completed',   'c'=>'#10b981','bg'=>'#ecfdf5','ic'=>'fa-check-circle'],
    'defaulted'   => ['l'=>'Defaulted',   'c'=>'#6b7280','bg'=>'#f3f4f6','ic'=>'fa-times-circle'],
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
:root { --rpt-radius: 14px; }

/* ── Member Header ── */
.rpt-member {
    background: #fff;
    border-radius: var(--rpt-radius);
    padding: 16px;
    margin-bottom: 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.rpt-m-left { display: flex; gap: 12px; align-items: center; }
.rpt-m-avatar {
    width: 46px; height: 46px; border-radius: 12px;
    background: linear-gradient(135deg, var(--primary,#0a6286), #0b78a6);
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 1.2rem; flex-shrink: 0;
}
.rpt-m-name { font-weight: 800; font-size: 1rem; line-height: 1.2; }
.rpt-m-detail { font-size: .72rem; color: var(--gray-500,#6b7280); margin-top: 1px; }
.rpt-m-detail i { font-size: .6rem; margin-right: 2px; }
.rpt-m-tags { display: flex; gap: 5px; margin-top: 5px; flex-wrap: wrap; }
.rpt-tag {
    font-size: .6rem; font-weight: 600; padding: 2px 7px; border-radius: 5px;
    display: inline-flex; align-items: center; gap: 3px;
}
.rpt-tag i { font-size: .55rem; }

/* ── Summary Cards ── */
.rpt-summary {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 14px;
}
.rpt-card {
    background: #fff;
    border-radius: var(--rpt-radius);
    padding: 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.rpt-card-title {
    font-size: .65rem; text-transform: uppercase; letter-spacing: .5px;
    font-weight: 700; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;
}
.rpt-card-title i { font-size: .7rem; }
.rpt-num { font-size: 1.3rem; font-weight: 800; line-height: 1; }
.rpt-label { font-size: .6rem; color: var(--gray-500,#6b7280); text-transform: uppercase; letter-spacing: .3px; margin-top: 1px; }
.rpt-row { display: flex; justify-content: space-between; align-items: baseline; padding: 5px 0; }
.rpt-row + .rpt-row { border-top: 1px solid var(--gray-100,#f3f4f6); }
.rpt-row-label { font-size: .72rem; color: var(--gray-600,#4b5563); }
.rpt-row-val { font-size: .8rem; font-weight: 700; }
.rpt-bar { height: 6px; background: var(--gray-100,#f3f4f6); border-radius: 3px; margin-top: 8px; overflow: hidden; }
.rpt-bar-fill { height: 100%; border-radius: 3px; }

/* ── Performance ── */
.rpt-perf {
    background: linear-gradient(135deg, var(--primary,#0a6286) 0%, #0b78a6 60%, rgba(226,202,24,.85) 100%);
    border-radius: var(--rpt-radius);
    padding: 16px;
    color: #fff;
    margin-bottom: 14px;
}
.rpt-perf-title { font-size: .85rem; font-weight: 800; margin-bottom: 12px; }
.rpt-perf-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.rpt-perf-item {
    background: rgba(255,255,255,.15); backdrop-filter: blur(8px);
    border-radius: 10px; padding: 10px; text-align: center;
}
.rpt-perf-item .pv { font-size: 1.3rem; font-weight: 800; line-height: 1; }
.rpt-perf-item .pl { font-size: .58rem; text-transform: uppercase; letter-spacing: .4px; opacity: .8; margin-top: 2px; }

/* ── Status Chips ── */
.rpt-statuses {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 14px;
}
.rpt-st {
    border-radius: 10px; padding: 10px 12px; text-decoration: none;
    display: flex; align-items: center; gap: 8px;
    border: 2px solid transparent; transition: transform .12s, box-shadow .12s;
}
.rpt-st:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(0,0,0,.07); }
.rpt-st.act { border-color: currentColor; }
.rpt-st-icon { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: .75rem; flex-shrink: 0; }
.rpt-st-count { font-size: 1.05rem; font-weight: 800; line-height: 1; }
.rpt-st-label { font-size: .58rem; text-transform: uppercase; letter-spacing: .3px; opacity: .7; }

/* ── Filters ── */
.rpt-filters {
    background: #fff; border-radius: var(--rpt-radius); padding: 12px;
    margin-bottom: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.05);
    display: flex; flex-direction: column; gap: 8px;
}
.rpt-search { position: relative; }
.rpt-search input {
    width: 100%; border: 1.5px solid var(--gray-200,#e5e7eb); border-radius: 8px;
    padding: 9px 10px 9px 34px; font-size: .82rem;
}
.rpt-search input:focus { outline: none; border-color: var(--primary,#0a6286); box-shadow: 0 0 0 3px rgba(10,98,134,.08); }
.rpt-search i { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--gray-400,#9ca3af); font-size: .8rem; }
.rpt-sel-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; }
.rpt-sel-row select, .rpt-sel-row button {
    border: 1.5px solid var(--gray-200,#e5e7eb); border-radius: 8px;
    padding: 8px 8px; font-size: .78rem; background: #fff; color: var(--gray-700,#374151);
}
.rpt-sel-row select:focus { outline: none; border-color: var(--primary,#0a6286); }
.rpt-sel-row button { background: var(--primary,#0a6286); color: #fff; border-color: var(--primary,#0a6286); font-weight: 600; cursor: pointer; }

/* ── Donor Cards ── */
.rpt-donors { display: flex; flex-direction: column; gap: 8px; }
.rpt-donor {
    background: #fff; border-radius: 12px; padding: 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,.05); display: block;
    text-decoration: none; color: inherit; transition: box-shadow .15s;
}
.rpt-donor:hover { box-shadow: 0 4px 14px rgba(0,0,0,.1); color: inherit; }
.rpt-d-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
.rpt-d-name { font-weight: 700; font-size: .9rem; line-height: 1.2; }
.rpt-d-phone { font-size: .7rem; color: var(--gray-500,#6b7280); }
.rpt-d-badge {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 2px 7px; border-radius: 5px; font-size: .6rem;
    font-weight: 700; text-transform: uppercase; letter-spacing: .3px; white-space: nowrap; flex-shrink: 0;
}
.rpt-d-money { display: grid; grid-template-columns: repeat(3,1fr); gap: 6px; margin-bottom: 8px; }
.rpt-d-mi { text-align: center; padding: 5px 2px; border-radius: 6px; background: var(--gray-50,#f9fafb); }
.rpt-d-mi .v { font-size: .85rem; font-weight: 800; line-height: 1; }
.rpt-d-mi .l { font-size: .55rem; text-transform: uppercase; color: var(--gray-500,#6b7280); margin-top: 1px; }
.rpt-d-bar { height: 5px; background: var(--gray-200,#e5e7eb); border-radius: 3px; overflow: hidden; margin-bottom: 6px; }
.rpt-d-bar-f { height: 100%; border-radius: 3px; }
.rpt-d-foot { display: flex; justify-content: space-between; align-items: center; font-size: .65rem; color: var(--gray-400,#9ca3af); }
.rpt-d-type {
    font-size: .55rem; padding: 2px 5px; border-radius: 4px; font-weight: 600; text-transform: uppercase;
}
.rpt-d-pct { font-size: .65rem; font-weight: 700; }

/* ── Pagination ── */
.rpt-pag { display: flex; justify-content: center; gap: 4px; margin-top: 16px; flex-wrap: wrap; }
.rpt-pag a, .rpt-pag span {
    min-width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center;
    border-radius: 8px; font-size: .78rem; font-weight: 600; text-decoration: none;
    color: var(--gray-600,#4b5563); background: #fff; border: 1.5px solid var(--gray-200,#e5e7eb);
}
.rpt-pag a:hover { border-color: var(--primary,#0a6286); color: var(--primary,#0a6286); }
.rpt-pag .act { background: var(--primary,#0a6286); color: #fff; border-color: var(--primary,#0a6286); }
.rpt-pag .dis { opacity: .35; pointer-events: none; }

.rpt-empty { text-align: center; padding: 40px 20px; }
.rpt-empty i { font-size: 2rem; color: var(--gray-300,#d1d5db); margin-bottom: 10px; display: block; }
.rpt-empty p { color: var(--gray-500,#6b7280); font-size: .85rem; }
.rpt-results-count { font-size: .75rem; color: var(--gray-500,#6b7280); font-weight: 600; margin-bottom: 8px; }

/* ── Responsive ── */
@media (min-width: 576px) {
    .rpt-statuses { grid-template-columns: repeat(3, 1fr); }
    .rpt-perf-grid { grid-template-columns: repeat(4, 1fr); }
}
@media (min-width: 768px) {
    .rpt-summary { grid-template-columns: repeat(2, 1fr); }
    .rpt-filters { flex-direction: row; flex-wrap: wrap; align-items: center; }
    .rpt-search { flex: 1; min-width: 180px; }
    .rpt-sel-row { flex: 2; }
}
@media (min-width: 992px) {
    .rpt-statuses { grid-template-columns: repeat(6, 1fr); }
    .rpt-perf-grid { grid-template-columns: repeat(4, 1fr); }
}
@media (min-width: 1200px) {
    .rpt-donors { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
}
@media (min-width: 1600px) {
    .rpt-donors { grid-template-columns: repeat(3, 1fr); }
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

        <!-- ═ Member Header ═ -->
        <div class="rpt-member">
          <div class="rpt-m-left">
            <div class="rpt-m-avatar"><?= strtoupper(mb_substr($member['name'], 0, 1)) ?></div>
            <div>
              <div class="rpt-m-name"><?= htmlspecialchars($member['name']) ?></div>
              <div class="rpt-m-detail">
                <?php if ($member['phone']): ?><i class="fas fa-phone"></i> <?= htmlspecialchars($member['phone']) ?><?php endif; ?>
                <?php if ($member['email']): ?> &middot; <i class="fas fa-envelope"></i> <?= htmlspecialchars($member['email']) ?><?php endif; ?>
              </div>
              <div class="rpt-m-tags">
                <span class="rpt-tag" style="background:<?= $member['role']==='admin'?'#eff6ff':'#f0fdf4' ?>;color:<?= $member['role']==='admin'?'#3b82f6':'#16a34a' ?>">
                  <i class="fas <?= $member['role']==='admin'?'fa-shield-halved':'fa-id-badge' ?>"></i> <?= ucfirst($member['role']) ?>
                </span>
                <span class="rpt-tag" style="background:<?= ((int)$member['active']===1)?'#ecfdf5':'#fef2f2' ?>;color:<?= ((int)$member['active']===1)?'#10b981':'#ef4444' ?>">
                  <?= ((int)$member['active']===1)?'Active':'Inactive' ?>
                </span>
                <span class="rpt-tag" style="background:#f5f3ff;color:#7c3aed">
                  Joined <?= date('M d, Y', strtotime($member['created_at'])) ?>
                </span>
              </div>
            </div>
          </div>
          <a href="./" class="btn btn-sm btn-outline-secondary" style="font-size:.72rem;white-space:nowrap">
            <i class="fas fa-arrow-left me-1"></i>Members
          </a>
        </div>

        <!-- ═ Performance Banner ═ -->
        <div class="rpt-perf">
          <div class="rpt-perf-title"><i class="fas fa-chart-line me-1"></i> Registrar Performance</div>
          <div class="rpt-perf-grid">
            <div class="rpt-perf-item">
              <div class="pv"><?= $grandDonors ?></div>
              <div class="pl">Donors</div>
            </div>
            <div class="rpt-perf-item">
              <div class="pv"><?= $grandPledges + $grandPayments ?></div>
              <div class="pl">Total Logged</div>
            </div>
            <div class="rpt-perf-item">
              <div class="pv"><?= $approvalRate ?>%</div>
              <div class="pl">Approval Rate</div>
            </div>
            <div class="rpt-perf-item">
              <div class="pv"><?= $collectionRate ?>%</div>
              <div class="pl">Collection Rate</div>
            </div>
          </div>
        </div>

        <!-- ═ Summary Cards ═ -->
        <div class="rpt-summary">
          <!-- Pledges Card -->
          <div class="rpt-card">
            <div class="rpt-card-title" style="color:#3b82f6"><i class="fas fa-hand-holding-heart"></i> Pledges Logged</div>
            <div class="rpt-num" style="color:#3b82f6"><?= (int)$pledgeStats['total'] ?></div>
            <div class="rpt-label">Total pledges</div>
            <div style="margin-top:10px">
              <div class="rpt-row">
                <span class="rpt-row-label"><i class="fas fa-circle" style="font-size:.4rem;color:#10b981;vertical-align:middle"></i> Approved</span>
                <span class="rpt-row-val" style="color:#10b981"><?= (int)$pledgeStats['approved'] ?> &middot; <?= $currency . number_format((float)$pledgeStats['approved_amount']) ?></span>
              </div>
              <div class="rpt-row">
                <span class="rpt-row-label"><i class="fas fa-circle" style="font-size:.4rem;color:#f59e0b;vertical-align:middle"></i> Pending</span>
                <span class="rpt-row-val" style="color:#f59e0b"><?= (int)$pledgeStats['pending'] ?> &middot; <?= $currency . number_format((float)$pledgeStats['pending_amount']) ?></span>
              </div>
              <div class="rpt-row">
                <span class="rpt-row-label"><i class="fas fa-circle" style="font-size:.4rem;color:#ef4444;vertical-align:middle"></i> Rejected</span>
                <span class="rpt-row-val" style="color:#ef4444"><?= (int)$pledgeStats['rejected'] ?></span>
              </div>
            </div>
            <?php $plPct = (int)$pledgeStats['total'] > 0 ? round(((int)$pledgeStats['approved'] / (int)$pledgeStats['total'])*100) : 0; ?>
            <div class="rpt-bar"><div class="rpt-bar-fill" style="width:<?= $plPct ?>%;background:#3b82f6"></div></div>
          </div>

          <!-- Payments Card -->
          <div class="rpt-card">
            <div class="rpt-card-title" style="color:#10b981"><i class="fas fa-money-bill-wave"></i> Payments Logged</div>
            <div class="rpt-num" style="color:#10b981"><?= (int)$paymentStats['total'] ?></div>
            <div class="rpt-label">Total payments</div>
            <div style="margin-top:10px">
              <div class="rpt-row">
                <span class="rpt-row-label"><i class="fas fa-circle" style="font-size:.4rem;color:#10b981;vertical-align:middle"></i> Approved</span>
                <span class="rpt-row-val" style="color:#10b981"><?= (int)$paymentStats['approved'] ?> &middot; <?= $currency . number_format((float)$paymentStats['approved_amount']) ?></span>
              </div>
              <div class="rpt-row">
                <span class="rpt-row-label"><i class="fas fa-circle" style="font-size:.4rem;color:#f59e0b;vertical-align:middle"></i> Pending</span>
                <span class="rpt-row-val" style="color:#f59e0b"><?= (int)$paymentStats['pending'] ?> &middot; <?= $currency . number_format((float)$paymentStats['pending_amount']) ?></span>
              </div>
              <div class="rpt-row">
                <span class="rpt-row-label"><i class="fas fa-circle" style="font-size:.4rem;color:#6b7280;vertical-align:middle"></i> Voided</span>
                <span class="rpt-row-val" style="color:#6b7280"><?= (int)$paymentStats['voided'] ?></span>
              </div>
            </div>
            <?php $pyPct = (int)$paymentStats['total'] > 0 ? round(((int)$paymentStats['approved'] / (int)$paymentStats['total'])*100) : 0; ?>
            <div class="rpt-bar"><div class="rpt-bar-fill" style="width:<?= $pyPct ?>%;background:#10b981"></div></div>
          </div>
        </div>

        <!-- ═ Financial Summary ═ -->
        <div class="rpt-summary" style="margin-bottom:14px">
          <div class="rpt-card">
            <div class="rpt-card-title" style="color:#1a73e8"><i class="fas fa-coins"></i> Financial (Donors)</div>
            <div class="rpt-row">
              <span class="rpt-row-label">Total Pledged</span>
              <span class="rpt-row-val" style="color:#1a73e8"><?= $currency . number_format((float)$donorStats['sum_pledged']) ?></span>
            </div>
            <div class="rpt-row">
              <span class="rpt-row-label">Total Paid</span>
              <span class="rpt-row-val" style="color:#10b981"><?= $currency . number_format((float)$donorStats['sum_paid']) ?></span>
            </div>
            <div class="rpt-row">
              <span class="rpt-row-label">Outstanding</span>
              <span class="rpt-row-val" style="color:#ef4444"><?= $currency . number_format((float)$donorStats['sum_balance']) ?></span>
            </div>
            <div class="rpt-bar"><div class="rpt-bar-fill" style="width:<?= $collectionRate ?>%;background:#10b981"></div></div>
            <div style="text-align:right;font-size:.6rem;color:var(--gray-500);margin-top:3px"><?= $collectionRate ?>% collected</div>
          </div>
          <div class="rpt-card">
            <div class="rpt-card-title" style="color:#8b5cf6"><i class="fas fa-users"></i> Donor Breakdown</div>
            <div class="rpt-row">
              <span class="rpt-row-label">Total Donors</span>
              <span class="rpt-row-val"><?= $grandDonors ?></span>
            </div>
            <div class="rpt-row">
              <span class="rpt-row-label">Pledge Donors</span>
              <span class="rpt-row-val" style="color:#3b82f6"><?= (int)($donorStats['pledge_donors'] ?? 0) ?></span>
            </div>
            <div class="rpt-row">
              <span class="rpt-row-label">Immediate Payment</span>
              <span class="rpt-row-val" style="color:#d97706"><?= (int)($donorStats['immediate_donors'] ?? 0) ?></span>
            </div>
          </div>
        </div>

        <!-- ═ Status Breakdown ═ -->
        <div class="rpt-statuses">
          <?php
          $stMap = [
              'no_pledge'   => (int)($donorStats['st_no_pledge'] ?? 0),
              'not_started' => (int)($donorStats['st_not_started'] ?? 0),
              'paying'      => (int)($donorStats['st_paying'] ?? 0),
              'overdue'     => (int)($donorStats['st_overdue'] ?? 0),
              'completed'   => (int)($donorStats['st_completed'] ?? 0),
              'defaulted'   => (int)($donorStats['st_defaulted'] ?? 0),
          ];
          foreach ($statusCfg as $key => $cfg):
              $cnt = $stMap[$key];
              $isAct = ($filterStatus === $key);
              $href = $isAct ? qsReplace(['status'=>'all','page'=>1]) : qsReplace(['status'=>$key,'page'=>1]);
          ?>
          <a href="<?= htmlspecialchars($href) ?>" class="rpt-st <?= $isAct?'act':'' ?>" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['c'] ?>">
            <div class="rpt-st-icon" style="background:<?= $cfg['c'] ?>15;color:<?= $cfg['c'] ?>"><i class="fas <?= $cfg['ic'] ?>"></i></div>
            <div>
              <div class="rpt-st-count"><?= $cnt ?></div>
              <div class="rpt-st-label"><?= $cfg['l'] ?></div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>

        <!-- ═ Filters ═ -->
        <form method="get" class="rpt-filters">
          <input type="hidden" name="id" value="<?= $memberId ?>">
          <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
          <div class="rpt-search">
            <i class="fas fa-search"></i>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search donor name or phone...">
          </div>
          <div class="rpt-sel-row">
            <select name="type" onchange="this.form.submit()">
              <option value="all" <?= $filterType==='all'?'selected':'' ?>>All Types</option>
              <option value="pledge" <?= $filterType==='pledge'?'selected':'' ?>>Pledge</option>
              <option value="immediate_payment" <?= $filterType==='immediate_payment'?'selected':'' ?>>Immediate</option>
            </select>
            <select name="sort" onchange="this.form.submit()">
              <option value="newest"       <?= $sort==='newest'?'selected':'' ?>>Newest</option>
              <option value="oldest"       <?= $sort==='oldest'?'selected':'' ?>>Oldest</option>
              <option value="name_asc"     <?= $sort==='name_asc'?'selected':'' ?>>Name A-Z</option>
              <option value="paid_desc"    <?= $sort==='paid_desc'?'selected':'' ?>>Most Paid</option>
              <option value="balance_desc" <?= $sort==='balance_desc'?'selected':'' ?>>Highest Balance</option>
            </select>
            <button type="submit"><i class="fas fa-filter me-1"></i>Filter</button>
          </div>
        </form>

        <!-- ═ Results ═ -->
        <div class="rpt-results-count">
          <?= $totalRows ?> donor<?= $totalRows !== 1 ? 's' : '' ?>
          <?php if ($filterStatus !== 'all' || $filterType !== 'all' || $search): ?>
            <a href="?id=<?= $memberId ?>" style="margin-left:6px;color:var(--danger,#ef4444);font-size:.7rem"><i class="fas fa-times"></i> Clear</a>
          <?php endif; ?>
        </div>

        <?php if (empty($donors)): ?>
          <div class="rpt-empty"><i class="fas fa-inbox"></i><p>No donors found.</p></div>
        <?php else: ?>
        <div class="rpt-donors">
          <?php foreach ($donors as $d):
              $pledged = (float)$d['total_pledged'];
              $paid    = (float)$d['total_paid'];
              $bal     = (float)$d['balance'];
              $pct     = $pledged > 0 ? min(100, round(($paid / $pledged) * 100)) : ($paid > 0 ? 100 : 0);
              $sc      = $statusCfg[$d['payment_status']] ?? $statusCfg['no_pledge'];
              $isP     = $d['donor_type'] === 'pledge';
              $barC    = $pct >= 100 ? '#10b981' : ($pct >= 50 ? '#3b82f6' : ($pct > 0 ? '#f59e0b' : '#e5e7eb'));
          ?>
          <a href="../donor-management/view-donor.php?id=<?= $d['id'] ?>" class="rpt-donor">
            <div class="rpt-d-top">
              <div>
                <div class="rpt-d-name"><?= htmlspecialchars($d['name']) ?></div>
                <div class="rpt-d-phone"><i class="fas fa-phone me-1"></i><?= htmlspecialchars($d['phone']) ?></div>
              </div>
              <div class="rpt-d-badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['c'] ?>">
                <i class="fas <?= $sc['ic'] ?>" style="font-size:.5rem"></i> <?= $sc['l'] ?>
              </div>
            </div>
            <div class="rpt-d-money">
              <div class="rpt-d-mi"><div class="v" style="color:#1a73e8"><?= $currency . number_format($pledged) ?></div><div class="l">Pledged</div></div>
              <div class="rpt-d-mi"><div class="v" style="color:#10b981"><?= $currency . number_format($paid) ?></div><div class="l">Paid</div></div>
              <div class="rpt-d-mi"><div class="v" style="color:<?= $bal > 0 ? '#ef4444' : '#10b981' ?>"><?= $currency . number_format($bal) ?></div><div class="l">Balance</div></div>
            </div>
            <?php if ($pledged > 0): ?>
            <div class="rpt-d-bar"><div class="rpt-d-bar-f" style="width:<?= $pct ?>%;background:<?= $barC ?>"></div></div>
            <?php endif; ?>
            <div class="rpt-d-foot">
              <div>
                <span class="rpt-d-type" style="background:<?= $isP ? '#eff6ff' : '#fef3c7' ?>;color:<?= $isP ? '#3b82f6' : '#d97706' ?>">
                  <?= $isP ? 'Pledge' : 'Immediate' ?>
                </span>
              </div>
              <div style="display:flex;align-items:center;gap:8px">
                <?php if ($pledged > 0): ?><span class="rpt-d-pct" style="color:<?= $barC ?>"><?= $pct ?>%</span><?php endif; ?>
                <span><?= $d['last_payment_date'] ? 'Paid: '.date('d M Y', strtotime($d['last_payment_date'])) : date('d M Y', strtotime($d['created_at'])) ?></span>
              </div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="rpt-pag">
          <a href="<?= htmlspecialchars(qsReplace(['page'=>max(1,$page-1)])) ?>" class="<?= $page<=1?'dis':'' ?>"><i class="fas fa-chevron-left"></i></a>
          <?php
          $s = max(1, $page - 2); $e = min($totalPages, $page + 2);
          if ($s > 1) echo '<a href="'.htmlspecialchars(qsReplace(['page'=>1])).'">1</a>';
          if ($s > 2) echo '<span class="dis">...</span>';
          for ($i = $s; $i <= $e; $i++):
          ?><a href="<?= htmlspecialchars(qsReplace(['page'=>$i])) ?>" class="<?= $i===$page?'act':'' ?>"><?= $i ?></a><?php
          endfor;
          if ($e < $totalPages-1) echo '<span class="dis">...</span>';
          if ($e < $totalPages) echo '<a href="'.htmlspecialchars(qsReplace(['page'=>$totalPages])).'">'.$totalPages.'</a>';
          ?>
          <a href="<?= htmlspecialchars(qsReplace(['page'=>min($totalPages,$page+1)])) ?>" class="<?= $page>=$totalPages?'dis':'' ?>"><i class="fas fa-chevron-right"></i></a>
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
