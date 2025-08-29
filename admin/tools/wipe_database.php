<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_admin();

$page_title = 'Wipe Database';
$db = db();
$msg = '';
$msg_type = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wipe_database_confirmed'])) {
    // Use the non-exiting CSRF check
    if (verify_csrf(false)) {
        try {
            set_time_limit(120); // 2 minutes
            $db->query('SET FOREIGN_KEY_CHECKS=0;');
            
            $result = $db->query('SHOW TABLES;');
            $tables = [];
            while($row = $result->fetch_array()) {
                $tables[] = $row[0];
            }
            
            if (empty($tables)) {
                 $msg = 'Database is already empty. No tables found to wipe.';
                 $msg_type = 'info';
            } else {
                foreach($tables as $table) {
                    $db->query("DROP TABLE `{$table}`");
                }
                $db->query('SET FOREIGN_KEY_CHECKS=1;');
                $msg = 'Success! All ' . count($tables) . ' tables have been wiped from the database.';
                $msg_type = 'success';
            }

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            $db->query('SET FOREIGN_KEY_CHECKS=1;');
            $msg = 'Error wiping database: ' . $e->getMessage();
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
            <div class="card border-danger shadow-sm">
                <div class="card-header bg-danger text-white">
                    <h4 class="mb-0">
                        <i class="fas fa-skull-crossbones me-2"></i>
                        Wipe Database
                    </h4>
                </div>
                <div class="card-body">
                    
                    <div class="alert alert-info">
                        <strong>Current Environment:</strong> <span class="badge bg-primary"><?php echo strtoupper(defined('ENVIRONMENT') ? ENVIRONMENT : 'unknown'); ?></span><br>
                        <strong>Database to be wiped:</strong> <?php echo defined('DB_NAME') ? DB_NAME : 'unknown'; ?>
                    </div>

                    <?php if ($msg): ?>
                        <div class="alert alert-<?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div>
                    <?php endif; ?>

                    <div class="alert alert-danger">
                        <h4><i class="fas fa-exclamation-triangle me-2"></i>EXTREME DANGER</h4>
                        <p>This action will **PERMANENTLY DELETE ALL TABLES** from the database. This cannot be undone. Make sure you have a backup before proceeding.</p>
                    </div>
                    
                    <form method="POST" onsubmit="return confirm('ARE YOU ABSOLUTELY SURE you want to wipe all tables from the database \'<?php echo DB_NAME; ?>\'? This action is irreversible.');">
                        <?php echo csrf_input(); ?>
                        <div class="d-grid">
                            <button type="submit" name="wipe_database_confirmed" class="btn btn-danger btn-lg">
                                <i class="fas fa-bomb me-2"></i>
                                Yes, I understand. Wipe The Database Now.
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
