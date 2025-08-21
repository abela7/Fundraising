<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/IntelligentGridAllocator.php';
require_login();
require_admin();

$db = db();
$gridAllocator = new IntelligentGridAllocator($db);

// Test the deallocation functionality
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'test_allocation') {
        $amount = (float)($_POST['amount'] ?? 100);
        $result = $gridAllocator->allocate(999999, null, $amount, null, 'Test Donor', 'pledged');
        echo json_encode(['type' => 'allocation', 'result' => $result]);
        exit;
    }
    
    if ($action === 'test_deallocation') {
        $pledgeId = (int)($_POST['pledge_id'] ?? 999999);
        $result = $gridAllocator->deallocate($pledgeId, null);
        echo json_encode(['type' => 'deallocation', 'result' => $result]);
        exit;
    }
    
    if ($action === 'get_stats') {
        $stats = $gridAllocator->getAllocationStats();
        echo json_encode(['type' => 'stats', 'result' => $stats]);
        exit;
    }
}

$page_title = 'Test Deallocation System';
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
                                    <i class="fas fa-flask me-2"></i>Test Deallocation System
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Test Allocation</h6>
                                        <div class="mb-3">
                                            <label>Amount to Allocate:</label>
                                            <input type="number" id="allocation-amount" value="100" class="form-control" step="25" min="25">
                                        </div>
                                        <button class="btn btn-success" onclick="testAllocation()">
                                            <i class="fas fa-plus me-1"></i>Allocate Test Cells
                                        </button>
                                        
                                        <hr>
                                        
                                        <h6>Test Deallocation</h6>
                                        <div class="mb-3">
                                            <label>Pledge ID to Deallocate:</label>
                                            <input type="number" id="pledge-id" value="999999" class="form-control">
                                        </div>
                                        <button class="btn btn-danger" onclick="testDeallocation()">
                                            <i class="fas fa-minus me-1"></i>Deallocate Test Cells
                                        </button>
                                        
                                        <hr>
                                        
                                        <button class="btn btn-info" onclick="getStats()">
                                            <i class="fas fa-chart-bar me-1"></i>Get Current Stats
                                        </button>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <h6>Results</h6>
                                        <div id="results" class="bg-dark text-light p-3 rounded" style="height: 400px; overflow-y: auto; font-family: monospace;">
                                            <div class="text-muted">Test results will appear here...</div>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <button class="btn btn-outline-secondary btn-sm" onclick="clearResults()">
                                                <i class="fas fa-eraser me-1"></i>Clear Results
                                            </button>
                                            <a href="../../public/projector/floor/" target="_blank" class="btn btn-outline-success btn-sm">
                                                <i class="fas fa-eye me-1"></i>View Floor Map
                                            </a>
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
        function logResult(message, data = null) {
            const results = document.getElementById('results');
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = document.createElement('div');
            logEntry.innerHTML = `<span class="text-warning">[${timestamp}]</span> ${message}`;
            if (data) {
                logEntry.innerHTML += `<pre class="mt-1 mb-1">${JSON.stringify(data, null, 2)}</pre>`;
            }
            results.appendChild(logEntry);
            results.scrollTop = results.scrollHeight;
        }

        function testAllocation() {
            const amount = document.getElementById('allocation-amount').value;
            logResult(`🔄 Testing allocation of £${amount}...`);
            
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=test_allocation&amount=${amount}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.result.success) {
                    logResult(`✅ Allocation successful!`, data.result);
                } else {
                    logResult(`❌ Allocation failed!`, data.result);
                }
            })
            .catch(error => {
                logResult(`💥 Error: ${error.message}`);
            });
        }

        function testDeallocation() {
            const pledgeId = document.getElementById('pledge-id').value;
            logResult(`🔄 Testing deallocation of pledge ID ${pledgeId}...`);
            
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=test_deallocation&pledge_id=${pledgeId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.result.success) {
                    logResult(`✅ Deallocation successful!`, data.result);
                } else {
                    logResult(`❌ Deallocation failed!`, data.result);
                }
            })
            .catch(error => {
                logResult(`💥 Error: ${error.message}`);
            });
        }

        function getStats() {
            logResult(`🔄 Getting current allocation stats...`);
            
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get_stats`
            })
            .then(response => response.json())
            .then(data => {
                logResult(`📊 Current Statistics:`, data.result);
            })
            .catch(error => {
                logResult(`💥 Error: ${error.message}`);
            });
        }

        function clearResults() {
            document.getElementById('results').innerHTML = '<div class="text-muted">Test results will appear here...</div>';
        }

        // Auto-load stats on page load
        window.onload = function() {
            setTimeout(getStats, 500);
        };
    </script>
</body>
</html>
