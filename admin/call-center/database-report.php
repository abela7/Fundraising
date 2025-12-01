<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$page_title = 'Database Structure Report';
$db = db();

// Tables to check
$tables_to_check = [
    'call_center_sessions',
    'call_center_queues',
    'donors',
    'users',
    'twilio_settings',
    'twilio_call_logs',
    'twilio_webhook_logs',
    'twilio_monthly_stats'
];

$table_structures = [];

foreach ($tables_to_check as $table) {
    try {
        // Check if table exists
        $check = $db->query("SHOW TABLES LIKE '$table'");
        if ($check && $check->num_rows > 0) {
            // Get columns
            $result = $db->query("SHOW COLUMNS FROM `$table`");
            $columns = [];
            while ($row = $result->fetch_assoc()) {
                $columns[] = $row;
            }
            $table_structures[$table] = [
                'exists' => true,
                'columns' => $columns
            ];
            
            // Get row count
            $count_result = $db->query("SELECT COUNT(*) as cnt FROM `$table`");
            $count = $count_result->fetch_assoc()['cnt'];
            $table_structures[$table]['row_count'] = $count;
        } else {
            $table_structures[$table] = [
                'exists' => false,
                'columns' => []
            ];
        }
    } catch (Exception $e) {
        $table_structures[$table] = [
            'exists' => false,
            'error' => $e->getMessage()
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .table-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
        }
        
        .table-name {
            font-weight: 700;
            color: #0a6286;
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }
        
        .table-exists {
            color: #10b981;
        }
        
        .table-missing {
            color: #ef4444;
        }
        
        .column-type {
            font-family: 'Courier New', monospace;
            background: #f1f5f9;
            padding: 0.125rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8125rem;
        }
        
        .copy-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 1000;
        }
        
        pre {
            background: #1e293b;
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 8px;
            font-size: 0.8125rem;
            max-height: 400px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <div class="admin-content">
            <?php include __DIR__ . '/../includes/topbar.php'; ?>
            
            <main class="main-content">
                <div class="container-fluid p-3 p-md-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="h4 mb-1">
                                <i class="fas fa-database text-primary me-2"></i>Database Structure Report
                            </h1>
                            <p class="text-muted mb-0 small">Complete table and column information for Twilio integration</p>
                        </div>
                        <button class="btn btn-primary" onclick="copyReport()">
                            <i class="fas fa-copy me-1"></i>Copy Full Report
                        </button>
                    </div>
                    
                    <!-- Summary -->
                    <div class="table-container">
                        <h5 class="mb-3"><i class="fas fa-chart-bar text-success me-2"></i>Summary</h5>
                        <div class="row">
                            <?php 
                            $existing_count = 0;
                            $missing_count = 0;
                            $total_columns = 0;
                            foreach ($table_structures as $table => $info) {
                                if ($info['exists']) {
                                    $existing_count++;
                                    $total_columns += count($info['columns']);
                                } else {
                                    $missing_count++;
                                }
                            }
                            ?>
                            <div class="col-md-3">
                                <div class="text-center p-3 bg-success-subtle rounded">
                                    <h2 class="mb-0 text-success"><?php echo $existing_count; ?></h2>
                                    <small class="text-muted">Tables Exist</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center p-3 bg-danger-subtle rounded">
                                    <h2 class="mb-0 text-danger"><?php echo $missing_count; ?></h2>
                                    <small class="text-muted">Tables Missing</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center p-3 bg-info-subtle rounded">
                                    <h2 class="mb-0 text-info"><?php echo $total_columns; ?></h2>
                                    <small class="text-muted">Total Columns</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center p-3 bg-warning-subtle rounded">
                                    <h2 class="mb-0 text-warning"><?php echo count($tables_to_check); ?></h2>
                                    <small class="text-muted">Tables Checked</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Table Details -->
                    <?php foreach ($table_structures as $table => $info): ?>
                    <div class="table-container">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="table-name">
                                <?php if ($info['exists']): ?>
                                <i class="fas fa-check-circle table-exists me-2"></i>
                                <?php else: ?>
                                <i class="fas fa-times-circle table-missing me-2"></i>
                                <?php endif; ?>
                                <?php echo $table; ?>
                            </div>
                            <?php if ($info['exists']): ?>
                            <span class="badge bg-primary">
                                <?php echo count($info['columns']); ?> columns | 
                                <?php echo $info['row_count']; ?> rows
                            </span>
                            <?php else: ?>
                            <span class="badge bg-danger">Table Does Not Exist</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($info['exists']): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 30%;">Column Name</th>
                                        <th style="width: 25%;">Type</th>
                                        <th style="width: 10%;">Null</th>
                                        <th style="width: 10%;">Key</th>
                                        <th style="width: 15%;">Default</th>
                                        <th style="width: 10%;">Extra</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($info['columns'] as $column): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($column['Field']); ?></strong></td>
                                        <td><span class="column-type"><?php echo htmlspecialchars($column['Type']); ?></span></td>
                                        <td><?php echo $column['Null'] === 'YES' ? '<span class="badge bg-warning">YES</span>' : '<span class="badge bg-secondary">NO</span>'; ?></td>
                                        <td><?php echo !empty($column['Key']) ? '<span class="badge bg-info">' . htmlspecialchars($column['Key']) . '</span>' : '-'; ?></td>
                                        <td><?php echo $column['Default'] !== null ? htmlspecialchars($column['Default']) : '<span class="text-muted">NULL</span>'; ?></td>
                                        <td><?php echo !empty($column['Extra']) ? '<small class="text-muted">' . htmlspecialchars($column['Extra']) . '</small>' : '-'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php elseif (isset($info['error'])): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Error: <?php echo htmlspecialchars($info['error']); ?>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle me-2"></i>
                            This table needs to be created. Run the SQL setup script.
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    
                    <!-- Text Report for Copying -->
                    <div class="table-container">
                        <h5 class="mb-3"><i class="fas fa-file-code text-primary me-2"></i>Text Report (Copy This)</h5>
                        <pre id="textReport"><?php
echo "==============================================\n";
echo "DATABASE STRUCTURE REPORT\n";
echo "Generated: " . date('Y-m-d H:i:s') . "\n";
echo "==============================================\n\n";

foreach ($table_structures as $table => $info) {
    echo str_repeat("=", 50) . "\n";
    echo "TABLE: $table\n";
    echo str_repeat("=", 50) . "\n";
    
    if ($info['exists']) {
        echo "Status: EXISTS ✓\n";
        echo "Columns: " . count($info['columns']) . "\n";
        echo "Rows: " . $info['row_count'] . "\n\n";
        
        echo str_pad("Column Name", 30) . str_pad("Type", 20) . str_pad("Null", 6) . str_pad("Key", 6) . "Default\n";
        echo str_repeat("-", 80) . "\n";
        
        foreach ($info['columns'] as $column) {
            echo str_pad($column['Field'], 30) . 
                 str_pad($column['Type'], 20) . 
                 str_pad($column['Null'], 6) . 
                 str_pad($column['Key'], 6) . 
                 ($column['Default'] ?? 'NULL') . "\n";
        }
    } else {
        echo "Status: DOES NOT EXIST ✗\n";
        if (isset($info['error'])) {
            echo "Error: " . $info['error'] . "\n";
        }
    }
    
    echo "\n\n";
}

echo "==============================================\n";
echo "END OF REPORT\n";
echo "==============================================\n";
?></pre>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <button class="btn btn-primary btn-lg copy-btn" onclick="copyReport()">
        <i class="fas fa-copy me-2"></i>Copy Report
    </button>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/admin.js"></script>
    <script>
    function copyReport() {
        const reportText = document.getElementById('textReport').textContent;
        navigator.clipboard.writeText(reportText).then(() => {
            const btn = event.target.closest('button');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check me-2"></i>Copied!';
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-success');
            
            setTimeout(() => {
                btn.innerHTML = originalHTML;
                btn.classList.remove('btn-success');
                btn.classList.add('btn-primary');
            }, 2000);
        });
    }
    </script>
</body>
</html>

