<?php
declare(strict_types=1);

/**
 * Test Smart Grid Allocation System
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/SmartGridAllocator.php';

require_login();
require_admin();

$db = db();
$smartAllocator = new SmartGridAllocator($db);
$testResult = null;

// Handle test allocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_allocation'])) {
    $amount = (float)($_POST['amount'] ?? 0);
    $packageId = !empty($_POST['package_id']) ? (int)$_POST['package_id'] : null;
    $donorName = trim($_POST['donor_name'] ?? 'Test Donor');
    $status = $_POST['status'] ?? 'pledged';
    
    if ($amount > 0) {
        $testResult = $smartAllocator->allocateGridCells(
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
$stats = $smartAllocator->getAllocationStats();
$packages = $db->query("SELECT id, label, sqm_meters, price FROM donation_packages WHERE active = 1 ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Smart Grid Allocation</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="container mt-5">
    <div class="row">
        <div class="col-md-8">
            <h2><i class="fas fa-brain me-2"></i>Smart Grid Allocation System</h2>
            
            <div class="alert alert-info">
                <h5><i class="fas fa-lightbulb me-2"></i>How It Works:</h5>
                <ul class="mb-0">
                    <li><strong>£400 (1m²):</strong> Allocates 1m×1m cell, blocks overlapping 0.5m cells</li>
                    <li><strong>£200 (0.5m²):</strong> Allocates 1m×0.5m cell, blocks conflicts</li>
                    <li><strong>£100 (0.25m²):</strong> Allocates 0.5m×0.5m cell, starts from A0505-185</li>
                    <li><strong>Conflict Detection:</strong> Prevents double-selling of same space</li>
                </ul>
            </div>
            
            <!-- Test Form -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-flask me-2"></i>Test Smart Allocation</h5>
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
                        
                        <div class="row">
                            <div class="col-md-3">
                                <button type="submit" name="test_allocation" class="btn btn-primary w-100">
                                    <i class="fas fa-play me-1"></i>Test £100
                                </button>
                                <input type="hidden" name="amount" value="100">
                                <input type="hidden" name="package_id" value="3">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" name="test_allocation" class="btn btn-warning w-100">
                                    <i class="fas fa-play me-1"></i>Test £200
                                </button>
                                <input type="hidden" name="amount" value="200">
                                <input type="hidden" name="package_id" value="2">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" name="test_allocation" class="btn btn-success w-100">
                                    <i class="fas fa-play me-1"></i>Test £400
                                </button>
                                <input type="hidden" name="amount" value="400">
                                <input type="hidden" name="package_id" value="1">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" name="test_allocation" class="btn btn-info w-100">
                                    <i class="fas fa-play me-1"></i>Custom Test
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Test Result -->
            <?php if ($testResult): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <h5><i class="fas fa-chart-line me-2"></i>Allocation Result</h5>
                </div>
                <div class="card-body">
                    <?php if ($testResult['success']): ?>
                        <div class="alert alert-success">
                            <h6><i class="fas fa-check-circle me-2"></i>Smart Allocation Successful!</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <ul class="mb-0">
                                        <li><strong>Strategy:</strong> <?= $testResult['strategy']['type'] ?></li>
                                        <li><strong>Area Allocated:</strong> <?= $testResult['area_allocated'] ?>m²</li>
                                        <li><strong>Cells Allocated:</strong> <?= $testResult['cells_allocated'] ?></li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <ul class="mb-0">
                                        <li><strong>Pattern:</strong> <?= $testResult['strategy']['pattern'] ?></li>
                                        <li><strong>Conflicts Checked:</strong> ✅</li>
                                        <li><strong>Overlaps Blocked:</strong> ✅</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <h6><i class="fas fa-map me-2"></i>Allocated Cells:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>Cell ID</th>
                                        <th>Rectangle</th>
                                        <th>Type</th>
                                        <th>Area</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($testResult['allocated_cells'] as $cell): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($cell['cell_id']) ?></code></td>
                                        <td><span class="badge bg-primary"><?= $cell['rectangle_id'] ?></span></td>
                                        <td><?= $cell['cell_type'] ?></td>
                                        <td><?= $cell['area_size'] ?>m²</td>
                                        <td><span class="badge bg-<?= $cell['status'] === 'paid' ? 'success' : 'warning' ?>"><?= $cell['status'] ?></span></td>
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
                    <h5><i class="fas fa-chart-pie me-2"></i>Smart Grid Statistics</h5>
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
                                <div class="bg-secondary rounded me-2" style="width: 12px; height: 12px;"></div>
                                <small>Blocked: <?= $stats['blocked_cells'] ?></small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex align-items-center mb-2">
                                <div class="bg-light border rounded me-2" style="width: 12px; height: 12px;"></div>
                                <small>Available: <?= $stats['available_cells'] ?></small>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <ul class="list-unstyled mb-0">
                        <li><strong>Total Cells:</strong><br><?= number_format($stats['total_cells']) ?></li>
                        <li><strong>Paid Area:</strong><br><?= number_format($stats['total_area_paid'], 2) ?>m²</li>
                        <li><strong>Pledged Area:</strong><br><?= number_format($stats['total_area_pledged'], 2) ?>m²</li>
                        <li><strong>Total Amount:</strong><br>£<?= number_format($stats['total_amount'], 2) ?></li>
                    </ul>
                </div>
            </div>
            
            <!-- Current Allocations -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5><i class="fas fa-list me-2"></i>Current Allocations</h5>
                </div>
                <div class="card-body">
                    <?php
                    $allocations = $smartAllocator->getGridStatus();
                    if (empty($allocations)): ?>
                        <p class="text-muted">No allocations yet</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr><th>Cell</th><th>Donor</th><th>Amount</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($allocations, 0, 10) as $alloc): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($alloc['cell_id']) ?></code></td>
                                        <td><?= htmlspecialchars(substr($alloc['donor_name'], 0, 10)) ?></td>
                                        <td>£<?= number_format($alloc['amount']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($allocations) > 10): ?>
                            <small class="text-muted">Showing 10 of <?= count($allocations) ?> allocations</small>
                        <?php endif; ?>
                    <?php endif; ?>
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
                        <a href="upgrade_grid_schema.php" class="btn btn-outline-warning">
                            <i class="fas fa-database me-1"></i>Upgrade Schema
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
<script>
// Quick test buttons
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('button[name="test_allocation"]').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Get hidden inputs for this button
            const hiddenInputs = this.parentElement.querySelectorAll('input[type="hidden"]');
            
            // Update form with these values
            hiddenInputs.forEach(input => {
                const formInput = document.querySelector(`input[name="${input.name}"], select[name="${input.name}"]`);
                if (formInput) {
                    formInput.value = input.value;
                }
            });
            
            // Submit form
            document.querySelector('form').submit();
        });
    });
});
</script>
</body>
</html>
