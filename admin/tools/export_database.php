<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

// This script only handles the download. The page itself is just a button.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_sql'])) {
    
    try {
        $db = db();
        set_time_limit(600); // 10 minutes for potentially large exports

        // Start with a clean slate
        if (ob_get_level()) ob_end_clean();

        $timestamp = date('Y-m-d_H-i-s');
        $filename = "backup-" . DB_NAME . "-{$timestamp}.sql";

        // --- Critical Download Headers ---
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        // --- SQL Backup Logic ---

        $tablesToExport = [
            'users', 'donation_packages', 'settings', 'counters', 'payments',
            'pledges', 'projector_footer', 'floor_grid_cells', 'custom_amount_tracking',
            'user_messages', 'projector_commands', 'registrar_applications',
            'user_blocklist', 'floor_area_allocations', 'message_attachments', 'audit_logs'
        ];

        echo "-- Fundraising System SQL Dump (v1.1 - Complete)\n";
        echo "-- Server version: " . $db->server_info . "\n";
        echo "-- Generation Time: " . date('Y-m-d H:i:s') . "\n";
        echo "-- Database: `" . DB_NAME . "`\n";
        echo "-- ------------------------------------------------------\n\n";

        echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        echo "START TRANSACTION;\n";
        echo "SET time_zone = \"+00:00\";\n\n";
        echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tablesToExport as $table) {
            echo "--\n-- Table structure for table `{$table}`\n--\n\n";
            echo "DROP TABLE IF EXISTS `{$table}`;\n";
            
            $createTableResult = $db->query("SHOW CREATE TABLE `{$table}`");
            $createTableRow = $createTableResult->fetch_assoc();
            echo $createTableRow['Create Table'] . ";\n\n";
            $createTableResult->free();

            $dataResult = $db->query("SELECT * FROM `{$table}`");
            if ($dataResult->num_rows > 0) {
                echo "--\n-- Dumping data for table `{$table}`\n--\n\n";
                while ($row = $dataResult->fetch_assoc()) {
                    $keys = array_keys($row);
                    $values = array_map(function ($value) use ($db) {
                        return is_null($value) ? 'NULL' : "'" . $db->real_escape_string($value) . "'";
                    }, array_values($row));
                    echo "INSERT INTO `{$table}` (`" . implode('`,`', $keys) . "`) VALUES (" . implode(',', $values) . ");\n";
                }
                echo "\n";
            }
            $dataResult->free();
        }

        echo "SET FOREIGN_KEY_CHECKS=1;\n\n";
        echo "COMMIT;\n";

        exit;

    } catch (Exception $e) {
        if (ob_get_level()) ob_end_clean();
        error_log('SQL Export Failed: ' . $e->getMessage());
        die("An error occurred during SQL export. Check server logs. Error: " . htmlspecialchars($e->getMessage()));
    }
}

// --- Page Display Logic ---
$page_title = 'Database SQL Export';
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
                        <i class="fas fa-file-code me-2"></i>
                        Database Export (.sql)
                    </h4>
                </div>
                <div class="card-body">
                    
                    <div class="alert alert-info">
                        <strong>Current Environment:</strong> <span class="badge bg-primary"><?php echo strtoupper(defined('ENVIRONMENT') ? ENVIRONMENT : 'unknown'); ?></span><br>
                        <strong>Database:</strong> <?php echo defined('DB_NAME') ? DB_NAME : 'unknown'; ?>
                    </div>
                    
                    <div class="alert alert-secondary">
                        <h6><i class="fas fa-info-circle me-2"></i>Workflow:</h6>
                        <ol class="mb-0">
                            <li>Click the button below to download a standard `.sql` backup file of the current database.</li>
                            <li>Go to your target machine's phpMyAdmin.</li>
                            <li>Select the target database (e.g., `abunetdg_fundraising_local`).</li>
                            <li>Go to the "Import" tab and upload the `.sql` file you just downloaded.</li>
                        </ol>
                    </div>
                    
                    <form method="POST">
                        <div class="d-grid">
                            <button type="submit" name="export_sql" value="1" class="btn btn-success btn-lg" id="exportBtn">
                                <i class="fas fa-download me-2"></i>
                                Export Full Database (.sql)
                            </button>
                        </div>
                    </form>
                    
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
</body>
</html>
