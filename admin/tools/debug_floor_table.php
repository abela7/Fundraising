<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$page_title = 'Debug Floor Table Structure';
$db = db();
$error = '';
$tableInfo = [];
$sampleData = [];

try {
    // Get table structure
    $result = $db->query("DESCRIBE floor_grid_cells");
    if ($result) {
        $tableInfo = $result->fetch_all(MYSQLI_ASSOC);
    }
    
    // Get sample data
    $result = $db->query("SELECT * FROM floor_grid_cells LIMIT 5");
    if ($result) {
        $sampleData = $result->fetch_all(MYSQLI_ASSOC);
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Fundraising System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/admin.css" rel="stylesheet">
</head>
<body>
    <div class="wrapper">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main">
            <?php include '../includes/topbar.php'; ?>
            
            <main class="content">
                <div class="container-fluid p-0">
                    <h1 class="h3 mb-3"><?= htmlspecialchars($page_title) ?></h1>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Table Structure -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-database me-2"></i>Floor Grid Cells Table Structure
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($tableInfo)): ?>
                                        <p class="text-muted">Could not retrieve table structure.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Field</th>
                                                        <th>Type</th>
                                                        <th>Null</th>
                                                        <th>Key</th>
                                                        <th>Default</th>
                                                        <th>Extra</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($tableInfo as $column): ?>
                                                        <tr>
                                                            <td><code><?= htmlspecialchars($column['Field']) ?></code></td>
                                                            <td><?= htmlspecialchars($column['Type']) ?></td>
                                                            <td><?= htmlspecialchars($column['Null']) ?></td>
                                                            <td><?= htmlspecialchars($column['Key']) ?></td>
                                                            <td><?= htmlspecialchars($column['Default'] ?? 'NULL') ?></td>
                                                            <td><?= htmlspecialchars($column['Extra']) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sample Data -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-list me-2"></i>Sample Data (First 5 Rows)
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($sampleData)): ?>
                                        <p class="text-muted">No sample data found.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <?php foreach (array_keys($sampleData[0]) as $column): ?>
                                                            <th><?= htmlspecialchars($column) ?></th>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($sampleData as $row): ?>
                                                        <tr>
                                                            <?php foreach ($row as $value): ?>
                                                                <td><?= htmlspecialchars($value ?? 'NULL') ?></td>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Test -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-test-tube me-2"></i>Quick Database Test
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php
                                    try {
                                        // Test basic queries
                                        $countResult = $db->query("SELECT COUNT(*) as total FROM floor_grid_cells");
                                        $count = $countResult ? $countResult->fetch_assoc()['total'] : 'Error';
                                        
                                        $statusResult = $db->query("SELECT status, COUNT(*) as count FROM floor_grid_cells GROUP BY status");
                                        $statuses = $statusResult ? $statusResult->fetch_all(MYSQLI_ASSOC) : [];
                                    } catch (Exception $e) {
                                        $count = 'Error: ' . $e->getMessage();
                                        $statuses = [];
                                    }
                                    ?>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="card bg-light">
                                                <div class="card-body text-center">
                                                    <h4 class="text-primary"><?= $count ?></h4>
                                                    <p class="mb-0 text-muted">Total Cells</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card bg-light">
                                                <div class="card-body">
                                                    <h6>Status Breakdown:</h6>
                                                    <?php if (empty($statuses)): ?>
                                                        <p class="text-muted">No status data</p>
                                                    <?php else: ?>
                                                        <ul class="list-unstyled mb-0">
                                                            <?php foreach ($statuses as $status): ?>
                                                                <li><strong><?= ucfirst($status['status']) ?>:</strong> <?= $status['count'] ?></li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/admin.js"></script>
</body>
</html>
