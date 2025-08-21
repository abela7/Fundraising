<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/IntelligentGridAllocator.php';
require_login();
require_admin();

$db = db();
$gridAllocator = new IntelligentGridAllocator($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'test_signal') {
        // Send immediate refresh signal
        echo "<script>localStorage.setItem('floorMapRefresh', Date.now());</script>";
        echo json_encode(['success' => true, 'message' => 'Refresh signal sent']);
        exit;
    }
}

$page_title = 'Test Immediate Refresh';
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
                                    <i class="fas fa-bolt me-2"></i>Test Immediate Refresh System
                                </h5>
                                <small class="text-muted">Test the real-time floor map refresh when admin actions occur</small>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="alert alert-info">
                                            <h6><i class="fas fa-info-circle me-2"></i>How It Works</h6>
                                            <ol class="mb-0">
                                                <li>Open the floor map in another tab/window</li>
                                                <li>Use the test buttons below to simulate admin actions</li>
                                                <li>Watch the floor map update immediately (no waiting for polling)</li>
                                            </ol>
                                        </div>
                                        
                                        <div class="d-grid gap-2">
                                            <a href="../../public/projector/floor/" target="_blank" class="btn btn-success">
                                                <i class="fas fa-external-link-alt me-2"></i>Open Floor Map
                                            </a>
                                            
                                            <button class="btn btn-warning" onclick="testRefreshSignal()">
                                                <i class="fas fa-bolt me-2"></i>Send Refresh Signal
                                            </button>
                                            
                                            <a href="../approved/" target="_blank" class="btn btn-info">
                                                <i class="fas fa-tasks me-2"></i>Go to Approvals Page
                                            </a>
                                        </div>
                                        
                                        <hr>
                                        
                                        <h6>Manual Testing Steps:</h6>
                                        <ol class="small">
                                            <li>Open floor map in new tab</li>
                                            <li>Go to admin approvals page</li>
                                            <li>Approve a donation → See immediate floor update</li>
                                            <li>Unapprove the donation → See immediate floor clear</li>
                                        </ol>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <h6>Real-Time Testing</h6>
                                        <div id="test-log" class="bg-dark text-light p-3 rounded" style="height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px;">
                                            <div class="text-muted">Test log will appear here...</div>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <button class="btn btn-outline-secondary btn-sm" onclick="clearLog()">
                                                <i class="fas fa-eraser me-1"></i>Clear Log
                                            </button>
                                            <button class="btn btn-outline-info btn-sm" onclick="checkLocalStorage()">
                                                <i class="fas fa-search me-1"></i>Check LocalStorage
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
        function log(message, type = 'info') {
            const testLog = document.getElementById('test-log');
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = document.createElement('div');
            
            let icon = '📝';
            let color = 'text-info';
            if (type === 'success') { icon = '✅'; color = 'text-success'; }
            if (type === 'error') { icon = '❌'; color = 'text-danger'; }
            if (type === 'warning') { icon = '⚠️'; color = 'text-warning'; }
            
            logEntry.innerHTML = `<div class="${color}"><span class="text-muted">[${timestamp}]</span> ${icon} ${message}</div>`;
            testLog.appendChild(logEntry);
            testLog.scrollTop = testLog.scrollHeight;
        }

        function testRefreshSignal() {
            log('🚀 Sending immediate refresh signal to all floor map windows...', 'info');
            localStorage.setItem('floorMapRefresh', Date.now());
            log('📡 Signal sent via localStorage', 'success');
            log('Floor maps should refresh immediately if open', 'info');
        }

        function checkLocalStorage() {
            const lastRefresh = localStorage.getItem('floorMapRefresh');
            if (lastRefresh) {
                const time = new Date(parseInt(lastRefresh)).toLocaleTimeString();
                log(`🔍 Last refresh signal: ${time}`, 'info');
            } else {
                log('🔍 No refresh signals found in localStorage', 'warning');
            }
        }

        function clearLog() {
            document.getElementById('test-log').innerHTML = '<div class="text-muted">Test log will appear here...</div>';
        }

        // Listen for refresh signals
        window.addEventListener('storage', (e) => {
            if (e.key === 'floorMapRefresh' && e.newValue) {
                log('📥 Detected refresh signal from another window/tab', 'success');
            }
        });

        // Auto-check localStorage on page load
        window.onload = function() {
            setTimeout(checkLocalStorage, 1000);
            log('🎯 Immediate refresh test system ready', 'success');
            log('💡 Press R on floor map to test manual refresh', 'info');
        };
    </script>
</body>
</html>
