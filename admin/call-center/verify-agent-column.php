<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$db = db();
$page_title = 'Agent Column Status';

// Check if agent_id column exists
$check_column = $db->query("SHOW COLUMNS FROM donors LIKE 'agent_id'");
$column_exists = $check_column && $check_column->num_rows > 0;

// Get column details if it exists
$column_details = null;
if ($column_exists) {
    $column_details = $check_column->fetch_assoc();
}

// Get statistics
$total_donors = $db->query("SELECT COUNT(*) as count FROM donors WHERE donor_type = 'pledge'")->fetch_assoc()['count'];
$assigned_donors = 0;
$unassigned_donors = 0;

if ($column_exists) {
    $assigned_donors = $db->query("SELECT COUNT(*) as count FROM donors WHERE agent_id IS NOT NULL")->fetch_assoc()['count'];
    $unassigned_donors = $total_donors - $assigned_donors;
}

// Get agents list
$agents = $db->query("SELECT id, name, role FROM users WHERE role IN ('admin', 'registrar') AND active = 1 ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .status-card {
            border-left: 4px solid #198754;
        }
        .status-card.error {
            border-left-color: #dc3545;
        }
        .sql-box {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 1.5rem;
            border-radius: 0.5rem;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            max-height: 400px;
            overflow-y: auto;
        }
        .copy-btn {
            position: absolute;
            top: 1rem;
            right: 1rem;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="content-header mb-4">
                <h1 class="content-title">
                    <i class="fas fa-database me-2"></i>Agent Column Status
                </h1>
                <p class="text-muted">Simple agent assignment system</p>
            </div>

            <!-- Status Card -->
            <div class="card mb-4 status-card <?php echo !$column_exists ? 'error' : ''; ?>">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <?php if ($column_exists): ?>
                                <div class="bg-success bg-opacity-10 text-success rounded-circle p-3">
                                    <i class="fas fa-check-circle fa-2x"></i>
                                </div>
                            <?php else: ?>
                                <div class="bg-danger bg-opacity-10 text-danger rounded-circle p-3">
                                    <i class="fas fa-times-circle fa-2x"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <?php if ($column_exists): ?>
                                <h5 class="text-success mb-1">✅ Column Exists!</h5>
                                <p class="text-muted mb-0">The <code>agent_id</code> column is ready to use.</p>
                            <?php else: ?>
                                <h5 class="text-danger mb-1">❌ Column Missing</h5>
                                <p class="text-muted mb-0">The <code>agent_id</code> column needs to be added to the donors table.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($column_exists): ?>
            <!-- Statistics -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-users fa-2x text-primary mb-2"></i>
                            <h3 class="mb-1"><?php echo number_format($total_donors); ?></h3>
                            <p class="text-muted small mb-0">Total Donors</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-user-check fa-2x text-success mb-2"></i>
                            <h3 class="mb-1"><?php echo number_format($assigned_donors); ?></h3>
                            <p class="text-muted small mb-0">Assigned to Agents</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-user-slash fa-2x text-warning mb-2"></i>
                            <h3 class="mb-1"><?php echo number_format($unassigned_donors); ?></h3>
                            <p class="text-muted small mb-0">Unassigned</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Column Details -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle me-2"></i>Column Details
                    </h5>
                </div>
                <div class="card-body">
                    <table class="table table-bordered mb-0">
                        <tr>
                            <th width="200">Column Name</th>
                            <td><code><?php echo htmlspecialchars($column_details['Field']); ?></code></td>
                        </tr>
                        <tr>
                            <th>Type</th>
                            <td><?php echo htmlspecialchars($column_details['Type']); ?></td>
                        </tr>
                        <tr>
                            <th>Nullable</th>
                            <td><?php echo $column_details['Null'] === 'YES' ? '✅ Yes (NULL = unassigned)' : '❌ No'; ?></td>
                        </tr>
                        <tr>
                            <th>Default</th>
                            <td><?php echo $column_details['Default'] ?? 'NULL'; ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Available Agents -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-users-cog me-2"></i>Available Agents
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Role</th>
                                    <th>Assigned Donors</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $agents->data_seek(0);
                                while ($agent = $agents->fetch_assoc()): 
                                    $agent_donors = $db->query("SELECT COUNT(*) as count FROM donors WHERE agent_id = {$agent['id']}")->fetch_assoc()['count'];
                                ?>
                                <tr>
                                    <td><?php echo $agent['id']; ?></td>
                                    <td><?php echo htmlspecialchars($agent['name']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $agent['role'] === 'admin' ? 'primary' : 'info'; ?>">
                                            <?php echo ucfirst($agent['role']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($agent_donors); ?> donors</td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Next Steps -->
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-check-circle me-2"></i>Column is Ready!
                    </h5>
                </div>
                <div class="card-body">
                    <p class="mb-3">The agent_id column is set up. Next steps:</p>
                    <ol class="mb-0">
                        <li class="mb-2">Update donor list to show assigned agent</li>
                        <li class="mb-2">Add "My Assigned Donors" filter</li>
                        <li class="mb-2">Add "Assign to Me" button on donor profiles</li>
                        <li class="mb-2">Registrars see only their assigned donors by default</li>
                    </ol>
                </div>
            </div>

            <?php else: ?>
            <!-- SQL Migration Instructions -->
            <div class="card border-warning mb-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>Action Required
                    </h5>
                </div>
                <div class="card-body">
                    <p class="mb-3">You need to run this SQL script in phpMyAdmin to add the agent_id column:</p>
                    
                    <div class="position-relative">
                        <button class="btn btn-sm btn-outline-secondary copy-btn" onclick="copySQL()">
                            <i class="fas fa-copy me-1"></i>Copy
                        </button>
                        <div class="sql-box">
<pre>-- Add agent_id column to donors table
ALTER TABLE donors
ADD COLUMN agent_id INT NULL 
    COMMENT 'Agent responsible for following up with this donor'
    AFTER registered_by_user_id;

-- Add index for fast filtering
ALTER TABLE donors
ADD INDEX idx_agent (agent_id);

-- Add foreign key to users table
ALTER TABLE donors
ADD CONSTRAINT fk_donor_agent 
    FOREIGN KEY (agent_id) 
    REFERENCES users(id) 
    ON DELETE SET NULL;</pre>
                        </div>
                    </div>

                    <div class="alert alert-info mt-3 mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>How to run:</strong>
                        <ol class="mb-0 mt-2">
                            <li>Click "Copy" button above</li>
                            <li>Go to phpMyAdmin</li>
                            <li>Select your database</li>
                            <li>Click "SQL" tab</li>
                            <li>Paste and run the script</li>
                            <li>Refresh this page to verify</li>
                        </ol>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="text-center mt-4">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
                <button class="btn btn-primary" onclick="location.reload()">
                    <i class="fas fa-sync me-2"></i>Refresh Status
                </button>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
function copySQL() {
    const sql = `-- Add agent_id column to donors table
ALTER TABLE donors
ADD COLUMN agent_id INT NULL 
    COMMENT 'Agent responsible for following up with this donor'
    AFTER registered_by_user_id;

-- Add index for fast filtering
ALTER TABLE donors
ADD INDEX idx_agent (agent_id);

-- Add foreign key to users table
ALTER TABLE donors
ADD CONSTRAINT fk_donor_agent 
    FOREIGN KEY (agent_id) 
    REFERENCES users(id) 
    ON DELETE SET NULL;`;
    
    navigator.clipboard.writeText(sql).then(() => {
        alert('✅ SQL copied to clipboard! Now paste it in phpMyAdmin.');
    }).catch(() => {
        alert('❌ Failed to copy. Please select and copy manually.');
    });
}
</script>
</body>
</html>

