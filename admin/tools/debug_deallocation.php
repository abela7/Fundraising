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
    
    if ($action === 'debug_allocation') {
        try {
            // Check current database schema
            $schemaResult = $db->query("DESCRIBE floor_grid_cells");
            $columns = [];
            while ($row = $schemaResult->fetch_assoc()) {
                $columns[] = $row['Field'];
            }
            
            $message = "📊 Database Schema:<br>";
            $message .= "Columns: " . implode(', ', $columns) . "<br><br>";
            
            // Check if there are any allocated cells
            $allocatedResult = $db->query("
                SELECT COUNT(*) as total_allocated, 
                       COUNT(CASE WHEN pledge_id IS NOT NULL THEN 1 END) as with_pledge_id,
                       COUNT(CASE WHEN payment_id IS NOT NULL THEN 1 END) as with_payment_id
                FROM floor_grid_cells 
                WHERE status IN ('pledged', 'paid')
            ");
            $allocatedStats = $allocatedResult->fetch_assoc();
            
            $message .= "📈 Allocation Statistics:<br>";
            $message .= "Total allocated cells: {$allocatedStats['total_allocated']}<br>";
            $message .= "Cells with pledge_id: {$allocatedStats['with_pledge_id']}<br>";
            $message .= "Cells with payment_id: {$allocatedStats['with_payment_id']}<br><br>";
            
            // Show sample allocated cells
            $sampleResult = $db->query("
                SELECT cell_id, status, pledge_id, payment_id, donor_name, amount
                FROM floor_grid_cells 
                WHERE status IN ('pledged', 'paid')
                LIMIT 5
            ");
            
            $message .= "🔍 Sample Allocated Cells:<br>";
            while ($row = $sampleResult->fetch_assoc()) {
                $message .= "Cell: {$row['cell_id']}, Status: {$row['status']}, ";
                $message .= "Pledge: " . ($row['pledge_id'] ?? 'NULL') . ", ";
                $message .= "Payment: " . ($row['payment_id'] ?? 'NULL') . ", ";
                $message .= "Donor: {$row['donor_name']}<br>";
            }
            
        } catch (Exception $e) {
            $error = "❌ Debug error: " . $e->getMessage();
        }
        
    } elseif ($action === 'test_manual_deallocation') {
        try {
            // Find a cell that should be deallocated
            $cellResult = $db->query("
                SELECT cell_id, pledge_id, payment_id, status
                FROM floor_grid_cells 
                WHERE status IN ('pledged', 'paid')
                LIMIT 1
            ");
            
            if ($cell = $cellResult->fetch_assoc()) {
                $message = "🔍 Found cell to test: {$cell['cell_id']}<br>";
                $message .= "Status: {$cell['status']}, Pledge: " . ($cell['pledge_id'] ?? 'NULL') . ", Payment: " . ($cell['payment_id'] ?? 'NULL') . "<br><br>";
                
                // Try manual deallocation
                $updateResult = $db->query("
                    UPDATE floor_grid_cells 
                    SET status = 'available', 
                        pledge_id = NULL, 
                        payment_id = NULL, 
                        donor_name = NULL, 
                        amount = NULL
                    WHERE cell_id = '{$cell['cell_id']}'
                ");
                
                if ($updateResult) {
                    $message .= "✅ Manual deallocation successful!<br>";
                    
                    // Verify the update
                    $verifyResult = $db->query("
                        SELECT cell_id, status, pledge_id, payment_id
                        FROM floor_grid_cells 
                        WHERE cell_id = '{$cell['cell_id']}'
                    ");
                    $verify = $verifyResult->fetch_assoc();
                    $message .= "Verification: Status = {$verify['status']}, Pledge = " . ($verify['pledge_id'] ?? 'NULL') . ", Payment = " . ($verify['payment_id'] ?? 'NULL');
                } else {
                    $message .= "❌ Manual deallocation failed!";
                }
            } else {
                $message = "No allocated cells found to test.";
            }
            
        } catch (Exception $e) {
            $error = "❌ Test error: " . $e->getMessage();
        }
        
    } elseif ($action === 'fix_schema') {
        try {
            // Check if we need to add missing columns
            $db->begin_transaction();
            
            // Add allocated_at column if it doesn't exist
            $db->query("
                ALTER TABLE floor_grid_cells 
                ADD COLUMN IF NOT EXISTS allocated_at TIMESTAMP NULL DEFAULT NULL
            ");
            
            // Add updated_at column if it doesn't exist
            $db->query("
                ALTER TABLE floor_grid_cells 
                ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ");
            
            // Update existing allocated cells to have proper timestamps
            $db->query("
                UPDATE floor_grid_cells 
                SET allocated_at = NOW() 
                WHERE status IN ('pledged', 'paid') AND allocated_at IS NULL
            ");
            
            $db->commit();
            $message = "✅ Database schema updated successfully!";
            
        } catch (Exception $e) {
            $db->rollback();
            $error = "❌ Schema update failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Floor Deallocation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .debug-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .info-box {
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
                    <i class="fas fa-bug me-2"></i>Debug Floor Deallocation
                </h1>
                
                <?php if ($message): ?>
                    <div class="alert alert-info">
                        <?= $message ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="debug-section">
                            <h4><i class="fas fa-search me-2"></i>Debug Allocation</h4>
                            <p class="text-muted">Check database schema and allocation status</p>
                            <form method="POST">
                                <?= csrf_token() ?>
                                <input type="hidden" name="action" value="debug_allocation">
                                <button type="submit" class="btn btn-info">
                                    <i class="fas fa-eye me-1"></i>Debug Allocation
                                </button>
                            </form>
                        </div>
                        
                        <div class="debug-section">
                            <h4><i class="fas fa-wrench me-2"></i>Test Manual Deallocation</h4>
                            <p class="text-muted">Test deallocation directly in database</p>
                            <form method="POST">
                                <?= csrf_token() ?>
                                <input type="hidden" name="action" value="test_manual_deallocation">
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-tools me-1"></i>Test Manual
                                </button>
                            </form>
                        </div>
                        
                        <div class="debug-section">
                            <h4><i class="fas fa-database me-2"></i>Fix Schema</h4>
                            <p class="text-muted">Add missing database columns</p>
                            <form method="POST">
                                <?= csrf_token() ?>
                                <input type="hidden" name="action" value="fix_schema">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-plus me-1"></i>Fix Schema
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="info-box">
                            <h5><i class="fas fa-lightbulb me-2"></i>What This Debug Tool Does</h5>
                            <ol class="small">
                                <li><strong>Debug Allocation:</strong> Shows database schema and current allocation state</li>
                                <li><strong>Test Manual:</strong> Tests deallocation directly in the database</li>
                                <li><strong>Fix Schema:</strong> Adds missing columns that might be causing issues</li>
                            </ol>
                        </div>
                        
                        <div class="info-box">
                            <h5><i class="fas fa-exclamation-triangle me-2"></i>Common Issues</h5>
                            <ul class="small">
                                <li>Missing database columns (allocated_at, updated_at)</li>
                                <li>Column name mismatches between allocator and deallocator</li>
                                <li>Cells not properly linked to pledge_id/payment_id</li>
                                <li>Transaction rollback issues</li>
                            </ul>
                        </div>
                        
                        <div class="info-box">
                            <h5><i class="fas fa-link me-2"></i>Quick Links</h5>
                            <div class="d-grid gap-2">
                                <a href="test_deallocation.php" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-cube me-1"></i>Test Deallocation
                                </a>
                                <a href="../../public/projector/floor/" class="btn btn-outline-success btn-sm" target="_blank">
                                    <i class="fas fa-map me-1"></i>View Floor Map
                                </a>
                                <a href="../approved/" class="btn btn-outline-warning btn-sm">
                                    <i class="fas fa-check-circle me-1"></i>Approved Items
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
