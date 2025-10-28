<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();

$database = db();
$donorId = max(1, (int)($_GET['id'] ?? 0));

// Get donor details
$donorQuery = "SELECT * FROM donors WHERE id = ?";
$stmt = $database->prepare($donorQuery);
$stmt->bind_param('i', $donorId);
$stmt->execute();
$donor = $stmt->get_result()->fetch_assoc();

if (!$donor) {
    header('Location: index.php');
    exit;
}

// Get pledges for this donor
$pledgesQuery = "SELECT * FROM pledges WHERE donor_id = ? ORDER BY created_at DESC";
$stmt = $database->prepare($pledgesQuery);
$stmt->bind_param('i', $donorId);
$stmt->execute();
$pledges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get payments for this donor
$paymentsQuery = "SELECT * FROM payments WHERE donor_id = ? ORDER BY created_at DESC";
$stmt = $database->prepare($paymentsQuery);
$stmt->bind_param('i', $donorId);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$currency = '£';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($donor['name']) ?> | Donor Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2><i class="bi bi-person-circle"></i> <?= htmlspecialchars($donor['name']) ?></h2>
                        <p class="text-muted mb-0">Donor ID: #<?= $donor['id'] ?> | Phone: <?= htmlspecialchars($donor['phone']) ?></p>
                    </div>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to List
                    </a>
                </div>
                
                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="text-muted">Total Pledged</h6>
                                <h3><?= $currency . number_format($donor['total_pledged'], 2) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="text-muted">Total Paid</h6>
                                <h3 class="text-success"><?= $currency . number_format($donor['total_paid'], 2) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="text-muted">Outstanding Balance</h6>
                                <h3 class="text-danger"><?= $currency . number_format($donor['balance'], 2) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="text-muted">Payment Status</h6>
                                <h5><?= ucwords(str_replace('_', ' ', $donor['payment_status'])) ?></h5>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Pledges -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-hand-thumbs-up"></i> Pledges (<?= count($pledges) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pledges)): ?>
                            <p class="text-muted mb-0">No pledges recorded</p>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Type</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pledges as $pledge): ?>
                                        <tr>
                                            <td><?= date('d M Y', strtotime($pledge['created_at'])) ?></td>
                                            <td><strong><?= $currency . number_format($pledge['amount'], 2) ?></strong></td>
                                            <td>
                                                <span class="badge bg-<?= $pledge['status'] === 'approved' ? 'success' : 'warning' ?>">
                                                    <?= ucfirst($pledge['status']) ?>
                                                </span>
                                            </td>
                                            <td><?= ucfirst($pledge['type']) ?></td>
                                            <td><?= htmlspecialchars($pledge['notes'] ?? '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Payments -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-cash-coin"></i> Payments (<?= count($payments) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($payments)): ?>
                            <p class="text-muted mb-0">No payments recorded</p>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Reference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td><?= date('d M Y', strtotime($payment['created_at'])) ?></td>
                                            <td><strong><?= $currency . number_format($payment['amount'], 2) ?></strong></td>
                                            <td><?= ucfirst($payment['method']) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $payment['status'] === 'approved' ? 'success' : 'warning' ?>">
                                                    <?= ucfirst($payment['status']) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($payment['reference'] ?? '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

