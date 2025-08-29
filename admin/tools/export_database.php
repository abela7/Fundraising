<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$page_title = 'Database Export for Local Backup';
$db = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_full'])) {
    try {
        $timestamp = date('Y-m-d_H-i-s');
        
        // Export all important tables
        $exportData = [
            'export_info' => [
                'timestamp' => date('Y-m-d H:i:s'),
                'environment' => ENVIRONMENT,
                'database_name' => DB_NAME,
                'version' => '1.0'
            ],
            'tables' => []
        ];
        
        // Tables to export (everything except audit logs which can be environment-specific)
        $tables = [
            'users',
            'donation_packages', 
            'settings',
            'counters',
            'payments',
            'pledges',
            'projector_footer',
            'floor_grid_cells',
            'custom_amount_tracking',
            'user_messages',
            'projector_commands'
        ];
        
        foreach ($tables as $table) {
            echo "Exporting {$table}...<br>";
            flush();
            
            $result = $db->query("SELECT * FROM {$table}");
            $exportData['tables'][$table] = [];
            
            while ($row = $result->fetch_assoc()) {
                $exportData['tables'][$table][] = $row;
            }
            
            echo "✅ Exported " . count($exportData['tables'][$table]) . " records from {$table}<br>";
            flush();
        }
        
        // Generate filename
        $filename = "fundraising_backup_{$timestamp}.json";
        
        // Send as download
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen(json_encode($exportData)));
        
        echo json_encode($exportData, JSON_PRETTY_PRINT);
        exit;
        
    } catch (Exception $e) {
        $msg = 'Export failed: ' . $e->getMessage();
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
</head>
<body>
<div class="container mt-4">
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">
                        <i class="fas fa-database me-2"></i>
                        Database Export for Local Backup
                    </h4>
                </div>
                <div class="card-body">
                    
                    <!-- Environment Info -->
                    <div class="alert alert-info">
                        <strong>Current Environment:</strong> <?php echo ENVIRONMENT; ?><br>
                        <strong>Database:</strong> <?php echo DB_NAME; ?><br>
                        <strong>Host:</strong> <?php echo $_SERVER['HTTP_HOST'] ?? 'Unknown'; ?>
                    </div>
                    
                    <?php if ($msg): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($msg); ?></div>
                    <?php endif; ?>
                    
                    <!-- Export Instructions -->
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-info-circle me-2"></i>How to Use:</h6>
                        <ol class="mb-0">
                            <li><strong>On Server:</strong> Click export to download current database</li>
                            <li><strong>On Local:</strong> Import the downloaded file in phpMyAdmin</li>
                            <li><strong>Process:</strong> Drop local database → Create new → Import</li>
                            <li><strong>Result:</strong> Local system continues from exact server state</li>
                        </ol>
                    </div>
                    
                    <!-- Export Form -->
                    <form method="POST" onsubmit="showExportProgress()">
                        <div class="d-grid">
                            <button type="submit" name="export_full" class="btn btn-success btn-lg">
                                <i class="fas fa-download me-2"></i>
                                Export Complete Database
                            </button>
                        </div>
                    </form>
                    
                    <!-- Progress indicator -->
                    <div id="exportProgress" class="mt-3" style="display: none;">
                        <div class="alert alert-info">
                            <i class="fas fa-spinner fa-spin me-2"></i>
                            Exporting database... Please wait.
                        </div>
                    </div>
                    
                    <!-- Back to Tools -->
                    <div class="mt-3 text-center">
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
    // Disable form to prevent double submission
    document.querySelector('form button').disabled = true;
}
</script>
</body>
</html>
