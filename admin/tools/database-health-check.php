<?php
declare(strict_types=1);

/**
 * Quick Database Health Check
 * Use this page to diagnose database connection and schema issues
 */

// Skip auth for this diagnostic tool - you can add it back if needed
// require_once __DIR__ . '/../../shared/auth.php';
// require_login();

$start_time = microtime(true);
$checks = [];

// Check 1: Can we load environment config?
try {
    require_once __DIR__ . '/../../config/env.php';
    $checks['env_loaded'] = ['status' => 'success', 'message' => 'Environment config loaded successfully'];
} catch (Throwable $e) {
    $checks['env_loaded'] = ['status' => 'error', 'message' => $e->getMessage()];
}

// Check 2: Can we connect to database?
try {
    require_once __DIR__ . '/../../config/db.php';
    $db = db();
    $checks['db_connection'] = ['status' => 'success', 'message' => 'Database connection established'];
    
    // Get database info
    $db_info = [
        'host' => DB_HOST,
        'database' => DB_NAME,
        'user' => DB_USER,
        'environment' => ENVIRONMENT ?? 'unknown',
        'server_version' => $db->server_info,
        'client_version' => $db->client_info,
        'charset' => $db->character_set_name()
    ];
} catch (Throwable $e) {
    $checks['db_connection'] = ['status' => 'error', 'message' => $e->getMessage()];
    $db = null;
    $db_info = [];
}

// Check 3: Do required tables exist?
$required_tables = ['donors', 'users', 'pledges', 'payments', 'churches'];
$table_status = [];

if ($db) {
    foreach ($required_tables as $table) {
        try {
            $result = $db->query("SHOW TABLES LIKE '{$table}'");
            if ($result && $result->num_rows > 0) {
                $table_status[$table] = 'exists';
            } else {
                $table_status[$table] = 'missing';
            }
        } catch (Throwable $e) {
            $table_status[$table] = 'error: ' . $e->getMessage();
        }
    }
    
    $all_tables_exist = !in_array('missing', $table_status, true);
    $checks['required_tables'] = [
        'status' => $all_tables_exist ? 'success' : 'warning',
        'message' => $all_tables_exist ? 'All required tables exist' : 'Some tables are missing',
        'details' => $table_status
    ];
}

// Check 4: Do critical columns exist in donors table?
$critical_columns = ['id', 'name', 'phone', 'donor_type', 'agent_id', 'balance', 'total_pledged'];
$column_status = [];

if ($db && isset($table_status['donors']) && $table_status['donors'] === 'exists') {
    try {
        $result = $db->query("SHOW COLUMNS FROM donors");
        $existing_columns = [];
        while ($row = $result->fetch_assoc()) {
            $existing_columns[] = $row['Field'];
        }
        
        foreach ($critical_columns as $col) {
            $column_status[$col] = in_array($col, $existing_columns) ? 'exists' : 'missing';
        }
        
        $all_columns_exist = !in_array('missing', $column_status, true);
        $checks['donor_columns'] = [
            'status' => $all_columns_exist ? 'success' : 'warning',
            'message' => $all_columns_exist ? 'All critical columns exist' : 'Some columns are missing',
            'details' => $column_status
        ];
    } catch (Throwable $e) {
        $checks['donor_columns'] = ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// Check 5: Can we run a basic query?
if ($db) {
    try {
        $result = $db->query("SELECT COUNT(*) as cnt FROM donors LIMIT 1");
        if ($result) {
            $count = $result->fetch_assoc()['cnt'];
            $checks['basic_query'] = ['status' => 'success', 'message' => "Query executed successfully. Found {$count} donors."];
        } else {
            $checks['basic_query'] = ['status' => 'error', 'message' => 'Query returned no result'];
        }
    } catch (Throwable $e) {
        $checks['basic_query'] = ['status' => 'error', 'message' => $e->getMessage()];
    }
}

$execution_time = round((microtime(true) - $start_time) * 1000, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Health Check - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .health-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .health-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 20px;
        }
        .status-success {
            color: #198754;
        }
        .status-warning {
            color: #ffc107;
        }
        .status-error {
            color: #dc3545;
        }
        .check-item {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        .check-item:last-child {
            border-bottom: none;
        }
        .badge-custom {
            padding: 5px 10px;
            border-radius: 6px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="health-container">
        <!-- Header -->
        <div class="health-card">
            <div class="card-body text-center py-4">
                <h1 class="mb-2">
                    <i class="fas fa-heartbeat text-danger"></i>
                    Database Health Check
                </h1>
                <p class="text-muted mb-0">Diagnostic tool to identify database issues</p>
                <small class="text-muted">Executed in <?php echo $execution_time; ?>ms</small>
            </div>
        </div>

        <!-- System Information -->
        <div class="health-card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>System Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <strong>PHP Version:</strong> <?php echo PHP_VERSION; ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Environment:</strong> <?php echo ENVIRONMENT ?? 'Not defined'; ?>
                    </div>
                </div>
                <?php if (!empty($db_info)): ?>
                    <hr>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Database Host:</strong> <?php echo htmlspecialchars($db_info['host']); ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Database Name:</strong> <?php echo htmlspecialchars($db_info['database']); ?>
                        </div>
                        <div class="col-md-6 mt-2">
                            <strong>Server Version:</strong> <?php echo htmlspecialchars($db_info['server_version']); ?>
                        </div>
                        <div class="col-md-6 mt-2">
                            <strong>Charset:</strong> <?php echo htmlspecialchars($db_info['charset']); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Health Checks -->
        <div class="health-card">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="fas fa-stethoscope me-2"></i>Health Checks</h5>
            </div>
            <div class="card-body p-0">
                <?php foreach ($checks as $check_name => $check): ?>
                    <div class="check-item">
                        <div class="d-flex align-items-start">
                            <div class="flex-shrink-0 me-3">
                                <?php if ($check['status'] === 'success'): ?>
                                    <i class="fas fa-check-circle fa-2x status-success"></i>
                                <?php elseif ($check['status'] === 'warning'): ?>
                                    <i class="fas fa-exclamation-triangle fa-2x status-warning"></i>
                                <?php else: ?>
                                    <i class="fas fa-times-circle fa-2x status-error"></i>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <h6 class="mb-1"><?php echo ucwords(str_replace('_', ' ', $check_name)); ?></h6>
                                <p class="mb-0"><?php echo htmlspecialchars($check['message']); ?></p>
                                
                                <?php if (isset($check['details'])): ?>
                                    <div class="mt-2">
                                        <?php foreach ($check['details'] as $key => $value): ?>
                                            <span class="badge <?php echo $value === 'exists' ? 'bg-success' : 'bg-danger'; ?> me-1 mb-1">
                                                <?php echo htmlspecialchars($key); ?>: <?php echo htmlspecialchars($value); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Actions -->
        <div class="health-card">
            <div class="card-body text-center">
                <h6 class="mb-3">Quick Actions</h6>
                <div class="d-flex gap-2 justify-content-center flex-wrap">
                    <button onclick="location.reload()" class="btn btn-primary">
                        <i class="fas fa-sync-alt me-2"></i>Refresh Check
                    </button>
                    <a href="../call-center/check-database.php" class="btn btn-success">
                        <i class="fas fa-wrench me-2"></i>Full Database Check
                    </a>
                    <a href="../dashboard/" class="btn btn-outline-secondary">
                        <i class="fas fa-home me-2"></i>Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

