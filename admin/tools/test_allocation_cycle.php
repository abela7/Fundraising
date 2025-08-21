<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/IntelligentGridAllocator.php';
require_login();
require_admin();

$db = db();
$gridAllocator = new IntelligentGridAllocator($db);

// Test the complete allocation/deallocation cycle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'full_cycle_test') {
        $amount = (float)($_POST['amount'] ?? 100);
        $testResults = [];
        
        try {
            // Step 1: Get initial stats
            $initialStats = $gridAllocator->getAllocationStats();
            $testResults[] = ['step' => 'Initial Stats', 'result' => $initialStats];
            
            // Step 2: Allocate cells
            $allocResult = $gridAllocator->allocate(888888, null, $amount, null, 'Test Cycle Donor', 'pledged');
            $testResults[] = ['step' => 'Allocation', 'result' => $allocResult];
            
            // Step 3: Get stats after allocation
            $afterAllocStats = $gridAllocator->getAllocationStats();
            $testResults[] = ['step' => 'After Allocation Stats', 'result' => $afterAllocStats];
            
            // Step 4: Deallocate cells
            $deallocResult = $gridAllocator->deallocate(888888, null);
            $testResults[] = ['step' => 'Deallocation', 'result' => $deallocResult];
            
            // Step 5: Get final stats
            $finalStats = $gridAllocator->getAllocationStats();
            $testResults[] = ['step' => 'Final Stats', 'result' => $finalStats];
            
            // Step 6: Verify stats match
            $statsMatch = (
                $initialStats['pledged_cells'] == $finalStats['pledged_cells'] &&
                $initialStats['paid_cells'] == $finalStats['paid_cells'] &&
                $initialStats['available_cells'] == $finalStats['available_cells']
            );
            
            $testResults[] = ['step' => 'Stats Verification', 'result' => ['stats_match' => $statsMatch]];
            
            echo json_encode(['type' => 'full_cycle', 'results' => $testResults]);
            
        } catch (Exception $e) {
            echo json_encode(['type' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'check_orphaned_cells') {
        // Check for cells that have allocation data but no valid pledge/payment
        $sql = "
            SELECT cell_id, status, pledge_id, payment_id, donor_name, amount 
            FROM floor_grid_cells 
            WHERE status IN ('pledged', 'paid', 'blocked') 
            AND (pledge_id IS NULL AND payment_id IS NULL)
        ";
        $result = $db->query($sql);
        $orphanedCells = $result->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode(['type' => 'orphaned_check', 'orphaned_cells' => $orphanedCells]);
        exit;
    }
    
    if ($action === 'cleanup_orphaned') {
        // Clean up orphaned cells
        $sql = "
            UPDATE floor_grid_cells 
            SET status = 'available', pledge_id = NULL, payment_id = NULL, 
                donor_name = NULL, amount = NULL, assigned_date = NULL
            WHERE status IN ('pledged', 'paid', 'blocked') 
            AND (pledge_id IS NULL AND payment_id IS NULL)
        ";
        $result = $db->query($sql);
        $cleaned = $db->affected_rows;
        
        echo json_encode(['type' => 'cleanup', 'cleaned_cells' => $cleaned]);
        exit;
    }
}

$page_title = 'Test Complete Allocation Cycle';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-recycle me-2"></i>Test Complete Allocation Cycle
                                </h5>
                                <small class="text-muted">Test the full approve/unapprove cycle to ensure floor cells are properly allocated and deallocated</small>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <h6>🔄 Full Cycle Test</h6>
                                        <p class="small text-muted">Tests: Allocate → Check Stats → Deallocate → Verify Clean State</p>
                                        <div class="mb-3">
                                            <label>Test Amount (£):</label>
                                            <input type="number" id="cycle-amount" value="100" class="form-control" step="25" min="25" max="1000">
                                        </div>
                                        <button class="btn btn-primary" onclick="runFullCycleTest()">
                                            <i class="fas fa-play me-1"></i>Run Full Cycle Test
                                        </button>
                                        
                                        <hr>
                                        
                                        <h6>🧹 Maintenance</h6>
                                        <button class="btn btn-warning btn-sm" onclick="checkOrphanedCells()">
                                            <i class="fas fa-search me-1"></i>Check Orphaned Cells
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="cleanupOrphaned()">
                                            <i class="fas fa-broom me-1"></i>Cleanup Orphaned
                                        </button>
                                        
                                        <hr>
                                        
                                        <div class="d-grid gap-2">
                                            <a href="../../public/projector/floor/" target="_blank" class="btn btn-outline-success">
                                                <i class="fas fa-eye me-1"></i>Floor Map
                                            </a>
                                            <a href="../../admin/approved/" target="_blank" class="btn btn-outline-info">
                                                <i class="fas fa-check me-1"></i>Admin Approvals
                                            </a>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-8">
                                        <h6>📊 Test Results</h6>
                                        <div id="results" class="bg-dark text-light p-3 rounded" style="height: 500px; overflow-y: auto; font-family: monospace; font-size: 12px;">
                                            <div class="text-muted">Test results will appear here...</div>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <button class="btn btn-outline-secondary btn-sm" onclick="clearResults()">
                                                <i class="fas fa-eraser me-1"></i>Clear Results
                                            </button>
                                            <button class="btn btn-outline-info btn-sm" onclick="exportResults()">
                                                <i class="fas fa-download me-1"></i>Export Results
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let testResults = [];

        function logResult(message, data = null, type = 'info') {
            const results = document.getElementById('results');
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = document.createElement('div');
            
            let icon = '📝';
            let color = 'text-info';
            if (type === 'success') { icon = '✅'; color = 'text-success'; }
            if (type === 'error') { icon = '❌'; color = 'text-danger'; }
            if (type === 'warning') { icon = '⚠️'; color = 'text-warning'; }
            
            logEntry.innerHTML = `<div class="${color}"><span class="text-muted">[${timestamp}]</span> ${icon} ${message}</div>`;
            if (data) {
                logEntry.innerHTML += `<pre class="mt-1 mb-2 ps-3" style="border-left: 2px solid #555; font-size: 11px;">${JSON.stringify(data, null, 2)}</pre>`;
            }
            
            results.appendChild(logEntry);
            results.scrollTop = results.scrollHeight;
            
            // Store for export
            testResults.push({ timestamp, type, message, data });
        }

        function runFullCycleTest() {
            const amount = document.getElementById('cycle-amount').value;
            testResults = []; // Clear previous results
            logResult(`🚀 Starting Full Cycle Test with £${amount}`, null, 'info');
            
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=full_cycle_test&amount=${amount}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.type === 'full_cycle') {
                    data.results.forEach((step, index) => {
                        logResult(`Step ${index + 1}: ${step.step}`, step.result, 'success');
                    });
                    
                    // Analyze results
                    const verification = data.results.find(r => r.step === 'Stats Verification');
                    if (verification && verification.result.stats_match) {
                        logResult('🎉 CYCLE TEST PASSED - Stats perfectly match!', null, 'success');
                    } else {
                        logResult('💥 CYCLE TEST FAILED - Stats mismatch detected!', verification, 'error');
                    }
                } else {
                    logResult('💥 Test failed with error', data, 'error');
                }
            })
            .catch(error => {
                logResult(`💥 Test error: ${error.message}`, null, 'error');
            });
        }

        function checkOrphanedCells() {
            logResult('🔍 Checking for orphaned cells...', null, 'info');
            
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=check_orphaned_cells'
            })
            .then(response => response.json())
            .then(data => {
                if (data.orphaned_cells.length > 0) {
                    logResult(`⚠️ Found ${data.orphaned_cells.length} orphaned cells`, data.orphaned_cells, 'warning');
                } else {
                    logResult('✅ No orphaned cells found', null, 'success');
                }
            })
            .catch(error => {
                logResult(`💥 Error: ${error.message}`, null, 'error');
            });
        }

        function cleanupOrphaned() {
            if (!confirm('Clean up orphaned cells? This will reset them to available.')) return;
            
            logResult('🧹 Cleaning up orphaned cells...', null, 'info');
            
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=cleanup_orphaned'
            })
            .then(response => response.json())
            .then(data => {
                logResult(`✅ Cleaned up ${data.cleaned_cells} orphaned cells`, null, 'success');
            })
            .catch(error => {
                logResult(`💥 Error: ${error.message}`, null, 'error');
            });
        }

        function clearResults() {
            document.getElementById('results').innerHTML = '<div class="text-muted">Test results will appear here...</div>';
            testResults = [];
        }

        function exportResults() {
            if (testResults.length === 0) {
                alert('No test results to export');
                return;
            }
            
            const exportData = {
                export_time: new Date().toISOString(),
                test_count: testResults.length,
                results: testResults
            };
            
            const blob = new Blob([JSON.stringify(exportData, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `allocation_cycle_test_${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.json`;
            a.click();
            URL.revokeObjectURL(url);
        }

        // Auto-run orphan check on page load
        window.onload = function() {
            setTimeout(checkOrphanedCells, 1000);
        };
    </script>
</body>
</html>
