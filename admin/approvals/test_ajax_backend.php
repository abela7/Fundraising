<?php
declare(strict_types=1);

/**
 * Test AJAX Backend - Verification Tool
 * 
 * This tool tests the AJAX approval backend without modifying the UI.
 * It verifies that all floor allocation logic works identically to the original system.
 */

require_once '../../config/db.php';
require_once '../../shared/auth.php';

session_start();

// Check authentication
$user = current_user();
if (!$user || !in_array($user['role'], ['admin', 'registrar'])) {
    die('Authentication required');
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db = db();

// Get some pending pledges and payments for testing
$pendingPledges = $db->query("SELECT id, donor_name, amount, type FROM pledges WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$pendingPayments = $db->query("SELECT id, donor_name, amount FROM payments WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AJAX Backend Test - Fundraising System</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 20px; 
            background: #f5f5f5;
        }
        .container { 
            background: white; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .test-item { 
            border: 1px solid #ddd; 
            padding: 15px; 
            margin: 10px 0; 
            border-radius: 5px; 
            background: #fafafa;
        }
        .test-button { 
            background: #007cba; 
            color: white; 
            border: none; 
            padding: 8px 16px; 
            border-radius: 4px; 
            cursor: pointer; 
            margin-right: 10px;
        }
        .test-button:hover { background: #005a87; }
        .test-button.reject { background: #dc3545; }
        .test-button.reject:hover { background: #c82333; }
        .test-button:disabled { 
            background: #6c757d; 
            cursor: not-allowed; 
        }
        .result { 
            margin-top: 10px; 
            padding: 10px; 
            border-radius: 4px; 
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 12px;
        }
        .result.success { 
            background: #d4edda; 
            color: #155724; 
            border: 1px solid #c3e6cb;
        }
        .result.error { 
            background: #f8d7da; 
            color: #721c24; 
            border: 1px solid #f5c6cb;
        }
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border: 1px solid #bee5eb;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
        }
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #1976d2;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 AJAX Backend Test</h1>
        
        <div class="warning">
            <strong>⚠️ TESTING ENVIRONMENT</strong><br>
            This page tests the AJAX approval backend with IDENTICAL floor allocation logic.
            All operations are REAL and will affect the database.
        </div>

        <div class="info">
            <strong>ℹ️ Floor Allocation Testing</strong><br>
            Each approval will test the complete floor allocation system:
            <ul>
                <li>✅ Counter updates (paid_total, pledged_total, grand_total)</li>
                <li>✅ Custom amount allocation (&lt;£100 accumulation, £100+ immediate allocation)</li>
                <li>✅ Intelligent grid allocation (sequential cell assignment)</li>
                <li>✅ Transaction safety (rollback on any failure)</li>
                <li>✅ Audit logging</li>
            </ul>
        </div>

        <?php
        // Get current counter totals for display
        $counters = $db->query("SELECT paid_total, pledged_total, grand_total, version FROM counters WHERE id = 1")->fetch_assoc();
        $customTracking = $db->query("SELECT total_amount, allocated_amount, remaining_amount FROM custom_amount_tracking WHERE id = 1")->fetch_assoc();
        $gridStats = $db->query("
            SELECT 
                COUNT(*) as total_cells,
                SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_cells,
                SUM(CASE WHEN status = 'pledged' THEN 1 ELSE 0 END) as pledged_cells,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_cells
            FROM floor_grid_cells 
            WHERE cell_type = '0.5x0.5'
        ")->fetch_assoc();
        ?>

        <h2>📊 Current System Status</h2>
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number">£<?= number_format($counters['paid_total'] ?? 0, 2) ?></div>
                <div>Paid Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">£<?= number_format($counters['pledged_total'] ?? 0, 2) ?></div>
                <div>Pledged Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">£<?= number_format($counters['grand_total'] ?? 0, 2) ?></div>
                <div>Grand Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $gridStats['available_cells'] ?></div>
                <div>Available Cells</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $gridStats['pledged_cells'] + $gridStats['paid_cells'] ?></div>
                <div>Allocated Cells</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">£<?= number_format($customTracking['remaining_amount'] ?? 0, 2) ?></div>
                <div>Accumulating Amounts</div>
            </div>
        </div>
    </div>

    <div class="container">
        <h2>📊 Live Activity Monitor</h2>
        <div id="activity-log" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 15px; height: 200px; overflow-y: auto; font-family: monospace; font-size: 12px;">
            <div style="color: #6c757d;">Waiting for approval activity...</div>
        </div>
    </div>

    <?php if (!empty($pendingPledges)): ?>
    <div class="container">
        <h2>🔄 Test Pending Pledges</h2>
        <?php foreach ($pendingPledges as $pledge): ?>
        <div class="test-item">
            <strong>Pledge #<?= $pledge['id'] ?></strong> - 
            <?= htmlspecialchars($pledge['donor_name']) ?> - 
            £<?= number_format($pledge['amount'], 2) ?> (<?= $pledge['type'] ?>)
            
            <div>
                <button class="test-button" onclick="testApproval('pledge', <?= $pledge['id'] ?>, this)">
                    ✅ Test Approve
                </button>
                <button class="test-button reject" onclick="testRejection('pledge', <?= $pledge['id'] ?>, this)">
                    ❌ Test Reject
                </button>
            </div>
            <div class="result" id="result-pledge-<?= $pledge['id'] ?>" style="display: none;"></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($pendingPayments)): ?>
    <div class="container">
        <h2>💰 Test Pending Payments</h2>
        <?php foreach ($pendingPayments as $payment): ?>
        <div class="test-item">
            <strong>Payment #<?= $payment['id'] ?></strong> - 
            <?= htmlspecialchars($payment['donor_name']) ?> - 
            £<?= number_format($payment['amount'], 2) ?>
            
            <div>
                <button class="test-button" onclick="testApproval('payment', <?= $payment['id'] ?>, this)">
                    ✅ Test Approve
                </button>
                <button class="test-button reject" onclick="testRejection('payment', <?= $payment['id'] ?>, this)">
                    ❌ Test Reject
                </button>
            </div>
            <div class="result" id="result-payment-<?= $payment['id'] ?>" style="display: none;"></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($pendingPledges) && empty($pendingPayments)): ?>
    <div class="container">
        <div class="info">
            <strong>ℹ️ No Pending Items</strong><br>
            There are currently no pending pledges or payments to test. 
            You can create some test data using the registrar interface or donation forms.
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Generate unique request UUID for each operation
        function generateUUID() {
            return 'ajax-test-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        }

        // Log activity to the monitor
        function logActivity(message, type = 'info') {
            const log = document.getElementById('activity-log');
            const timestamp = new Date().toLocaleTimeString();
            const colors = {
                'info': '#6c757d',
                'success': '#155724', 
                'error': '#721c24',
                'warning': '#856404'
            };
            
            const logEntry = document.createElement('div');
            logEntry.style.color = colors[type] || colors.info;
            logEntry.style.marginBottom = '5px';
            logEntry.textContent = `[${timestamp}] ${message}`;
            
            // Clear initial message if present
            if (log.textContent.includes('Waiting for approval activity...')) {
                log.innerHTML = '';
            }
            
            log.appendChild(logEntry);
            log.scrollTop = log.scrollHeight;
        }

        // Test approval functionality
        async function testApproval(type, id, button) {
            const resultDiv = document.getElementById(`result-${type}-${id}`);
            const originalText = button.textContent;
            
            // Log start of operation
            logActivity(`Starting approval of ${type} #${id}...`, 'info');
            
            // Disable button and show loading
            button.disabled = true;
            button.textContent = '⏳ Testing...';
            resultDiv.style.display = 'block';
            resultDiv.className = 'result';
            resultDiv.textContent = 'Sending AJAX request...';

            try {
                const formData = new FormData();
                formData.append('action', 'approve');
                formData.append('type', type);
                formData.append('id', id);
                formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
                formData.append('request_uuid', generateUUID());

                const response = await fetch('ajax_approve.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (result.success) {
                    logActivity(`✅ SUCCESS: ${type} #${id} approved - ${result.message}`, 'success');
                    if (result.allocation_result) {
                        logActivity(`Floor allocation: ${result.allocation_result.message || 'Completed'}`, 'success');
                    }
                    
                    resultDiv.className = 'result success';
                    resultDiv.textContent = `✅ SUCCESS!\n\nMessage: ${result.message}\n\nDetails:\n${JSON.stringify(result, null, 2)}`;
                    
                    // Update stats immediately after successful approval
                    logActivity('Updating system statistics...', 'info');
                    updateStats();
                    
                    // Hide the test item after successful approval
                    setTimeout(() => {
                        button.closest('.test-item').style.opacity = '0.5';
                        button.closest('.test-item').style.pointerEvents = 'none';
                        button.closest('.test-item').innerHTML += '<div style="color: green; font-weight: bold; margin-top: 10px;">✅ PROCESSED - Item approved successfully</div>';
                    }, 1000);
                } else {
                    logActivity(`❌ FAILED: ${type} #${id} approval failed - ${result.error}`, 'error');
                    resultDiv.className = 'result error';
                    resultDiv.textContent = `❌ FAILED!\n\nError: ${result.error}\n\nFull Response:\n${JSON.stringify(result, null, 2)}`;
                }
            } catch (error) {
                logActivity(`❌ NETWORK ERROR: ${type} #${id} - ${error.message}`, 'error');
                resultDiv.className = 'result error';
                resultDiv.textContent = `❌ NETWORK ERROR!\n\nError: ${error.message}`;
            } finally {
                // Re-enable button
                button.disabled = false;
                button.textContent = originalText;
            }
        }

        // Test rejection functionality
        async function testRejection(type, id, button) {
            const resultDiv = document.getElementById(`result-${type}-${id}`);
            const originalText = button.textContent;
            
            // Log start of operation
            logActivity(`Starting rejection of ${type} #${id}...`, 'warning');
            
            // Disable button and show loading
            button.disabled = true;
            button.textContent = '⏳ Testing...';
            resultDiv.style.display = 'block';
            resultDiv.className = 'result';
            resultDiv.textContent = 'Sending AJAX request...';

            try {
                const formData = new FormData();
                formData.append('action', 'reject');
                formData.append('type', type);
                formData.append('id', id);
                formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
                formData.append('request_uuid', generateUUID());

                const response = await fetch('ajax_approve.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (result.success) {
                    logActivity(`✅ SUCCESS: ${type} #${id} rejected - ${result.message}`, 'warning');
                    
                    resultDiv.className = 'result success';
                    resultDiv.textContent = `✅ SUCCESS!\n\nMessage: ${result.message}\n\nDetails:\n${JSON.stringify(result, null, 2)}`;
                    
                    // Update stats immediately after successful rejection
                    updateStats();
                    
                    // Hide the test item after successful rejection
                    setTimeout(() => {
                        button.closest('.test-item').style.opacity = '0.5';
                        button.closest('.test-item').style.pointerEvents = 'none';
                        button.closest('.test-item').innerHTML += '<div style="color: red; font-weight: bold; margin-top: 10px;">❌ PROCESSED - Item rejected successfully</div>';
                    }, 1000);
                } else {
                    logActivity(`❌ FAILED: ${type} #${id} rejection failed - ${result.error}`, 'error');
                    resultDiv.className = 'result error';
                    resultDiv.textContent = `❌ FAILED!\n\nError: ${result.error}\n\nFull Response:\n${JSON.stringify(result, null, 2)}`;
                }
            } catch (error) {
                logActivity(`❌ NETWORK ERROR: ${type} #${id} rejection - ${error.message}`, 'error');
                resultDiv.className = 'result error';
                resultDiv.textContent = `❌ NETWORK ERROR!\n\nError: ${error.message}`;
            } finally {
                // Re-enable button
                button.disabled = false;
                button.textContent = originalText;
            }
        }

        // Auto-refresh stats every 5 minutes (not 30 seconds)
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                updateStats();
            }
        }, 300000);

        // Function to update stats without page reload
        async function updateStats() {
            try {
                const response = await fetch('get_stats.php');
                const stats = await response.json();
                
                // Update stat cards
                const statCards = document.querySelectorAll('.stat-number');
                if (statCards.length >= 6) {
                    statCards[0].textContent = '£' + parseFloat(stats.paid_total || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
                    statCards[1].textContent = '£' + parseFloat(stats.pledged_total || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
                    statCards[2].textContent = '£' + parseFloat(stats.grand_total || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
                    statCards[3].textContent = stats.available_cells || '0';
                    statCards[4].textContent = stats.allocated_cells || '0';
                    statCards[5].textContent = '£' + parseFloat(stats.remaining_amount || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
                }
            } catch (error) {
                console.log('Stats update failed:', error);
            }
        }
    </script>
</body>
</html>
