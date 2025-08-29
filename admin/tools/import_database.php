<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_admin();

$page_title = 'Import Database from SQL Backup';
$msg = '';
$msg_type = 'info';

// Increase limits for large file uploads
ini_set('upload_max_filesize', '100M');
ini_set('post_max_size', '100M');
ini_set('max_execution_time', '600'); // 10 minutes
ini_set('memory_limit', '256M');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_file'])) {
    if (verify_csrf(false)) {
        try {
            if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('File upload error. Code: ' . ($_FILES['backup_file']['error'] ?? 'Unknown'));
            }

            $sql_file_path = $_FILES['backup_file']['tmp_name'];
            $db = db();
            
            // --- 1. WIPE EXISTING DATABASE ---
            $db->query('SET FOREIGN_KEY_CHECKS=0;');
            $result = $db->query('SHOW TABLES;');
            while ($row = $result->fetch_array()) {
                $db->query("DROP TABLE IF EXISTS `{$row[0]}`");
            }
            $result->free();

            // --- 2. IMPORT FROM SQL FILE (ROBUST, LOW-MEMORY METHOD) ---
            $templine = '';
            $file_handle = fopen($sql_file_path, 'r');
            if (!$file_handle) {
                throw new Exception("Could not open the uploaded SQL file.");
            }

            while (($line = fgets($file_handle)) !== false) {
                // Skip comments and empty lines
                if (substr($line, 0, 2) == '--' || trim($line) == '') {
                    continue;
                }
                
                // Add this line to the current segment
                $templine .= $line;
                
                // If it has a semicolon at the end, it's a complete statement
                if (substr(trim($line), -1, 1) == ';') {
                    // Perform the query
                    if (!$db->query($templine)) {
                        // Log the failing query for debugging but don't expose it to the user
                        error_log("SQL Import Error on query: " . $templine . " | Error: " . $db->error);
                        throw new Exception("A database error occurred during import. Please check the server logs for details.");
                    }
                    // Reset temp line
                    $templine = '';
                }
            }
            fclose($file_handle);

            $db->query('SET FOREIGN_KEY_CHECKS=1;');
            $msg = 'Success! Database has been wiped and restored from the SQL backup file.';
            $msg_type = 'success';

        } catch (Exception $e) {
            // Rollback is not applicable here as we are running raw DDL queries
            if(isset($db)) $db->query('SET FOREIGN_KEY_CHECKS=1;');
            $msg = 'Import failed: ' . $e->getMessage();
            $msg_type = 'danger';
        }
    } else {
        $msg = 'Invalid security token. Please refresh the page and try again.';
        $msg_type = 'danger';
    }
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
<div class="container mt-4">
    <div class="row">
        <div class="col-lg-8 mx-auto">
            <div class="card border-info shadow-sm">
                <div class="card-header bg-info text-white">
                    <h4 class="mb-0">
                        <i class="fas fa-upload me-2"></i>
                        Import Database
                    </h4>
                </div>
                <div class="card-body">

                    <div class="alert alert-info">
                        <strong>Current Environment:</strong> <span class="badge bg-primary"><?php echo strtoupper(defined('ENVIRONMENT') ? ENVIRONMENT : 'unknown'); ?></span><br>
                        <strong>Database to be overwritten:</strong> <?php echo defined('DB_NAME') ? DB_NAME : 'unknown'; ?>
                    </div>

                     <?php if ($msg): ?>
                        <div class="alert alert-<?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div>
                    <?php endif; ?>

                    <div class="alert alert-warning">
                        <h4><i class="fas fa-exclamation-triangle me-2"></i>Warning</h4>
                        <p>Uploading a `.sql` backup file here will first **WIPE ALL DATA** in the current database and then restore it from the file. This is irreversible.</p>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" id="importForm">
                        <?php echo csrf_input(); ?>
                        <div class="mb-3">
                            <label for="backup_file" class="form-label">Select Backup File (.sql)</label>
                            <input class="form-control" type="file" id="backup_file" name="backup_file" accept=".sql" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-info btn-lg">
                                <i class="fas fa-upload me-2"></i>
                                Wipe and Import from SQL File
                            </button>
                        </div>
                    </form>

                     <div id="importProgress" class="mt-3" style="display: none;">
                        <div class="progress">
                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-info" role="progressbar" style="width: 100%">Importing...</div>
                        </div>
                        <div class="text-center mt-2">This may take several minutes. Please do not close this page.</div>
                    </div>
                    
                    <div class="mt-4 text-center">
                        <a href="../tools/" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Tools
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('importForm').addEventListener('submit', function(event) {
    const fileInput = document.getElementById('backup_file');
    if (fileInput.files.length > 0) {
        const confirmationMessage = 'ARE YOU SURE you want to overwrite the database \'<?php echo DB_NAME; ?>\' with this backup? This will wipe all existing data first.';
        if (!confirm(confirmationMessage)) {
            event.preventDefault();
            return;
        }
        document.getElementById('importProgress').style.display = 'block';
        const button = event.target.querySelector('button[type="submit"]');
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Importing...';
    }
});
</script>
</body>
</html>
