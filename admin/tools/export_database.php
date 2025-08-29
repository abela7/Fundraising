<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$page_title = 'Database Export for Backup';
$db = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_full'])) {
    if (verify_csrf(true)) {
        try {
            set_time_limit(300); // Allow up to 5 minutes for export
            
            // Clear all previous output
            if (ob_get_level()) {
                ob_end_clean();
            }

            $timestamp = date('Y-m-d_H-i-s');
            
            $exportData = [
                'export_info' => [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'environment' => ENVIRONMENT,
                    'database_name' => DB_NAME,
                    'version' => '1.1'
                ],
                'schema' => [],
                'data' => []
            ];
            
            // Tables to export (everything except logs and transient data)
            $tablesToExport = [
                'users', 'donation_packages', 'settings', 'counters', 'payments',
                'pledges', 'projector_footer', 'floor_grid_cells', 'custom_amount_tracking',
                'user_messages', 'projector_commands', 'registrar_applications',
                'user_blocklist', 'floor_area_allocations'
            ];
            
            // 1. Get Schema (CREATE TABLE statements)
            foreach ($tablesToExport as $table) {
                $result = $db->query("SHOW CREATE TABLE `{$table}`");
                if ($row = $result->fetch_assoc()) {
                    $exportData['schema'][$table] = $row['Create Table'];
                }
                $result->free();
            }

            // 2. Get Data
            foreach ($tablesToExport as $table) {
                $exportData['data'][$table] = [];
                $result = $db->query("SELECT * FROM `{$table}`");
                while ($row = $result->fetch_assoc()) {
                    $exportData['data'][$table][] = $row;
                }
                $result->free();
            }
            
            $json_data = json_encode($exportData, JSON_PRETTY_PRINT);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Failed to encode data to JSON: ' . json_last_error_msg());
            }

            // Generate filename
            $filename = "fundraising_backup_" . ENVIRONMENT . "_{$timestamp}.json";
            
            // Send as download
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($json_data));
            
            echo $json_data;
            exit;
            
        } catch (Exception $e) {
            // We can't show a nice error message here because headers are already sent.
            // The user will see a partial download or a failed download.
            // Logging this error to the server's error log is the best we can do.
            error_log('Database Export Failed: ' . $e->getMessage());
            // Ensure script termination
            exit;
        }
    } else {
        $msg = 'Invalid security token. Please refresh the page and try again.';
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
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h4 class="mb-0">
                        <i class="fas fa-database me-2"></i>
                        Database Export for Backup
                    </h4>
                </div>
                <div class="card-body">
                    
                    <div class="alert alert-info">
                        <strong>Current Environment:</strong> <span class="badge bg-primary"><?php echo strtoupper(ENVIRONMENT); ?></span><br>
                        <strong>Database:</strong> <?php echo DB_NAME; ?>
                    </div>
                    
                    <?php if ($msg): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($msg); ?></div>
                    <?php endif; ?>
                    
                    <div class="alert alert-secondary">
                        <h6><i class="fas fa-info-circle me-2"></i>How to Use:</h6>
                        <ol class="mb-0">
                            <li><strong>On your SOURCE machine (e.g., SERVER):</strong> Click the export button to download a complete backup file (`.json`).</li>
                            <li><strong>On your TARGET machine (e.g., LOCAL):</strong> Go to the "Import Database" tool and upload this file.</li>
                            <li>The import process will automatically wipe the target database before restoring the backup.</li>
                        </ol>
                    </div>
                    
                    <form method="POST" onsubmit="showExportProgress()">
                        <?php include __DIR__ . '/../../shared/csrf_input.php'; ?>
                        <div class="d-grid">
                            <button type="submit" name="export_full" class="btn btn-success btn-lg">
                                <i class="fas fa-download me-2"></i>
                                Export Full Database (Structure + Data)
                            </button>
                        </div>
                    </form>
                    
                    <div id="exportProgress" class="mt-3" style="display: none;">
                        <div class="progress">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 100%">Exporting...</div>
                        </div>
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
function showExportProgress() {
    document.getElementById('exportProgress').style.display = 'block';
    document.querySelector('form button').disabled = true;
    document.querySelector('form button').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Exporting, please wait...';
}
</script>
</body>
</html>
