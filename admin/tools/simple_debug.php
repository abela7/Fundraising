<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'check_db') {
        try {
            // Simple database connection test
            $result = $db->query("SELECT 1 as test");
            if ($result) {
                $message = "✅ Database connection successful!";
            } else {
                $error = "❌ Database connection failed!";
            }
        } catch (Exception $e) {
            $error = "❌ Database error: " . $e->getMessage();
        }
        
    } elseif ($action === 'check_table') {
        try {
            // Check if table exists
            $result = $db->query("SHOW TABLES LIKE 'floor_grid_cells'");
            if ($result && $result->num_rows > 0) {
                $message = "✅ floor_grid_cells table exists!";
                
                // Count total rows
                $countResult = $db->query("SELECT COUNT(*) as total FROM floor_grid_cells");
                $count = $countResult->fetch_assoc()['total'];
                $message .= "<br>📊 Total cells: {$count}";
                
                // Count allocated cells
                $allocatedResult = $db->query("SELECT COUNT(*) as allocated FROM floor_grid_cells WHERE status IN ('pledged', 'paid')");
                $allocated = $allocatedResult->fetch_assoc()['allocated'];
                $message .= "<br>🎯 Allocated cells: {$allocated}";
                
            } else {
                $error = "❌ floor_grid_cells table does not exist!";
            }
        } catch (Exception $e) {
            $error = "❌ Table check error: " . $e->getMessage();
        }
        
    } elseif ($action === 'check_columns') {
        try {
            // Check table structure
            $result = $db->query("DESCRIBE floor_grid_cells");
            $columns = [];
            while ($row = $result->fetch_assoc()) {
                $columns[] = $row['Field'];
            }
            
            $message = "📋 Table columns: " . implode(', ', $columns);
            
            // Check for required columns
            $required = ['pledge_id', 'payment_id', 'status', 'donor_name', 'amount'];
            $missing = [];
            foreach ($required as $col) {
                if (!in_array($col, $columns)) {
                    $missing[] = $col;
                }
            }
            
            if (empty($missing)) {
                $message .= "<br>✅ All required columns exist!";
            } else {
                $message .= "<br>❌ Missing columns: " . implode(', ', $missing);
            }
            
        } catch (Exception $e) {
            $error = "❌ Column check error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple Debug - Floor System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .debug-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">
                    <i class="fas fa-bug me-2"></i>Simple Debug - Floor System
                </h1>
                
                <?php if ($message): ?>
                    <div class="alert alert-success"><?= $message ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="debug-section">
                            <h4><i class="fas fa-database me-2"></i>Check Database</h4>
                            <p class="text-muted">Test basic database connection</p>
                            <form method="POST">
                                <?= csrf_token() ?>
                                <input type="hidden" name="action" value="check_db">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-plug me-1"></i>Test Connection
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="debug-section">
                            <h4><i class="fas fa-table me-2"></i>Check Table</h4>
                            <p class="text-muted">Verify floor_grid_cells table exists</p>
                            <form method="POST">
                                <?= csrf_token() ?>
                                <input type="hidden" name="action" value="check_table">
                                <button type="submit" class="btn btn-info">
                                    <i class="fas fa-eye me-1"></i>Check Table
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="debug-section">
                            <h4><i class="fas fa-columns me-2"></i>Check Columns</h4>
                            <p class="text-muted">Verify required columns exist</p>
                            <form method="POST">
                                <?= csrf_token() ?>
                                <input type="hidden" name="action" value="check_columns">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-list me-1"></i>Check Columns
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="mt-4">
                    <div class="alert alert-info">
                        <h5><i class="fas fa-info-circle me-2"></i>What This Tool Does</h5>
                        <p>This simple debug tool tests basic database functionality step by step:</p>
                        <ol>
                            <li><strong>Check Database:</strong> Tests if we can connect to the database</li>
                            <li><strong>Check Table:</strong> Verifies the floor_grid_cells table exists and shows basic stats</li>
                            <li><strong>Check Columns:</strong> Ensures all required columns are present</li>
                        </ol>
                        <p>If any of these fail, we know where the problem is!</p>
                    </div>
                </div>
                
                <div class="mt-3">
                    <a href="../tools/" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back to Tools
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
