<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$page_title = 'Database Import Helper';
$msg = '';

// Test database connection
try {
    $db = db();
    $connectionStatus = "✅ Connected to: " . DB_NAME . " (Environment: " . ENVIRONMENT . ")";
} catch (Exception $e) {
    $connectionStatus = "❌ Connection failed: " . $e->getMessage();
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
        <div class="col-md-10 mx-auto">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h4 class="mb-0">
                        <i class="fas fa-upload me-2"></i>
                        Database Import Helper for Local System
                    </h4>
                </div>
                <div class="card-body">
                    
                    <!-- Connection Status -->
                    <div class="alert <?php echo strpos($connectionStatus, '✅') !== false ? 'alert-success' : 'alert-danger'; ?>">
                        <?php echo $connectionStatus; ?>
                    </div>
                    
                    <!-- Import Instructions -->
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>Import Process using phpMyAdmin:</h6>
                        <ol>
                            <li><strong>Download `.sql` backup</strong> from your server using the "Export Database" tool.</li>
                            <li><strong>Open phpMyAdmin</strong> on this local machine (link below).</li>
                            <li><strong>Select your local database:</strong> `<?php echo DB_NAME; ?>`</li>
                            <li>Click the **"Import"** tab at the top.</li>
                            <li>Click **"Choose File"** and select the `.sql` file you downloaded.</li>
                            <li>Ensure the format is set to "SQL" and click **"Import"**. This will restore the database to the state of the server backup.</li>
                        </ol>
                    </div>
                    
                    <!-- Environment Detection Details -->
                    <div class="card mt-3">
                        <div class="card-header">
                            <h6 class="mb-0">Environment Detection Details</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>HTTP Host:</strong> <?php echo $_SERVER['HTTP_HOST'] ?? 'Not set'; ?><br>
                                    <strong>Server Name:</strong> <?php echo $_SERVER['SERVER_NAME'] ?? 'Not set'; ?><br>
                                    <strong>Document Root:</strong> <?php echo $_SERVER['DOCUMENT_ROOT'] ?? 'Not set'; ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>Environment:</strong> <?php echo defined('ENVIRONMENT') ? ENVIRONMENT : 'unknown'; ?><br>
                                    <strong>Database Name:</strong> <?php echo defined('DB_NAME') ? DB_NAME : 'unknown'; ?><br>
                                    <strong>Database User:</strong> <?php echo defined('DB_USER') ? DB_USER : 'unknown'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Database Test -->
                    <div class="card mt-3">
                        <div class="card-header">
                            <h6 class="mb-0">Database Status Check</h6>
                        </div>
                        <div class="card-body">
                            <?php
                            try {
                                $db = db();
                                
                                // Check key tables exist
                                $tables = ['users', 'payments', 'pledges', 'counters', 'settings'];
                                echo "<div class='row'>";
                                
                                foreach ($tables as $table) {
                                    $result = $db->query("SELECT COUNT(*) as count FROM {$table}");
                                    $count = $result->fetch_assoc()['count'] ?? 0;
                                    
                                    echo "<div class='col-md-4 mb-2'>";
                                    echo "<span class='badge bg-success me-2'>✅</span>";
                                    echo "<strong>{$table}:</strong> {$count} records";
                                    echo "</div>";
                                }
                                
                                echo "</div>";
                                
                                // Show current totals
                                $counters = $db->query("SELECT * FROM counters WHERE id=1")->fetch_assoc();
                                if ($counters) {
                                    echo "<div class='alert alert-light mt-3'>";
                                    echo "<h6>Current Fundraising Totals:</h6>";
                                    echo "<strong>Paid:</strong> £" . number_format((float)($counters['paid_total'] ?? 0), 2) . "<br>";
                                    echo "<strong>Pledged:</strong> £" . number_format((float)($counters['pledged_total'] ?? 0), 2) . "<br>";
                                    echo "<strong>Grand Total:</strong> £" . number_format((float)($counters['grand_total'] ?? 0), 2) . "<br>";
                                    echo "<strong>Last Updated:</strong> " . ($counters['last_updated'] ?? 'N/A');
                                    echo "</div>";
                                } else {
                                     echo "<div class='alert alert-warning mt-3'>Could not find totals data. The `counters` table may be empty.</div>";
                                }
                                
                            } catch (Exception $e) {
                                echo "<div class='alert alert-danger'>";
                                echo "❌ Database check failed: " . htmlspecialchars($e->getMessage());
                                echo "</div>";
                            }
                            ?>
                        </div>
                    </div>
                    
                    <!-- phpMyAdmin Quick Link -->
                    <div class="text-center mt-4">
                        <a href="http://localhost/phpmyadmin" target="_blank" class="btn btn-primary me-3">
                            <i class="fas fa-external-link-alt me-2"></i>
                            Open phpMyAdmin
                        </a>
                        <a href="../tools/" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>
                            Back to Tools
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
