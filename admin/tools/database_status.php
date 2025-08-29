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

// --- Function to get detailed stats from the LIVE database ---
function get_live_database_stats(): array {
    $stats = [
        'pledges_by_status' => [], 'payments_by_status' => [], 'users_by_role' => [],
        'pending_applications' => 0, 'cells_by_status' => [], 'projector_commands' => 0,
        'footer_message' => 'N/A'
    ];
    try {
        $db = db();
        if ($db->query("SHOW TABLES LIKE 'pledges'")->num_rows > 0) {
            $res = $db->query("SELECT status, COUNT(*) as count, SUM(amount) as total FROM pledges GROUP BY status");
            while($row = $res->fetch_assoc()) { $stats['pledges_by_status'][$row['status']] = ['count' => (int)$row['count'], 'total' => (float)$row['total']]; }
        }
        if ($db->query("SHOW TABLES LIKE 'payments'")->num_rows > 0) {
            $res = $db->query("SELECT status, COUNT(*) as count, SUM(amount) as total FROM payments GROUP BY status");
            while($row = $res->fetch_assoc()) { $stats['payments_by_status'][$row['status']] = ['count' => (int)$row['count'], 'total' => (float)$row['total']]; }
        }
        if ($db->query("SHOW TABLES LIKE 'users'")->num_rows > 0) {
             $res = $db->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
             while($row = $res->fetch_assoc()) { $stats['users_by_role'][$row['role']] = (int)$row['count']; }
        }
        if ($db->query("SHOW TABLES LIKE 'registrar_applications'")->num_rows > 0) {
            $stats['pending_applications'] = (int)($db->query("SELECT COUNT(*) FROM registrar_applications WHERE status = 'pending'")->fetch_row()[0] ?? 0);
        }
        if ($db->query("SHOW TABLES LIKE 'floor_grid_cells'")->num_rows > 0) {
            $res = $db->query("SELECT status, COUNT(*) as count FROM floor_grid_cells GROUP BY status");
             while($row = $res->fetch_assoc()) { $stats['cells_by_status'][$row['status']] = (int)$row['count']; }
        }
        if ($db->query("SHOW TABLES LIKE 'projector_commands'")->num_rows > 0) {
            $stats['projector_commands'] = (int)($db->query("SELECT COUNT(*) FROM projector_commands")->fetch_row()[0] ?? 0);
        }
        if ($db->query("SHOW TABLES LIKE 'projector_footer'")->num_rows > 0) {
            $stats['footer_message'] = $db->query("SELECT message FROM projector_footer LIMIT 1")->fetch_row()[0] ?? 'N/A';
        }
    } catch (Exception $e) { /* Fail gracefully */ }
    return $stats;
}

// --- Function to get detailed stats by PARSING a backup .sql file ---
function get_backup_file_stats(string $file_path): array {
    $stats = [
        'pledges_by_status' => [], 'payments_by_status' => [], 'users_by_role' => [],
        'pending_applications' => 0, 'cells_by_status' => [], 'projector_commands' => 0,
        'footer_message' => 'N/A'
    ];
    $file_handle = fopen($file_path, 'r');
    if (!$file_handle) { throw new Exception("Could not open the uploaded file."); }

    $pledges = []; $payments = []; $users = []; $applications = []; $cells = []; $commands = 0;
    
    while (($line = fgets($file_handle)) !== false) {
        if (strpos($line, 'INSERT INTO') !== 0) continue;
        if (preg_match('/INSERT INTO `(.*?)` \((.*?)\) VALUES \((.*?)\);/', $line, $matches)) {
            $table = $matches[1];
            $columns = array_map('trim', explode(',', str_replace('`', '', $matches[2])));
            $values = str_getcsv($matches[3]);
            if(count($columns) !== count($values)) continue;
            $row = array_combine($columns, $values);
            foreach($row as &$val) { if(is_string($val)) $val = trim($val, "'"); } unset($val);
            if ($table === 'pledges') $pledges[] = $row;
            if ($table === 'payments') $payments[] = $row;
            if ($table === 'users') $users[] = $row;
            if ($table === 'registrar_applications') $applications[] = $row;
            if ($table === 'floor_grid_cells') $cells[] = $row;
            if ($table === 'projector_commands') $commands++;
            if ($table === 'projector_footer' && isset($row['message'])) $stats['footer_message'] = $row['message'];
        }
    }
    fclose($file_handle);

    foreach($pledges as $p) {
        $status = $p['status'] ?? 'unknown';
        if(!isset($stats['pledges_by_status'][$status])) $stats['pledges_by_status'][$status] = ['count' => 0, 'total' => 0];
        $stats['pledges_by_status'][$status]['count']++;
        $stats['pledges_by_status'][$status]['total'] += (float)($p['amount'] ?? 0);
    }
    foreach($payments as $p) {
        $status = $p['status'] ?? 'unknown';
        if(!isset($stats['payments_by_status'][$status])) $stats['payments_by_status'][$status] = ['count' => 0, 'total' => 0];
        $stats['payments_by_status'][$status]['count']++;
        $stats['payments_by_status'][$status]['total'] += (float)($p['amount'] ?? 0);
    }
    foreach($users as $u) {
        $role = $u['role'] ?? 'unknown';
        if(!isset($stats['users_by_role'][$role])) $stats['users_by_role'][$role] = 0;
        $stats['users_by_role'][$role]++;
    }
    foreach($applications as $a) { if(($a['status'] ?? '') === 'pending') $stats['pending_applications']++; }
    foreach($cells as $c) {
        $status = $c['status'] ?? 'unknown';
        if(!isset($stats['cells_by_status'][$status])) $stats['cells_by_status'][$status] = 0;
        $stats['cells_by_status'][$status]++;
    }
    $stats['projector_commands'] = $commands;
    return $stats;
}

// --- Handle File Upload for Comparison ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_file'])) {
    if (verify_csrf(false)) {
        try {
            if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('File upload error. Code: ' . ($_FILES['backup_file']['error'] ?? 'Unknown'));
            }
            $comparison_results = [
                'live' => get_live_database_stats(),
                'backup' => get_backup_file_stats($_FILES['backup_file']['tmp_name']),
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

function render_comparison_row($label, $live_val, $backup_val, $is_money = false) {
    $live_val = $live_val ?? 0;
    $backup_val = $backup_val ?? 0;
    $diff = $backup_val - $live_val;

    $live_display = $is_money ? '£' . number_format($live_val, 2) : number_format($live_val);
    $backup_display = $is_money ? '£' . number_format($backup_val, 2) : number_format($backup_val);
    
    $diff_badge = '';
    $diff_display = $is_money ? '£' . number_format($diff, 2) : number_format($diff);
    if ($diff > 0) $diff_badge = "<span class='badge bg-success ms-2'>+{$diff_display}</span>";
    if ($diff < 0) $diff_badge = "<span class='badge bg-danger ms-2'>{$diff_display}</span>";
    
    echo "<tr><td>{$label}</td><td class='text-center'>{$live_display}</td><td class='text-center'>{$backup_display} {$diff_badge}</td></tr>";
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
<div class="container mt-4 mb-4">
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

                    <!-- Compare with Backup -->
                    <div class="card mb-4">
                        <div class="card-header"><h5><i class="fas fa-exchange-alt me-2"></i>Compare Live DB with Backup File</h5></div>
                        <div class="card-body">
                             <p class="text-muted">Upload a `.sql` backup file to generate a detailed report comparing it against the live database.</p>
                             <form method="POST" enctype="multipart/form-data">
                                <?php echo csrf_input(); ?>
                                <div class="input-group">
                                    <input class="form-control" type="file" name="backup_file" accept=".sql" required>
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-search me-2"></i>Analyze & Compare</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Comparison Results -->
                    <?php if ($comparison_results): 
                        $live = $comparison_results['live'];
                        $backup = $comparison_results['backup'];
                    ?>
                    <div class="card">
                        <div class="card-header bg-light">
                            <h5><i class="fas fa-balance-scale me-2"></i>Comparison Report</h5>
                            <small class="text-muted">Comparing <strong>Live Database</strong> with backup file: <strong><?php echo $comparison_results['filename']; ?></strong></small>
                        </div>
                        <div class="row g-0">
                            <!-- Live Column -->
                            <div class="col-lg-6 border-end">
                                <h6 class="text-center p-2 bg-light">Live Database</h6>
                                <table class="table table-striped table-sm mb-0">
                                    <tr><td><strong>Pending Pledges:</strong></td><td><?php echo number_format($live['pledges_by_status']['pending']['count'] ?? 0); ?> (<?php echo '£' . number_format($live['pledges_by_status']['pending']['total'] ?? 0, 2); ?>)</td></tr>
                                    <tr><td><strong>Approved Pledges:</strong></td><td><?php echo number_format($live['pledges_by_status']['approved']['count'] ?? 0); ?> (<?php echo '£' . number_format($live['pledges_by_status']['approved']['total'] ?? 0, 2); ?>)</td></tr>
                                    <tr><td><strong>Pending Payments:</strong></td><td><?php echo number_format($live['payments_by_status']['pending']['count'] ?? 0); ?> (<?php echo '£' . number_format($live['payments_by_status']['pending']['total'] ?? 0, 2); ?>)</td></tr>
                                    <tr><td><strong>Approved Payments:</strong></td><td><?php echo number_format($live['payments_by_status']['approved']['count'] ?? 0); ?> (<?php echo '£' . number_format($live['payments_by_status']['approved']['total'] ?? 0, 2); ?>)</td></tr>
                                    <tr><td colspan="2" class="bg-light"><strong>Users & Access</strong></td></tr>
                                    <tr><td><strong>Admin Users:</strong></td><td><?php echo number_format($live['users_by_role']['admin'] ?? 0); ?></td></tr>
                                    <tr><td><strong>Registrar Users:</strong></td><td><?php echo number_format($live['users_by_role']['registrar'] ?? 0); ?></td></tr>
                                    <tr><td><strong>Pending Applications:</strong></td><td><?php echo number_format($live['pending_applications'] ?? 0); ?></td></tr>
                                    <tr><td colspan="2" class="bg-light"><strong>Floor Map</strong></td></tr>
                                    <tr><td><strong>Available Cells:</strong></td><td><?php echo number_format($live['cells_by_status']['available'] ?? 0); ?></td></tr>
                                    <tr><td><strong>Pledged/Paid Cells:</strong></td><td><?php echo number_format(($live['cells_by_status']['pledged'] ?? 0) + ($live['cells_by_status']['paid'] ?? 0)); ?></td></tr>
                                    <tr><td colspan="2" class="bg-light"><strong>Projector</strong></td></tr>
                                    <tr><td><strong>Projector Commands:</strong></td><td><?php echo number_format($live['projector_commands'] ?? 0); ?></td></tr>
                                    <tr><td><strong>Footer Message:</strong></td><td style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px;"><?php echo htmlspecialchars($live['footer_message'] ?? 'N/A'); ?></td></tr>
                                </table>
                            </div>
                            <!-- Backup Column -->
                            <div class="col-lg-6">
                                <h6 class="text-center p-2 bg-light">Backup File</h6>
                                <table class="table table-striped table-sm mb-0">
                                     <?php render_comparison_row("Pending Pledges:", $live['pledges_by_status']['pending']['count'] ?? 0, $backup['pledges_by_status']['pending']['count'] ?? 0); ?>
                                     <?php render_comparison_row("Approved Pledges:", $live['pledges_by_status']['approved']['count'] ?? 0, $backup['pledges_by_status']['approved']['count'] ?? 0); ?>
                                     <?php render_comparison_row("Pending Payments:", $live['payments_by_status']['pending']['count'] ?? 0, $backup['payments_by_status']['pending']['count'] ?? 0); ?>
                                     <?php render_comparison_row("Approved Payments:", $live['payments_by_status']['approved']['count'] ?? 0, $backup['payments_by_status']['approved']['count'] ?? 0); ?>
                                     <tr><td colspan="3" class="bg-light text-white">.</td></tr>
                                     <?php render_comparison_row("Admin Users:", $live['users_by_role']['admin'] ?? 0, $backup['users_by_role']['admin'] ?? 0); ?>
                                     <?php render_comparison_row("Registrar Users:", $live['users_by_role']['registrar'] ?? 0, $backup['users_by_role']['registrar'] ?? 0); ?>
                                     <?php render_comparison_row("Pending Applications:", $live['pending_applications'] ?? 0, $backup['pending_applications'] ?? 0); ?>
                                     <tr><td colspan="3" class="bg-light text-white">.</td></tr>
                                     <?php render_comparison_row("Available Cells:", $live['cells_by_status']['available'] ?? 0, $backup['cells_by_status']['available'] ?? 0); ?>
                                     <?php render_comparison_row("Pledged/Paid Cells:", ($live['cells_by_status']['pledged'] ?? 0) + ($live['cells_by_status']['paid'] ?? 0), ($backup['cells_by_status']['pledged'] ?? 0) + ($backup['cells_by_status']['paid'] ?? 0)); ?>
                                     <tr><td colspan="3" class="bg-light text-white">.</td></tr>
                                     <?php render_comparison_row("Projector Commands:", $live['projector_commands'] ?? 0, $backup['projector_commands'] ?? 0); ?>
                                     <tr><td><strong>Footer Message:</strong></td><td class="text-center" colspan="2" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px;"><?php echo htmlspecialchars($backup['footer_message'] ?? 'N/A'); ?></td></tr>
                                </table>
                            </div>
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
