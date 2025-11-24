<?php
declare(strict_types=1);

// ============================================
// CRITICAL: Error handling - catch ALL errors
// ============================================
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Log but don't die on warnings/notices
    if ($errno === E_ERROR || $errno === E_PARSE || $errno === E_CORE_ERROR) {
        error_log("FATAL PHP ERROR: {$errstr} in {$errfile}:{$errline}");
        // Don't output - let the page handle it
    }
    return false; // Let PHP handle it normally
});

// ============================================
// CRITICAL: Start output buffering to catch any premature output
// ============================================
ob_start();

// ============================================
// CRITICAL: Ensure output buffer flushes at end
// ============================================
register_shutdown_function(function() {
    // Flush any remaining output
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
});

// ============================================
// DEBUG MODE - Track Every Step
// ============================================
$debug_mode = true;
$debug_log = [];
$debug_start_time = microtime(true);

function debug_log($step, $message, $data = null) {
    global $debug_log;
    $debug_log[] = [
        'step' => $step,
        'time' => microtime(true),
        'message' => $message,
        'data' => $data
    ];
    error_log("[ASSIGN-DONORS] Step {$step}: {$message}");
}

debug_log(1, "Script started", ['file' => __FILE__]);

// ============================================
// STEP 1: Load Dependencies
// ============================================
try {
    debug_log(2, "Loading auth.php");
require_once __DIR__ . '/../../shared/auth.php';
    debug_log(3, "auth.php loaded successfully");
} catch (Exception $e) {
    debug_log(2, "FAILED loading auth.php", ['error' => $e->getMessage()]);
    die("FATAL: Cannot load auth.php - " . $e->getMessage());
}

try {
    debug_log(4, "Loading db.php");
require_once __DIR__ . '/../../config/db.php';
    debug_log(5, "db.php loaded successfully");
} catch (Exception $e) {
    debug_log(4, "FAILED loading db.php", ['error' => $e->getMessage()]);
    die("FATAL: Cannot load db.php - " . $e->getMessage());
}

// ============================================
// STEP 2: Authentication
// ============================================
try {
    debug_log(6, "Checking admin access");
    require_admin();
    debug_log(7, "Admin access granted");
} catch (Exception $e) {
    debug_log(6, "FAILED admin check", ['error' => $e->getMessage()]);
    die("FATAL: Admin access denied - " . $e->getMessage());
}

// ============================================
// STEP 3: Initialize Variables
// ============================================
debug_log(8, "Initializing variables");
$page_title = 'Assign Donors to Agents';
$error_message = null;
$donors = null;
$churches = null;
$agents = null;
$stats = ['total' => 0, 'assigned' => 0, 'unassigned' => 0];
$total_donors = 0;
$total_pages = 1;
$db = null;
debug_log(9, "Variables initialized");

// ============================================
// STEP 4: Database Connection
// ============================================
try {
    debug_log(10, "Connecting to database");
    $db = db();
    debug_log(11, "Database connected", ['db_name' => $db->get_server_info()]);
} catch (Exception $e) {
    debug_log(10, "FAILED database connection", ['error' => $e->getMessage()]);
    $error_message = "Database connection failed: " . $e->getMessage();
}

// ============================================
// STEP 5: Column Check
// ============================================
if ($db && !$error_message) {
    try {
        debug_log(12, "Checking donor table columns");
        $columns_check = $db->query("SHOW COLUMNS FROM donors");
        if (!$columns_check) {
            throw new Exception("Query failed: " . $db->error);
        }
        
        $existing_cols = [];
        while ($col = $columns_check->fetch_assoc()) {
            $existing_cols[] = $col['Field'];
        }
        debug_log(13, "Columns retrieved", ['count' => count($existing_cols)]);
        
        $required = ['donor_type', 'agent_id'];
        $missing = array_diff($required, $existing_cols);
        
        if (!empty($missing)) {
            throw new Exception("Missing columns: " . implode(', ', $missing));
        }
        debug_log(14, "All required columns exist");
    } catch (Exception $e) {
        debug_log(12, "FAILED column check", ['error' => $e->getMessage()]);
        $error_message = "Column check failed: " . $e->getMessage();
    }
}

// ============================================
// STEP 6: Get Filter Parameters (ALWAYS initialize, even if no DB)
// ============================================
debug_log(15, "Processing GET parameters");
try {
    // Initialize ALL variables FIRST, before any conditionals
    $search = isset($_GET['search']) ? (string)$_GET['search'] : '';
    $church_filter = isset($_GET['church']) ? (int)$_GET['church'] : 0;
    $status_filter = isset($_GET['status']) ? (string)$_GET['status'] : '';
    $assignment_filter = isset($_GET['assignment']) ? (string)$_GET['assignment'] : 'all';
    $agent_filter = isset($_GET['agent']) ? (int)$_GET['agent'] : 0;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = 50;
    $offset = ($page - 1) * $per_page;
    
    // Initialize query building variables
    $where_conditions = [];
    $params = [];
    $param_types = '';
    $where_clause = '';
    
    debug_log(16, "Parameters processed", [
        'search' => $search,
        'church' => $church_filter,
        'status' => $status_filter,
        'assignment' => $assignment_filter,
        'agent' => $agent_filter,
        'page' => $page,
        'get_count' => count($_GET)
    ]);
} catch (Exception $e) {
    debug_log(15, "FAILED parameter processing", ['error' => $e->getMessage()]);
    // Set defaults on error
    $search = '';
    $church_filter = 0;
    $status_filter = '';
    $assignment_filter = 'all';
    $agent_filter = 0;
    $page = 1;
    $per_page = 50;
    $offset = 0;
    $where_conditions = [];
    $params = [];
    $param_types = '';
    $where_clause = '';
    $error_message = "Parameter error: " . $e->getMessage();
}

// ============================================
// STEP 7: Build WHERE Clause (only if DB connected)
// ============================================
if ($db && !$error_message) {
    try {
        debug_log(17, "Building WHERE clause");
        // Reset query building variables
        $where_conditions = ["d.donor_type = 'pledge'"];
        $params = [];
        $param_types = '';

if ($search) {
    $where_conditions[] = "(d.name LIKE ? OR d.phone LIKE ? OR d.email LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'sss';
}

if ($church_filter > 0) {
    $where_conditions[] = "d.church_id = ?";
    $params[] = $church_filter;
    $param_types .= 'i';
}

if ($status_filter) {
    $where_conditions[] = "d.payment_status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

if ($assignment_filter === 'assigned') {
    $where_conditions[] = "d.agent_id IS NOT NULL";
} elseif ($assignment_filter === 'unassigned') {
    $where_conditions[] = "d.agent_id IS NULL";
}

if ($agent_filter > 0) {
    $where_conditions[] = "d.agent_id = ?";
    $params[] = $agent_filter;
    $param_types .= 'i';
}

        $where_clause = implode(' AND ', $where_conditions);
        
        // Safety check: ensure WHERE clause is never empty
        if (empty($where_clause)) {
            $where_clause = "d.donor_type = 'pledge'";
            debug_log(18, "WHERE clause was empty, using default");
        }
        
        debug_log(18, "WHERE clause built", [
            'clause' => $where_clause,
            'param_count' => count($params),
            'param_types' => $param_types,
            'conditions_count' => count($where_conditions)
        ]);
    } catch (Exception $e) {
        debug_log(17, "FAILED building WHERE clause", ['error' => $e->getMessage()]);
        $error_message = "Query building error: " . $e->getMessage();
    }
}

// ============================================
// STEP 8: Count Query
// ============================================
if ($db && !$error_message) {
    try {
        debug_log(19, "Preparing COUNT query");
$count_query = "SELECT COUNT(*) as total FROM donors d WHERE {$where_clause}";
        debug_log(20, "COUNT query prepared", ['query' => $count_query]);
        
$count_stmt = $db->prepare($count_query);
        if (!$count_stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }
        debug_log(21, "COUNT statement prepared");
        
        if (!empty($params)) {
            debug_log(22, "Binding COUNT parameters", ['types' => $param_types, 'count' => count($params)]);
    $count_stmt->bind_param($param_types, ...$params);
}
        
        debug_log(23, "Executing COUNT query");
$count_stmt->execute();
        debug_log(24, "COUNT query executed");
        
        $count_result = $count_stmt->get_result();
        if (!$count_result) {
            throw new Exception("Get result failed: " . $db->error);
        }
        
        $count_data = $count_result->fetch_assoc();
        $total_donors = $count_data['total'] ?? 0;
$total_pages = ceil($total_donors / $per_page);

        debug_log(25, "COUNT query completed", [
            'total_donors' => $total_donors,
            'total_pages' => $total_pages
        ]);
        
        $count_stmt->close();
    } catch (Exception $e) {
        debug_log(19, "FAILED COUNT query", ['error' => $e->getMessage()]);
        $error_message = "Count query failed: " . $e->getMessage();
    }
}

// ============================================
// STEP 9: Donor List Query
// ============================================
if ($db && !$error_message) {
    try {
        debug_log(26, "Preparing donor list query");
        $donor_params = $params;
        $donor_param_types = $param_types;
        $donor_params[] = $per_page;
        $donor_params[] = $offset;
        $donor_param_types .= 'ii';
        
$donor_query = "
    SELECT 
        d.id,
        d.name,
        d.phone,
        d.email,
        d.balance,
        d.total_pledged,
        d.payment_status,
        d.agent_id,
        d.church_id,
        c.name as church_name,
        u.name as agent_name
    FROM donors d
    LEFT JOIN churches c ON d.church_id = c.id
    LEFT JOIN users u ON d.agent_id = u.id
    WHERE {$where_clause}
    ORDER BY 
        CASE WHEN d.agent_id IS NULL THEN 0 ELSE 1 END,
        d.balance DESC,
        d.name ASC
    LIMIT ? OFFSET ?
";

        debug_log(27, "Donor query prepared", ['param_count' => count($donor_params)]);

$donor_stmt = $db->prepare($donor_query);
        if (!$donor_stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }
        debug_log(28, "Donor statement prepared");
        
        if (!empty($donor_params)) {
            debug_log(29, "Binding donor parameters", ['types' => $donor_param_types]);
            $donor_stmt->bind_param($donor_param_types, ...$donor_params);
}
        
        debug_log(30, "Executing donor query");
$donor_stmt->execute();
        debug_log(31, "Donor query executed");
        
$donors = $donor_stmt->get_result();
        if (!$donors) {
            throw new Exception("Get result failed: " . $db->error);
        }
        
        debug_log(32, "Donor query completed", ['rows' => $donors->num_rows]);
    } catch (Exception $e) {
        debug_log(26, "FAILED donor query", ['error' => $e->getMessage()]);
        $error_message = "Donor query failed: " . $e->getMessage();
    }
}

// ============================================
// STEP 10: Get Churches
// ============================================
if ($db && !$error_message) {
    try {
        debug_log(33, "Fetching churches");
        $churches_result = $db->query("SELECT id, name FROM churches ORDER BY name");
        if (!$churches_result) {
            throw new Exception("Churches query failed: " . $db->error);
        }
        $churches = $churches_result;
        debug_log(34, "Churches fetched", ['count' => $churches->num_rows]);
    } catch (Exception $e) {
        debug_log(33, "FAILED fetching churches", ['error' => $e->getMessage()]);
        // Non-critical, continue
        $churches = null;
    }
}

// ============================================
// STEP 11: Get Agents
// ============================================
if ($db && !$error_message) {
    try {
        debug_log(35, "Fetching agents");
        $agents_result = $db->query("SELECT id, name, role FROM users WHERE role IN ('admin', 'registrar') ORDER BY name");
        if (!$agents_result) {
            throw new Exception("Agents query failed: " . $db->error);
        }
        $agents = $agents_result;
        debug_log(36, "Agents fetched", ['count' => $agents->num_rows]);
    } catch (Exception $e) {
        debug_log(35, "FAILED fetching agents", ['error' => $e->getMessage()]);
        // Non-critical, continue
        $agents = null;
    }
}

// ============================================
// STEP 12: Get Statistics
// ============================================
if ($db && !$error_message) {
    try {
        debug_log(37, "Fetching statistics");
        
        $total_result = $db->query("SELECT COUNT(*) as count FROM donors WHERE donor_type = 'pledge'");
        if ($total_result) {
            $stats['total'] = (int)($total_result->fetch_assoc()['count'] ?? 0);
        }
        
        $assigned_result = $db->query("SELECT COUNT(*) as count FROM donors WHERE agent_id IS NOT NULL");
        if ($assigned_result) {
            $stats['assigned'] = (int)($assigned_result->fetch_assoc()['count'] ?? 0);
        }
        
        $unassigned_result = $db->query("SELECT COUNT(*) as count FROM donors WHERE agent_id IS NULL AND donor_type = 'pledge'");
        if ($unassigned_result) {
            $stats['unassigned'] = (int)($unassigned_result->fetch_assoc()['count'] ?? 0);
        }
        
        debug_log(38, "Statistics fetched", $stats);
    } catch (Exception $e) {
        debug_log(37, "FAILED fetching statistics", ['error' => $e->getMessage()]);
        // Non-critical, continue
    }
}

// ============================================
// STEP 13: Calculate Execution Time
// ============================================
$debug_end_time = microtime(true);
$debug_execution_time = round(($debug_end_time - $debug_start_time) * 1000, 2);
debug_log(39, "Script execution completed", ['time_ms' => $debug_execution_time]);

// ============================================
// STEP 14: Ensure ALL variables are initialized before HTML output
// ============================================
if (!isset($search)) $search = '';
if (!isset($church_filter)) $church_filter = 0;
if (!isset($status_filter)) $status_filter = '';
if (!isset($assignment_filter)) $assignment_filter = 'all';
if (!isset($agent_filter)) $agent_filter = 0;
if (!isset($page)) $page = 1;
if (!isset($total_donors)) $total_donors = 0;
if (!isset($total_pages)) $total_pages = 1;
if (!isset($stats)) $stats = ['total' => 0, 'assigned' => 0, 'unassigned' => 0];
if (!isset($where_clause) || empty($where_clause)) $where_clause = "d.donor_type = 'pledge'";
if (!isset($params)) $params = [];
if (!isset($param_types)) $param_types = '';

debug_log(40, "Final variable check completed", [
    'all_vars_set' => true,
    'search' => $search,
    'assignment' => $assignment_filter
]);

// ============================================
// CRITICAL: Check for any premature output
// ============================================
$premature_output = ob_get_contents();
if (!empty($premature_output) && trim($premature_output) !== '') {
    debug_log(41, "WARNING: Premature output detected", ['output' => substr($premature_output, 0, 200)]);
    // Clear it - we'll handle errors properly
    ob_clean();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .donor-card {
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        .donor-card:hover {
            box-shadow: 0 0.125rem 0.5rem rgba(0,0,0,0.1);
            border-left-color: var(--bs-primary);
        }
        .donor-card.selected {
            background-color: #e7f3ff;
            border-left-color: var(--bs-primary);
        }
        .stat-card {
            border-left: 3px solid;
        }
        .bulk-actions {
            position: sticky;
            top: 70px;
            z-index: 100;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .agent-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        .debug-panel {
            position: fixed;
            bottom: 0;
            right: 0;
            width: 400px;
            max-height: 300px;
            background: #1e1e1e;
            color: #0f0;
            font-family: 'Courier New', monospace;
            font-size: 11px;
            padding: 10px;
            overflow-y: auto;
            z-index: 9999;
            border-top: 2px solid #0f0;
            display: none;
        }
        .debug-panel.active {
            display: block;
        }
        .debug-panel .step {
            margin: 2px 0;
            padding: 2px 5px;
        }
        .debug-panel .step.success {
            color: #0f0;
        }
        .debug-panel .step.error {
            color: #f00;
            background: #300;
        }
        .debug-toggle {
            position: fixed;
            bottom: 10px;
            right: 10px;
            z-index: 10000;
            background: #1e1e1e;
            color: #0f0;
            border: 1px solid #0f0;
            padding: 5px 10px;
            cursor: pointer;
            font-size: 12px;
        }
        @media (max-width: 768px) {
            .donor-card {
                font-size: 0.9rem;
            }
            .bulk-actions {
                top: 56px;
            }
            .debug-panel {
                width: 100%;
                max-height: 200px;
            }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <!-- Debug Panel Toggle -->
            <button class="debug-toggle" onclick="toggleDebug()">🔍 Debug (<?php echo count($debug_log); ?> steps)</button>
            
            <!-- Debug Panel -->
            <div class="debug-panel" id="debugPanel">
                <div style="color: #0ff; font-weight: bold; margin-bottom: 5px;">
                    DEBUG LOG (<?php echo count($debug_log); ?> steps, <?php echo $debug_execution_time; ?>ms)
                </div>
                <?php foreach ($debug_log as $log): ?>
                <div class="step <?php echo strpos($log['message'], 'FAILED') !== false ? 'error' : 'success'; ?>">
                    [<?php echo $log['step']; ?>] <?php echo htmlspecialchars($log['message']); ?>
                    <?php if ($log['data']): ?>
                        <span style="color: #ff0;"><?php echo htmlspecialchars(json_encode($log['data'], JSON_PARTIAL_OUTPUT_ON_ERROR)); ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($error_message): ?>
            <!-- Error Alert -->
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Database Error</h5>
                <p class="mb-2"><?php echo htmlspecialchars($error_message); ?></p>
                <hr>
                <p class="mb-0">
                    <strong>To fix this:</strong>
                    <a href="check-database.php" class="btn btn-sm btn-warning ms-2">
                        <i class="fas fa-tools me-1"></i>Run Database Check
                    </a>
                </p>
            </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="content-header mb-4">
                <div>
                    <h1 class="content-title">
                        <i class="fas fa-users-cog me-2"></i>Assign Donors to Agents
                    </h1>
                    <p class="text-muted mb-0">Select donors and assign them to agents for follow-up</p>
                </div>
                <div>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back
                    </a>
                </div>
            </div>

            <!-- Statistics -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card stat-card border-primary">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-users fa-2x text-primary"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <h4 class="mb-0"><?php echo number_format($stats['total']); ?></h4>
                                    <p class="text-muted small mb-0">Total Donors</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stat-card border-success">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-user-check fa-2x text-success"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <h4 class="mb-0"><?php echo number_format($stats['assigned']); ?></h4>
                                    <p class="text-muted small mb-0">Assigned</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stat-card border-warning">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-user-slash fa-2x text-warning"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <h4 class="mb-0"><?php echo number_format($stats['unassigned']); ?></h4>
                                    <p class="text-muted small mb-0">Unassigned</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-3">
                <div class="card-body">
                    <form method="GET" id="filterForm" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Name, phone, email..." value="<?php echo htmlspecialchars($search ?? ''); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Assignment</label>
                            <select name="assignment" class="form-select">
                                <option value="all" <?php echo ($assignment_filter ?? 'all') === 'all' ? 'selected' : ''; ?>>All Donors</option>
                                <option value="unassigned" <?php echo ($assignment_filter ?? '') === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                                <option value="assigned" <?php echo ($assignment_filter ?? '') === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Agent</label>
                            <select name="agent" class="form-select">
                                <option value="0">All Agents</option>
                                <?php 
                                if ($agents) {
                                $agents->data_seek(0);
                                while ($agent = $agents->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $agent['id']; ?>" <?php echo ($agent_filter ?? 0) === $agent['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($agent['name']); ?>
                                </option>
                                    <?php endwhile; 
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Church</label>
                            <select name="church" class="form-select">
                                <option value="0">All Churches</option>
                                <?php 
                                if ($churches) {
                                    while ($church = $churches->fetch_assoc()): 
                                    ?>
                                    <option value="<?php echo $church['id']; ?>" <?php echo ($church_filter ?? 0) === $church['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($church['name']); ?>
                                </option>
                                    <?php endwhile;
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="not_started" <?php echo ($status_filter ?? '') === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                <option value="paying" <?php echo ($status_filter ?? '') === 'paying' ? 'selected' : ''; ?>>Paying</option>
                                <option value="overdue" <?php echo ($status_filter ?? '') === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                <option value="completed" <?php echo ($status_filter ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            </select>
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Bulk Actions Bar (Sticky) -->
            <div class="bulk-actions p-3 mb-3 rounded" id="bulkActionsBar" style="display: none;">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <span class="fw-bold">
                            <span id="selectedCount">0</span> donor(s) selected
                        </span>
                        <button type="button" class="btn btn-sm btn-link" onclick="clearSelection()">Clear</button>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <select id="bulkAgentSelect" class="form-select form-select-sm d-inline-block w-auto me-2">
                            <option value="">Select Agent...</option>
                            <?php 
                            if ($agents) {
                            $agents->data_seek(0);
                            while ($agent = $agents->fetch_assoc()): 
                            ?>
                            <option value="<?php echo $agent['id']; ?>">
                                <?php echo htmlspecialchars($agent['name']); ?> (<?php echo ucfirst($agent['role']); ?>)
                            </option>
                                <?php endwhile;
                            }
                            ?>
                        </select>
                        <button type="button" class="btn btn-sm btn-success" onclick="assignBulk()">
                            <i class="fas fa-check me-1"></i>Assign
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="unassignBulk()">
                            <i class="fas fa-times me-1"></i>Unassign
                        </button>
                    </div>
                </div>
            </div>

            <!-- Donors List -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Donors (<?php echo number_format($total_donors); ?>)
                    </h6>
                    <div>
                        <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll(this)">
                        <label class="form-check-label ms-1" for="selectAll">Select All</label>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if ($donors && $donors->num_rows > 0): ?>
                        <div class="list-group list-group-flush">
                            <?php while ($donor = $donors->fetch_assoc()): ?>
                            <div class="list-group-item donor-card" data-donor-id="<?php echo $donor['id']; ?>">
                                <div class="row align-items-center">
                                    <!-- Checkbox -->
                                    <div class="col-auto">
                                        <input type="checkbox" class="form-check-input donor-checkbox" 
                                               value="<?php echo $donor['id']; ?>" 
                                               onchange="updateSelection()">
                                    </div>

                                    <!-- Donor Info -->
                                    <div class="col-md-3 col-12">
                                        <div class="fw-bold"><?php echo htmlspecialchars($donor['name']); ?></div>
                                        <small class="text-muted">
                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor['phone'] ?? 'N/A'); ?>
                                        </small>
                                    </div>

                                    <!-- Balance -->
                                    <div class="col-md-2 col-6">
                                        <small class="text-muted d-block">Balance</small>
                                        <span class="badge bg-<?php echo $donor['balance'] > 0 ? 'warning' : 'success'; ?>">
                                            £<?php echo number_format($donor['balance'], 2); ?>
                                        </span>
                                    </div>

                                    <!-- Church -->
                                    <div class="col-md-2 col-6 d-none d-md-block">
                                        <small class="text-muted d-block">Church</small>
                                        <small><?php echo htmlspecialchars($donor['church_name'] ?? 'Not assigned'); ?></small>
                                    </div>

                                    <!-- Current Agent -->
                                    <div class="col-md-3 col-12 mt-2 mt-md-0">
                                        <small class="text-muted d-block">Assigned Agent</small>
                                        <?php if ($donor['agent_id']): ?>
                                            <span class="badge bg-primary agent-badge">
                                                <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($donor['agent_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary agent-badge">Unassigned</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Quick Actions -->
                                    <div class="col-md-2 col-12 text-md-end mt-2 mt-md-0">
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary btn-sm" 
                                                    onclick="quickAssign(<?php echo $donor['id']; ?>)" 
                                                    title="Quick Assign">
                                                <i class="fas fa-user-plus"></i>
                                            </button>
                                            <a href="../donor-management/view-donor.php?id=<?php echo $donor['id']; ?>" 
                                               class="btn btn-outline-secondary btn-sm" 
                                               title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-users-slash fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No donors found matching your filters</p>
                            <a href="assign-donors.php" class="btn btn-sm btn-primary">Clear Filters</a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="card-footer">
                    <nav>
                        <ul class="pagination pagination-sm mb-0 justify-content-center">
                            <?php if (isset($page) && $page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET ?? [], ['page' => $page - 1]))); ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            <?php endif; ?>

                            <?php 
                            if (isset($page) && isset($total_pages)) {
                                for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): 
                            ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET ?? [], ['page' => $i]))); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php 
                                endfor;
                            }
                            ?>

                            <?php if (isset($page) && isset($total_pages) && $page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET ?? [], ['page' => $page + 1]))); ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <div class="text-center mt-2">
                        <small class="text-muted">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?> 
                            (<?php echo number_format($total_donors); ?> total donors)
                        </small>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Quick Assign Modal -->
<div class="modal fade" id="quickAssignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Quick Assign Donor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="quickAssignDonorId">
                <div class="mb-3">
                    <label class="form-label">Select Agent</label>
                    <select id="quickAssignAgentSelect" class="form-select">
                        <option value="">Select an agent...</option>
                        <?php 
                        if ($agents) {
                        $agents->data_seek(0);
                        while ($agent = $agents->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $agent['id']; ?>">
                            <?php echo htmlspecialchars($agent['name']); ?> (<?php echo ucfirst($agent['role']); ?>)
                        </option>
                            <?php endwhile;
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmQuickAssign()">Assign</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ============================================
// CRITICAL: Define functions BEFORE loading admin.js
// ============================================
console.log('[DEBUG] JavaScript started loading');

// Debug panel toggle - MUST be defined early
function toggleDebug() {
    const panel = document.getElementById('debugPanel');
    if (panel) {
        panel.classList.toggle('active');
        console.log('[DEBUG] Panel toggled');
    } else {
        console.error('[DEBUG] debugPanel element not found!');
    }
}

// Fallback for toggleSidebar if admin.js fails to load
if (typeof toggleSidebar === 'undefined') {
    window.toggleSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        if (sidebar) {
            sidebar.classList.toggle('active');
            if (overlay) overlay.classList.toggle('active');
            console.log('[DEBUG] Sidebar toggled (fallback function)');
        } else {
            console.error('[DEBUG] Sidebar element not found!');
        }
    };
    console.log('[DEBUG] toggleSidebar fallback function defined');
}
</script>
<script src="../assets/admin.js"></script>
<script>
console.log('[DEBUG] admin.js loaded, checking toggleSidebar');
if (typeof toggleSidebar !== 'undefined') {
    console.log('[DEBUG] toggleSidebar exists from admin.js');
} else {
    console.warn('[DEBUG] toggleSidebar NOT found in admin.js, using fallback');
}

// Track JavaScript execution
console.log('[DEBUG] Scripts loaded, initializing functions');

let selectedDonors = new Set();

function updateSelection() {
    console.log('[DEBUG] updateSelection() called');
    try {
    selectedDonors.clear();
    document.querySelectorAll('.donor-checkbox:checked').forEach(cb => {
        selectedDonors.add(cb.value);
        cb.closest('.donor-card').classList.add('selected');
    });
    
    document.querySelectorAll('.donor-checkbox:not(:checked)').forEach(cb => {
        cb.closest('.donor-card').classList.remove('selected');
    });
    
        const countEl = document.getElementById('selectedCount');
        const bar = document.getElementById('bulkActionsBar');
        if (countEl) countEl.textContent = selectedDonors.size;
        if (bar) bar.style.display = selectedDonors.size > 0 ? 'block' : 'none';
        console.log('[DEBUG] Selection updated:', selectedDonors.size);
    } catch (e) {
        console.error('[DEBUG] updateSelection() error:', e);
    }
}

function toggleSelectAll(checkbox) {
    console.log('[DEBUG] toggleSelectAll() called');
    try {
    document.querySelectorAll('.donor-checkbox').forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateSelection();
    } catch (e) {
        console.error('[DEBUG] toggleSelectAll() error:', e);
    }
}

function clearSelection() {
    console.log('[DEBUG] clearSelection() called');
    try {
    document.querySelectorAll('.donor-checkbox').forEach(cb => {
        cb.checked = false;
    });
        const selectAll = document.getElementById('selectAll');
        if (selectAll) selectAll.checked = false;
    updateSelection();
    } catch (e) {
        console.error('[DEBUG] clearSelection() error:', e);
    }
}

function assignBulk() {
    console.log('[DEBUG] assignBulk() called');
    try {
    const agentId = document.getElementById('bulkAgentSelect').value;
    if (!agentId) {
        alert('Please select an agent');
        return;
    }
    
    if (selectedDonors.size === 0) {
        alert('Please select at least one donor');
        return;
    }
    
    if (!confirm(`Assign ${selectedDonors.size} donor(s) to the selected agent?`)) {
        return;
    }
    
    processBulkAction('assign', agentId);
    } catch (e) {
        console.error('[DEBUG] assignBulk() error:', e);
        alert('Error: ' + e.message);
    }
}

function unassignBulk() {
    console.log('[DEBUG] unassignBulk() called');
    try {
    if (selectedDonors.size === 0) {
        alert('Please select at least one donor');
        return;
    }
    
    if (!confirm(`Remove agent assignment from ${selectedDonors.size} donor(s)?`)) {
        return;
    }
    
    processBulkAction('unassign', null);
    } catch (e) {
        console.error('[DEBUG] unassignBulk() error:', e);
        alert('Error: ' + e.message);
    }
}

function processBulkAction(action, agentId) {
    console.log('[DEBUG] processBulkAction() called', {action, agentId});
    try {
    const donorIds = Array.from(selectedDonors);
    
    fetch('process-assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: action,
            donor_ids: donorIds,
            agent_id: agentId
        })
    })
        .then(response => {
            console.log('[DEBUG] Fetch response received', response.status);
            return response.json();
        })
    .then(data => {
            console.log('[DEBUG] Fetch data received', data);
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
            console.error('[DEBUG] Fetch error:', error);
        alert('Error processing request');
    });
    } catch (e) {
        console.error('[DEBUG] processBulkAction() error:', e);
        alert('Error: ' + e.message);
    }
}

function quickAssign(donorId) {
    console.log('[DEBUG] quickAssign() called', donorId);
    try {
        const input = document.getElementById('quickAssignDonorId');
        if (input) input.value = donorId;
        const modalEl = document.getElementById('quickAssignModal');
        if (modalEl) {
            const modal = new bootstrap.Modal(modalEl);
    modal.show();
        }
    } catch (e) {
        console.error('[DEBUG] quickAssign() error:', e);
        alert('Error: ' + e.message);
    }
}

function confirmQuickAssign() {
    console.log('[DEBUG] confirmQuickAssign() called');
    try {
    const donorId = document.getElementById('quickAssignDonorId').value;
    const agentId = document.getElementById('quickAssignAgentSelect').value;
    
    if (!agentId) {
        alert('Please select an agent');
        return;
    }
    
    fetch('process-assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'assign',
            donor_ids: [donorId],
            agent_id: agentId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
            console.error('[DEBUG] confirmQuickAssign() fetch error:', error);
        alert('Error processing request');
        });
    } catch (e) {
        console.error('[DEBUG] confirmQuickAssign() error:', e);
        alert('Error: ' + e.message);
    }
}

// Check if Bootstrap is loaded
console.log('[DEBUG] Checking Bootstrap...');
if (typeof bootstrap !== 'undefined') {
    console.log('[DEBUG] Bootstrap loaded successfully');
} else {
    console.error('[DEBUG] Bootstrap NOT loaded!');
}

// Check if admin.js functions exist
console.log('[DEBUG] Checking admin.js...');
if (typeof toggleSidebar !== 'undefined') {
    console.log('[DEBUG] toggleSidebar() function exists');
} else {
    console.error('[DEBUG] toggleSidebar() function NOT found!');
}

// Document ready check
document.addEventListener('DOMContentLoaded', function() {
    console.log('[DEBUG] DOMContentLoaded fired');
    console.log('[DEBUG] Page fully loaded, all scripts executed');
    
    // Test if buttons work
    const testBtn = document.querySelector('.btn-primary');
    if (testBtn) {
        console.log('[DEBUG] Found test button:', testBtn);
    }
});

console.log('[DEBUG] All JavaScript functions defined');
</script>
</body>
</html>
