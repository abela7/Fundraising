<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$db = db();
$page_title = 'Database Manager';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

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
            
            $msg = "Database wiped successfully! All " . count($tables) . " tables removed.";
            
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
                $msg = "Import completed with " . count($errors) . " errors. Executed {$executed} statements.";
            } else {
                $msg = "Database imported successfully! Executed {$executed} SQL statements.";
            }
        }
        
    } catch (Exception $e) {
        $msg = "Error: " . $e->getMessage();
    }
}

// Get current database status
$dbStatus = [];
try {
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
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($page_title); ?> - Fundraising Admin</title>
  <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.css'); ?>">
</head>
<body>
<div class="admin-wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="admin-content">
    <?php include '../includes/topbar.php'; ?>
    <main class="main-content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            
            <?php if ($msg): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
              <i class="fas fa-info-circle me-2"></i><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0">
                    <i class="fas fa-database me-2"></i>Database Manager
                </h1>
                <span class="badge bg-<?php echo ENVIRONMENT === 'local' ? 'primary' : 'success'; ?> fs-6">
                    <?php echo strtoupper(ENVIRONMENT); ?>
                </span>
            </div>
            
            <!-- Current Status -->
            <div class="card mb-4">
              <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Current Database Status</h5>
              </div>
              <div class="card-body">
                <div class="row">
                  <div class="col-md-6">
                    <strong>Environment:</strong> <?php echo ENVIRONMENT; ?><br>
                    <strong>Database:</strong> <?php echo DB_NAME; ?><br>
                    <strong>Host:</strong> <?php echo $_SERVER['HTTP_HOST'] ?? 'CLI'; ?>
                  </div>
                  <div class="col-md-6">
                    <?php if (isset($dbStatus['error'])): ?>
                      <div class="alert alert-danger">❌ <?php echo htmlspecialchars($dbStatus['error']); ?></div>
                    <?php else: ?>
                      <strong>Records:</strong><br>
                      Users: <?php echo $dbStatus['users'] ?? 0; ?> | 
                      Payments: <?php echo $dbStatus['payments'] ?? 0; ?> | 
                      Pledges: <?php echo $dbStatus['pledges'] ?? 0; ?><br>
                      <?php if (isset($dbStatus['totals'])): ?>
                        <strong>Total:</strong> £<?php echo number_format($dbStatus['totals']['grand_total'], 2); ?>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>

            <!-- Database Operations -->
            <div class="row">
              
              <!-- Export -->
              <div class="col-md-4 mb-4">
                <div class="card h-100 border-success">
                  <div class="card-header bg-success text-white">
                    <h6 class="mb-0"><i class="fas fa-download me-2"></i>Export Database</h6>
                  </div>
                  <div class="card-body">
                    <p>Export complete database with all tables and data as SQL file.</p>
                  </div>
                  <div class="card-footer">
                    <form method="post">
                      <?php echo csrf_input(); ?>
                      <input type="hidden" name="action" value="export_full_database">
                      <button type="submit" class="btn btn-success w-100">
                        <i class="fas fa-download me-2"></i>Export
                      </button>
                    </form>
                  </div>
                </div>
              </div>
              
              <!-- Wipe -->
              <div class="col-md-4 mb-4">
                <div class="card h-100 border-danger">
                  <div class="card-header bg-danger text-white">
                    <h6 class="mb-0"><i class="fas fa-trash me-2"></i>Wipe Database</h6>
                  </div>
                  <div class="card-body">
                    <p>Remove ALL tables from database. Use before importing.</p>
                    <div class="alert alert-warning small">⚠️ This removes everything!</div>
                  </div>
                  <div class="card-footer">
                    <form method="post" onsubmit="return confirmWipe()">
                      <?php echo csrf_input(); ?>
                      <input type="hidden" name="action" value="wipe_database">
                      <input type="hidden" name="confirmation" id="wipeConfirmation">
                      <button type="submit" class="btn btn-danger w-100">
                        <i class="fas fa-trash me-2"></i>Wipe All
                      </button>
                    </form>
                  </div>
                </div>
              </div>
              
              <!-- Import -->
              <div class="col-md-4 mb-4">
                <div class="card h-100 border-primary">
                  <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="fas fa-upload me-2"></i>Import Database</h6>
                  </div>
                  <div class="card-body">
                    <p>Import complete database from SQL file.</p>
                    <form method="post" enctype="multipart/form-data">
                      <?php echo csrf_input(); ?>
                      <input type="hidden" name="action" value="import_database">
                      <div class="mb-2">
                        <input type="file" name="sql_file" class="form-control" accept=".sql" required>
                      </div>
                      <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-upload me-2"></i>Import
                      </button>
                    </form>
                  </div>
                </div>
              </div>
              
            </div>

            <!-- Instructions -->
            <div class="card">
              <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-list-ol me-2"></i>Sync Workflow</h5>
              </div>
              <div class="card-body">
                <div class="row">
                  <div class="col-md-6">
                    <h6>📤 Server → Local:</h6>
                    <ol>
                      <li>On Server: Export database</li>
                      <li>On Local: Wipe tables</li>
                      <li>On Local: Import server file</li>
                    </ol>
                  </div>
                  <div class="col-md-6">
                    <h6>📥 Local → Server:</h6>
                    <ol>
                      <li>On Local: Export database</li>
                      <li>On Server: Wipe tables</li>
                      <li>On Server: Import local file</li>
                    </ol>
                  </div>
                </div>
              </div>
            </div>

            <!-- Back Link -->
            <div class="text-center mt-4">
              <a href="./" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Tools
              </a>
            </div>

          </div>
        </div>
      </div>
    </main>
  </div>
</div>

<script>
function confirmWipe() {
    const confirmation = prompt(
        'This will DELETE ALL TABLES!\n\n' +
        'Type: WIPE_ALL_TABLES\n\n' +
        'Database: <?php echo DB_NAME; ?>'
    );
    
    if (confirmation === 'WIPE_ALL_TABLES') {
        document.getElementById('wipeConfirmation').value = confirmation;
        return confirm('Are you absolutely sure?');
    }
    
    alert('Incorrect confirmation. Cancelled.');
    return false;
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.js'); ?>"></script>
</body>
</html>