<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$page_title = 'Donor Info Database Report';

$errors = [];
$success_messages = [];
$columns_status = [];
$fix_applied = false;

// Handle fix action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_database'])) {
    try {
        $db->begin_transaction();
        
        // Check and add baptism_name
        $check = $db->query("SHOW COLUMNS FROM donors LIKE 'baptism_name'");
        if ($check->num_rows == 0) {
            $db->query("ALTER TABLE donors ADD COLUMN baptism_name VARCHAR(255) NULL COMMENT 'Baptism name of the donor' AFTER name");
            $success_messages[] = "Added column: baptism_name";
        }
        
        // Check and add city
        $check = $db->query("SHOW COLUMNS FROM donors LIKE 'city'");
        if ($check->num_rows == 0) {
            $db->query("ALTER TABLE donors ADD COLUMN city VARCHAR(255) NULL COMMENT 'City where donor lives' AFTER phone");
            $success_messages[] = "Added column: city";
        }
        
        // Check and add email
        $check = $db->query("SHOW COLUMNS FROM donors LIKE 'email'");
        if ($check->num_rows == 0) {
            $db->query("ALTER TABLE donors ADD COLUMN email VARCHAR(255) NULL COMMENT 'Email address' AFTER phone");
            $success_messages[] = "Added column: email";
            
            // Add index for email
            $index_check = $db->query("SHOW INDEX FROM donors WHERE Key_name = 'idx_email'");
            if ($index_check->num_rows == 0) {
                $db->query("CREATE INDEX idx_email ON donors(email)");
                $success_messages[] = "Added index: idx_email";
            }
        } else {
            // Check if index exists even if column exists
            $index_check = $db->query("SHOW INDEX FROM donors WHERE Key_name = 'idx_email'");
            if ($index_check->num_rows == 0) {
                $db->query("CREATE INDEX idx_email ON donors(email)");
                $success_messages[] = "Added index: idx_email";
            }
        }
        
        // Check and add preferred_language
        $check = $db->query("SHOW COLUMNS FROM donors LIKE 'preferred_language'");
        if ($check->num_rows == 0) {
            $db->query("ALTER TABLE donors ADD COLUMN preferred_language ENUM('en', 'am', 'ti') DEFAULT 'en' COMMENT 'Preferred language: en=English, am=Amharic, ti=Tigrinya' AFTER email");
            $success_messages[] = "Added column: preferred_language";
        }
        
        // Check church_id exists
        $check = $db->query("SHOW COLUMNS FROM donors LIKE 'church_id'");
        if ($check->num_rows == 0) {
            $db->query("ALTER TABLE donors ADD COLUMN church_id INT NULL COMMENT 'Church assignment' AFTER preferred_language");
            $success_messages[] = "Added column: church_id";
        }
        
        $db->commit();
        $fix_applied = true;
        
    } catch (Exception $e) {
        $db->rollback();
        $errors[] = "Error fixing database: " . $e->getMessage();
    }
}

// Get current column status
try {
    $required_columns = [
        'baptism_name' => [
            'type' => 'VARCHAR(255)',
            'nullable' => true,
            'description' => 'Baptism name of the donor',
            'required' => false
        ],
        'city' => [
            'type' => 'VARCHAR(255)',
            'nullable' => true,
            'description' => 'City where donor lives',
            'required' => false
        ],
        'email' => [
            'type' => 'VARCHAR(255)',
            'nullable' => true,
            'description' => 'Email address',
            'required' => false
        ],
        'preferred_language' => [
            'type' => "ENUM('en', 'am', 'ti')",
            'nullable' => false,
            'default' => 'en',
            'description' => 'Preferred language: en=English, am=Amharic, ti=Tigrinya',
            'required' => true
        ],
        'church_id' => [
            'type' => 'INT',
            'nullable' => true,
            'description' => 'Church assignment (which church they attend)',
            'required' => false
        ]
    ];
    
    $result = $db->query("DESCRIBE donors");
    $existing_columns = [];
    while ($row = $result->fetch_assoc()) {
        $existing_columns[$row['Field']] = $row;
    }
    
    foreach ($required_columns as $column_name => $spec) {
        $exists = isset($existing_columns[$column_name]);
        $columns_status[$column_name] = [
            'exists' => $exists,
            'spec' => $spec,
            'current' => $exists ? $existing_columns[$column_name] : null
        ];
    }
    
    // Check email index
    $email_index_exists = false;
    $index_result = $db->query("SHOW INDEX FROM donors WHERE Key_name = 'idx_email'");
    if ($index_result && $index_result->num_rows > 0) {
        $email_index_exists = true;
    }
    
    // Get statistics
    $stats = [];
    
    // Count donors with each field filled
    foreach (['baptism_name', 'city', 'email', 'preferred_language', 'church_id'] as $col) {
        if (isset($existing_columns[$col])) {
            $count_result = $db->query("SELECT COUNT(*) as total, COUNT({$col}) as filled FROM donors");
            if ($count_result) {
                $stats[$col] = $count_result->fetch_assoc();
            }
        }
    }
    
    // Get preferred language breakdown
    if (isset($existing_columns['preferred_language'])) {
        $lang_result = $db->query("SELECT preferred_language, COUNT(*) as count FROM donors GROUP BY preferred_language");
        $lang_stats = [];
        while ($row = $lang_result->fetch_assoc()) {
            $lang_stats[$row['preferred_language']] = $row['count'];
        }
    }
    
} catch (Exception $e) {
    $errors[] = "Error checking database: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Call Center</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .status-card {
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .status-card.success {
            background: #f0fdf4;
            border: 2px solid #22c55e;
        }
        
        .status-card.warning {
            background: #fef3c7;
            border: 2px solid #f59e0b;
        }
        
        .status-card.danger {
            background: #fef2f2;
            border: 2px solid #ef4444;
        }
        
        .status-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .column-detail {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            border-left: 4px solid #e2e8f0;
        }
        
        .column-detail.exists {
            border-left-color: #22c55e;
        }
        
        .column-detail.missing {
            border-left-color: #ef4444;
        }
        
        .stat-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            margin: 0.25rem;
        }
        
        .stat-badge.primary {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .stat-badge.success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .stat-badge.warning {
            background: #fef3c7;
            color: #92400e;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                
                <!-- Page Header -->
                <div class="content-header mb-4">
                    <h1 class="content-title">
                        <i class="fas fa-database me-2"></i>
                        Donor Information Database Report
                    </h1>
                    <p class="content-subtitle">
                        Check and fix database columns needed for call center donor information collection
                    </p>
                </div>
                
                <!-- Success Messages -->
                <?php if (!empty($success_messages)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <h5><i class="fas fa-check-circle me-2"></i>Database Fixed Successfully!</h5>
                    <ul class="mb-0">
                        <?php foreach ($success_messages as $msg): ?>
                            <li><?php echo htmlspecialchars($msg); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <h5><i class="fas fa-exclamation-circle me-2"></i>Errors</h5>
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Overall Status -->
                <?php
                $all_exist = true;
                foreach ($columns_status as $status) {
                    if (!$status['exists']) {
                        $all_exist = false;
                        break;
                    }
                }
                $needs_fix = !$all_exist || !$email_index_exists;
                ?>
                
                <div class="status-card <?php echo $needs_fix ? 'warning' : 'success'; ?>">
                    <div class="text-center">
                        <div class="status-icon">
                            <?php if (!$needs_fix): ?>
                                <i class="fas fa-check-circle text-success"></i>
                            <?php else: ?>
                                <i class="fas fa-exclamation-triangle text-warning"></i>
                            <?php endif; ?>
                        </div>
                        <h3 class="mb-2">
                            <?php if (!$needs_fix): ?>
                                All Required Columns & Indexes Exist
                            <?php elseif ($all_exist && !$email_index_exists): ?>
                                Columns Exist - Email Index Missing
                            <?php else: ?>
                                Some Columns Are Missing
                            <?php endif; ?>
                        </h3>
                        <p class="mb-3">
                            <?php
                            $existing_count = 0;
                            foreach ($columns_status as $status) {
                                if ($status['exists']) $existing_count++;
                            }
                            ?>
                            <?php echo $existing_count; ?> of <?php echo count($columns_status); ?> required columns exist
                            <?php if (!$email_index_exists): ?>
                                <br><span class="text-warning"><i class="fas fa-exclamation-circle me-1"></i>Email index (idx_email) is missing</span>
                            <?php endif; ?>
                        </p>
                        <?php if ($needs_fix): ?>
                        <form method="POST" class="d-inline">
                            <button type="submit" name="fix_database" class="btn btn-primary btn-lg">
                                <i class="fas fa-tools me-2"></i>Fix Database - Add Missing Items
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Column Details -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Column Status Details</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($columns_status as $column_name => $status): ?>
                        <div class="column-detail <?php echo $status['exists'] ? 'exists' : 'missing'; ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1">
                                        <i class="fas fa-<?php echo $status['exists'] ? 'check-circle text-success' : 'times-circle text-danger'; ?> me-2"></i>
                                        <?php echo htmlspecialchars($column_name); ?>
                                    </h6>
                                    <p class="text-muted small mb-1">
                                        <?php echo htmlspecialchars($status['spec']['description']); ?>
                                    </p>
                                    <?php if ($status['exists'] && $status['current']): ?>
                                        <div class="small">
                                            <strong>Type:</strong> <?php echo htmlspecialchars($status['current']['Type']); ?><br>
                                            <strong>Nullable:</strong> <?php echo $status['current']['Null'] === 'YES' ? 'Yes' : 'No'; ?><br>
                                            <?php if ($status['current']['Default'] !== null): ?>
                                                <strong>Default:</strong> <?php echo htmlspecialchars($status['current']['Default']); ?><br>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="small text-danger">
                                            <strong>Expected Type:</strong> <?php echo htmlspecialchars($status['spec']['type']); ?><br>
                                            <strong>Nullable:</strong> <?php echo $status['spec']['nullable'] ? 'Yes' : 'No'; ?><br>
                                            <?php if (isset($status['spec']['default'])): ?>
                                                <strong>Default:</strong> <?php echo htmlspecialchars($status['spec']['default']); ?><br>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="ms-3">
                                    <?php if ($status['exists']): ?>
                                        <span class="badge bg-success">Exists</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Missing</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <!-- Email Index Status -->
                        <div class="column-detail <?php echo $email_index_exists ? 'exists' : 'missing'; ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1">
                                        <i class="fas fa-<?php echo $email_index_exists ? 'check-circle text-success' : 'times-circle text-danger'; ?> me-2"></i>
                                        Email Index (idx_email)
                                    </h6>
                                    <p class="text-muted small mb-0">
                                        Index on email column for faster searches
                                    </p>
                                </div>
                                <div class="ms-3">
                                    <?php if ($email_index_exists): ?>
                                        <span class="badge bg-success">Exists</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Missing</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics -->
                <?php if (!empty($stats)): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Data Statistics</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($stats as $column => $stat): ?>
                            <div class="col-md-6 mb-3">
                                <div class="p-3 bg-light rounded">
                                    <h6 class="text-uppercase small text-muted mb-2"><?php echo htmlspecialchars($column); ?></h6>
                                    <div>
                                        <span class="stat-badge primary">
                                            Total Donors: <?php echo number_format($stat['total']); ?>
                                        </span>
                                        <span class="stat-badge <?php echo ($stat['filled'] / $stat['total'] * 100) > 50 ? 'success' : 'warning'; ?>">
                                            Filled: <?php echo number_format($stat['filled']); ?> 
                                            (<?php echo round($stat['filled'] / $stat['total'] * 100, 1); ?>%)
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (isset($lang_stats)): ?>
                        <div class="mt-4">
                            <h6 class="mb-3">Preferred Language Breakdown</h6>
                            <div>
                                <?php
                                $lang_labels = ['en' => 'English', 'am' => 'Amharic', 'ti' => 'Tigrinya'];
                                foreach ($lang_stats as $lang => $count):
                                ?>
                                    <span class="stat-badge primary">
                                        <?php echo htmlspecialchars($lang_labels[$lang] ?? strtoupper($lang)); ?>: <?php echo number_format($count); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Information Section -->
                <div class="card shadow-sm">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Information</h5>
                    </div>
                    <div class="card-body">
                        <h6>Required Columns for Call Center</h6>
                        <p>The following columns are needed to collect donor information during call center conversations:</p>
                        <ul>
                            <li><strong>baptism_name</strong> - Baptism name of the donor</li>
                            <li><strong>city</strong> - City where the donor lives</li>
                            <li><strong>email</strong> - Email address for communication</li>
                            <li><strong>preferred_language</strong> - Language preference (en/am/ti)</li>
                            <li><strong>church_id</strong> - Church assignment (which church they attend)</li>
                        </ul>
                        
                        <h6 class="mt-4">About church_id</h6>
                        <p>
                            The <code>church_id</code> column is used to track which church the donor attends regularly.
                            This is the main church assignment field (we don't need a separate attending_church_id).
                        </p>
                        
                        <h6 class="mt-4">Next Steps</h6>
                        <ol>
                            <li>Verify all columns exist (use the "Fix Database" button if needed)</li>
                            <li>Review the statistics to see how much data is already filled</li>
                            <li>Proceed with adding the new call center step to collect this information</li>
                        </ol>
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

