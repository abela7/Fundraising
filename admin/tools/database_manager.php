<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$page_title = 'Database Manager - Local/Server Sync';
$db = db();
$msg = '';
$msgType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'export_full_database') {
            // Export complete database as SQL
            $timestamp = date('Y-m-d_H-i-s');
            $filename = "fundraising_complete_{$timestamp}.sql";
            
            // Get all tables
            $tables = [];
            $result = $db->query("SHOW TABLES");
            while ($row = $result->fetch_array()) {
                $tables[] = $row[0];
            }
            
            $sqlDump = "-- Fundraising Database Complete Export\n";
            $sqlDump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
            $sqlDump .= "-- Environment: " . ENVIRONMENT . "\n";
            $sqlDump .= "-- Database: " . DB_NAME . "\n\n";
            $sqlDump .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
            $sqlDump .= "START TRANSACTION;\n";
            $sqlDump .= "SET time_zone = \"+00:00\";\n\n";
            
            foreach ($tables as $table) {
                // Get table structure
                $result = $db->query("SHOW CREATE TABLE `{$table}`");
                $row = $result->fetch_array();
                $sqlDump .= "-- Table: {$table}\n";
                $sqlDump .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sqlDump .= $row[1] . ";\n\n";
                
                // Get table data
                $result = $db->query("SELECT * FROM `{$table}`");
                if ($result->num_rows > 0) {
                    $sqlDump .= "-- Data for table `{$table}`\n";
                    
                    // Get column info for proper escaping
                    $columns = [];
                    $columnResult = $db->query("SHOW COLUMNS FROM `{$table}`");
                    while ($col = $columnResult->fetch_assoc()) {
                        $columns[] = $col['Field'];
                    }
                    
                    $sqlDump .= "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES\n";
                    
                    $rows = [];
                    while ($row = $result->fetch_assoc()) {
                        $values = [];
                        foreach ($row as $value) {
                            if ($value === null) {
                                $values[] = 'NULL';
                            } else {
                                $values[] = "'" . $db->real_escape_string($value) . "'";
                            }
                        }
                        $rows[] = '(' . implode(', ', $values) . ')';
                    }
                    
                    $sqlDump .= implode(",\n", $rows) . ";\n\n";
                }
            }
            
            $sqlDump .= "COMMIT;\n";
            
            // Send as download
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($sqlDump));
            echo $sqlDump;
            exit;
            
        } elseif ($action === 'wipe_database') {
            // Wipe all tables but keep database structure
            $confirmation = $_POST['confirmation'] ?? '';
            if ($confirmation !== 'WIPE_ALL_TABLES') {
                throw new Exception('Invalid confirmation. Type exactly: WIPE_ALL_TABLES');
            }
            
            $db->begin_transaction();
            
            // Disable foreign key checks
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            
            // Get all tables
            $tables = [];
            $result = $db->query("SHOW TABLES");
            while ($row = $result->fetch_array()) {
                $tables[] = $row[0];
            }
            
            // Drop all tables
            foreach ($tables as $table) {
                $db->query("DROP TABLE IF EXISTS `{$table}`");
            }
            
            // Re-enable foreign key checks
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
            
            $db->commit();
            
            $msg = "✅ Database wiped successfully! All " . count($tables) . " tables removed.";
            $msgType = 'success';
            
        } elseif ($action === 'import_database') {
            // Import uploaded SQL file
            if (!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No file uploaded or upload error occurred');
            }
            
            $uploadedFile = $_FILES['sql_file']['tmp_name'];
            $sqlContent = file_get_contents($uploadedFile);
            
            if (!$sqlContent) {
                throw new Exception('Failed to read uploaded file');
            }
            
            // Disable foreign key checks for import
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            
            // Split SQL into individual statements
            $statements = array_filter(
                array_map('trim', explode(';', $sqlContent)),
                function($stmt) { return !empty($stmt) && !preg_match('/^--/', $stmt); }
            );
            
            $executed = 0;
            $errors = [];
            
            foreach ($statements as $statement) {
                if (trim($statement)) {
                    try {
                        $db->query($statement);
                        $executed++;
                    } catch (Exception $e) {
                        $errors[] = "Error in statement: " . substr($statement, 0, 100) . "... -> " . $e->getMessage();
                        if (count($errors) > 10) break; // Limit error reporting
                    }
                }
            }
            
            // Re-enable foreign key checks
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
            
            if (count($errors) > 0) {
                $msg = "⚠️ Import completed with " . count($errors) . " errors. Executed {$executed} statements. First few errors: " . implode('; ', array_slice($errors, 0, 3));
                $msgType = 'warning';
            } else {
                $msg = "✅ Database imported successfully! Executed {$executed} SQL statements.";
                $msgType = 'success';
            }
        }
        
    } catch (Exception $e) {
        $msg = "❌ Error: " . $e->getMessage();
        $msgType = 'danger';
    }
}

// Get current database status
$dbStatus = [];
try {
    $db = db();
    
    // Count records in key tables
    $tables = ['users', 'donation_packages', 'payments', 'pledges', 'settings'];
    foreach ($tables as $table) {
        $result = $db->query("SELECT COUNT(*) as count FROM {$table}");
        $dbStatus[$table] = $result ? $result->fetch_assoc()['count'] : 0;
    }
    
    // Get current totals
    $counters = $db->query("SELECT * FROM counters WHERE id=1")->fetch_assoc();
    $dbStatus['totals'] = $counters;
    
} catch (Exception $e) {
    $dbStatus['error'] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="fas fa-database me-2"></i>
                        Database Manager
                    </h1>
                    <span class="badge bg-<?php echo ENVIRONMENT === 'local' ? 'primary' : 'success'; ?> fs-6">
                        <?php echo strtoupper(ENVIRONMENT); ?> Environment
                    </span>
                </div>
                
                <?php if ($msg): ?>
                    <div class="alert alert-<?php echo $msgType; ?> alert-dismissible fade show">
                        <?php echo $msg; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Current Database Status -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Current Database Status
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Environment Info:</h6>
                                        <ul class="list-unstyled">
                                            <li><strong>Environment:</strong> <?php echo ENVIRONMENT; ?></li>
                                            <li><strong>Database:</strong> <?php echo DB_NAME; ?></li>
                                            <li><strong>Host:</strong> <?php echo $_SERVER['HTTP_HOST'] ?? 'CLI'; ?></li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <?php if (isset($dbStatus['error'])): ?>
                                            <div class="alert alert-danger">
                                                ❌ Database Error: <?php echo htmlspecialchars($dbStatus['error']); ?>
                                            </div>
                                        <?php else: ?>
                                            <h6>Table Records:</h6>
                                            <ul class="list-unstyled">
                                                <li><strong>Users:</strong> <?php echo $dbStatus['users'] ?? 0; ?></li>
                                                <li><strong>Payments:</strong> <?php echo $dbStatus['payments'] ?? 0; ?></li>
                                                <li><strong>Pledges:</strong> <?php echo $dbStatus['pledges'] ?? 0; ?></li>
                                                <li><strong>Packages:</strong> <?php echo $dbStatus['donation_packages'] ?? 0; ?></li>
                                            </ul>
                                            
                                            <?php if (isset($dbStatus['totals'])): ?>
                                                <h6 class="mt-3">Current Totals:</h6>
                                                <ul class="list-unstyled">
                                                    <li><strong>Paid:</strong> £<?php echo number_format($dbStatus['totals']['paid_total'], 2); ?></li>
                                                    <li><strong>Pledged:</strong> £<?php echo number_format($dbStatus['totals']['pledged_total'], 2); ?></li>
                                                    <li><strong>Grand Total:</strong> £<?php echo number_format($dbStatus['totals']['grand_total'], 2); ?></li>
                                                </ul>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Database Operations -->
                <div class="row">
                    
                    <!-- 1. Export Complete Database -->
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card h-100 border-success">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-download me-2"></i>
                                    1. Export Complete Database
                                </h6>
                            </div>
                            <div class="card-body">
                                <p class="card-text">Export the entire database including all tables, structure, and data as a complete SQL file.</p>
                                <div class="alert alert-info small">
                                    <strong>Use on:</strong> Server to backup current state<br>
                                    <strong>Includes:</strong> All tables + all data
                                </div>
                            </div>
                            <div class="card-footer">
                                <form method="POST">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="export_full_database">
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="fas fa-download me-2"></i>Export Complete Database
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 2. Wipe Database -->
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card h-100 border-danger">
                            <div class="card-header bg-danger text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-trash me-2"></i>
                                    2. Wipe All Tables
                                </h6>
                            </div>
                            <div class="card-body">
                                <p class="card-text">Remove ALL tables from the current database. This will completely empty the database.</p>
                                <div class="alert alert-warning small">
                                    <strong>⚠️ DANGER:</strong> This removes everything!<br>
                                    <strong>Use before:</strong> Importing new database
                                </div>
                            </div>
                            <div class="card-footer">
                                <form method="POST" onsubmit="return confirmWipe()">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="wipe_database">
                                    <input type="hidden" name="confirmation" id="wipeConfirmation">
                                    <button type="submit" class="btn btn-danger w-100">
                                        <i class="fas fa-trash me-2"></i>Wipe All Tables
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 3. Import Database -->
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card h-100 border-primary">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-upload me-2"></i>
                                    3. Import Complete Database
                                </h6>
                            </div>
                            <div class="card-body">
                                <p class="card-text">Import a complete database SQL file. This will recreate all tables and data.</p>
                                <div class="alert alert-info small">
                                    <strong>Process:</strong> Upload → Import → Complete<br>
                                    <strong>Result:</strong> Exact copy of exported database
                                </div>
                            </div>
                            <div class="card-footer">
                                <form method="POST" enctype="multipart/form-data">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="import_database">
                                    <div class="mb-2">
                                        <input type="file" name="sql_file" class="form-control" accept=".sql" required>
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-upload me-2"></i>Import Database
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                </div>
                
                <!-- Workflow Instructions -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-list-ol me-2"></i>
                                    Complete Sync Workflow
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>📤 Server → Local Sync:</h6>
                                        <ol>
                                            <li><strong>On Server:</strong> Export complete database</li>
                                            <li><strong>On Local:</strong> Wipe all tables</li>
                                            <li><strong>On Local:</strong> Import the server export</li>
                                            <li><strong>Result:</strong> Local = exact copy of server</li>
                                        </ol>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>📥 Local → Server Sync:</h6>
                                        <ol>
                                            <li><strong>On Local:</strong> Export complete database</li>
                                            <li><strong>On Server:</strong> Wipe all tables</li>
                                            <li><strong>On Server:</strong> Import the local export</li>
                                            <li><strong>Result:</strong> Server = exact copy of local</li>
                                        </ol>
                                    </div>
                                </div>
                                
                                <div class="alert alert-success mt-3">
                                    <h6><i class="fas fa-lightbulb me-2"></i>Emergency Failover Process:</h6>
                                    <p class="mb-0">
                                        <strong>1.</strong> Server fails during event →
                                        <strong>2.</strong> Switch URL to local system →
                                        <strong>3.</strong> Continue fundraising seamlessly →
                                        <strong>4.</strong> When server returns, sync local data back to server
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Back to Tools -->
                <div class="text-center mt-4">
                    <a href="../tools/" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Tools
                    </a>
                </div>
                
            </div>
        </main>
    </div>
</div>

<script>
function confirmWipe() {
    const confirmation = prompt(
        'This will DELETE ALL TABLES in the database!\n\n' +
        'Type exactly: WIPE_ALL_TABLES\n\n' +
        'Current database: <?php echo DB_NAME; ?>'
    );
    
    if (confirmation === 'WIPE_ALL_TABLES') {
        document.getElementById('wipeConfirmation').value = confirmation;
        return confirm('Are you absolutely sure? This cannot be undone!');
    }
    
    alert('Confirmation text was incorrect. Operation cancelled.');
    return false;
}

// Show upload progress
document.querySelector('form[enctype="multipart/form-data"]').addEventListener('submit', function() {
    const button = this.querySelector('button[type="submit"]');
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Importing...';
    button.disabled = true;
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>
