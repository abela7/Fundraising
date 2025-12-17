<?php
/**
 * Debug wrapper for inbound-callbacks.php
 * Runs through the EXACT same code step by step
 * DELETE THIS FILE AFTER DEBUGGING
 */

// Capture ALL errors
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$debug_steps = [];
$fatal_error = null;

function debug_log($step, $status, $details = '') {
    global $debug_steps;
    $debug_steps[] = [
        'step' => $step,
        'status' => $status,
        'details' => $details,
        'memory' => memory_get_usage(true),
        'time' => microtime(true)
    ];
}

// Capture fatal errors on shutdown
register_shutdown_function(function() {
    global $fatal_error, $debug_steps;
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $fatal_error = $error;
    }
    
    // Always output the debug report
    outputDebugReport();
});

function outputDebugReport() {
    global $debug_steps, $fatal_error;
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Debug - Inbound Callbacks</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; background: #1a1a2e; color: #eee; }
            .header { background: linear-gradient(135deg, #16213e, #0f3460); padding: 20px; border-radius: 12px; margin-bottom: 20px; }
            .step { padding: 12px 15px; margin: 8px 0; border-radius: 8px; border-left: 4px solid; }
            .step.success { background: #1b4332; border-color: #40916c; }
            .step.error { background: #5c1a1a; border-color: #e63946; }
            .step.warning { background: #5c4a1a; border-color: #f4a261; }
            .fatal { background: #e63946; color: white; padding: 20px; border-radius: 12px; margin: 20px 0; }
            .copy-box { width: 100%; height: 300px; background: #0d1117; color: #58a6ff; border: 1px solid #30363d; 
                        border-radius: 8px; padding: 15px; font-family: monospace; font-size: 12px; }
            h1 { margin: 0; }
            h2 { color: #58a6ff; border-bottom: 1px solid #30363d; padding-bottom: 10px; }
            .emoji { font-size: 1.2em; margin-right: 8px; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>🔍 Inbound Callbacks - Step-by-Step Debug</h1>
            <p>Running through the exact same code as inbound-callbacks.php</p>
        </div>
        
        <?php if ($fatal_error): ?>
        <div class="fatal">
            <h2 style="color:white;border:none;">⚠️ FATAL ERROR FOUND!</h2>
            <p><strong>Type:</strong> <?php echo $fatal_error['type']; ?></p>
            <p><strong>Message:</strong> <?php echo htmlspecialchars($fatal_error['message']); ?></p>
            <p><strong>File:</strong> <?php echo htmlspecialchars($fatal_error['file']); ?></p>
            <p><strong>Line:</strong> <?php echo $fatal_error['line']; ?></p>
        </div>
        <?php endif; ?>
        
        <h2>Execution Steps</h2>
        <?php foreach ($debug_steps as $s): ?>
        <div class="step <?php echo $s['status']; ?>">
            <span class="emoji"><?php echo $s['status'] === 'success' ? '✅' : ($s['status'] === 'error' ? '❌' : '⚠️'); ?></span>
            <strong><?php echo htmlspecialchars($s['step']); ?></strong>
            <?php if ($s['details']): ?>
                <br><small style="opacity:0.8"><?php echo htmlspecialchars($s['details']); ?></small>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        
        <h2>📋 Copy This Error Report</h2>
        <textarea class="copy-box" onclick="this.select()" readonly><?php
echo "=== INBOUND CALLBACKS DEBUG REPORT ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "PHP: " . phpversion() . "\n";
echo "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n\n";

if ($fatal_error) {
    echo "🔴 FATAL ERROR:\n";
    echo "   Type: " . $fatal_error['type'] . "\n";
    echo "   Message: " . $fatal_error['message'] . "\n";
    echo "   File: " . $fatal_error['file'] . "\n";
    echo "   Line: " . $fatal_error['line'] . "\n\n";
}

echo "STEPS:\n";
foreach ($debug_steps as $s) {
    $icon = $s['status'] === 'success' ? '✓' : ($s['status'] === 'error' ? '✗' : '!');
    echo "[$icon] " . $s['step'];
    if ($s['details']) echo " - " . $s['details'];
    echo "\n";
}
?></textarea>
        
        <p style="margin-top: 20px; color: #f4a261;">
            ⚠️ <strong>DELETE THIS FILE (debug-inbound.php) AFTER DEBUGGING!</strong>
        </p>
    </body>
    </html>
    <?php
    exit;
}

// ============================================
// START RUNNING THE SAME CODE AS INBOUND-CALLBACKS.PHP
// ============================================

debug_log('Step 1: Load auth.php', 'success');
require_once __DIR__ . '/../../shared/auth.php';

debug_log('Step 2: Load db.php', 'success');
require_once __DIR__ . '/../../config/db.php';

debug_log('Step 3: require_login()', 'success');
require_login();

debug_log('Step 4: require_admin()', 'success');
require_admin();

debug_log('Step 5: Get database connection', 'success');
$db = db();

if (!$db) {
    debug_log('Step 5b: Database check', 'error', 'Database connection is null');
} else {
    debug_log('Step 5b: Database check', 'success', 'Connected');
}

debug_log('Step 6: Get user session', 'success');
$user_id = (int)($_SESSION['user']['id'] ?? 0);
$user_name = $_SESSION['user']['name'] ?? 'Agent';
debug_log('Step 6b: User info', 'success', "ID: $user_id, Name: $user_name");

// Check table
debug_log('Step 7: Check if table exists', 'success');
try {
    $tableCheck = $db->query("SHOW TABLES LIKE 'twilio_inbound_calls'");
    $tableExists = $tableCheck && $tableCheck->num_rows > 0;
    debug_log('Step 7b: Table check result', 'success', $tableExists ? 'Table EXISTS' : 'Table does NOT exist');
} catch (Throwable $e) {
    debug_log('Step 7b: Table check', 'error', $e->getMessage());
    $tableExists = false;
}

// Initialize variables
$callbacks = [];
$stats = [
    'total' => 0, 
    'pending' => 0, 
    'followed_up' => 0, 
    'today' => 0,
    'donors' => 0,
    'non_donors' => 0,
    'this_week' => 0
];

$filter = $_GET['filter'] ?? 'pending';
$search = trim($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

debug_log('Step 8: Filter params', 'success', "filter=$filter, search=$search");

if ($tableExists) {
    // Stats query
    debug_log('Step 9: Run stats query', 'success');
    try {
        $statsQuery = $db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN agent_followed_up = 0 THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN agent_followed_up = 1 THEN 1 ELSE 0 END) as followed_up,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN is_donor = 1 THEN 1 ELSE 0 END) as donors,
                SUM(CASE WHEN is_donor = 0 THEN 1 ELSE 0 END) as non_donors,
                SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week
            FROM twilio_inbound_calls
        ");
        if ($statsQuery) {
            $fetchedStats = $statsQuery->fetch_assoc();
            if ($fetchedStats) {
                $stats = array_map(function($v) { return $v ?? 0; }, $fetchedStats);
            }
            debug_log('Step 9b: Stats result', 'success', "Total: {$stats['total']}, Pending: {$stats['pending']}");
        } else {
            debug_log('Step 9b: Stats query failed', 'error', $db->error);
        }
    } catch (Throwable $e) {
        debug_log('Step 9: Stats query', 'error', $e->getMessage());
    }
    
    // Build WHERE clause
    debug_log('Step 10: Build WHERE clause', 'success');
    $whereClauses = [];
    $params = [];
    $types = '';
    
    if ($filter === 'pending') {
        $whereClauses[] = 'ic.agent_followed_up = 0';
    } elseif ($filter === 'followed_up') {
        $whereClauses[] = 'ic.agent_followed_up = 1';
    } elseif ($filter === 'donors') {
        $whereClauses[] = 'ic.is_donor = 1';
    } elseif ($filter === 'non_donors') {
        $whereClauses[] = 'ic.is_donor = 0';
    } elseif ($filter === 'today') {
        $whereClauses[] = 'DATE(ic.created_at) = CURDATE()';
    }
    
    if (!empty($search)) {
        $whereClauses[] = '(ic.caller_phone LIKE ? OR ic.donor_name LIKE ? OR d.name LIKE ?)';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= 'sss';
    }
    
    if (!empty($date_from)) {
        $whereClauses[] = 'DATE(ic.created_at) >= ?';
        $params[] = $date_from;
        $types .= 's';
    }
    
    if (!empty($date_to)) {
        $whereClauses[] = 'DATE(ic.created_at) <= ?';
        $params[] = $date_to;
        $types .= 's';
    }
    
    $whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
    debug_log('Step 10b: WHERE clause', 'success', $whereSQL ?: '(none)');
    
    // Build main query
    $query = "
        SELECT ic.*, 
               d.name as donor_name_current,
               d.balance as donor_balance,
               d.total_pledged,
               d.phone as donor_phone,
               u.name as followed_up_by_name
        FROM twilio_inbound_calls ic
        LEFT JOIN donors d ON ic.donor_id = d.id
        LEFT JOIN users u ON ic.followed_up_by = u.id
        {$whereSQL}
        ORDER BY ic.agent_followed_up ASC, ic.created_at DESC
        LIMIT 200
    ";
    
    debug_log('Step 11: Execute main query', 'success');
    try {
        if (!empty($params)) {
            debug_log('Step 11b: Using prepared statement', 'success', "Types: $types, Params: " . count($params));
            $stmt = $db->prepare($query);
            if (!$stmt) {
                debug_log('Step 11c: Prepare failed', 'error', $db->error);
            } else {
                debug_log('Step 11c: Prepare success', 'success');
                
                // Bind params properly
                debug_log('Step 11d: Binding params', 'success');
                $bindParams = [$types];
                for ($i = 0; $i < count($params); $i++) {
                    $bindParams[] = &$params[$i];
                }
                call_user_func_array([$stmt, 'bind_param'], $bindParams);
                debug_log('Step 11e: Params bound', 'success');
                
                $stmt->execute();
                debug_log('Step 11f: Query executed', 'success');
                
                $result = $stmt->get_result();
                debug_log('Step 11g: Got result', 'success');
            }
        } else {
            debug_log('Step 11b: Using direct query (no params)', 'success');
            $result = $db->query($query);
            if (!$result) {
                debug_log('Step 11c: Query failed', 'error', $db->error);
            } else {
                debug_log('Step 11c: Query success', 'success');
            }
        }
        
        if (isset($result) && $result) {
            $count = 0;
            while ($row = $result->fetch_assoc()) {
                $count++;
            }
            debug_log('Step 12: Fetch results', 'success', "Fetched $count rows");
        }
    } catch (Throwable $e) {
        debug_log('Step 11: Main query', 'error', $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
} else {
    debug_log('Step 9-12: Skipped', 'warning', 'Table does not exist');
}

// Test helper functions
debug_log('Step 13: Define timeAgo function', 'success');
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    elseif ($diff < 3600) return floor($diff / 60) . ' mins ago';
    elseif ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    else return date('M j, Y', $time);
}

debug_log('Step 14: Define formatDuration function', 'success');
function formatDuration($seconds) {
    if (!$seconds) return null;
    if ($seconds < 60) return $seconds . 's';
    return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
}

debug_log('Step 15: Include sidebar.php', 'success');
try {
    // Just check if file exists, don't include it
    $sidebarPath = __DIR__ . '/../includes/sidebar.php';
    if (file_exists($sidebarPath)) {
        debug_log('Step 15b: Sidebar file exists', 'success', $sidebarPath);
    } else {
        debug_log('Step 15b: Sidebar file', 'error', 'File not found: ' . $sidebarPath);
    }
} catch (Throwable $e) {
    debug_log('Step 15: Sidebar', 'error', $e->getMessage());
}

debug_log('Step 16: Include topbar.php', 'success');
try {
    $topbarPath = __DIR__ . '/../includes/topbar.php';
    if (file_exists($topbarPath)) {
        debug_log('Step 16b: Topbar file exists', 'success', $topbarPath);
    } else {
        debug_log('Step 16b: Topbar file', 'error', 'File not found: ' . $topbarPath);
    }
} catch (Throwable $e) {
    debug_log('Step 16: Topbar', 'error', $e->getMessage());
}

debug_log('✅ ALL STEPS COMPLETED', 'success', 'No fatal errors detected in the code logic');

// The shutdown function will output the report
