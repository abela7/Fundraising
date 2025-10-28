<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();

$database = db();

// Get statistics
$stats = $database->query("SELECT 
    COUNT(*) as total_donors,
    COUNT(CASE WHEN total_pledged > 0 THEN 1 END) as donors_with_pledges,
    COUNT(CASE WHEN total_paid > 0 THEN 1 END) as donors_with_payments,
    COUNT(CASE WHEN payment_status = 'completed' THEN 1 END) as fully_paid_donors,
    SUM(total_pledged) as total_pledged_amount,
    SUM(total_paid) as total_paid_amount,
    SUM(balance) as total_outstanding_balance
FROM donors")->fetch_assoc() ?: [];

// Get donor list
$donors = $database->query("SELECT 
    id, name, phone, total_pledged, total_paid, balance, 
    payment_status, achievement_badge
FROM donors 
ORDER BY balance DESC
LIMIT 50")->fetch_all(MYSQLI_ASSOC);
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
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <h2 class="mb-4"><i class="fas fa-users"></i> Donor Overview</h2>
                
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h3 class="card-title"><?= $stats['total_donors'] ?? 0 ?></h3>
                                <p class="card-text text-muted">Total Donors</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h3 class="card-title"><?= $stats['donors_with_pledges'] ?? 0 ?></h3>
                                <p class="card-text text-muted">With Pledges</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h3 class="card-title"><?= $stats['donors_with_payments'] ?? 0 ?></h3>
                                <p class="card-text text-muted">With Payments</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h3 class="card-title"><?= $stats['fully_paid_donors'] ?? 0 ?></h3>
                                <p class="card-text text-muted">Fully Paid</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Financial Summary -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body text-center">
                                <h4>£<?= number_format($stats['total_pledged_amount'] ?? 0, 2) ?></h4>
                                <p class="mb-0">Total Pledged</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <h4>£<?= number_format($stats['total_paid_amount'] ?? 0, 2) ?></h4>
                                <p class="mb-0">Total Paid</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card bg-warning text-dark">
                            <div class="card-body text-center">
                                <h4>£<?= number_format($stats['total_outstanding_balance'] ?? 0, 2) ?></h4>
                                <p class="mb-0">Outstanding</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Donors Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Donors List</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th class="text-end">Pledged</th>
                                    <th class="text-end">Paid</th>
                                    <th class="text-end">Balance</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($donors as $d): ?>
                                <tr>
                                    <td><?= htmlspecialchars($d['name']) ?></td>
                                    <td><?= htmlspecialchars($d['phone']) ?></td>
                                    <td class="text-end">£<?= number_format($d['total_pledged'], 2) ?></td>
                                    <td class="text-end">£<?= number_format($d['total_paid'], 2) ?></td>
                                    <td class="text-end">£<?= number_format($d['balance'], 2) ?></td>
                                    <td><small><?= ucfirst(str_replace('_', ' ', $d['payment_status'])) ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
