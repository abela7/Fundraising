<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$page_title = 'Test Deallocator Class';
$db = db();
$error = '';
$success = '';

try {
    // Test if the class can be loaded
    require_once __DIR__ . '/../../shared/IntelligentGridDeallocator.php';
    
    // Test if the class exists
    if (!class_exists('IntelligentGridDeallocator')) {
        throw new Exception('IntelligentGridDeallocator class not found');
    }
    
    // Test if we can create an instance
    $deallocator = new IntelligentGridDeallocator($db);
    
    // Test basic database connection
    $testResult = $db->query("SELECT COUNT(*) as count FROM floor_grid_cells LIMIT 1");
    if ($testResult) {
        $count = $testResult->fetch_assoc()['count'];
        $success = "✅ Class loaded successfully! Database connection working. Total cells: {$count}";
    } else {
        throw new Exception('Database query failed');
    }
    
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
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
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <?= htmlspecialchars($success) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Class Information -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-info-circle me-2"></i>Class Information
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6>Class Details:</h6>
                                            <ul class="list-unstyled">
                                                <li><strong>Class Name:</strong> IntelligentGridDeallocator</li>
                                                <li><strong>File:</strong> shared/IntelligentGridDeallocator.php</li>
                                                <li><strong>Status:</strong> 
                                                    <?php if (class_exists('IntelligentGridDeallocator')): ?>
                                                        <span class="badge bg-success">Loaded</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Not Found</span>
                                                    <?php endif; ?>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Database Connection:</h6>
                                            <ul class="list-unstyled">
                                                <li><strong>Status:</strong> 
                                                    <?php if ($db && !$db->connect_error): ?>
                                                        <span class="badge bg-success">Connected</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Failed</span>
                                                    <?php endif; ?>
                                                </li>
                                                <li><strong>Server:</strong> <?= $db->server_info ?? 'Unknown' ?></li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Test Methods -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-cogs me-2"></i>Available Methods
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (class_exists('IntelligentGridDeallocator')): ?>
                                        <?php
                                        $reflection = new ReflectionClass('IntelligentGridDeallocator');
                                        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
                                        ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Method</th>
                                                        <th>Parameters</th>
                                                        <th>Return Type</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($methods as $method): ?>
                                                        <tr>
                                                            <td><code><?= $method->getName() ?></code></td>
                                                            <td>
                                                                <?php
                                                                $params = [];
                                                                foreach ($method->getParameters() as $param) {
                                                                    $type = $param->getType() ? $param->getType()->getName() : 'mixed';
                                                                    $name = $param->getName();
                                                                    $params[] = "{$type} \${$name}";
                                                                }
                                                                echo implode(', ', $params);
                                                                ?>
                                                            </td>
                                                            <td>
                                                                <code>
                                                                    <?= $method->getReturnType() ? $method->getReturnType()->getName() : 'mixed' ?>
                                                                </code>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted">Class not available for inspection.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Next Steps -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-arrow-right me-2"></i>Next Steps
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>If everything is working:</strong>
                                        <ol class="mb-0 mt-2">
                                            <li>Go to <a href="test_deallocation.php">Test Deallocation</a> to see floor allocations</li>
                                            <li>Try unapproving a donation from <a href="../approved/">Approved Items</a></li>
                                            <li>Check if floor cells become available again</li>
                                        </ol>
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
