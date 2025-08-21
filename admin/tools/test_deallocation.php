<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/IntelligentGridAllocator.php';
require_once __DIR__ . '/../../shared/IntelligentGridDeallocator.php';
require_login();
require_admin();

$db = db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'test_allocation') {
        try {
            $db->begin_transaction();
            
            // Test allocation
            $allocator = new IntelligentGridAllocator($db);
            $result = $allocator->allocate(
                999999, // Test pledge ID
                null,   // No payment ID
                100.0,  // £100 test amount
                null,   // No package ID
                'Test Donor',
                'pledged'
            );
            
            if ($result['success']) {
                $message = "✅ Test allocation successful: {$result['message']}";
                $message .= "<br>📊 Allocated cells: " . implode(', ', $result['allocated_cells']);
            } else {
                $error = "❌ Test allocation failed: {$result['error']}";
            }
            
            $db->commit();
            
        } catch (Exception $e) {
            $db->rollback();
            $error = "❌ Test allocation error: " . $e->getMessage();
        }
        
    } elseif ($action === 'test_deallocation') {
        try {
            $db->begin_transaction();
            
            // Test deallocation
            $deallocator = new IntelligentGridDeallocator($db);
            $result = $deallocator->deallocatePledge(999999);
            
            if ($result['success']) {
                $message = "✅ Test deallocation successful: {$result['message']}";
                $message .= "<br>📊 Deallocated cells: " . implode(', ', $result['deallocated_cells']);
            } else {
                $error = "❌ Test deallocation failed: {$result['error']}";
            }
            
            $db->commit();
            
        } catch (Exception $e) {
            $db->rollback();
            $error = "❌ Test deallocation error: " . $e->getMessage();
        }
        
    } elseif ($action === 'check_status') {
        // Check current grid status
        $allocator = new IntelligentGridAllocator($db);
        $stats = $allocator->getAllocationStats();
        $message = "📊 Current Grid Status:<br>";
        $message .= "Total cells: {$stats['total_cells']}<br>";
        $message .= "Available: {$stats['available_cells']}<br>";
        $message .= "Pledged: {$stats['pledged_cells']}<br>";
        $message .= "Paid: {$stats['paid_cells']}<br>";
        $message .= "Total area: {$stats['total_possible_area']} m²<br>";
        $message .= "Allocated area: {$stats['total_allocated_area']} m²";
    }
}

// Get deallocation statistics
$deallocator = new IntelligentGridDeallocator($db);
$deallocationStats = $deallocator->getDeallocationStats();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Floor Deallocation System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .test-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .status-card {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">
                    <i class="fas fa-cube me-2"></i>Test Floor Deallocation System
                </h1>
                
                <?php if ($message): ?>
                    <div class="alert alert-success"><?= $message ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="test-section">
                            <h4><i class="fas fa-play me-2"></i>Test Allocation</h4>
                            <p class="text-muted">Allocate £100 test donation to floor cells</p>
                            <form method="POST">
                                <?= csrf_token() ?>
                                <input type="hidden" name="action" value="test_allocation">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-plus me-1"></i>Test Allocate
                                </button>
                            </form>
                        </div>
                        
                        <div class="test-section">
                            <h4><i class="fas fa-undo me-2"></i>Test Deallocation</h4>
                            <p class="text-muted">Deallocate the test donation from floor cells</p>
                            <form method="POST">
                                <?= csrf_token() ?>
                                <input type="hidden" name="action" value="test_deallocation">
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-minus me-1"></i>Test Deallocate
                                </button>
                            </form>
                        </div>
                        
                        <div class="test-section">
                            <h4><i class="fas fa-chart-bar me-2"></i>Check Status</h4>
                            <p class="text-muted">View current grid allocation status</p>
                            <form method="POST">
                                <?= csrf_token() ?>
                                <input type="hidden" name="action" value="check_status">
                                <button type="submit" class="btn btn-info">
                                    <i class="fas fa-eye me-1"></i>Check Status
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="status-card">
                            <h5><i class="fas fa-info-circle me-2"></i>Deallocation Statistics</h5>
                            <div class="row">
                                <div class="col-6">
                                    <strong>Total Deallocations:</strong><br>
                                    <span class="badge bg-secondary"><?= $deallocationStats['total_deallocations'] ?? 0 ?></span>
                                </div>
                                <div class="col-6">
                                    <strong>Cells Deallocated:</strong><br>
                                    <span class="badge bg-warning"><?= $deallocationStats['total_cells_deallocated'] ?? 0 ?></span>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-6">
                                    <strong>Amount Deallocated:</strong><br>
                                    <span class="badge bg-danger">£<?= number_format($deallocationStats['total_amount_deallocated'] ?? 0, 2) ?></span>
                                </div>
                                <div class="col-6">
                                    <strong>Last Deallocation:</strong><br>
                                    <small><?= $deallocationStats['last_deallocation'] ?? 'Never' ?></small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="status-card">
                            <h5><i class="fas fa-lightbulb me-2"></i>How It Works</h5>
                            <ol class="small">
                                <li><strong>Test Allocation:</strong> Creates a test £100 donation and allocates floor cells</li>
                                <li><strong>Test Deallocation:</strong> Removes the test donation and frees up floor cells</li>
                                <li><strong>Check Status:</strong> Shows current grid allocation state</li>
                                <li><strong>Real System:</strong> This integrates with admin approval/unapproval</li>
                            </ol>
                        </div>
                        
                        <div class="status-card">
                            <h5><i class="fas fa-link me-2"></i>Quick Links</h5>
                            <div class="d-grid gap-2">
                                <a href="../approved/" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-check-circle me-1"></i>Approved Items
                                </a>
                                <a href="../../public/projector/floor/" class="btn btn-outline-success btn-sm" target="_blank">
                                    <i class="fas fa-map me-1"></i>View Floor Map
                                </a>
                                <a href="../tools/" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-tools me-1"></i>Admin Tools
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
