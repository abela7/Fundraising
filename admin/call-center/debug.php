<?php
/**
 * Call Center Debug Page
 * Comprehensive diagnostic tool to identify issues
 */

// Disable error display for this page (we'll show them ourselves)
ini_set('display_errors', 0);
error_reporting(E_ALL);

$debug_info = [];
$errors = [];
$warnings = [];

// Start output buffering to catch any errors
ob_start();

// Test 1: PHP Version
$debug_info['php_version'] = [
    'value' => PHP_VERSION,
    'status' => version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'error',
    'message' => version_compare(PHP_VERSION, '8.0.0', '>=') 
        ? 'PHP version is compatible' 
        : 'PHP version must be 8.0 or higher'
];

// Test 2: Check if required files exist
$required_files = [
    __DIR__ . '/../../shared/auth.php' => 'Auth file',
    __DIR__ . '/../../config/db.php' => 'Database config',
    __DIR__ . '/../includes/sidebar.php' => 'Sidebar include',
    __DIR__ . '/../includes/topbar.php' => 'Topbar include',
    __DIR__ . '/../assets/admin.css' => 'Admin CSS',
    __DIR__ . '/assets/call-center.css' => 'Call Center CSS',
];

$debug_info['files'] = [];
foreach ($required_files as $file => $name) {
    $exists = file_exists($file);
    $debug_info['files'][$name] = [
        'path' => $file,
        'exists' => $exists,
        'readable' => $exists ? is_readable($file) : false,
        'status' => $exists && is_readable($file) ? 'ok' : 'error'
    ];
    if (!$exists) {
        $errors[] = "Required file missing: $name ($file)";
    } elseif (!is_readable($file)) {
        $errors[] = "File not readable: $name ($file)";
    }
}

// Test 3: Try to load auth.php
try {
    if (file_exists(__DIR__ . '/../../shared/auth.php')) {
        require_once __DIR__ . '/../../shared/auth.php';
        $debug_info['auth_loaded'] = ['status' => 'ok', 'message' => 'Auth file loaded successfully'];
    } else {
        $debug_info['auth_loaded'] = ['status' => 'error', 'message' => 'Auth file not found'];
        $errors[] = 'Cannot load auth.php';
    }
} catch (Exception $e) {
    $debug_info['auth_loaded'] = ['status' => 'error', 'message' => $e->getMessage()];
    $errors[] = 'Error loading auth.php: ' . $e->getMessage();
}

// Test 4: Check session
$debug_info['session'] = [
    'started' => session_status() === PHP_SESSION_ACTIVE,
    'status' => session_status() === PHP_SESSION_ACTIVE ? 'ok' : 'warning',
    'message' => session_status() === PHP_SESSION_ACTIVE 
        ? 'Session is active' 
        : 'Session not started (this is normal if not logged in)'
];

if (session_status() === PHP_SESSION_ACTIVE) {
    $debug_info['session']['user_id'] = $_SESSION['user_id'] ?? 'Not set';
    $debug_info['session']['name'] = $_SESSION['name'] ?? 'Not set';
    $debug_info['session']['role'] = $_SESSION['role'] ?? 'Not set';
}

// Test 5: Try to load database config
try {
    if (file_exists(__DIR__ . '/../../config/db.php')) {
        require_once __DIR__ . '/../../config/db.php';
        $debug_info['db_config_loaded'] = ['status' => 'ok', 'message' => 'Database config loaded'];
    } else {
        $debug_info['db_config_loaded'] = ['status' => 'error', 'message' => 'Database config not found'];
        $errors[] = 'Cannot load db.php';
    }
} catch (Exception $e) {
    $debug_info['db_config_loaded'] = ['status' => 'error', 'message' => $e->getMessage()];
    $errors[] = 'Error loading db.php: ' . $e->getMessage();
}

// Test 6: Try database connection
$debug_info['database'] = [];
try {
    if (function_exists('db')) {
        $db = db();
        if ($db) {
            $debug_info['database']['connection'] = [
                'status' => 'ok',
                'message' => 'Database connection successful',
                'host' => $db->host_info ?? 'Unknown',
                'server_info' => $db->server_info ?? 'Unknown'
            ];
            
            // Test query
            $test_query = $db->query("SELECT 1 as test");
            if ($test_query) {
                $debug_info['database']['query_test'] = [
                    'status' => 'ok',
                    'message' => 'Can execute queries'
                ];
            } else {
                $debug_info['database']['query_test'] = [
                    'status' => 'error',
                    'message' => 'Cannot execute queries: ' . $db->error
                ];
                $errors[] = 'Database query failed: ' . $db->error;
            }
            
            // Check call center tables
            $tables_check = $db->query("SHOW TABLES LIKE 'call_center_%'");
            if ($tables_check) {
                $table_count = $tables_check->num_rows;
                $debug_info['database']['call_center_tables'] = [
                    'status' => $table_count >= 15 ? 'ok' : 'warning',
                    'count' => $table_count,
                    'message' => "Found $table_count call center tables (expected 15-16)"
                ];
                if ($table_count < 15) {
                    $warnings[] = "Only $table_count call center tables found (expected 15-16)";
                }
            } else {
                $debug_info['database']['call_center_tables'] = [
                    'status' => 'error',
                    'message' => 'Cannot check tables: ' . $db->error
                ];
                $errors[] = 'Cannot check call center tables: ' . $db->error;
            }
            
            // Check donors table
            $donors_check = $db->query("SHOW TABLES LIKE 'donors'");
            if ($donors_check && $donors_check->num_rows > 0) {
                $debug_info['database']['donors_table'] = [
                    'status' => 'ok',
                    'message' => 'Donors table exists'
                ];
                
                // Check required columns
                $cols_check = $db->query("SHOW COLUMNS FROM donors");
                $existing_cols = [];
                if ($cols_check) {
                    while ($col = $cols_check->fetch_assoc()) {
                        $existing_cols[] = $col['Field'];
                    }
                }
                $required_cols = ['baptism_name', 'city', 'church_id', 'portal_profile_completed'];
                $missing_cols = array_diff($required_cols, $existing_cols);
                
                if (empty($missing_cols)) {
                    $debug_info['database']['donors_columns'] = [
                        'status' => 'ok',
                        'message' => 'All required columns exist'
                    ];
                } else {
                    $debug_info['database']['donors_columns'] = [
                        'status' => 'warning',
                        'message' => 'Missing columns: ' . implode(', ', $missing_cols)
                    ];
                    $warnings[] = 'Donors table missing columns: ' . implode(', ', $missing_cols);
                }
            } else {
                $debug_info['database']['donors_table'] = [
                    'status' => 'error',
                    'message' => 'Donors table does not exist'
                ];
                $errors[] = 'Donors table not found';
            }
            
        } else {
            $debug_info['database']['connection'] = [
                'status' => 'error',
                'message' => 'db() function returned null/false'
            ];
            $errors[] = 'Database connection failed - db() returned null';
        }
    } else {
        $debug_info['database']['connection'] = [
            'status' => 'error',
            'message' => 'db() function does not exist'
        ];
        $errors[] = 'db() function not found - check config/db.php';
    }
} catch (Exception $e) {
    $debug_info['database']['connection'] = [
        'status' => 'error',
        'message' => 'Exception: ' . $e->getMessage()
    ];
    $errors[] = 'Database connection exception: ' . $e->getMessage();
}

// Test 7: Check if index.php can be parsed
try {
    $index_content = file_get_contents(__DIR__ . '/index.php');
    if ($index_content !== false) {
        // Try to check for syntax errors
        $syntax_check = shell_exec('php -l "' . __DIR__ . '/index.php" 2>&1');
        if (strpos($syntax_check, 'No syntax errors') !== false) {
            $debug_info['index_php'] = [
                'status' => 'ok',
                'message' => 'index.php syntax is valid'
            ];
        } else {
            $debug_info['index_php'] = [
                'status' => 'error',
                'message' => 'Syntax error detected',
                'details' => $syntax_check
            ];
            $errors[] = 'index.php has syntax errors';
        }
    } else {
        $debug_info['index_php'] = [
            'status' => 'error',
            'message' => 'Cannot read index.php'
        ];
        $errors[] = 'Cannot read index.php file';
    }
} catch (Exception $e) {
    $debug_info['index_php'] = [
        'status' => 'error',
        'message' => 'Exception: ' . $e->getMessage()
    ];
    $errors[] = 'Error checking index.php: ' . $e->getMessage();
}

// Test 8: Check directory permissions
$debug_info['permissions'] = [
    'call_center_dir' => [
        'writable' => is_writable(__DIR__),
        'readable' => is_readable(__DIR__),
        'status' => (is_writable(__DIR__) && is_readable(__DIR__)) ? 'ok' : 'warning'
    ],
    'assets_dir' => [
        'exists' => file_exists(__DIR__ . '/assets'),
        'writable' => file_exists(__DIR__ . '/assets') ? is_writable(__DIR__ . '/assets') : false,
        'status' => (file_exists(__DIR__ . '/assets') && is_writable(__DIR__ . '/assets')) ? 'ok' : 'warning'
    ]
];

// Test 9: Check for PHP errors in output buffer
$output = ob_get_clean();
if (!empty($output)) {
    $errors[] = 'PHP output detected: ' . substr($output, 0, 500);
    $debug_info['php_output'] = $output;
}

// Test 10: Memory and limits
$debug_info['php_limits'] = [
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size')
];

// Test 11: Try to simulate index.php execution
$debug_info['simulation'] = [];
try {
    // Try to execute the critical parts
    if (function_exists('db') && isset($db)) {
        $test_query = "SHOW TABLES LIKE 'call_center_sessions'";
        $result = $db->query($test_query);
        if ($result) {
            $debug_info['simulation']['table_check'] = [
                'status' => 'ok',
                'message' => 'Can query call_center_sessions table',
                'exists' => $result->num_rows > 0
            ];
        } else {
            $debug_info['simulation']['table_check'] = [
                'status' => 'error',
                'message' => 'Cannot query: ' . $db->error
            ];
            $errors[] = 'Cannot query call_center_sessions: ' . $db->error;
        }
    }
} catch (Exception $e) {
    $debug_info['simulation']['error'] = $e->getMessage();
    $errors[] = 'Simulation error: ' . $e->getMessage();
}

// Get any PHP errors
$last_error = error_get_last();
if ($last_error) {
    $errors[] = 'PHP Error: ' . $last_error['message'] . ' in ' . $last_error['file'] . ':' . $last_error['line'];
    $debug_info['last_php_error'] = $last_error;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Call Center Debug - Diagnostic Tool</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .status-ok { color: #28a745; }
        .status-warning { color: #ffc107; }
        .status-error { color: #dc3545; }
        .debug-section { margin-bottom: 2rem; }
        .debug-item { padding: 0.5rem; border-left: 3px solid #ddd; margin-bottom: 0.5rem; }
        .debug-item.ok { border-color: #28a745; background: #f0fff4; }
        .debug-item.warning { border-color: #ffc107; background: #fffbf0; }
        .debug-item.error { border-color: #dc3545; background: #fff0f0; }
        pre { background: #f5f5f5; padding: 1rem; border-radius: 0.25rem; overflow-x: auto; }
    </style>
</head>
<body>
<div class="container mt-4">
    <div class="row">
        <div class="col-12">
            <h1 class="mb-4">
                <i class="fas fa-bug me-2"></i>Call Center Debug Diagnostic
            </h1>
            
            <!-- Overall Status -->
            <div class="alert alert-<?php echo empty($errors) ? 'success' : 'danger'; ?> mb-4">
                <h4 class="alert-heading">
                    <?php if (empty($errors)): ?>
                        <i class="fas fa-check-circle me-2"></i>All Checks Passed!
                    <?php else: ?>
                        <i class="fas fa-exclamation-triangle me-2"></i>Issues Found
                    <?php endif; ?>
                </h4>
                <p class="mb-0">
                    <?php if (empty($errors)): ?>
                        All diagnostic checks passed. The system appears to be configured correctly.
                    <?php else: ?>
                        Found <strong><?php echo count($errors); ?></strong> error(s) and <strong><?php echo count($warnings); ?></strong> warning(s).
                    <?php endif; ?>
                </p>
            </div>

            <!-- Errors List -->
            <?php if (!empty($errors)): ?>
            <div class="card border-danger mb-4">
                <div class="card-header bg-danger text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-times-circle me-2"></i>Errors (<?php echo count($errors); ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li class="text-danger"><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <!-- Warnings List -->
            <?php if (!empty($warnings)): ?>
            <div class="card border-warning mb-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>Warnings (<?php echo count($warnings); ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <?php foreach ($warnings as $warning): ?>
                            <li class="text-warning"><?php echo htmlspecialchars($warning); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <!-- Detailed Debug Info -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-info-circle me-2"></i>Detailed Diagnostic Information
                    </h5>
                </div>
                <div class="card-body">
                    <?php foreach ($debug_info as $section => $data): ?>
                        <div class="debug-section">
                            <h6 class="text-uppercase text-muted mb-3">
                                <i class="fas fa-chevron-right me-2"></i><?php echo ucwords(str_replace('_', ' ', $section)); ?>
                            </h6>
                            
                            <?php if (is_array($data)): ?>
                                <?php foreach ($data as $key => $value): ?>
                                    <?php if (is_array($value) && isset($value['status'])): ?>
                                        <div class="debug-item <?php echo $value['status']; ?>">
                                            <strong><?php echo htmlspecialchars($key); ?>:</strong>
                                            <span class="status-<?php echo $value['status']; ?>">
                                                <?php echo htmlspecialchars($value['message'] ?? $value['value'] ?? 'N/A'); ?>
                                            </span>
                                            <?php if (isset($value['details'])): ?>
                                                <pre class="mt-2 mb-0 small"><?php echo htmlspecialchars($value['details']); ?></pre>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif (is_scalar($value)): ?>
                                        <div class="debug-item">
                                            <strong><?php echo htmlspecialchars($key); ?>:</strong>
                                            <?php echo htmlspecialchars($value); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="debug-item">
                                    <?php echo htmlspecialchars($data); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <hr>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-tools me-2"></i>Quick Actions
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="check-database.php" class="btn btn-primary">
                            <i class="fas fa-database me-2"></i>Check Database Tables
                        </a>
                        <a href="index.php" class="btn btn-outline-primary">
                            <i class="fas fa-headset me-2"></i>Try Call Center Dashboard
                        </a>
                        <button onclick="location.reload()" class="btn btn-outline-secondary">
                            <i class="fas fa-sync-alt me-2"></i>Refresh Diagnostics
                        </button>
                    </div>
                </div>
            </div>

            <!-- Raw Debug Data (for advanced debugging) -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-code me-2"></i>Raw Debug Data
                    </h5>
                </div>
                <div class="card-body">
                    <pre><?php print_r($debug_info); ?></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

