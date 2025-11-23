<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_admin(); // Only admins can assign donors

$db = db();
$page_title = 'Assign Donors to Agents';

// Get filter parameters
$search = $_GET['search'] ?? '';
$church_filter = isset($_GET['church']) ? (int)$_GET['church'] : 0;
$status_filter = $_GET['status'] ?? '';
$assignment_filter = $_GET['assignment'] ?? 'all'; // all, assigned, unassigned
$agent_filter = isset($_GET['agent']) ? (int)$_GET['agent'] : 0;

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where_conditions = ["d.donor_type = 'pledge'"];
$params = [];
$param_types = '';

if ($search) {
    $where_conditions[] = "(d.name LIKE ? OR d.phone LIKE ? OR d.email LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'sss';
}

if ($church_filter > 0) {
    $where_conditions[] = "d.church_id = ?";
    $params[] = $church_filter;
    $param_types .= 'i';
}

if ($status_filter) {
    $where_conditions[] = "d.payment_status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

if ($assignment_filter === 'assigned') {
    $where_conditions[] = "d.agent_id IS NOT NULL";
} elseif ($assignment_filter === 'unassigned') {
    $where_conditions[] = "d.agent_id IS NULL";
}

if ($agent_filter > 0) {
    $where_conditions[] = "d.agent_id = ?";
    $params[] = $agent_filter;
    $param_types .= 'i';
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM donors d WHERE {$where_clause}";
$count_stmt = $db->prepare($count_query);
if ($params) {
    $count_stmt->bind_param($param_types, ...$params);
}
$count_stmt->execute();
$total_donors = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_donors / $per_page);

// Get donors
$donor_query = "
    SELECT 
        d.id,
        d.name,
        d.phone,
        d.email,
        d.balance,
        d.total_pledged,
        d.payment_status,
        d.agent_id,
        d.church_id,
        c.name as church_name,
        u.name as agent_name
    FROM donors d
    LEFT JOIN churches c ON d.church_id = c.id
    LEFT JOIN users u ON d.agent_id = u.id
    WHERE {$where_clause}
    ORDER BY 
        CASE WHEN d.agent_id IS NULL THEN 0 ELSE 1 END,
        d.balance DESC,
        d.name ASC
    LIMIT ? OFFSET ?
";

// Add pagination params
$params[] = $per_page;
$params[] = $offset;
$param_types .= 'ii';

$donor_stmt = $db->prepare($donor_query);
if ($params) {
    $donor_stmt->bind_param($param_types, ...$params);
}
$donor_stmt->execute();
$donors = $donor_stmt->get_result();

// Get churches for filter
$churches = $db->query("SELECT id, name FROM churches ORDER BY name");

// Get agents for filter and assignment
$agents = $db->query("SELECT id, name, role FROM users WHERE role IN ('admin', 'registrar') ORDER BY name");

// Get statistics
$stats = [
    'total' => $db->query("SELECT COUNT(*) as count FROM donors WHERE donor_type = 'pledge'")->fetch_assoc()['count'],
    'assigned' => $db->query("SELECT COUNT(*) as count FROM donors WHERE agent_id IS NOT NULL")->fetch_assoc()['count'],
    'unassigned' => $db->query("SELECT COUNT(*) as count FROM donors WHERE agent_id IS NULL AND donor_type = 'pledge'")->fetch_assoc()['count'],
];
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
        .donor-card {
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        .donor-card:hover {
            box-shadow: 0 0.125rem 0.5rem rgba(0,0,0,0.1);
            border-left-color: var(--bs-primary);
        }
        .donor-card.selected {
            background-color: #e7f3ff;
            border-left-color: var(--bs-primary);
        }
        .stat-card {
            border-left: 3px solid;
        }
        .bulk-actions {
            position: sticky;
            top: 70px;
            z-index: 100;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .agent-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        @media (max-width: 768px) {
            .donor-card {
                font-size: 0.9rem;
            }
            .bulk-actions {
                top: 56px;
            }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <!-- Page Header -->
            <div class="content-header mb-4">
                <div>
                    <h1 class="content-title">
                        <i class="fas fa-users-cog me-2"></i>Assign Donors to Agents
                    </h1>
                    <p class="text-muted mb-0">Select donors and assign them to agents for follow-up</p>
                </div>
                <div>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back
                    </a>
                </div>
            </div>

            <!-- Statistics -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card stat-card border-primary">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-users fa-2x text-primary"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <h4 class="mb-0"><?php echo number_format($stats['total']); ?></h4>
                                    <p class="text-muted small mb-0">Total Donors</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stat-card border-success">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-user-check fa-2x text-success"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <h4 class="mb-0"><?php echo number_format($stats['assigned']); ?></h4>
                                    <p class="text-muted small mb-0">Assigned</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stat-card border-warning">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-user-slash fa-2x text-warning"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <h4 class="mb-0"><?php echo number_format($stats['unassigned']); ?></h4>
                                    <p class="text-muted small mb-0">Unassigned</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-3">
                <div class="card-body">
                    <form method="GET" id="filterForm" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Name, phone, email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Assignment</label>
                            <select name="assignment" class="form-select">
                                <option value="all" <?php echo $assignment_filter === 'all' ? 'selected' : ''; ?>>All Donors</option>
                                <option value="unassigned" <?php echo $assignment_filter === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                                <option value="assigned" <?php echo $assignment_filter === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Agent</label>
                            <select name="agent" class="form-select">
                                <option value="0">All Agents</option>
                                <?php 
                                $agents->data_seek(0);
                                while ($agent = $agents->fetch_assoc()): 
                                ?>
                                <option value="<?php echo $agent['id']; ?>" <?php echo $agent_filter === $agent['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($agent['name']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Church</label>
                            <select name="church" class="form-select">
                                <option value="0">All Churches</option>
                                <?php while ($church = $churches->fetch_assoc()): ?>
                                <option value="<?php echo $church['id']; ?>" <?php echo $church_filter === $church['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($church['name']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="not_started" <?php echo $status_filter === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                <option value="paying" <?php echo $status_filter === 'paying' ? 'selected' : ''; ?>>Paying</option>
                                <option value="overdue" <?php echo $status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            </select>
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Bulk Actions Bar (Sticky) -->
            <div class="bulk-actions p-3 mb-3 rounded" id="bulkActionsBar" style="display: none;">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <span class="fw-bold">
                            <span id="selectedCount">0</span> donor(s) selected
                        </span>
                        <button type="button" class="btn btn-sm btn-link" onclick="clearSelection()">Clear</button>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <select id="bulkAgentSelect" class="form-select form-select-sm d-inline-block w-auto me-2">
                            <option value="">Select Agent...</option>
                            <?php 
                            $agents->data_seek(0);
                            while ($agent = $agents->fetch_assoc()): 
                            ?>
                            <option value="<?php echo $agent['id']; ?>">
                                <?php echo htmlspecialchars($agent['name']); ?> (<?php echo ucfirst($agent['role']); ?>)
                            </option>
                            <?php endwhile; ?>
                        </select>
                        <button type="button" class="btn btn-sm btn-success" onclick="assignBulk()">
                            <i class="fas fa-check me-1"></i>Assign
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="unassignBulk()">
                            <i class="fas fa-times me-1"></i>Unassign
                        </button>
                    </div>
                </div>
            </div>

            <!-- Donors List -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Donors (<?php echo number_format($total_donors); ?>)
                    </h6>
                    <div>
                        <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll(this)">
                        <label class="form-check-label ms-1" for="selectAll">Select All</label>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if ($donors->num_rows > 0): ?>
                        <div class="list-group list-group-flush">
                            <?php while ($donor = $donors->fetch_assoc()): ?>
                            <div class="list-group-item donor-card" data-donor-id="<?php echo $donor['id']; ?>">
                                <div class="row align-items-center">
                                    <!-- Checkbox -->
                                    <div class="col-auto">
                                        <input type="checkbox" class="form-check-input donor-checkbox" 
                                               value="<?php echo $donor['id']; ?>" 
                                               onchange="updateSelection()">
                                    </div>

                                    <!-- Donor Info -->
                                    <div class="col-md-3 col-12">
                                        <div class="fw-bold"><?php echo htmlspecialchars($donor['name']); ?></div>
                                        <small class="text-muted">
                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor['phone'] ?? 'N/A'); ?>
                                        </small>
                                    </div>

                                    <!-- Balance -->
                                    <div class="col-md-2 col-6">
                                        <small class="text-muted d-block">Balance</small>
                                        <span class="badge bg-<?php echo $donor['balance'] > 0 ? 'warning' : 'success'; ?>">
                                            £<?php echo number_format($donor['balance'], 2); ?>
                                        </span>
                                    </div>

                                    <!-- Church -->
                                    <div class="col-md-2 col-6 d-none d-md-block">
                                        <small class="text-muted d-block">Church</small>
                                        <small><?php echo htmlspecialchars($donor['church_name'] ?? 'Not assigned'); ?></small>
                                    </div>

                                    <!-- Current Agent -->
                                    <div class="col-md-3 col-12 mt-2 mt-md-0">
                                        <small class="text-muted d-block">Assigned Agent</small>
                                        <?php if ($donor['agent_id']): ?>
                                            <span class="badge bg-primary agent-badge">
                                                <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($donor['agent_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary agent-badge">Unassigned</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Quick Actions -->
                                    <div class="col-md-2 col-12 text-md-end mt-2 mt-md-0">
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary btn-sm" 
                                                    onclick="quickAssign(<?php echo $donor['id']; ?>)" 
                                                    title="Quick Assign">
                                                <i class="fas fa-user-plus"></i>
                                            </button>
                                            <a href="../donor-management/view-donor.php?id=<?php echo $donor['id']; ?>" 
                                               class="btn btn-outline-secondary btn-sm" 
                                               title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-users-slash fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No donors found matching your filters</p>
                            <a href="assign-donors.php" class="btn btn-sm btn-primary">Clear Filters</a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="card-footer">
                    <nav>
                        <ul class="pagination pagination-sm mb-0 justify-content-center">
                            <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <div class="text-center mt-2">
                        <small class="text-muted">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?> 
                            (<?php echo number_format($total_donors); ?> total donors)
                        </small>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Quick Assign Modal -->
<div class="modal fade" id="quickAssignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Quick Assign Donor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="quickAssignDonorId">
                <div class="mb-3">
                    <label class="form-label">Select Agent</label>
                    <select id="quickAssignAgentSelect" class="form-select">
                        <option value="">Select an agent...</option>
                        <?php 
                        $agents->data_seek(0);
                        while ($agent = $agents->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $agent['id']; ?>">
                            <?php echo htmlspecialchars($agent['name']); ?> (<?php echo ucfirst($agent['role']); ?>)
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmQuickAssign()">Assign</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
let selectedDonors = new Set();

function updateSelection() {
    selectedDonors.clear();
    document.querySelectorAll('.donor-checkbox:checked').forEach(cb => {
        selectedDonors.add(cb.value);
        cb.closest('.donor-card').classList.add('selected');
    });
    
    document.querySelectorAll('.donor-checkbox:not(:checked)').forEach(cb => {
        cb.closest('.donor-card').classList.remove('selected');
    });
    
    document.getElementById('selectedCount').textContent = selectedDonors.size;
    document.getElementById('bulkActionsBar').style.display = selectedDonors.size > 0 ? 'block' : 'none';
}

function toggleSelectAll(checkbox) {
    document.querySelectorAll('.donor-checkbox').forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateSelection();
}

function clearSelection() {
    document.querySelectorAll('.donor-checkbox').forEach(cb => {
        cb.checked = false;
    });
    document.getElementById('selectAll').checked = false;
    updateSelection();
}

function assignBulk() {
    const agentId = document.getElementById('bulkAgentSelect').value;
    if (!agentId) {
        alert('Please select an agent');
        return;
    }
    
    if (selectedDonors.size === 0) {
        alert('Please select at least one donor');
        return;
    }
    
    if (!confirm(`Assign ${selectedDonors.size} donor(s) to the selected agent?`)) {
        return;
    }
    
    processBulkAction('assign', agentId);
}

function unassignBulk() {
    if (selectedDonors.size === 0) {
        alert('Please select at least one donor');
        return;
    }
    
    if (!confirm(`Remove agent assignment from ${selectedDonors.size} donor(s)?`)) {
        return;
    }
    
    processBulkAction('unassign', null);
}

function processBulkAction(action, agentId) {
    const donorIds = Array.from(selectedDonors);
    
    fetch('process-assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: action,
            donor_ids: donorIds,
            agent_id: agentId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error processing request');
        console.error(error);
    });
}

function quickAssign(donorId) {
    document.getElementById('quickAssignDonorId').value = donorId;
    const modal = new bootstrap.Modal(document.getElementById('quickAssignModal'));
    modal.show();
}

function confirmQuickAssign() {
    const donorId = document.getElementById('quickAssignDonorId').value;
    const agentId = document.getElementById('quickAssignAgentSelect').value;
    
    if (!agentId) {
        alert('Please select an agent');
        return;
    }
    
    fetch('process-assignment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'assign',
            donor_ids: [donorId],
            agent_id: agentId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error processing request');
        console.error(error);
    });
}
</script>
</body>
</html>

