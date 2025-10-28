<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();

$database = db();

// Get all donors with their pledge and payment counts
$donors = $database->query("
    SELECT 
        d.id,
        d.name,
        d.phone,
        d.preferred_language,
        d.total_pledged,
        d.total_paid,
        d.balance,
        d.payment_status,
        d.achievement_badge,
        d.pledge_count,
        d.payment_count,
        d.created_at,
        d.last_payment_date,
        d.active_payment_plan_id,
        COALESCE((SELECT COUNT(*) FROM pledges WHERE donor_id = d.id), 0) as pledge_records,
        COALESCE((SELECT COUNT(*) FROM payments WHERE donor_id = d.id), 0) as payment_records
    FROM donors d
    ORDER BY d.balance DESC
")->fetch_all(MYSQLI_ASSOC);

// Get overall statistics
$stats = $database->query("
    SELECT 
        COUNT(*) as total_donors,
        COUNT(CASE WHEN total_pledged > 0 THEN 1 END) as donors_with_pledges,
        COUNT(CASE WHEN total_paid > 0 THEN 1 END) as donors_with_payments,
        COUNT(CASE WHEN payment_status = 'completed' THEN 1 END) as fully_paid_donors,
        SUM(total_pledged) as total_pledged_amount,
        SUM(total_paid) as total_paid_amount,
        SUM(balance) as total_outstanding_balance
    FROM donors
")->fetch_assoc() ?: [];

// Get status breakdown
$statusBreakdown = $database->query("
    SELECT payment_status, COUNT(*) as count 
    FROM donors 
    GROUP BY payment_status
")->fetch_all(MYSQLI_ASSOC);

// Get badge breakdown
$badgeBreakdown = $database->query("
    SELECT achievement_badge, COUNT(*) as count 
    FROM donors 
    GROUP BY achievement_badge
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Donors - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .stat-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 0.25rem; font-size: 0.875rem; font-weight: 600; }
        .status-badge { padding: 0.35rem 0.6rem; border-radius: 0.25rem; font-size: 0.8rem; font-weight: 600; }
        .progress-mini { height: 6px; margin-top: 0.25rem; }
        .donor-section { margin-bottom: 3rem; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 1.5rem; background: #fff; }
        .donor-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #f0f0f0; }
        .donor-title { font-size: 1.25rem; font-weight: 700; color: #1f2937; }
        .donor-meta { font-size: 0.9rem; color: #6b7280; margin-top: 0.25rem; }
        .pledges-table, .payments-table { font-size: 0.9rem; }
        .pledges-table th, .payments-table th { background: #f3f4f6; font-weight: 600; font-size: 0.85rem; }
        .amount-pledged { color: #3b82f6; font-weight: 600; }
        .amount-paid { color: #10b981; font-weight: 600; }
        .amount-outstanding { color: #ef4444; font-weight: 600; }
        .empty-state { text-align: center; padding: 2rem; color: #9ca3af; }
        .stat-card { background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); padding: 1rem; border-radius: 0.375rem; margin-bottom: 1rem; }
        .stat-card.primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .stat-card.success { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; }
        .stat-card.warning { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; }
        .stat-number { font-size: 1.75rem; font-weight: 700; }
        .stat-label { font-size: 0.85rem; font-weight: 500; margin-top: 0.5rem; opacity: 0.9; }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <h2 class="mb-4"><i class="fas fa-users"></i> Comprehensive Donor Data</h2>
                
                <!-- Key Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="stat-card primary">
                            <div class="stat-number"><?= $stats['total_donors'] ?? 0 ?></div>
                            <div class="stat-label">Total Donors</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card success">
                            <div class="stat-number">£<?= number_format($stats['total_pledged_amount'] ?? 0, 0) ?></div>
                            <div class="stat-label">Total Pledged</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card warning">
                            <div class="stat-number">£<?= number_format($stats['total_paid_amount'] ?? 0, 0) ?></div>
                            <div class="stat-label">Total Paid</div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <div style="font-size: 1.75rem; font-weight: 700; color: #ef4444;">£<?= number_format($stats['total_outstanding_balance'] ?? 0, 0) ?></div>
                                <div style="font-size: 0.85rem; font-weight: 500; margin-top: 0.5rem; color: #6b7280;">Outstanding Balance</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Status & Badge Breakdown -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Payment Status Breakdown</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm mb-0">
                                    <tbody>
                                        <?php foreach ($statusBreakdown as $s): ?>
                                        <tr>
                                            <td><strong><?= ucwords(str_replace('_', ' ', $s['payment_status'])) ?></strong></td>
                                            <td class="text-end"><span class="badge bg-info"><?= $s['count'] ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-star"></i> Achievement Badges</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm mb-0">
                                    <tbody>
                                        <?php foreach ($badgeBreakdown as $b): ?>
                                        <tr>
                                            <td><strong><?= ucfirst($b['achievement_badge'] ?? 'N/A') ?></strong></td>
                                            <td class="text-end"><span class="badge bg-warning"><?= $b['count'] ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Donor Sections -->
                <h3 class="mt-4 mb-3">Individual Donor Data</h3>
                <?php if (empty($donors)): ?>
                <div class="alert alert-info">No donors found in the database.</div>
                <?php else: ?>
                    <?php foreach ($donors as $donor): ?>
                    <div class="donor-section">
                        <!-- Donor Header -->
                        <div class="donor-header">
                            <div>
                                <div class="donor-title"><?= htmlspecialchars($donor['name']) ?></div>
                                <div class="donor-meta">
                                    <i class="fas fa-phone"></i> <?= htmlspecialchars($donor['phone']) ?>
                                    | <i class="fas fa-language"></i> <?= strtoupper($donor['preferred_language'] ?? 'EN') ?>
                                    | Registered: <?= date('d M Y', strtotime($donor['created_at'])) ?>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <span class="status-badge" style="background: <?= $donor['payment_status'] === 'completed' ? '#10b981' : ($donor['payment_status'] === 'paying' ? '#3b82f6' : '#ef4444'); color: white;">
                                    <?= ucwords(str_replace('_', ' ', $donor['payment_status'])) ?>
                                </span>
                                <br>
                                <span class="stat-badge" style="background: #f0ad4e; color: white; margin-top: 0.5rem;">
                                    <?= ucfirst($donor['achievement_badge'] ?? 'pending') ?>
                                </span>
                            </div>
                        </div>

                        <!-- Donor Financial Summary -->
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <div style="padding: 0.75rem; background: #f0f9ff; border-radius: 0.375rem; border-left: 3px solid #3b82f6;">
                                    <div style="font-size: 0.8rem; color: #6b7280;">Total Pledged</div>
                                    <div class="amount-pledged">£<?= number_format($donor['total_pledged'], 2) ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div style="padding: 0.75rem; background: #f0fdf4; border-radius: 0.375rem; border-left: 3px solid #10b981;">
                                    <div style="font-size: 0.8rem; color: #6b7280;">Total Paid</div>
                                    <div class="amount-paid">£<?= number_format($donor['total_paid'], 2) ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div style="padding: 0.75rem; background: #fef2f2; border-radius: 0.375rem; border-left: 3px solid #ef4444;">
                                    <div style="font-size: 0.8rem; color: #6b7280;">Balance</div>
                                    <div class="amount-outstanding">£<?= number_format($donor['balance'], 2) ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div style="padding: 0.75rem; background: #f5f5f5; border-radius: 0.375rem; border-left: 3px solid #6b7280;">
                                    <div style="font-size: 0.8rem; color: #6b7280;">Payment %</div>
                                    <div style="font-weight: 600; font-size: 1.1rem;">
                                        <?= $donor['total_pledged'] > 0 ? number_format(($donor['total_paid'] / $donor['total_pledged']) * 100, 1) : 0 ?>%
                                    </div>
                                    <div class="progress progress-mini">
                                        <div class="progress-bar" style="width: <?= $donor['total_pledged'] > 0 ? ($donor['total_paid'] / $donor['total_pledged']) * 100 : 0 ?>%; background: #3b82f6;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pledges Section -->
                        <div class="row">
                            <div class="col-md-6">
                                <h5 style="margin-top: 1.5rem; margin-bottom: 1rem; font-weight: 700; border-top: 2px solid #e5e7eb; padding-top: 1rem;">
                                    <i class="fas fa-handshake"></i> PLEDGES (<?= $donor['pledge_records'] ?>)
                                </h5>
                                <?php
                                $stmt = $database->prepare("SELECT id, amount, pledge_date, status, notes FROM pledges WHERE donor_id = ? ORDER BY pledge_date DESC");
                                $stmt->bind_param("i", $donor['id']);
                                $stmt->execute();
                                $pledgesResult = $stmt->get_result();
                                $pledgeRecords = $pledgesResult ? $pledgesResult->fetch_all(MYSQLI_ASSOC) : [];
                                ?>
                                <?php if (empty($pledgeRecords)): ?>
                                <div class="empty-state">No pledges found</div>
                                <?php else: ?>
                                <table class="table pledges-table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Amount</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pledgeRecords as $p): ?>
                                        <tr>
                                            <td class="amount-pledged">£<?= number_format($p['amount'], 2) ?></td>
                                            <td><small><?= date('d M Y', strtotime($p['pledge_date'])) ?></small></td>
                                            <td><small class="status-badge" style="background: #e5e7eb; color: #374151;"><?= ucfirst($p['status']) ?></small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>
                            </div>

                            <!-- Payments Section -->
                            <div class="col-md-6">
                                <h5 style="margin-top: 1.5rem; margin-bottom: 1rem; font-weight: 700; border-top: 2px solid #e5e7eb; padding-top: 1rem;">
                                    <i class="fas fa-credit-card"></i> PAYMENTS (<?= $donor['payment_records'] ?>)
                                </h5>
                                <?php
                                $stmt2 = $database->prepare("SELECT id, amount, payment_date, payment_method, status, pledge_id, installment_number FROM payments WHERE donor_id = ? ORDER BY payment_date DESC");
                                $stmt2->bind_param("i", $donor['id']);
                                $stmt2->execute();
                                $paymentsResult = $stmt2->get_result();
                                $paymentRecords = $paymentsResult ? $paymentsResult->fetch_all(MYSQLI_ASSOC) : [];
                                ?>
                                <?php if (empty($paymentRecords)): ?>
                                <div class="empty-state">No payments found</div>
                                <?php else: ?>
                                <table class="table payments-table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Amount</th>
                                            <th>Date</th>
                                            <th>Method</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($paymentRecords as $pay): ?>
                                        <tr>
                                            <td class="amount-paid">£<?= number_format($pay['amount'], 2) ?></td>
                                            <td><small><?= date('d M Y', strtotime($pay['payment_date'])) ?></small></td>
                                            <td><small><?= ucfirst(str_replace('_', ' ', $pay['payment_method'] ?? 'N/A')) ?></small></td>
                                            <td><small class="status-badge" style="background: <?= $pay['status'] === 'approved' ? '#d1fae5' : '#fecaca'; color: <?= $pay['status'] === 'approved' ? '#065f46' : '#7f1d1d'; ?>"><?= ucfirst($pay['status']) ?></small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Additional Info -->
                        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; font-size: 0.85rem; color: #6b7280;">
                            <?php if ($donor['last_payment_date']): ?>
                            <i class="fas fa-calendar"></i> Last Payment: <strong><?= date('d M Y', strtotime($donor['last_payment_date'])) ?></strong>
                            <?php endif; ?>
                            <?php if ($donor['active_payment_plan_id']): ?>
                            | <i class="fas fa-calendar-alt"></i> Active Payment Plan ID: <strong>#<?= $donor['active_payment_plan_id'] ?></strong>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>
