<?php
declare(strict_types=1);

/**
 * Test Grid Allocation System
 * 
 * This page allows testing the floor grid allocation system with various scenarios.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/FloorGridAllocator.php';

// Only allow admin access
require_login();
require_admin();

$db = db();
$gridAllocator = new FloorGridAllocator($db);
$testResult = null;

// Handle test allocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_allocation'])) {
    $amount = (float)($_POST['amount'] ?? 0);
    $packageId = !empty($_POST['package_id']) ? (int)$_POST['package_id'] : null;
    $donorName = trim($_POST['donor_name'] ?? 'Test Donor');
    $status = $_POST['status'] ?? 'pledged';
    
    if ($amount > 0) {
        $testResult = $gridAllocator->allocateGridCells(
            null, // pledge_id
            null, // payment_id  
            $amount,
            $packageId,
            $donorName,
            $status
        );
    }
}

// Get current statistics
$stats = $gridAllocator->getAllocationStats();
$packages = $db->query("SELECT id, label, sqm_meters, price FROM donation_packages WHERE active = 1 ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Grid Allocation System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="container mt-5">
    <div class="row">
        <div class="col-md-8">
            <h2><i class="fas fa-grid-3x3 me-2"></i>Test Grid Allocation System</h2>
            
            <!-- Test Form -->
            <div class="card">
                <div class="card-header">
                    <h5>Test Allocation</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="donor_name" class="form-label">Donor Name</label>
                                    <input type="text" class="form-control" id="donor_name" name="donor_name" value="Test Donor" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="amount" class="form-label">Amount (£)</label>
                                    <input type="number" class="form-control" id="amount" name="amount" step="0.01" min="0" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="package_id" class="form-label">Package</label>
                                    <select class="form-select" id="package_id" name="package_id">
                                        <option value="">— Custom Amount —</option>
                                        <?php foreach ($packages as $pkg): ?>
                                            <option value="<?= $pkg['id'] ?>"><?= htmlspecialchars($pkg['label']) ?> (<?= $pkg['sqm_meters'] ?>m² - £<?= $pkg['price'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="pledged">Pledged</option>
                                        <option value="paid">Paid</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" name="test_allocation" class="btn btn-primary">
                            <i class="fas fa-play me-1"></i>Test Allocation
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Test Result -->
            <?php if ($testResult): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <h5>Allocation Result</h5>
                </div>
                <div class="card-body">
                    <?php if ($testResult['success']): ?>
                        <div class="alert alert-success">
                            <h6><i class="fas fa-check-circle me-2"></i>Allocation Successful!</h6>
                            <ul class="mb-0">
                                <li><strong>Area Allocated:</strong> <?= $testResult['area_allocated'] ?>m²</li>
                                <li><strong>Cells Allocated:</strong> <?= $testResult['cells_allocated'] ?></li>
                                <li><strong>Cells Details:</strong> <?= count($testResult['allocated_cells']) ?> cells</li>
                            </ul>
                        </div>
                        
                        <h6>Allocated Cells:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>Rectangle</th>
                                        <th>Position (X,Y)</th>
                                        <th>Size</th>
                                        <th>Cell ID</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($testResult['allocated_cells'] as $cell): ?>
                                    <tr>
                                        <td><span class="badge bg-primary"><?= $cell['rectangle_id'] ?></span></td>
                                        <td>(<?= $cell['grid_x'] ?>, <?= $cell['grid_y'] ?>)</td>
                                        <td><?= $cell['cell_size'] ?></td>
                                        <td>#<?= $cell['id'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger">
                            <h6><i class="fas fa-exclamation-triangle me-2"></i>Allocation Failed</h6>
                            <p class="mb-0"><strong>Error:</strong> <?= htmlspecialchars($testResult['error']) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="col-md-4">
            <!-- Current Statistics -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie me-2"></i>Current Statistics</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-12 mb-3">
                            <h3 class="text-primary"><?= number_format($stats['progress_percentage'], 1) ?>%</h3>
                            <small class="text-muted">Progress</small>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="row">
                        <div class="col-6">
                            <div class="d-flex align-items-center mb-2">
                                <div class="bg-success rounded me-2" style="width: 12px; height: 12px;"></div>
                                <small>Paid: <?= $stats['paid_cells'] ?></small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex align-items-center mb-2">
                                <div class="bg-warning rounded me-2" style="width: 12px; height: 12px;"></div>
                                <small>Pledged: <?= $stats['pledged_cells'] ?></small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex align-items-center mb-2">
                                <div class="bg-light border rounded me-2" style="width: 12px; height: 12px;"></div>
                                <small>Available: <?= $stats['available_cells'] ?></small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex align-items-center mb-2">
                                <div class="bg-primary rounded me-2" style="width: 12px; height: 12px;"></div>
                                <small>Total: <?= $stats['total_cells'] ?></small>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <ul class="list-unstyled mb-0">
                        <li><strong>Total Area:</strong><br><?= number_format($stats['total_cells'] * 0.25, 2) ?>m²</li>
                        <li><strong>Paid Area:</strong><br><?= number_format($stats['total_area_paid'], 2) ?>m²</li>
                        <li><strong>Pledged Area:</strong><br><?= number_format($stats['total_area_pledged'], 2) ?>m²</li>
                        <li><strong>Total Amount:</strong><br>£<?= number_format($stats['total_amount'], 2) ?></li>
                    </ul>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5><i class="fas fa-tools me-2"></i>Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="../approvals/" class="btn btn-outline-primary">
                            <i class="fas fa-clock me-1"></i>View Approvals
                        </a>
                        <a href="../../projector/floor/" class="btn btn-outline-success" target="_blank">
                            <i class="fas fa-eye me-1"></i>View Floor Plan
                        </a>
                        <a href="../../api/grid_status.php" class="btn btn-outline-info" target="_blank">
                            <i class="fas fa-code me-1"></i>API Status
                        </a>
                        <a href="populate_grid_cells.php" class="btn btn-outline-warning">
                            <i class="fas fa-database me-1"></i>Populate Grid
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="mt-4">
        <a href="../" class="btn btn-secondary">← Back to Admin Tools</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
