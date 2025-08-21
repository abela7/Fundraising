<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/IntelligentGridDeallocator.php';
require_login();
require_admin();

$page_title = 'Test Floor Deallocation';
$db = db();
$message = '';
$error = '';

// Get current floor allocation status
$floorStatus = $db->query("
    SELECT 
        status,
        COUNT(*) as count,
        SUM(amount) as total_amount
    FROM floor_grid_cells 
    GROUP BY status
")->fetch_all(MYSQLI_ASSOC);

// Get recent allocations
$recentAllocations = $db->query("
    SELECT 
        cell_id,
        status,
        pledge_id,
        payment_id,
        donor_name,
        amount,
        allocated_at
    FROM floor_grid_cells 
    WHERE status IN ('pledged', 'paid')
    ORDER BY allocated_at DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Get pledges that have floor allocations
$allocatedPledges = $db->query("
    SELECT DISTINCT
        p.id,
        p.amount,
        p.donor_name,
        p.status,
        COUNT(fgc.cell_id) as allocated_cells
    FROM pledges p
    INNER JOIN floor_grid_cells fgc ON p.id = fgc.pledge_id
    WHERE p.status = 'approved' AND fgc.status IN ('pledged', 'paid')
    GROUP BY p.id
    ORDER BY p.id DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Get payments that have floor allocations
$allocatedPayments = $db->query("
    SELECT DISTINCT
        pay.id,
        pay.amount,
        pay.donor_name,
        pay.status,
        COUNT(fgc.cell_id) as allocated_cells
    FROM payments pay
    INNER JOIN floor_grid_cells fgc ON pay.id = fgc.payment_id
    WHERE pay.status = 'approved' AND fgc.status IN ('pledged', 'paid')
    GROUP BY pay.id
    ORDER BY pay.id DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Fundraising System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/admin.css" rel="stylesheet">
</head>
<body>
    <div class="wrapper">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main">
            <?php include '../includes/topbar.php'; ?>
            
            <main class="content">
                <div class="container-fluid p-0">
                    <h1 class="h3 mb-3"><?= htmlspecialchars($page_title) ?></h1>
                    
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <!-- Floor Status Overview -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-chart-pie me-2"></i>Floor Allocation Status
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($floorStatus as $status): ?>
                                            <div class="col-md-3 mb-3">
                                                <div class="card bg-light">
                                                    <div class="card-body text-center">
                                                        <h4 class="text-primary"><?= $status['count'] ?></h4>
                                                        <p class="mb-0 text-muted"><?= ucfirst($status['status']) ?></p>
                                                        <?php if ($status['total_amount']): ?>
                                                            <small class="text-success">£<?= number_format($status['total_amount'], 2) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Allocated Pledges -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-handshake me-2"></i>Pledges with Floor Allocations
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($allocatedPledges)): ?>
                                        <p class="text-muted">No pledges currently have floor allocations.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Donor</th>
                                                        <th>Amount</th>
                                                        <th>Cells</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($allocatedPledges as $pledge): ?>
                                                        <tr>
                                                            <td><?= $pledge['id'] ?></td>
                                                            <td><?= htmlspecialchars($pledge['donor_name']) ?></td>
                                                            <td>£<?= number_format($pledge['amount'], 2) ?></td>
                                                            <td><?= $pledge['allocated_cells'] ?></td>
                                                            <td>
                                                                <span class="badge bg-success"><?= ucfirst($pledge['status']) ?></span>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Allocated Payments -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-credit-card me-2"></i>Payments with Floor Allocations
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($allocatedPayments)): ?>
                                        <p class="text-muted">No payments currently have floor allocations.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Donor</th>
                                                        <th>Amount</th>
                                                        <th>Cells</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($allocatedPayments as $payment): ?>
                                                        <tr>
                                                            <td><?= $payment['id'] ?></td>
                                                            <td><?= htmlspecialchars($payment['donor_name']) ?></td>
                                                            <td>£<?= number_format($payment['amount'], 2) ?></td>
                                                            <td><?= $payment['allocated_cells'] ?></td>
                                                            <td>
                                                                <span class="badge bg-success"><?= ucfirst($payment['status']) ?></span>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Allocations -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-list me-2"></i>Recent Floor Allocations
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($recentAllocations)): ?>
                                        <p class="text-muted">No recent floor allocations found.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Cell ID</th>
                                                        <th>Status</th>
                                                        <th>Pledge ID</th>
                                                        <th>Payment ID</th>
                                                        <th>Donor</th>
                                                        <th>Amount</th>
                                                        <th>Allocated At</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($recentAllocations as $allocation): ?>
                                                        <tr>
                                                            <td><code><?= htmlspecialchars($allocation['cell_id']) ?></code></td>
                                                            <td>
                                                                <span class="badge bg-<?= $allocation['status'] === 'paid' ? 'success' : 'warning' ?>">
                                                                    <?= ucfirst($allocation['status']) ?>
                                                                </span>
                                                            </td>
                                                            <td><?= $allocation['pledge_id'] ?: '-' ?></td>
                                                            <td><?= $allocation['payment_id'] ?: '-' ?></td>
                                                            <td><?= htmlspecialchars($allocation['donor_name']) ?></td>
                                                            <td>£<?= number_format($allocation['amount'], 2) ?></td>
                                                            <td><?= $allocation['allocated_at'] ? date('M j, Y g:i A', strtotime($allocation['allocated_at'])) : '-' ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Test Deallocation -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-test-tube me-2"></i>Test Deallocation
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>How to test:</strong>
                                        <ol class="mb-0 mt-2">
                                            <li>Go to <a href="../approved/" target="_blank">Approved Items</a></li>
                                            <li>Find a pledge or payment with floor allocations</li>
                                            <li>Click the undo button (↶) to unapprove it</li>
                                            <li>Check this page again to see the cells become available</li>
                                            <li>Or check the <a href="../../public/projector/floor/" target="_blank">Floor Map</a></li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/admin.js"></script>
</body>
</html>
