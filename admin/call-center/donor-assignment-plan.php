<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$db = db();
$page_title = 'Donor Assignment System - Implementation Plan';

// Check current database structure
$donors_columns = $db->query("SHOW COLUMNS FROM donors");
$columns_list = [];
while ($col = $donors_columns->fetch_assoc()) {
    $columns_list[] = $col;
}

// Check if assigned_agent_id exists
$has_assigned_agent = false;
foreach ($columns_list as $col) {
    if ($col['Field'] === 'assigned_agent_id') {
        $has_assigned_agent = true;
        break;
    }
}

// Get list of agents (admin + registrar)
$agents = $db->query("SELECT id, name, role FROM users WHERE role IN ('admin', 'registrar') ORDER BY name");

// Get donor statistics
$total_donors = $db->query("SELECT COUNT(*) as count FROM donors WHERE donor_type = 'pledge'")->fetch_assoc()['count'];
$donors_with_balance = $db->query("SELECT COUNT(*) as count FROM donors WHERE balance > 0")->fetch_assoc()['count'];
$unassigned_donors = 0;
$assigned_donors = 0;

if ($has_assigned_agent) {
    $unassigned_donors = $db->query("SELECT COUNT(*) as count FROM donors WHERE assigned_agent_id IS NULL AND donor_type = 'pledge'")->fetch_assoc()['count'];
    $assigned_donors = $db->query("SELECT COUNT(*) as count FROM donors WHERE assigned_agent_id IS NOT NULL")->fetch_assoc()['count'];
}
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
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="content-header mb-4">
                <h1 class="content-title">
                    <i class="fas fa-users-cog me-2"></i>Donor Assignment System
                </h1>
                <p class="text-muted">Implementation plan and current status</p>
            </div>

            <!-- Current Status -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-users fa-2x text-primary mb-2"></i>
                            <h3 class="mb-1"><?php echo number_format($total_donors); ?></h3>
                            <p class="text-muted small mb-0">Total Donors</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-pound-sign fa-2x text-warning mb-2"></i>
                            <h3 class="mb-1"><?php echo number_format($donors_with_balance); ?></h3>
                            <p class="text-muted small mb-0">With Outstanding Balance</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-user-check fa-2x text-success mb-2"></i>
                            <h3 class="mb-1"><?php echo number_format($assigned_donors); ?></h3>
                            <p class="text-muted small mb-0">Assigned Donors</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <i class="fas fa-user-slash fa-2x text-danger mb-2"></i>
                            <h3 class="mb-1"><?php echo number_format($unassigned_donors); ?></h3>
                            <p class="text-muted small mb-0">Unassigned Donors</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Database Status -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-database me-2"></i>Database Status
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($has_assigned_agent): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Column Exists!</strong> The <code>assigned_agent_id</code> column is already in the donors table.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Column Missing!</strong> The <code>assigned_agent_id</code> column needs to be added to the donors table.
                        </div>
                    <?php endif; ?>

                    <h6 class="mt-4 mb-3">Existing Related Columns:</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Column Name</th>
                                    <th>Type</th>
                                    <th>Nullable</th>
                                    <th>Purpose</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $relevant_columns = ['church_id', 'representative_id', 'registered_by_user_id', 'assigned_agent_id'];
                                foreach ($columns_list as $col):
                                    if (in_array($col['Field'], $relevant_columns)):
                                ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($col['Field']); ?></code></td>
                                    <td><?php echo htmlspecialchars($col['Type']); ?></td>
                                    <td><?php echo $col['Null'] === 'YES' ? '✅ Yes' : '❌ No'; ?></td>
                                    <td>
                                        <?php
                                        switch ($col['Field']) {
                                            case 'church_id':
                                                echo 'Church assignment (admin/location)';
                                                break;
                                            case 'representative_id':
                                                echo 'Cash collection representative';
                                                break;
                                            case 'registered_by_user_id':
                                                echo 'Who created the donor record';
                                                break;
                                            case 'assigned_agent_id':
                                                echo '<strong>Agent responsible for follow-up</strong>';
                                                break;
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php
                                    endif;
                                endforeach;
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Implementation Plan -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-list-check me-2"></i>Implementation Plan
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-1 me-2"></i>Phase 1: Database Setup
                            </h6>
                            <ul class="list-unstyled">
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    Add <code>assigned_agent_id</code> column to donors table
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    Add <code>assigned_at</code> column (timestamp)
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    Add index for fast filtering
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    Add foreign key constraint to users table
                                </li>
                            </ul>

                            <h6 class="text-primary mb-3 mt-4">
                                <i class="fas fa-2 me-2"></i>Phase 2: Assignment UI
                            </h6>
                            <ul class="list-unstyled">
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-warning me-2"></i>
                                    Create "Assign Donors" page for bulk assignment
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-warning me-2"></i>
                                    Add "Assign to Me" button on donor profile
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-warning me-2"></i>
                                    Add "Reassign" functionality
                                </li>
                            </ul>
                        </div>

                        <div class="col-md-6">
                            <h6 class="text-primary mb-3">
                                <i class="fas fa-3 me-2"></i>Phase 3: Filter & Display
                            </h6>
                            <ul class="list-unstyled">
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-info me-2"></i>
                                    Add "My Assigned Donors" filter to donor list
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-info me-2"></i>
                                    Show assigned agent in donor details
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-info me-2"></i>
                                    Default registrar view to "My Donors Only"
                                </li>
                            </ul>

                            <h6 class="text-primary mb-3 mt-4">
                                <i class="fas fa-4 me-2"></i>Phase 4: Dashboard Integration
                            </h6>
                            <ul class="list-unstyled">
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-secondary me-2"></i>
                                    Show "My Donors" count on dashboard
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-secondary me-2"></i>
                                    Show "Overdue Assigned Donors" alert
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-secondary me-2"></i>
                                    Performance tracking per agent
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Assignment Methods -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-lightbulb me-2"></i>Assignment Methods (Options)
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">
                                        <i class="fas fa-hand-pointer me-2"></i>Manual Assignment
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <p class="small mb-3"><strong>How it works:</strong></p>
                                    <ul class="small">
                                        <li>Admin selects donors from list</li>
                                        <li>Assigns to specific agent</li>
                                        <li>Agent gets notification</li>
                                    </ul>
                                    <p class="small mb-2"><strong>Best for:</strong></p>
                                    <ul class="small mb-0">
                                        <li>Strategic assignments</li>
                                        <li>VIP donors</li>
                                        <li>Specific expertise needed</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">
                                        <i class="fas fa-magic me-2"></i>Auto Assignment
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <p class="small mb-3"><strong>How it works:</strong></p>
                                    <ul class="small">
                                        <li>Round-robin distribution</li>
                                        <li>Based on workload</li>
                                        <li>Based on church/location</li>
                                    </ul>
                                    <p class="small mb-2"><strong>Best for:</strong></p>
                                    <ul class="small mb-0">
                                        <li>Large donor batches</li>
                                        <li>Equal workload</li>
                                        <li>New donors</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">
                                        <i class="fas fa-user-check me-2"></i>Self Assignment
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <p class="small mb-3"><strong>How it works:</strong></p>
                                    <ul class="small">
                                        <li>Agent picks donors</li>
                                        <li>"Claim this donor" button</li>
                                        <li>First-come, first-served</li>
                                    </ul>
                                    <p class="small mb-2"><strong>Best for:</strong></p>
                                    <ul class="small mb-0">
                                        <li>Empowering agents</li>
                                        <li>Agent knows donor</li>
                                        <li>Flexible workload</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recommended Approach -->
            <div class="card mb-4 border-success">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-star me-2"></i>Recommended Approach
                    </h5>
                </div>
                <div class="card-body">
                    <h6 class="text-success mb-3">Hybrid Model: Manual + Self Assignment</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-2"><strong>For Admins:</strong></p>
                            <ul>
                                <li>Can assign any donor to any agent</li>
                                <li>Bulk assignment tool</li>
                                <li>Can reassign if needed</li>
                                <li>View all agents' workloads</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-2"><strong>For Registrars/Agents:</strong></p>
                            <ul>
                                <li>See "Unassigned Donors" list</li>
                                <li>Can "Claim" donors they know</li>
                                <li>See only "My Assigned Donors" by default</li>
                                <li>Can request reassignment</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Next Steps -->
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-rocket me-2"></i>Next Steps
                    </h5>
                </div>
                <div class="card-body">
                    <ol class="mb-0">
                        <li class="mb-2">
                            <strong>Step 1:</strong> Run the SQL migration to add <code>assigned_agent_id</code> column
                        </li>
                        <li class="mb-2">
                            <strong>Step 2:</strong> Create "Assign Donors" page with bulk assignment
                        </li>
                        <li class="mb-2">
                            <strong>Step 3:</strong> Update donor list to show assigned agent
                        </li>
                        <li class="mb-2">
                            <strong>Step 4:</strong> Add "My Assigned Donors" filter
                        </li>
                        <li class="mb-0">
                            <strong>Step 5:</strong> Update dashboard to show agent statistics
                        </li>
                    </ol>
                </div>
            </div>

            <div class="mt-4 text-center">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
                <?php if (!$has_assigned_agent): ?>
                <button class="btn btn-primary" onclick="showMigrationSQL()">
                    <i class="fas fa-database me-2"></i>Show SQL Migration
                </button>
                <?php else: ?>
                <a href="#" class="btn btn-success">
                    <i class="fas fa-play me-2"></i>Start Implementation
                </a>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- SQL Modal -->
<div class="modal fade" id="sqlModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">SQL Migration Script</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Copy and run this in phpMyAdmin:</p>
                <pre class="bg-dark text-light p-3 rounded"><code>-- Add assigned_agent_id column to donors table
ALTER TABLE donors
ADD COLUMN assigned_agent_id INT NULL COMMENT 'Agent responsible for this donor' AFTER registered_by_user_id,
ADD COLUMN assigned_at DATETIME NULL COMMENT 'When donor was assigned to agent' AFTER assigned_agent_id,
ADD INDEX idx_assigned_agent (assigned_agent_id),
ADD CONSTRAINT fk_assigned_agent 
    FOREIGN KEY (assigned_agent_id) 
    REFERENCES users(id) 
    ON DELETE SET NULL;</code></pre>
                <button class="btn btn-sm btn-outline-secondary" onclick="copySQL()">
                    <i class="fas fa-copy me-1"></i>Copy to Clipboard
                </button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
function showMigrationSQL() {
    const modal = new bootstrap.Modal(document.getElementById('sqlModal'));
    modal.show();
}

function copySQL() {
    const sql = `-- Add assigned_agent_id column to donors table
ALTER TABLE donors
ADD COLUMN assigned_agent_id INT NULL COMMENT 'Agent responsible for this donor' AFTER registered_by_user_id,
ADD COLUMN assigned_at DATETIME NULL COMMENT 'When donor was assigned to agent' AFTER assigned_agent_id,
ADD INDEX idx_assigned_agent (assigned_agent_id),
ADD CONSTRAINT fk_assigned_agent 
    FOREIGN KEY (assigned_agent_id) 
    REFERENCES users(id) 
    ON DELETE SET NULL;`;
    
    navigator.clipboard.writeText(sql).then(() => {
        alert('SQL copied to clipboard!');
    });
}
</script>
</body>
</html>

