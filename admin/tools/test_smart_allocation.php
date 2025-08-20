<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/IntelligentGridAllocator.php';

require_login();
require_admin();

$db = db();
$allocator = new IntelligentGridAllocator($db);
$testResult = null;

// Handle test allocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['amount'])) {
    $amount = (float)($_POST['amount'] ?? 0);
    $packageId = !empty($_POST['package_id']) ? (int)$_POST['package_id'] : null;
    $donorName = trim($_POST['donor_name'] ?? 'Test Donor');
    $status = $_POST['status'] ?? 'pledged';
    
    if ($amount > 0) {
        $testResult = $allocator->allocate(
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
$stats = $allocator->getAllocationStats();
$packages = $db->query("SELECT id, label, sqm_meters, price FROM donation_packages WHERE active = 1 ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Intelligent Grid Allocation</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="container mt-5">
    <div class="row">
        <div class="col-md-8">
            <h2><i class="fas fa-brain me-2"></i>Intelligent Allocation System Test</h2>
            
            <div class="alert alert-info">
                <h5><i class="fas fa-lightbulb me-2"></i>How It Works:</h5>
                <p>This system allocates donations by filling the smallest available `0.5m x 0.5m` cells sequentially, ensuring there are no gaps left on the floor plan.</p>
            </div>
            
            <!-- Test Form -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-flask me-2"></i>Run a New Test Allocation</h5>
                </div>
                <div class="card-body">
                    <form method="post" id="test-form">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="donor_name" class="form-label">Donor Name</label>
                                <input type="text" class="form-control" id="donor_name" name="donor_name" value="Test Donor-<?= uniqid() ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="pledged" selected>Pledged</option>
                                    <option value="paid">Paid</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Select a Test Amount:</label>
                            <div class="btn-group w-100" role="group">
                                <button type="submit" class="btn btn-outline-primary" name="amount" value="100">Test £100 (0.25m²)</button>
                                <button type="submit" class="btn btn-outline-primary" name="amount" value="200">Test £200 (0.5m²)</button>
                                <button type="submit" class="btn btn-outline-primary" name="amount" value="400">Test £400 (1.0m²)</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Test Result -->
            <?php if ($testResult): ?>
            <div class="card mt-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Last Test Result</h5>
                </div>
                <div class="card-body">
                    <?php if ($testResult['success']): ?>
                        <div class="alert alert-success">
                            <h6><i class="fas fa-check-circle me-2"></i>Allocation Successful!</h6>
                            <p class="mb-0"><?= htmlspecialchars($testResult['message']) ?></p>
                            <p class="mb-0"><strong>Area Allocated:</strong> <?= htmlspecialchars((string)$testResult['area_allocated']) ?>m²</p>
                            <h6>Allocated Cell IDs:</h6>
                            <textarea class="form-control" rows="3" readonly><?= implode(', ', $testResult['allocated_cells']) ?></textarea>
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
                    <h5><i class="fas fa-chart-pie me-2"></i>Live Grid Statistics</h5>
                </div>
                <div class="card-body">
                    <?php 
                        $allocated_area = ($stats['pledged_cells'] + $stats['paid_cells']) * 0.25;
                        $total_area = $stats['total_cells'] * 0.25;
                        $progress = $total_area > 0 ? ($allocated_area / $total_area) * 100 : 0;
                    ?>
                    <div class="text-center mb-3">
                        <h3 class="text-primary"><?= number_format($progress, 1) ?>%</h3>
                        <div class="progress">
                            <div class="progress-bar" role="progressbar" style="width: <?= $progress ?>%;" aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <small class="text-muted"><?= number_format($allocated_area, 2) ?>m² of <?= number_format($total_area, 2) ?>m² Allocated</small>
                    </div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center">Paid Cells <span class="badge bg-success rounded-pill"><?= $stats['paid_cells'] ?></span></li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">Pledged Cells <span class="badge bg-warning rounded-pill"><?= $stats['pledged_cells'] ?></span></li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">Available Cells <span class="badge bg-light text-dark rounded-pill"><?= $stats['available_cells'] ?></span></li>
                        <li class="list-group-item d-flex justify-content-between align-items-center"><strong>Total Cells</strong> <span class="badge bg-dark rounded-pill"><?= $stats['total_cells'] ?></span></li>
                    </ul>
                </div>
            </div>
            
            <div class="card mt-4">
                <div class="card-header">
                    <h5><i class="fas fa-tools me-2"></i>Actions</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="../../projector/floor/" class="btn btn-outline-success" target="_blank"><i class="fas fa-eye me-1"></i>View Live Floor Plan</a>
                        <a href="reset_floor_map.php" class="btn btn-outline-danger"><i class="fas fa-power-off me-1"></i>Reset Floor Map</a>
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
