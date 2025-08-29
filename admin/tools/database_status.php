<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_admin();

$page_title = 'Database Status & Comparison';
$msg = '';
$msg_type = 'info';
$comparison_results = null;

// --- Function to get stats from the LIVE database ---
function get_live_database_stats(): array {
    $stats = [
        'pledges' => 0, 'payments' => 0, 'users' => 0,
        'grand_total' => 0.0, 'last_pledge' => 'N/A', 'last_payment' => 'N/A'
    ];
    try {
        $db = db();
        // Check for tables first
        if ($db->query("SHOW TABLES LIKE 'pledges'")->num_rows > 0) {
            $stats['pledges'] = (int)$db->query("SELECT COUNT(*) FROM pledges")->fetch_row()[0];
            $stats['last_pledge'] = $db->query("SELECT MAX(created_at) FROM pledges")->fetch_row()[0] ?? 'N/A';
        }
        if ($db->query("SHOW TABLES LIKE 'payments'")->num_rows > 0) {
            $stats['payments'] = (int)$db->query("SELECT COUNT(*) FROM payments")->fetch_row()[0];
            $stats['last_payment'] = $db->query("SELECT MAX(created_at) FROM payments")->fetch_row()[0] ?? 'N/A';
        }
        if ($db->query("SHOW TABLES LIKE 'users'")->num_rows > 0) {
            $stats['users'] = (int)$db->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
        }
        if ($db->query("SHOW TABLES LIKE 'counters'")->num_rows > 0) {
            $stats['grand_total'] = (float)($db->query("SELECT grand_total FROM counters WHERE id=1")->fetch_row()[0] ?? 0.0);
        }
    } catch (Exception $e) {
        // DB not ready, return empty stats
    }
    return $stats;
}

// --- Function to get stats by PARSING a backup .sql file ---
function get_backup_file_stats(string $file_path): array {
    $stats = ['pledges' => 0, 'payments' => 0, 'users' => 0];
    $file_handle = fopen($file_path, 'r');
    if (!$file_handle) {
        throw new Exception("Could not open the uploaded file.");
    }
    while (($line = fgets($file_handle)) !== false) {
        if (strpos($line, 'INSERT INTO `pledges`') === 0) {
            $stats['pledges']++;
        } elseif (strpos($line, 'INSERT INTO `payments`') === 0) {
            $stats['payments']++;
        } elseif (strpos($line, 'INSERT INTO `users`') === 0) {
            $stats['users']++;
        }
    }
    fclose($file_handle);
    return $stats;
}

// --- Handle File Upload for Comparison ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_file'])) {
    if (verify_csrf(false)) {
        try {
            if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('File upload error. Code: ' . ($_FILES['backup_file']['error'] ?? 'Unknown'));
            }
            
            $live_stats = get_live_database_stats();
            $backup_stats = get_backup_file_stats($_FILES['backup_file']['tmp_name']);

            $comparison_results = [
                'live' => $live_stats,
                'backup' => $backup_stats,
                'filename' => htmlspecialchars($_FILES['backup_file']['name'])
            ];
            $msg = 'Comparison complete. See the results below.';
            $msg_type = 'success';

        } catch (Exception $e) {
            $msg = 'Comparison failed: ' . $e->getMessage();
            $msg_type = 'danger';
        }
    } else {
        $msg = 'Invalid security token. Please refresh and try again.';
        $msg_type = 'danger';
    }
}

$live_stats = get_live_database_stats();
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
        <div class="col-lg-10 mx-auto">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Database Status & Comparison</h4>
                </div>
                <div class="card-body">
                    <?php if ($msg): ?>
                        <div class="alert alert-<?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div>
                    <?php endif; ?>

                    <!-- Live Database Status -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5><i class="fas fa-database me-2"></i>Live Database Status</h5>
                            <small class="text-muted">
                                Currently connected to: <?php echo defined('DB_NAME') ? DB_NAME : 'N/A'; ?> 
                                (<?php echo defined('ENVIRONMENT') ? ENVIRONMENT : 'N/A'; ?>)
                            </small>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3"><strong>Total Pledges:</strong> <?php echo number_format($live_stats['pledges']); ?></div>
                                <div class="col-md-3"><strong>Total Payments:</strong> <?php echo number_format($live_stats['payments']); ?></div>
                                <div class="col-md-3"><strong>Total Users:</strong> <?php echo number_format($live_stats['users']); ?></div>
                                <div class="col-md-3"><strong>Grand Total:</strong> £<?php echo number_format($live_stats['grand_total'], 2); ?></div>
                                <div class="col-md-6"><strong>Last Pledge:</strong> <?php echo $live_stats['last_pledge']; ?></div>
                                <div class="col-md-6"><strong>Last Payment:</strong> <?php echo $live_stats['last_payment']; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Compare with Backup -->
                    <div class="card mb-4">
                        <div class="card-header">
                             <h5><i class="fas fa-exchange-alt me-2"></i>Compare with Backup File</h5>
                        </div>
                        <div class="card-body">
                             <p class="text-muted">Upload a `.sql` backup file to see how it compares to the live database before you import.</p>
                             <form method="POST" enctype="multipart/form-data">
                                <?php echo csrf_input(); ?>
                                <div class="input-group">
                                    <input class="form-control" type="file" name="backup_file" accept=".sql" required>
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-search me-2"></i>Compare Now</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Comparison Results -->
                    <?php if ($comparison_results): 
                        $live = $comparison_results['live'];
                        $backup = $comparison_results['backup'];
                        
                        function get_diff_badge($live_val, $backup_val) {
                            $diff = $backup_val - $live_val;
                            if ($diff > 0) return "<span class='badge bg-success'>+" . number_format($diff) . "</span>";
                            if ($diff < 0) return "<span class='badge bg-danger'>" . number_format($diff) . "</span>";
                            return "<span class='badge bg-secondary'>0</span>";
                        }
                    ?>
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5><i class="fas fa-balance-scale me-2"></i>Comparison Results</h5>
                             <small class="text-muted">Comparing live database with: <strong><?php echo $comparison_results['filename']; ?></strong></small>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Metric</th>
                                        <th class="text-center">Live Database</th>
                                        <th class="text-center">Backup File</th>
                                        <th class="text-center">Difference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><i class="fas fa-hand-holding-usd me-2 text-primary"></i>Pledges</td>
                                        <td class="text-center"><?php echo number_format($live['pledges']); ?></td>
                                        <td class="text-center"><?php echo number_format($backup['pledges']); ?></td>
                                        <td class="text-center"><?php echo get_diff_badge($live['pledges'], $backup['pledges']); ?></td>
                                    </tr>
                                    <tr>
                                        <td><i class="fas fa-credit-card me-2 text-success"></i>Payments</td>
                                        <td class="text-center"><?php echo number_format($live['payments']); ?></td>
                                        <td class="text-center"><?php echo number_format($backup['payments']); ?></td>
                                        <td class="text-center"><?php echo get_diff_badge($live['payments'], $backup['payments']); ?></td>
                                    </tr>
                                    <tr>
                                        <td><i class="fas fa-users me-2 text-info"></i>Users</td>
                                        <td class="text-center"><?php echo number_format($live['users']); ?></td>
                                        <td class="text-center"><?php echo number_format($backup['users']); ?></td>
                                        <td class="text-center"><?php echo get_diff_badge($live['users'], $backup['users']); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                         <div class="card-footer text-muted">
                            This comparison is based on counting `INSERT` statements in the `.sql` file. It's a strong indicator of changes but may not capture modified or deleted records.
                         </div>
                    </div>
                    <?php endif; ?>

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
