<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'test_localStorage') {
        // Test localStorage trigger
        $_SESSION['trigger_floor_refresh'] = true;
        echo json_encode(['success' => true, 'message' => 'Session flag set, page should trigger on reload']);
        exit;
    }
    
    if ($action === 'manual_trigger') {
        // Manual trigger
        echo "<script>
            console.log('Manual trigger executed');
            localStorage.setItem('floorMapRefresh', Date.now());
            alert('Manual refresh signal sent!');
        </script>";
        exit;
    }
}

$page_title = 'Debug Refresh Issue';
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
                                    <i class="fas fa-bug me-2"></i>Debug Refresh Issue
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>🔧 Test Tools</h6>
                                        
                                        <div class="d-grid gap-2 mb-3">
                                            <a href="../../public/projector/floor/" target="floormap" class="btn btn-success">
                                                <i class="fas fa-external-link-alt me-2"></i>Open Floor Map (Named Window)
                                            </a>
                                            
                                            <button class="btn btn-warning" onclick="manualTrigger()">
                                                <i class="fas fa-bolt me-2"></i>Manual localStorage Trigger
                                            </button>
                                            
                                            <button class="btn btn-info" onclick="directRefresh()">
                                                <i class="fas fa-sync me-2"></i>Direct Window Refresh
                                            </button>
                                            
                                            <button class="btn btn-secondary" onclick="checkStorage()">
                                                <i class="fas fa-search me-2"></i>Check localStorage
                                            </button>
                                        </div>
                                        
                                        <div class="alert alert-info">
                                            <h6>Test Steps:</h6>
                                            <ol class="mb-0">
                                                <li>Open floor map in named window</li>
                                                <li>Try manual trigger</li>
                                                <li>Check browser console on both windows</li>
                                                <li>Try direct window refresh</li>
                                            </ol>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <h6>📊 Debug Log</h6>
                                        <div id="debug-log" class="bg-dark text-light p-3 rounded" style="height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px;">
                                            <div class="text-muted">Debug messages will appear here...</div>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <button class="btn btn-outline-secondary btn-sm" onclick="clearLog()">
                                                <i class="fas fa-eraser me-1"></i>Clear Log
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
            const debugLog = document.getElementById('debug-log');
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = document.createElement('div');
            
            let icon = '📝';
            let color = 'text-info';
            if (type === 'success') { icon = '✅'; color = 'text-success'; }
            if (type === 'error') { icon = '❌'; color = 'text-danger'; }
            if (type === 'warning') { icon = '⚠️'; color = 'text-warning'; }
            
            logEntry.innerHTML = `<div class="${color}"><span class="text-muted">[${timestamp}]</span> ${icon} ${message}</div>`;
            debugLog.appendChild(logEntry);
            debugLog.scrollTop = debugLog.scrollHeight;
        }

        function manualTrigger() {
            log('🚀 Sending manual localStorage trigger...', 'info');
            localStorage.setItem('floorMapRefresh', Date.now());
            log('📡 localStorage signal sent', 'success');
            
            // Also log the actual value
            const value = localStorage.getItem('floorMapRefresh');
            log(`📊 localStorage value: ${value}`, 'info');
        }

        function directRefresh() {
            log('🎯 Attempting direct window refresh...', 'info');
            try {
                // Try to access the named window
                const floorWindow = window.open('', 'floormap');
                if (floorWindow && floorWindow.refreshFloorMap) {
                    floorWindow.refreshFloorMap();
                    log('✅ Direct refresh called successfully', 'success');
                } else {
                    log('❌ No floor map window found or refreshFloorMap not available', 'error');
                }
            } catch (e) {
                log(`❌ Error accessing window: ${e.message}`, 'error');
            }
        }

        function checkStorage() {
            const value = localStorage.getItem('floorMapRefresh');
            if (value) {
                const time = new Date(parseInt(value)).toLocaleTimeString();
                log(`🔍 localStorage floorMapRefresh: ${value} (${time})`, 'info');
            } else {
                log('🔍 No floorMapRefresh value in localStorage', 'warning');
            }
        }

        function clearLog() {
            document.getElementById('debug-log').innerHTML = '<div class="text-muted">Debug messages will appear here...</div>';
        }

        // Listen for storage events
        window.addEventListener('storage', (e) => {
            if (e.key === 'floorMapRefresh') {
                log(`📥 Storage event: ${e.key} = ${e.newValue}`, 'success');
            }
        });

        // Check if session trigger was set
        <?php if (isset($_SESSION['trigger_floor_refresh']) && $_SESSION['trigger_floor_refresh']): ?>
        <?php unset($_SESSION['trigger_floor_refresh']); ?>
        log('🎯 Session trigger detected - sending refresh signal', 'warning');
        localStorage.setItem('floorMapRefresh', Date.now());
        <?php endif; ?>

        window.onload = function() {
            log('🎯 Debug page loaded', 'success');
            checkStorage();
        };
    </script>
</body>
</html>
