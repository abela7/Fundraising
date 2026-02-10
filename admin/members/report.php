<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$page_title = 'Registrar Report';
$db = db();
$currency = '£';

// ══════════════════════════════════════════════════════════════════
// LOAD ALL REGISTRARS / ADMINS
// ══════════════════════════════════════════════════════════════════
$users = [];
$uRes = $db->query("SELECT id, name, phone, email, role, active, created_at FROM users WHERE role IN ('admin','registrar') ORDER BY name");
if ($uRes) { while ($r = $uRes->fetch_assoc()) $users[] = $r; }

// ══════════════════════════════════════════════════════════════════
// PER-MEMBER STATS from source tables (pledges + payments)
// ══════════════════════════════════════════════════════════════════

// Pledge stats grouped by created_by_user_id
$pledgeByUser = [];
$pq = $db->query("
    SELECT created_by_user_id AS uid,
           COUNT(*)                                                  AS total,
           SUM(status='approved')                                    AS approved,
           SUM(status='pending')                                     AS pending,
           SUM(status='rejected')                                    AS rejected,
           COALESCE(SUM(amount),0)                                   AS total_amount,
           COALESCE(SUM(CASE WHEN status='approved' THEN amount ELSE 0 END),0) AS approved_amount,
           COALESCE(SUM(CASE WHEN status='pending'  THEN amount ELSE 0 END),0) AS pending_amount
    FROM pledges
    WHERE created_by_user_id IS NOT NULL
    GROUP BY created_by_user_id
");
if ($pq) { while ($r = $pq->fetch_assoc()) $pledgeByUser[(int)$r['uid']] = $r; }

// Payment stats grouped by received_by_user_id
$paymentByUser = [];
$pyq = $db->query("
    SELECT received_by_user_id AS uid,
           COUNT(*)                                                  AS total,
           SUM(status='approved')                                    AS approved,
           SUM(status='pending')                                     AS pending,
           SUM(status='voided')                                      AS voided,
           COALESCE(SUM(amount),0)                                   AS total_amount,
           COALESCE(SUM(CASE WHEN status='approved' THEN amount ELSE 0 END),0) AS approved_amount,
           COALESCE(SUM(CASE WHEN status='pending'  THEN amount ELSE 0 END),0) AS pending_amount
    FROM payments
    WHERE received_by_user_id IS NOT NULL
    GROUP BY received_by_user_id
");
if ($pyq) { while ($r = $pyq->fetch_assoc()) $paymentByUser[(int)$r['uid']] = $r; }

// Donor counts per member (via pledges + payments + registered_by)
$donorCountByUser = [];
$dcq = $db->query("
    SELECT uid, COUNT(DISTINCT donor_id) AS cnt FROM (
        SELECT created_by_user_id AS uid, donor_id FROM pledges WHERE created_by_user_id IS NOT NULL AND donor_id IS NOT NULL
        UNION ALL
        SELECT received_by_user_id AS uid, donor_id FROM payments WHERE received_by_user_id IS NOT NULL AND donor_id IS NOT NULL
        UNION ALL
        SELECT registered_by_user_id AS uid, id AS donor_id FROM donors WHERE registered_by_user_id IS NOT NULL
    ) t GROUP BY uid
");
if ($dcq) { while ($r = $dcq->fetch_assoc()) $donorCountByUser[(int)$r['uid']] = (int)$r['cnt']; }

// ══════════════════════════════════════════════════════════════════
// BUILD REGISTRAR DATA ARRAY
// ══════════════════════════════════════════════════════════════════
$registrars = [];
foreach ($users as $u) {
    $uid = (int)$u['id'];
    $pl  = $pledgeByUser[$uid]  ?? [];
    $py  = $paymentByUser[$uid] ?? [];

    $totalPledges       = (int)($pl['total'] ?? 0);
    $approvedPledges    = (int)($pl['approved'] ?? 0);
    $pendingPledges     = (int)($pl['pending'] ?? 0);
    $rejectedPledges    = (int)($pl['rejected'] ?? 0);
    $pledgeAmount       = (float)($pl['approved_amount'] ?? 0);
    $pledgePendingAmt   = (float)($pl['pending_amount'] ?? 0);

    $totalPayments      = (int)($py['total'] ?? 0);
    $approvedPayments   = (int)($py['approved'] ?? 0);
    $pendingPayments    = (int)($py['pending'] ?? 0);
    $voidedPayments     = (int)($py['voided'] ?? 0);
    $paymentAmount      = (float)($py['approved_amount'] ?? 0);
    $paymentPendingAmt  = (float)($py['pending_amount'] ?? 0);

    $totalLogged  = $totalPledges + $totalPayments;
    $totalApproved = $approvedPledges + $approvedPayments;
    $approvalRate = $totalLogged > 0 ? round(($totalApproved / $totalLogged) * 100) : 0;
    $donors       = $donorCountByUser[$uid] ?? 0;

    $registrars[] = [
        'id'               => $uid,
        'name'             => $u['name'],
        'phone'            => $u['phone'],
        'email'            => $u['email'],
        'role'             => $u['role'],
        'active'           => (int)$u['active'],
        'created_at'       => $u['created_at'],
        'donors'           => $donors,
        'total_logged'     => $totalLogged,
        'total_pledges'    => $totalPledges,
        'approved_pledges' => $approvedPledges,
        'pending_pledges'  => $pendingPledges,
        'rejected_pledges' => $rejectedPledges,
        'pledge_amount'    => $pledgeAmount,
        'pledge_pending'   => $pledgePendingAmt,
        'total_payments'   => $totalPayments,
        'approved_payments'=> $approvedPayments,
        'pending_payments' => $pendingPayments,
        'voided_payments'  => $voidedPayments,
        'payment_amount'   => $paymentAmount,
        'payment_pending'  => $paymentPendingAmt,
        'approval_rate'    => $approvalRate,
    ];
}

// ── Grand totals ──
$grandDonors        = array_sum(array_column($registrars, 'donors'));
$grandLogged        = array_sum(array_column($registrars, 'total_logged'));
$grandPledgeAmt     = array_sum(array_column($registrars, 'pledge_amount'));
$grandPaymentAmt    = array_sum(array_column($registrars, 'payment_amount'));
$grandApprovalRate  = $grandLogged > 0 ? round((array_sum(array_column($registrars, 'approved_pledges')) + array_sum(array_column($registrars, 'approved_payments'))) / $grandLogged * 100) : 0;
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
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="../assets/admin.css">
<link rel="stylesheet" href="../donor-management/assets/donor-management.css">
<style>
/* ── Registrar Report Extras ── */
.rr-hero {
    background: linear-gradient(135deg, var(--primary,#0a6286) 0%, #0b78a6 60%, rgba(226,202,24,.85) 100%);
    border-radius: 12px;
    padding: 20px;
    color: #fff;
    margin-bottom: 1.5rem;
}
.rr-hero-title { font-size: 1.15rem; font-weight: 800; }
.rr-hero-sub { font-size: .78rem; opacity: .8; }
.rr-hero-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-top: 14px;
}
.rr-hero-item {
    background: rgba(255,255,255,.15);
    backdrop-filter: blur(8px);
    border-radius: 10px;
    padding: 12px;
    text-align: center;
}
.rr-hero-item .hv { font-size: 1.35rem; font-weight: 800; line-height: 1; }
.rr-hero-item .hl { font-size: .6rem; text-transform: uppercase; letter-spacing: .5px; opacity: .8; margin-top: 2px; }

/* ── Registrar Cards (mobile view) ── */
.rr-cards { display: flex; flex-direction: column; gap: 12px; }
.rr-card {
    background: #fff; border-radius: 12px; padding: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08); border: 1px solid #dee2e6;
    border-top: 3px solid var(--primary,#0a6286);
    transition: transform .2s, box-shadow .2s; text-decoration: none; color: inherit; display: block;
}
.rr-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,.12); color: inherit; }
.rr-card-head {
    display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;
}
.rr-card-name { font-weight: 700; font-size: .95rem; line-height: 1.2; }
.rr-card-meta { font-size: .7rem; color: var(--gray-500,#6b7280); margin-top: 2px; }
.rr-card-tags { display: flex; gap: 4px; margin-top: 5px; flex-wrap: wrap; }
.rr-tag {
    font-size: .58rem; font-weight: 600; padding: 2px 6px; border-radius: 4px;
    display: inline-flex; align-items: center; gap: 3px;
}
.rr-card-stats {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 12px;
}
.rr-cs {
    text-align: center; padding: 8px 4px; border-radius: 8px; background: var(--gray-50,#f9fafb);
}
.rr-cs .cv { font-size: .9rem; font-weight: 800; line-height: 1; }
.rr-cs .cl { font-size: .52rem; text-transform: uppercase; color: var(--gray-500,#6b7280); margin-top: 2px; letter-spacing: .3px; }
.rr-card-breakdown {
    display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
}
.rr-bd {
    padding: 8px 10px; border-radius: 8px;
}
.rr-bd-title { font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; margin-bottom: 6px; display: flex; align-items: center; gap: 4px; }
.rr-bd-row { display: flex; justify-content: space-between; font-size: .7rem; padding: 2px 0; }
.rr-bd-row + .rr-bd-row { border-top: 1px solid rgba(0,0,0,.05); }
.rr-bd-val { font-weight: 700; }
.rr-card-bar { height: 5px; background: var(--gray-200,#e5e7eb); border-radius: 3px; margin-top: 10px; overflow: hidden; }
.rr-card-bar-fill { height: 100%; border-radius: 3px; }
.rr-card-foot { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; font-size: .65rem; color: var(--gray-400,#9ca3af); }

/* ── Table View (desktop) ── */
.rr-table-wrap { display: none; }

/* ── Filter bar ── */
.rr-filter-bar {
    background: #fff; border-radius: 12px; padding: 14px; margin-bottom: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,.08); border: 1px solid #dee2e6;
}

/* ── Responsive ── */
@media (min-width: 576px) {
    .rr-hero-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (min-width: 768px) {
    .rr-hero-grid { grid-template-columns: repeat(5, 1fr); }
    .rr-cards { display: grid; grid-template-columns: repeat(2, 1fr); }
}
@media (min-width: 992px) {
    .rr-cards { display: none; }
    .rr-table-wrap { display: block; }
}
@media (min-width: 1200px) {
    .rr-hero-item .hv { font-size: 1.5rem; }
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

                <!-- ═ Hero Banner ═ -->
                <div class="rr-hero">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <div class="rr-hero-title"><i class="fas fa-chart-bar me-2"></i>Registrar Performance Report</div>
                            <div class="rr-hero-sub">All registrars &amp; admins — real data from pledges &amp; payments</div>
                        </div>
                        <a href="./" class="btn btn-sm btn-light" style="font-size:.72rem"><i class="fas fa-arrow-left me-1"></i>Members</a>
                    </div>
                    <div class="rr-hero-grid">
                        <div class="rr-hero-item">
                            <div class="hv"><?= count($registrars) ?></div>
                            <div class="hl">Registrars</div>
                        </div>
                        <div class="rr-hero-item">
                            <div class="hv"><?= number_format($grandDonors) ?></div>
                            <div class="hl">Total Donors</div>
                        </div>
                        <div class="rr-hero-item">
                            <div class="hv"><?= number_format($grandLogged) ?></div>
                            <div class="hl">Total Logged</div>
                        </div>
                        <div class="rr-hero-item">
                            <div class="hv"><?= $currency . number_format($grandPledgeAmt) ?></div>
                            <div class="hl">Pledge Value</div>
                        </div>
                        <div class="rr-hero-item">
                            <div class="hv"><?= $currency . number_format($grandPaymentAmt) ?></div>
                            <div class="hl">Payment Value</div>
                        </div>
                    </div>
                </div>

                <!-- ═ Stats Cards ═ -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card" style="color: #0a6286;">
                            <div class="stat-icon bg-primary"><i class="fas fa-users"></i></div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?= count($registrars) ?></h3>
                                <p class="stat-label">Registrars</p>
                                <div class="stat-trend text-muted"><i class="fas fa-user-tie"></i> Active team</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card" style="color: #b88a1a;">
                            <div class="stat-icon bg-warning"><i class="fas fa-hand-holding-heart"></i></div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?= $currency . number_format($grandPledgeAmt) ?></h3>
                                <p class="stat-label">Approved Pledges</p>
                                <div class="stat-trend text-warning"><i class="fas fa-check"></i> Value confirmed</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card" style="color: #0d7f4d;">
                            <div class="stat-icon bg-success"><i class="fas fa-money-bill-wave"></i></div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?= $currency . number_format($grandPaymentAmt) ?></h3>
                                <p class="stat-label">Approved Payments</p>
                                <div class="stat-trend text-success"><i class="fas fa-check-double"></i> Cash received</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card" style="color: #7c3aed;">
                            <div class="stat-icon" style="background:#7c3aed"><i class="fas fa-percentage"></i></div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?= $grandApprovalRate ?>%</h3>
                                <p class="stat-label">Approval Rate</p>
                                <div class="stat-trend" style="color:#7c3aed"><i class="fas fa-chart-line"></i> Overall</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═ Filter Bar ═ -->
                <div class="rr-filter-bar">
                    <div class="d-flex flex-column flex-md-row gap-2 align-items-md-center">
                        <div class="flex-grow-1">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" id="rrSearch" placeholder="Search registrar by name or phone...">
                            </div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <select class="form-select form-select-sm" id="rrRoleFilter" style="width:auto">
                                <option value="">All Roles</option>
                                <option value="admin">Admin</option>
                                <option value="registrar">Registrar</option>
                            </select>
                            <select class="form-select form-select-sm" id="rrSortBy" style="width:auto">
                                <option value="donors_desc">Most Donors</option>
                                <option value="logged_desc">Most Logged</option>
                                <option value="pledge_desc">Highest Pledge</option>
                                <option value="payment_desc">Highest Payment</option>
                                <option value="rate_desc">Best Approval</option>
                                <option value="name_asc">Name A-Z</option>
                            </select>
                            <select class="form-select form-select-sm" id="rrActiveFilter" style="width:auto">
                                <option value="">All Status</option>
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ═ Mobile Card View ═ -->
                <div class="rr-cards" id="rrCards">
                    <?php foreach ($registrars as $r): ?>
                    <a href="view.php?id=<?= $r['id'] ?>" class="rr-card"
                       data-name="<?= htmlspecialchars(strtolower($r['name'])) ?>"
                       data-phone="<?= htmlspecialchars($r['phone'] ?? '') ?>"
                       data-role="<?= $r['role'] ?>"
                       data-active="<?= $r['active'] ?>"
                       data-donors="<?= $r['donors'] ?>"
                       data-logged="<?= $r['total_logged'] ?>"
                       data-pledge="<?= $r['pledge_amount'] ?>"
                       data-payment="<?= $r['payment_amount'] ?>"
                       data-rate="<?= $r['approval_rate'] ?>">
                        <div class="rr-card-head">
                            <div>
                                <div class="rr-card-name"><?= htmlspecialchars($r['name']) ?></div>
                                <div class="rr-card-meta">
                                    <?php if ($r['phone']): ?><i class="fas fa-phone"></i> <?= htmlspecialchars($r['phone']) ?><?php endif; ?>
                                </div>
                                <div class="rr-card-tags">
                                    <span class="rr-tag" style="background:<?= $r['role']==='admin'?'#eff6ff':'#f0fdf4' ?>;color:<?= $r['role']==='admin'?'#3b82f6':'#16a34a' ?>">
                                        <?= ucfirst($r['role']) ?>
                                    </span>
                                    <span class="rr-tag" style="background:<?= $r['active']?'#ecfdf5':'#fef2f2' ?>;color:<?= $r['active']?'#10b981':'#ef4444' ?>">
                                        <?= $r['active']?'Active':'Inactive' ?>
                                    </span>
                                </div>
                            </div>
                            <div style="text-align:right">
                                <div style="font-size:1.2rem;font-weight:800;color:var(--primary,#0a6286)"><?= $r['donors'] ?></div>
                                <div style="font-size:.55rem;text-transform:uppercase;color:var(--gray-500);letter-spacing:.3px">Donors</div>
                            </div>
                        </div>

                        <div class="rr-card-stats">
                            <div class="rr-cs"><div class="cv"><?= $r['total_logged'] ?></div><div class="cl">Logged</div></div>
                            <div class="rr-cs"><div class="cv" style="color:#10b981"><?= $r['approved_pledges'] + $r['approved_payments'] ?></div><div class="cl">Approved</div></div>
                            <div class="rr-cs"><div class="cv" style="color:#f59e0b"><?= $r['pending_pledges'] + $r['pending_payments'] ?></div><div class="cl">Pending</div></div>
                            <div class="rr-cs"><div class="cv" style="color:#7c3aed"><?= $r['approval_rate'] ?>%</div><div class="cl">Rate</div></div>
                        </div>

                        <div class="rr-card-breakdown">
                            <div class="rr-bd" style="background:#eff6ff">
                                <div class="rr-bd-title" style="color:#3b82f6"><i class="fas fa-hand-holding-heart"></i> Pledges</div>
                                <div class="rr-bd-row"><span>Approved</span><span class="rr-bd-val" style="color:#10b981"><?= $r['approved_pledges'] ?> · <?= $currency . number_format($r['pledge_amount']) ?></span></div>
                                <div class="rr-bd-row"><span>Pending</span><span class="rr-bd-val" style="color:#f59e0b"><?= $r['pending_pledges'] ?> · <?= $currency . number_format($r['pledge_pending']) ?></span></div>
                                <div class="rr-bd-row"><span>Rejected</span><span class="rr-bd-val" style="color:#ef4444"><?= $r['rejected_pledges'] ?></span></div>
                            </div>
                            <div class="rr-bd" style="background:#ecfdf5">
                                <div class="rr-bd-title" style="color:#10b981"><i class="fas fa-money-bill-wave"></i> Payments</div>
                                <div class="rr-bd-row"><span>Approved</span><span class="rr-bd-val" style="color:#10b981"><?= $r['approved_payments'] ?> · <?= $currency . number_format($r['payment_amount']) ?></span></div>
                                <div class="rr-bd-row"><span>Pending</span><span class="rr-bd-val" style="color:#f59e0b"><?= $r['pending_payments'] ?> · <?= $currency . number_format($r['payment_pending']) ?></span></div>
                                <div class="rr-bd-row"><span>Voided</span><span class="rr-bd-val" style="color:#6b7280"><?= $r['voided_payments'] ?></span></div>
                            </div>
                        </div>

                        <?php $barPct = $r['approval_rate']; $barC = $barPct >= 80 ? '#10b981' : ($barPct >= 50 ? '#3b82f6' : ($barPct > 0 ? '#f59e0b' : '#e5e7eb')); ?>
                        <div class="rr-card-bar"><div class="rr-card-bar-fill" style="width:<?= $barPct ?>%;background:<?= $barC ?>"></div></div>
                        <div class="rr-card-foot">
                            <span>Approval: <?= $barPct ?>%</span>
                            <span>Joined <?= date('d M Y', strtotime($r['created_at'])) ?></span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- ═ Desktop Table View ═ -->
                <div class="rr-table-wrap">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white border-bottom">
                            <h5 class="mb-0"><i class="fas fa-table me-2 text-primary"></i>All Registrars</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table id="rrTable" class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Registrar</th>
                                            <th>Role</th>
                                            <th>Donors</th>
                                            <th>Pledges</th>
                                            <th>Pledge Value</th>
                                            <th>Payments</th>
                                            <th>Payment Value</th>
                                            <th>Pending</th>
                                            <th>Rate</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i=1; foreach ($registrars as $r): ?>
                                        <tr data-role="<?= $r['role'] ?>" data-active="<?= $r['active'] ?>">
                                            <td class="text-muted fw-bold"><?= $i++ ?></td>
                                            <td>
                                                <div class="fw-bold"><?= htmlspecialchars($r['name']) ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($r['phone'] ?? '') ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $r['role']==='admin'?'primary':'success' ?>"><?= ucfirst($r['role']) ?></span>
                                                <?php if (!$r['active']): ?><span class="badge bg-secondary ms-1">Inactive</span><?php endif; ?>
                                            </td>
                                            <td><strong><?= $r['donors'] ?></strong></td>
                                            <td>
                                                <span class="text-success fw-bold"><?= $r['approved_pledges'] ?></span>
                                                <?php if ($r['pending_pledges']): ?><span class="text-warning ms-1">(+<?= $r['pending_pledges'] ?>)</span><?php endif; ?>
                                                / <?= $r['total_pledges'] ?>
                                            </td>
                                            <td class="fw-bold" style="color:#3b82f6"><?= $currency . number_format($r['pledge_amount']) ?></td>
                                            <td>
                                                <span class="text-success fw-bold"><?= $r['approved_payments'] ?></span>
                                                <?php if ($r['pending_payments']): ?><span class="text-warning ms-1">(+<?= $r['pending_payments'] ?>)</span><?php endif; ?>
                                                / <?= $r['total_payments'] ?>
                                            </td>
                                            <td class="fw-bold" style="color:#10b981"><?= $currency . number_format($r['payment_amount']) ?></td>
                                            <td>
                                                <?php $totalPending = $r['pending_pledges'] + $r['pending_payments']; ?>
                                                <?php if ($totalPending > 0): ?>
                                                    <span class="badge bg-warning text-dark"><?= $totalPending ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $rateColor = $r['approval_rate'] >= 80 ? 'success' : ($r['approval_rate'] >= 50 ? 'primary' : 'warning');
                                                ?>
                                                <span class="badge bg-<?= $rateColor ?>"><?= $r['approval_rate'] ?>%</span>
                                            </td>
                                            <td>
                                                <a href="view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye me-1"></i>View
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
$(function() {
    // DataTable for desktop
    const table = $('#rrTable').DataTable({
        order: [[3, 'desc']],
        pageLength: 25,
        lengthMenu: [[25, 50, -1], [25, 50, "All"]],
        language: { search: "", lengthMenu: "Show _MENU_" },
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
        initComplete: function() { $('.dataTables_filter').hide(); }
    });

    // ── Filters (work on both cards + table) ──
    function applyFilters() {
        const q      = $('#rrSearch').val().toLowerCase();
        const role   = $('#rrRoleFilter').val();
        const active = $('#rrActiveFilter').val();
        const sort   = $('#rrSortBy').val();

        // Cards
        const $cards = $('#rrCards .rr-card');
        $cards.each(function() {
            const $c = $(this);
            const name  = $c.data('name') || '';
            const phone = ($c.data('phone') || '').toString().toLowerCase();
            const r     = $c.data('role');
            const a     = ($c.data('active') || 0).toString();

            let show = true;
            if (q && !name.includes(q) && !phone.includes(q)) show = false;
            if (role && r !== role) show = false;
            if (active !== '' && a !== active) show = false;

            $c.toggle(show);
        });

        // Sort cards
        const sortKey = sort.split('_')[0];
        const sortDir = sort.split('_')[1] === 'asc' ? 1 : -1;
        const dataAttr = { donors:'donors', logged:'logged', pledge:'pledge', payment:'payment', rate:'rate', name:'name' }[sortKey] || 'donors';

        const sorted = $cards.toArray().sort(function(a, b) {
            let va = $(a).data(dataAttr);
            let vb = $(b).data(dataAttr);
            if (dataAttr === 'name') return sortDir * (va || '').localeCompare(vb || '');
            return sortDir * (parseFloat(vb || 0) - parseFloat(va || 0));
        });
        $('#rrCards').append(sorted);

        // Table
        $.fn.dataTable.ext.search.length = 0;
        $.fn.dataTable.ext.search.push(function(settings, data, idx) {
            if (settings.sTableId !== 'rrTable') return true;
            const $row = $(settings.aoData[idx].nTr);
            if (role && $row.data('role') !== role) return false;
            if (active !== '' && ($row.data('active') || 0).toString() !== active) return false;
            return true;
        });
        table.search(q).draw();
    }

    $('#rrSearch, #rrRoleFilter, #rrSortBy, #rrActiveFilter').on('input change', applyFilters);
});

if (typeof window.toggleSidebar !== 'function') {
  window.toggleSidebar = function() {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.querySelector('.sidebar-overlay');
    if (window.innerWidth <= 991.98) {
      if (sidebar) sidebar.classList.toggle('active');
      if (overlay) overlay.classList.toggle('active');
      document.body.style.overflow = (sidebar && sidebar.classList.contains('active')) ? 'hidden' : '';
    } else {
      document.body.classList.toggle('sidebar-collapsed');
    }
  };
}
</script>
</body>
</html>
