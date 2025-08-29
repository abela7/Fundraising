<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$page_title = 'Import Database from Backup';
$db = db();
$msg = '';
$msg_type = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_file'])) {
    if (verify_csrf(true)) {
        try {
            if ($_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('File upload error: ' . $_FILES['backup_file']['error']);
            }
            
            $json_content = file_get_contents($_FILES['backup_file']['tmp_name']);
            $backupData = json_decode($json_content, true);

            if (json_last_error() !== JSON_ERROR_NONE || !isset($backupData['schema']) || !isset($backupData['data'])) {
                throw new Exception('Invalid or corrupted JSON backup file.');
            }
            
            set_time_limit(600); // 10 minutes for import
            $db->begin_transaction();

            // 1. Wipe existing tables
            $db->query('SET FOREIGN_KEY_CHECKS=0;');
            $result = $db->query('SHOW TABLES;');
            while($row = $result->fetch_array()) {
                $db->query("DROP TABLE `{$row[0]}`");
            }

            // 2. Recreate tables from schema
            foreach ($backupData['schema'] as $table => $createStatement) {
                $db->query($createStatement);
            }

            // 3. Import data
            foreach ($backupData['data'] as $table => $records) {
                if (empty($records)) continue;

                $columns = array_keys($records[0]);
                $placeholders = implode(',', array_fill(0, count($columns), '?'));
                $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $columns) . "`) VALUES ({$placeholders})";
                $stmt = $db->prepare($sql);
                
                // Determine param types
                $types = '';
                foreach ($records[0] as $value) {
                    if (is_int($value)) $types .= 'i';
                    elseif (is_float($value)) $types .= 'd';
                    else $types .= 's';
                }

                foreach ($records as $record) {
                    $stmt->bind_param($types, ...array_values($record));
                    $stmt->execute();
                }
            }
            
            $db->query('SET FOREIGN_KEY_CHECKS=1;');
            $db->commit();
            $msg = 'Success! Database has been restored from the backup file.';
            $msg_type = 'success';

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            $db->query('SET FOREIGN_KEY_CHECKS=1;');
            $msg = 'Import failed: ' . $e->getMessage();
            $msg_type = 'danger';
        }
    } else {
        $msg = 'Invalid security token. Please try again.';
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
                        <strong>Current Environment:</strong> <span class="badge bg-primary"><?php echo strtoupper(ENVIRONMENT); ?></span><br>
                        <strong>Database to be overwritten:</strong> <?php echo DB_NAME; ?>
                    </div>

                     <?php if ($msg): ?>
                        <div class="alert alert-<?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div>
                    <?php endif; ?>

                    <div class="alert alert-warning">
                        <h4><i class="fas fa-exclamation-triangle me-2"></i>Warning</h4>
                        <p>Uploading a backup file here will first **WIPE ALL DATA** in the current database and then restore it from the file. This is irreversible.</p>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" id="importForm">
                        <?php include __DIR__ . '/../../shared/csrf_input.php'; ?>
                        <div class="mb-3">
                            <label for="backup_file" class="form-label">Select Backup File (.json)</label>
                            <input class="form-control" type="file" id="backup_file" name="backup_file" accept=".json" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-info btn-lg">
                                <i class="fas fa-upload me-2"></i>
                                Wipe and Import from File
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
document.getElementById('importForm').addEventListener('submit', function() {
    if (document.getElementById('backup_file').files.length > 0) {
        if (!confirm('ARE YOU SURE you want to overwrite the database \'<?php echo DB_NAME; ?>\' with this backup?')) {
            event.preventDefault();
            return;
        }
        document.getElementById('importProgress').style.display = 'block';
        document.querySelector('form button').disabled = true;
        document.querySelector('form button').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Importing...';
    }
});
</script>
</body>
</html>
