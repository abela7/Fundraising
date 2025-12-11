<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Only admins can set this up
$user_role = $_SESSION['user']['role'] ?? '';
if ($user_role !== 'admin') {
    http_response_code(403);
    die('Access denied. Admin role required.');
}

$page_title = 'Setup Security Fix Tracker';

try {
    $db = db();

    // Check if table already exists
    $result = $db->query("SHOW TABLES LIKE 'security_fixes'");
    $table_exists = $result && $result->num_rows > 0;

    if ($table_exists) {
        $message = 'Security fixes table already exists!';
        $message_type = 'warning';
    } else {
        // Read and execute the SQL file
        $sql_file = __DIR__ . '/create_fix_tracker_table.sql';
        if (!file_exists($sql_file)) {
            throw new Exception('SQL file not found: ' . $sql_file);
        }

        $sql = file_get_contents($sql_file);
        $db->multi_query($sql);

        // Clear any remaining results
        while ($db->next_result()) {
            if (!$db->more_results()) break;
        }

        $message = 'Security fixes table created successfully!';
        $message_type = 'success';
    }

} catch (Exception $e) {
    $message = 'Error: ' . htmlspecialchars($e->getMessage());
    $message_type = 'danger';
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
    <link rel="stylesheet" href="../assets/admin.css">
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
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-database me-2"></i>
                                    Setup Security Fix Tracker
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-<?php echo $message_type; ?>">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <?php echo $message; ?>
                                </div>

                                <?php if ($table_exists): ?>
                                    <p>The security fixes table is already set up. You can now use the <a href="../fix_report.php">Fix Report</a> page.</p>
                                <?php else: ?>
                                    <p>The security fixes table has been created. You can now use the <a href="../fix_report.php">Fix Report</a> page to track security fixes.</p>
                                <?php endif; ?>

                                <div class="mt-3">
                                    <a href="../fix_report.php" class="btn btn-primary">
                                        <i class="fas fa-arrow-right me-2"></i>
                                        Go to Fix Report
                                    </a>
                                    <a href="index.php" class="btn btn-secondary ms-2">
                                        <i class="fas fa-tools me-2"></i>
                                        Back to Tools
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>