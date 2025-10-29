<?php
declare(strict_types=1);

set_time_limit(60); // Prevent timeout
ini_set('max_execution_time', 60);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();

$database = db();

// ==== COMBINED QUERY - Get all metrics at once ====
$metrics = $database->query("SELECT 
    COUNT(DISTINCT d.id) as total_unique_donors,
    COUNT(DISTINCT CASE WHEN d.name IS NOT NULL AND d.name != '' THEN d.id END) as donors_with_name,
    COUNT(DISTINCT CASE WHEN d.name IS NULL OR d.name = '' THEN d.id END) as donors_without_name,
    COUNT(DISTINCT CASE WHEN d.phone IS NOT NULL AND d.phone != '' THEN d.id END) as donors_with_phone,
    COUNT(DISTINCT CASE WHEN d.phone IS NULL OR d.phone = '' THEN d.id END) as donors_without_phone,
    COUNT(DISTINCT CASE WHEN (d.name IS NOT NULL AND d.name != '') AND (d.phone IS NOT NULL AND d.phone != '') THEN d.id END) as donors_with_complete_contact,
    COUNT(DISTINCT CASE WHEN (d.name IS NULL OR d.name = '') OR (d.phone IS NULL OR d.phone = '') THEN d.id END) as donors_without_complete_contact,
    COUNT(DISTINCT CASE WHEN d.total_pledged > 0 THEN d.id END) as donors_with_pledges,
    COUNT(DISTINCT CASE WHEN d.total_paid > 0 THEN d.id END) as donors_with_payments,
    COUNT(DISTINCT CASE WHEN d.preferred_language IS NOT NULL AND d.preferred_language != '' THEN d.id END) as donors_with_language,
    COUNT(DISTINCT CASE WHEN d.preferred_payment_method IS NOT NULL AND d.preferred_payment_method != '' THEN d.id END) as donors_with_payment_method,
    SUM(COALESCE(d.total_pledged, 0)) as total_pledged_all,
    SUM(COALESCE(d.total_paid, 0)) as total_paid_all,
    SUM(COALESCE(d.balance, 0)) as total_balance_all
FROM donors d")->fetch_assoc() ?: [];

// ==== PLEDGES ANALYSIS ====
$pledgesMetrics = $database->query("SELECT 
    COUNT(*) as total_pledges,
    COUNT(CASE WHEN status = 'approved' THEN 1 END) as pledges_approved,
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pledges_pending,
    COUNT(CASE WHEN donor_id IS NOT NULL THEN 1 END) as pledges_with_donor_id,
    COUNT(CASE WHEN donor_id IS NULL THEN 1 END) as pledges_missing_donor_id,
    SUM(COALESCE(amount, 0)) as pledges_total_amount
FROM pledges")->fetch_assoc() ?: [];

// ==== PAYMENTS ANALYSIS ====
$paymentsMetrics = $database->query("SELECT 
    COUNT(*) as total_payments,
    COUNT(CASE WHEN status = 'approved' THEN 1 END) as payments_approved,
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as payments_pending,
    COUNT(CASE WHEN donor_id IS NOT NULL THEN 1 END) as payments_with_donor_id,
    COUNT(CASE WHEN donor_id IS NULL THEN 1 END) as payments_missing_donor_id,
    COUNT(CASE WHEN method = 'cash' THEN 1 END) as payments_cash,
    COUNT(CASE WHEN method = 'card' THEN 1 END) as payments_card,
    COUNT(CASE WHEN method = 'bank' THEN 1 END) as payments_bank,
    COUNT(CASE WHEN method = 'other' THEN 1 END) as payments_other,
    SUM(COALESCE(amount, 0)) as payments_total_amount
FROM payments")->fetch_assoc() ?: [];

$currency = '£';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Clarity Report - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .metric-box { min-height: 120px; border-left: 4px solid; }
        .metric-box.complete { border-left-color: #10b981; }
        .metric-box.incomplete { border-left-color: #f59e0b; }
        .metric-box.danger { border-left-color: #ef4444; }
        .metric-box.info { border-left-color: #3b82f6; }
        .progress-bar-custom { height: 25px; font-weight: bold; }
        .clarity-percentage { font-size: 2rem; font-weight: bold; }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <h2 class="mb-4"><i class="fas fa-chart-pie"></i> Data Clarity Report</h2>

                <!-- ===== OVERALL DONORS METRICS ===== -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-users"></i> Overall Donor Data Quality</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <div class="metric-box complete p-3 rounded">
                                    <small class="text-muted">Total Donors</small>
                                    <h3><?= number_format($metrics['total_unique_donors'] ?? 0) ?></h3>
                                    <small class="d-block mt-2">
                                        <i class="fas fa-check-circle text-success"></i> Registered
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="metric-box complete p-3 rounded">
                                    <small class="text-muted">With Pledges</small>
                                    <h3><?= number_format($metrics['donors_with_pledges'] ?? 0) ?></h3>
                                    <small class="d-block mt-2">
                                        <?php 
                                            $pledgePercent = ($metrics['total_unique_donors'] > 0) ? 
                                                round(($metrics['donors_with_pledges'] / $metrics['total_unique_donors']) * 100, 1) : 0;
                                            echo "<strong>{$pledgePercent}%</strong> of total";
                                        ?>
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="metric-box complete p-3 rounded">
                                    <small class="text-muted">With Payments</small>
                                    <h3><?= number_format($metrics['donors_with_payments'] ?? 0) ?></h3>
                                    <small class="d-block mt-2">
                                        <?php 
                                            $paymentPercent = ($metrics['total_unique_donors'] > 0) ? 
                                                round(($metrics['donors_with_payments'] / $metrics['total_unique_donors']) * 100, 1) : 0;
                                            echo "<strong>{$paymentPercent}%</strong> of total";
                                        ?>
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="metric-box info p-3 rounded">
                                    <small class="text-muted">With Both</small>
                                    <h3><?= number_format($metrics['donors_with_pledges'] + $metrics['donors_with_payments'] > 0 ? 
                                        count(array_filter(array_map(function($i) use ($metrics) {
                                            return ($metrics['total_pledged_all'] > 0 && $metrics['total_paid_all'] > 0) ? 1 : 0;
                                        }, range(1, 1)))) : 0) ?></h3>
                                    <small class="d-block mt-2">Pledges + Payments</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== CONTACT INFORMATION CLARITY ===== -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-address-card"></i> Contact Information Clarity</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- NAME DATA -->
                            <div class="col-md-6 mb-4">
                                <h6 class="mb-3"><i class="fas fa-user"></i> Name Field</h6>
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between mb-1">
                                        <strong>Have Names:</strong>
                                        <span class="badge bg-success"><?= number_format($metrics['donors_with_name'] ?? 0) ?></span>
                                    </div>
                                    <div class="progress" role="progressbar" style="height: 30px;">
                                        <?php 
                                            $namePercent = ($metrics['total_unique_donors'] > 0) ? 
                                                round(($metrics['donors_with_name'] / $metrics['total_unique_donors']) * 100, 1) : 0;
                                        ?>
                                        <div class="progress-bar progress-bar-custom bg-success" style="width: <?= $namePercent ?>%">
                                            <?= $namePercent ?>%
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between mb-1">
                                        <strong>Missing Names:</strong>
                                        <span class="badge bg-danger"><?= number_format($metrics['donors_without_name'] ?? 0) ?></span>
                                    </div>
                                    <div class="progress" role="progressbar" style="height: 30px;">
                                        <?php 
                                            $namePercentMissing = 100 - $namePercent;
                                        ?>
                                        <div class="progress-bar progress-bar-custom bg-danger" style="width: <?= $namePercentMissing ?>%">
                                            <?= $namePercentMissing ?>%
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- PHONE DATA -->
                            <div class="col-md-6 mb-4">
                                <h6 class="mb-3"><i class="fas fa-phone"></i> Phone Field</h6>
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between mb-1">
                                        <strong>Have Phone:</strong>
                                        <span class="badge bg-success"><?= number_format($metrics['donors_with_phone'] ?? 0) ?></span>
                                    </div>
                                    <div class="progress" role="progressbar" style="height: 30px;">
                                        <?php 
                                            $phonePercent = ($metrics['total_unique_donors'] > 0) ? 
                                                round(($metrics['donors_with_phone'] / $metrics['total_unique_donors']) * 100, 1) : 0;
                                        ?>
                                        <div class="progress-bar progress-bar-custom bg-success" style="width: <?= $phonePercent ?>%">
                                            <?= $phonePercent ?>%
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between mb-1">
                                        <strong>Missing Phone:</strong>
                                        <span class="badge bg-danger"><?= number_format($metrics['donors_without_phone'] ?? 0) ?></span>
                                    </div>
                                    <div class="progress" role="progressbar" style="height: 30px;">
                                        <?php 
                                            $phonePercentMissing = 100 - $phonePercent;
                                        ?>
                                        <div class="progress-bar progress-bar-custom bg-danger" style="width: <?= $phonePercentMissing ?>%">
                                            <?= $phonePercentMissing ?>%
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- COMPLETE CONTACT -->
                            <div class="col-md-6">
                                <h6 class="mb-3"><i class="fas fa-check-double"></i> Complete Contact Info (Name + Phone)</h6>
                                <div class="alert alert-info mb-0">
                                    <h4 class="clarity-percentage text-info">
                                        <?php 
                                            $completePercent = ($metrics['total_unique_donors'] > 0) ? 
                                                round(($metrics['donors_with_complete_contact'] / $metrics['total_unique_donors']) * 100, 1) : 0;
                                            echo $completePercent . '%';
                                        ?>
                                    </h4>
                                    <small>
                                        <strong><?= number_format($metrics['donors_with_complete_contact'] ?? 0) ?></strong> have both name and phone<br>
                                        <strong><?= number_format($metrics['donors_without_complete_contact'] ?? 0) ?></strong> missing one or both
                                    </small>
                                </div>
                            </div>

                            <!-- LANGUAGE & PAYMENT METHOD -->
                            <div class="col-md-6">
                                <h6 class="mb-3"><i class="fas fa-cogs"></i> Preferences</h6>
                                <small class="d-block mb-2">
                                    <strong>Language Preference:</strong> <?= number_format($metrics['donors_with_language'] ?? 0) ?> / <?= number_format($metrics['total_unique_donors'] ?? 0) ?>
                                    (<?php echo ($metrics['total_unique_donors'] > 0) ? round(($metrics['donors_with_language'] / $metrics['total_unique_donors']) * 100, 1) : 0 ?>%)
                                </small>
                                <small class="d-block">
                                    <strong>Payment Method:</strong> <?= number_format($metrics['donors_with_payment_method'] ?? 0) ?> / <?= number_format($metrics['total_unique_donors'] ?? 0) ?>
                                    (<?php echo ($metrics['total_unique_donors'] > 0) ? round(($metrics['donors_with_payment_method'] / $metrics['total_unique_donors']) * 100, 1) : 0 ?>%)
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== PLEDGES ANALYSIS ===== -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-handshake"></i> Pledges Data Analysis</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <div class="metric-box info p-3 rounded">
                                    <small class="text-muted">Total Pledges</small>
                                    <h3><?= number_format($pledgesMetrics['total_pledges'] ?? 0) ?></h3>
                                    <small class="d-block mt-2">Records in pledges table</small>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="metric-box complete p-3 rounded">
                                    <small class="text-muted">Approved</small>
                                    <h3><?= number_format($pledgesMetrics['pledges_approved'] ?? 0) ?></h3>
                                    <small class="d-block mt-2 text-success"><strong>✓ Ready</strong></small>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="metric-box incomplete p-3 rounded">
                                    <small class="text-muted">Pending</small>
                                    <h3><?= number_format($pledgesMetrics['pledges_pending'] ?? 0) ?></h3>
                                    <small class="d-block mt-2 text-warning"><strong>⏳ Awaiting</strong></small>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="metric-box info p-3 rounded">
                                    <small class="text-muted">Total Amount</small>
                                    <h3><?= $currency . number_format($pledgesMetrics['pledges_total_amount'] ?? 0, 0) ?></h3>
                                    <small class="d-block mt-2">Total pledged</small>
                                </div>
                            </div>
                        </div>

                        <!-- Pledge Linking -->
                        <div class="alert alert-warning mt-3 mb-0">
                            <i class="fas fa-link"></i> <strong>Data Linking:</strong>
                            <?= number_format($pledgesMetrics['pledges_with_donor_id'] ?? 0) ?> pledges linked to donors |
                            <?= number_format($pledgesMetrics['pledges_missing_donor_id'] ?? 0) ?> pledges <strong>NOT linked</strong>
                            (<?php 
                                $pledgeLinkPercent = ($pledgesMetrics['total_pledges'] > 0) ? 
                                    round(($pledgesMetrics['pledges_with_donor_id'] / $pledgesMetrics['total_pledges']) * 100, 1) : 0;
                                echo $pledgeLinkPercent . '% linked';
                            ?>)
                        </div>
                    </div>
                </div>

                <!-- ===== PAYMENTS ANALYSIS ===== -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-money-bill"></i> Payments Data Analysis</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <div class="metric-box info p-3 rounded">
                                    <small class="text-muted">Total Payments</small>
                                    <h3><?= number_format($paymentsMetrics['total_payments'] ?? 0) ?></h3>
                                    <small class="d-block mt-2">Records in payments table</small>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="metric-box complete p-3 rounded">
                                    <small class="text-muted">Approved</small>
                                    <h3><?= number_format($paymentsMetrics['payments_approved'] ?? 0) ?></h3>
                                    <small class="d-block mt-2 text-success"><strong>✓ Confirmed</strong></small>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="metric-box incomplete p-3 rounded">
                                    <small class="text-muted">Pending</small>
                                    <h3><?= number_format($paymentsMetrics['payments_pending'] ?? 0) ?></h3>
                                    <small class="d-block mt-2 text-warning"><strong>⏳ Processing</strong></small>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="metric-box info p-3 rounded">
                                    <small class="text-muted">Total Amount</small>
                                    <h3><?= $currency . number_format($paymentsMetrics['payments_total_amount'] ?? 0, 0) ?></h3>
                                    <small class="d-block mt-2">Total paid</small>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Methods -->
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <h6>Payment Methods</h6>
                                <ul class="list-unstyled">
                                    <li><i class="fas fa-coins"></i> <strong>Cash:</strong> <?= number_format($paymentsMetrics['payments_cash'] ?? 0) ?></li>
                                    <li><i class="fas fa-credit-card"></i> <strong>Card:</strong> <?= number_format($paymentsMetrics['payments_card'] ?? 0) ?></li>
                                    <li><i class="fas fa-university"></i> <strong>Bank:</strong> <?= number_format($paymentsMetrics['payments_bank'] ?? 0) ?></li>
                                    <li><i class="fas fa-ellipsis-h"></i> <strong>Other:</strong> <?= number_format($paymentsMetrics['payments_other'] ?? 0) ?></li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <!-- Payment Linking -->
                                <div class="alert alert-warning mb-0">
                                    <i class="fas fa-link"></i> <strong>Data Linking:</strong>
                                    <?= number_format($paymentsMetrics['payments_with_donor_id'] ?? 0) ?> payments linked to donors |
                                    <?= number_format($paymentsMetrics['payments_missing_donor_id'] ?? 0) ?> payments <strong>NOT linked</strong>
                                    (<?php 
                                        $paymentLinkPercent = ($paymentsMetrics['total_payments'] > 0) ? 
                                            round(($paymentsMetrics['payments_with_donor_id'] / $paymentsMetrics['total_payments']) * 100, 1) : 0;
                                        echo $paymentLinkPercent . '% linked';
                                    ?>)
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== DATA QUALITY SUMMARY ===== -->
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Data Quality Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <tbody>
                                    <tr>
                                        <td><strong>Total Donor Records</strong></td>
                                        <td class="text-end"><strong><?= number_format($metrics['total_unique_donors'] ?? 0) ?></strong></td>
                                        <td class="text-end"><span class="badge bg-primary">100%</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Complete Contact Info (Name + Phone)</strong></td>
                                        <td class="text-end"><strong><?= number_format($metrics['donors_with_complete_contact'] ?? 0) ?></strong></td>
                                        <td class="text-end">
                                            <span class="badge bg-<?= $completePercent >= 80 ? 'success' : ($completePercent >= 50 ? 'warning' : 'danger') ?>">
                                                <?= $completePercent ?>%
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Donors with Pledges</strong></td>
                                        <td class="text-end"><strong><?= number_format($metrics['donors_with_pledges'] ?? 0) ?></strong></td>
                                        <td class="text-end"><span class="badge bg-info"><?= $pledgePercent ?>%</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Donors with Payments</strong></td>
                                        <td class="text-end"><strong><?= number_format($metrics['donors_with_payments'] ?? 0) ?></strong></td>
                                        <td class="text-end"><span class="badge bg-success"><?= $paymentPercent ?>%</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Pledges Linked to Donors</strong></td>
                                        <td class="text-end"><strong><?= number_format($pledgesMetrics['pledges_with_donor_id'] ?? 0) ?></strong> / <?= number_format($pledgesMetrics['total_pledges'] ?? 0) ?></td>
                                        <td class="text-end"><span class="badge bg-<?= $pledgeLinkPercent >= 95 ? 'success' : 'warning' ?>"><?= $pledgeLinkPercent ?>%</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Payments Linked to Donors</strong></td>
                                        <td class="text-end"><strong><?= number_format($paymentsMetrics['payments_with_donor_id'] ?? 0) ?></strong> / <?= number_format($paymentsMetrics['total_payments'] ?? 0) ?></td>
                                        <td class="text-end"><span class="badge bg-<?= $paymentLinkPercent >= 95 ? 'success' : 'warning' ?>"><?= $paymentLinkPercent ?>%</span></td>
                                    </tr>
                                    <tr style="background-color: rgba(10, 98, 134, 0.1);">
                                        <td><strong><i class="fas fa-pound-sign"></i> Total Pledged</strong></td>
                                        <td class="text-end"><strong><?= $currency . number_format($metrics['total_pledged_all'] ?? 0, 2) ?></strong></td>
                                        <td class="text-end"><span class="badge bg-primary">From <?= number_format($pledgesMetrics['total_pledges'] ?? 0) ?> pledges</span></td>
                                    </tr>
                                    <tr style="background-color: rgba(16, 185, 129, 0.1);">
                                        <td><strong><i class="fas fa-pound-sign"></i> Total Paid</strong></td>
                                        <td class="text-end"><strong><?= $currency . number_format($metrics['total_paid_all'] ?? 0, 2) ?></strong></td>
                                        <td class="text-end"><span class="badge bg-success">From <?= number_format($paymentsMetrics['total_payments'] ?? 0) ?> payments</span></td>
                                    </tr>
                                    <tr style="background-color: rgba(245, 158, 11, 0.1);">
                                        <td><strong><i class="fas fa-pound-sign"></i> Outstanding</strong></td>
                                        <td class="text-end"><strong><?= $currency . number_format($metrics['total_balance_all'] ?? 0, 2) ?></strong></td>
                                        <td class="text-end">
                                            <?php 
                                                $overallPercent = ($metrics['total_pledged_all'] > 0) ? 
                                                    round(($metrics['total_paid_all'] / $metrics['total_pledged_all']) * 100, 1) : 0;
                                            ?>
                                            <span class="badge bg-warning"><?= $overallPercent ?>% collected</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>
