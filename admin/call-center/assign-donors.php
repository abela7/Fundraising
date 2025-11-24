<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$db = db();
$page_title = 'Assign Donors to Agents';

// Handle bulk assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $donor_ids = $_POST['donor_ids'] ?? [];
    $action = $_POST['bulk_action'];
    $agent_id = isset($_POST['bulk_agent_id']) ? (int)$_POST['bulk_agent_id'] : 0;
    
    if (!empty($donor_ids) && is_array($donor_ids)) {
        try {
            $success_count = 0;
            foreach ($donor_ids as $donor_id) {
                $donor_id = (int)$donor_id;
                if ($donor_id > 0) {
                    if ($action === 'assign' && $agent_id > 0) {
                        $stmt = $db->prepare("UPDATE donors SET agent_id = ? WHERE id = ?");
                        $stmt->bind_param('ii', $agent_id, $donor_id);
                        $stmt->execute();
                        $success_count++;
                    } elseif ($action === 'unassign') {
                        $stmt = $db->prepare("UPDATE donors SET agent_id = NULL WHERE id = ?");
                        $stmt->bind_param('i', $donor_id);
                        $stmt->execute();
                        $success_count++;
                    }
                }
            }
            header("Location: assign-donors.php");
            exit;
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    }
}

// Handle single assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign']) && !isset($_POST['bulk_action'])) {
    $donor_id = (int)$_POST['donor_id'];
    $agent_id = (int)$_POST['agent_id'];
    
    if ($donor_id > 0) {
        try {
            if ($agent_id > 0) {
                $stmt = $db->prepare("UPDATE donors SET agent_id = ? WHERE id = ?");
                $stmt->bind_param('ii', $agent_id, $donor_id);
                $stmt->execute();
            } else {
                $stmt = $db->prepare("UPDATE donors SET agent_id = NULL WHERE id = ?");
                $stmt->bind_param('i', $donor_id);
                $stmt->execute();
            }
            header("Location: assign-donors.php");
            exit;
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    }
}

// Get agents
$agents_result = $db->query("SELECT id, name FROM users WHERE role IN ('admin', 'registrar') ORDER BY name");
$agents = [];
while ($agent = $agents_result->fetch_assoc()) {
    $agents[] = $agent;
}

// Get donors by agent
$donors_by_agent = [];
foreach ($agents as $agent) {
    $donors_result = $db->query("SELECT d.id, d.name,
        COALESCE(d.total_pledged, 0) as total_pledged,
        COALESCE(d.total_paid, 0) as total_paid,
        (COALESCE(d.total_pledged, 0) - COALESCE(d.total_paid, 0)) as balance
        FROM donors d
        WHERE d.agent_id = " . (int)$agent['id'] . "
        ORDER BY d.name");
    
    $donors_by_agent[$agent['id']] = [];
    while ($donor = $donors_result->fetch_assoc()) {
        $donors_by_agent[$agent['id']][] = $donor;
    }
}

// Get unassigned donors
$unassigned_result = $db->query("SELECT d.id, d.name,
    COALESCE(d.total_pledged, 0) as total_pledged,
    COALESCE(d.total_paid, 0) as total_paid,
    (COALESCE(d.total_pledged, 0) - COALESCE(d.total_paid, 0)) as balance
    FROM donors d
    WHERE d.agent_id IS NULL
    ORDER BY d.name");
$unassigned_donors = [];
while ($donor = $unassigned_result->fetch_assoc()) {
    $unassigned_donors[] = $donor;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .bulk-actions-bar {
            position: sticky;
            top: 70px;
            z-index: 1000;
            background: white;
            padding: 15px;
            border-bottom: 2px solid #007bff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: none;
            margin-bottom: 20px;
        }
        .donor-card {
            transition: all 0.2s;
        }
        .donor-card:hover {
            background-color: #f8f9fa;
        }
        .donor-card.selected {
            background-color: #e7f3ff;
        }
        @media (max-width: 768px) {
            .bulk-actions-bar {
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

            <?php if (isset($message)): ?>
                <div class="alert alert-info alert-dismissible fade show">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Bulk Actions Bar -->
            <div class="bulk-actions-bar" id="bulkActionsBar">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <strong><span id="selectedCount">0</span> donor(s) selected</strong>
                        <button type="button" class="btn btn-sm btn-link" onclick="clearSelection()">Clear</button>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <select id="bulkAgentSelect" class="form-select form-select-sm" style="width: auto; min-width: 200px;">
                            <option value="0">Select Agent...</option>
                            <?php foreach ($agents as $agent): ?>
                                <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-sm btn-success" onclick="bulkAssign()">
                            <i class="fas fa-check me-1"></i>Bulk Assign
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="bulkUnassign()">
                            <i class="fas fa-times me-1"></i>Bulk Unassign
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3" id="myTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all" type="button" role="tab">
                        <i class="fas fa-list me-2"></i>All Donors
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="agents-tab" data-bs-toggle="tab" data-bs-target="#agents" type="button" role="tab">
                        <i class="fas fa-users me-2"></i>By Agents
                    </button>
                </li>
            </ul>
            
            <div class="tab-content" id="myTabContent">
                <!-- All Donors Tab -->
                <div class="tab-pane fade show active" id="all" role="tabpanel">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">All Donors</h6>
                            <div>
                                <input type="checkbox" id="selectAllAll" onchange="toggleSelectAll('all')">
                                <label for="selectAllAll" class="ms-1">Select All</label>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <?php
                            try {
                                $result = $db->query("SELECT d.id, d.name, d.agent_id,
                                    COALESCE(d.total_pledged, 0) as total_pledged,
                                    COALESCE(d.total_paid, 0) as total_paid,
                                    (COALESCE(d.total_pledged, 0) - COALESCE(d.total_paid, 0)) as balance,
                                    u.name as agent_name
                                    FROM donors d
                                    LEFT JOIN users u ON d.agent_id = u.id
                                    ORDER BY d.name
                                    LIMIT 100");
                                
                                if ($result && $result->num_rows > 0) {
                                    echo "<div class='table-responsive'>";
                                    echo "<table class='table table-hover mb-0'>";
                                    echo "<thead class='table-light'>";
                                    echo "<tr>";
                                    echo "<th style='width: 40px;'><input type='checkbox' id='selectAllAllHeader' onchange='toggleSelectAll(\"all\")'></th>";
                                    echo "<th>ID</th>";
                                    echo "<th>Name</th>";
                                    echo "<th>Pledge Amount</th>";
                                    echo "<th>Balance</th>";
                                    echo "<th>Assigned To</th>";
                                    echo "<th style='width: 250px;'>Assign</th>";
                                    echo "</tr>";
                                    echo "</thead>";
                                    echo "<tbody>";
                                    while ($row = $result->fetch_assoc()) {
                                        $balance = (float)$row['balance'];
                                        $pledge = (float)$row['total_pledged'];
                                        echo "<tr class='donor-card'>";
                                        echo "<td><input type='checkbox' class='donor-checkbox' name='donor_ids[]' value='" . $row['id'] . "' onchange='updateBulkBar()'></td>";
                                        echo "<td>" . $row['id'] . "</td>";
                                        echo "<td><strong>" . htmlspecialchars($row['name']) . "</strong></td>";
                                        echo "<td>£" . number_format($pledge, 2) . "</td>";
                                        echo "<td><span class='badge bg-" . ($balance > 0 ? 'warning' : 'success') . "'>£" . number_format($balance, 2) . "</span></td>";
                                        echo "<td>" . ($row['agent_name'] ? '<span class="badge bg-primary">' . htmlspecialchars($row['agent_name']) . '</span>' : '<span class="text-muted">Unassigned</span>') . "</td>";
                                        echo "<td>";
                                        echo "<form method='POST' style='display: inline;' class='d-flex gap-1'>";
                                        echo "<input type='hidden' name='donor_id' value='" . $row['id'] . "'>";
                                        echo "<select name='agent_id' class='form-select form-select-sm' style='flex: 1;'>";
                                        echo "<option value='0'>Unassign</option>";
                                        foreach ($agents as $agent) {
                                            $selected = ($row['agent_id'] == $agent['id']) ? 'selected' : '';
                                            echo "<option value='" . $agent['id'] . "' $selected>" . htmlspecialchars($agent['name']) . "</option>";
                                        }
                                        echo "</select>";
                                        echo "<button type='submit' name='assign' class='btn btn-sm btn-primary'>Assign</button>";
                                        echo "</form>";
                                        echo "</td>";
                                        echo "</tr>";
                                    }
                                    echo "</tbody></table>";
                                    echo "</div>";
                                } else {
                                    echo "<div class='text-center py-5'><p class='text-muted'>No donors found</p></div>";
                                }
                            } catch (Exception $e) {
                                echo "<div class='alert alert-danger'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <!-- By Agents Tab -->
                <div class="tab-pane fade" id="agents" role="tabpanel">
                    <div class="accordion" id="agentsAccordion">
                        <?php
                        $accordion_index = 0;
                        foreach ($agents as $agent):
                            $donors = $donors_by_agent[$agent['id']] ?? [];
                            $count = count($donors);
                            $accordion_index++;
                        ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="heading<?php echo $accordion_index; ?>">
                                <button class="accordion-button <?php echo $accordion_index === 1 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $accordion_index; ?>">
                                    <i class="fas fa-user me-2"></i>
                                    <?php echo htmlspecialchars($agent['name']); ?> 
                                    <span class="badge bg-primary ms-2"><?php echo $count; ?> donor<?php echo $count !== 1 ? 's' : ''; ?></span>
                                </button>
                            </h2>
                            <div id="collapse<?php echo $accordion_index; ?>" class="accordion-collapse collapse <?php echo $accordion_index === 1 ? 'show' : ''; ?>" data-bs-parent="#agentsAccordion">
                                <div class="accordion-body">
                                    <?php if ($count > 0): ?>
                                        <div class="mb-2">
                                            <input type="checkbox" class="select-all-agent" data-agent="<?php echo $agent['id']; ?>" onchange="toggleSelectAllAgent(<?php echo $agent['id']; ?>)">
                                            <label class="ms-1">Select All</label>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th style="width: 40px;"></th>
                                                        <th>ID</th>
                                                        <th>Name</th>
                                                        <th>Pledge Amount</th>
                                                        <th>Balance</th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($donors as $donor): ?>
                                                    <tr class="donor-card">
                                                        <td><input type="checkbox" class="donor-checkbox" name="donor_ids[]" value="<?php echo $donor['id']; ?>" onchange="updateBulkBar()"></td>
                                                        <td><?php echo $donor['id']; ?></td>
                                                        <td><strong><?php echo htmlspecialchars($donor['name']); ?></strong></td>
                                                        <td>£<?php echo number_format((float)$donor['total_pledged'], 2); ?></td>
                                                        <td><span class="badge bg-<?php echo $donor['balance'] > 0 ? 'warning' : 'success'; ?>">£<?php echo number_format((float)$donor['balance'], 2); ?></span></td>
                                                        <td>
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="donor_id" value="<?php echo $donor['id']; ?>">
                                                                <input type="hidden" name="agent_id" value="0">
                                                                <button type="submit" name="assign" class="btn btn-sm btn-outline-danger">
                                                                    <i class="fas fa-times me-1"></i>Unassign
                                                                </button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted mb-0">No donors assigned to this agent.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <!-- Unassigned Donors -->
                        <?php if (count($unassigned_donors) > 0): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingUnassigned">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseUnassigned">
                                    <i class="fas fa-user-slash me-2"></i>
                                    Unassigned Donors
                                    <span class="badge bg-secondary ms-2"><?php echo count($unassigned_donors); ?> donor<?php echo count($unassigned_donors) !== 1 ? 's' : ''; ?></span>
                                </button>
                            </h2>
                            <div id="collapseUnassigned" class="accordion-collapse collapse" data-bs-parent="#agentsAccordion">
                                <div class="accordion-body">
                                    <div class="mb-2">
                                        <input type="checkbox" class="select-all-unassigned" onchange="toggleSelectAllUnassigned()">
                                        <label class="ms-1">Select All</label>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 40px;"></th>
                                                    <th>ID</th>
                                                    <th>Name</th>
                                                    <th>Pledge Amount</th>
                                                    <th>Balance</th>
                                                    <th>Assign</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($unassigned_donors as $donor): ?>
                                                <tr class="donor-card">
                                                    <td><input type="checkbox" class="donor-checkbox" name="donor_ids[]" value="<?php echo $donor['id']; ?>" onchange="updateBulkBar()"></td>
                                                    <td><?php echo $donor['id']; ?></td>
                                                    <td><strong><?php echo htmlspecialchars($donor['name']); ?></strong></td>
                                                    <td>£<?php echo number_format((float)$donor['total_pledged'], 2); ?></td>
                                                    <td><span class="badge bg-<?php echo $donor['balance'] > 0 ? 'warning' : 'success'; ?>">£<?php echo number_format((float)$donor['balance'], 2); ?></span></td>
                                                    <td>
                                                        <form method="POST" style="display: inline;" class="d-flex gap-1">
                                                            <input type="hidden" name="donor_id" value="<?php echo $donor['id']; ?>">
                                                            <select name="agent_id" class="form-select form-select-sm" style="flex: 1;">
                                                                <option value="0">Select...</option>
                                                                <?php foreach ($agents as $agent): ?>
                                                                    <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['name']); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <button type="submit" name="assign" class="btn btn-sm btn-primary">
                                                                <i class="fas fa-check me-1"></i>Assign
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
function updateBulkBar() {
    const checkboxes = document.querySelectorAll('.donor-checkbox:checked');
    const count = checkboxes.length;
    const bar = document.getElementById('bulkActionsBar');
    
    if (count > 0) {
        bar.style.display = 'block';
        document.getElementById('selectedCount').textContent = count;
    } else {
        bar.style.display = 'none';
    }
}

function clearSelection() {
    document.querySelectorAll('.donor-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.select-all-agent, .select-all-unassigned, #selectAllAll, #selectAllAllHeader').forEach(cb => cb.checked = false);
    updateBulkBar();
}

function toggleSelectAll(tab) {
    const checkboxes = document.querySelectorAll('#all .donor-checkbox');
    const selectAll = document.getElementById('selectAllAll');
    const selectAllHeader = document.getElementById('selectAllAllHeader');
    const checked = selectAll.checked || selectAllHeader.checked;
    
    checkboxes.forEach(cb => cb.checked = checked);
    selectAll.checked = checked;
    selectAllHeader.checked = checked;
    updateBulkBar();
}

function toggleSelectAllAgent(agentId) {
    const accordion = document.querySelector(`#collapse${agentId}`);
    if (!accordion) return;
    const checkboxes = accordion.querySelectorAll('.donor-checkbox');
    const selectAll = accordion.querySelector('.select-all-agent');
    if (!selectAll) return;
    const checked = selectAll.checked;
    
    checkboxes.forEach(cb => cb.checked = checked);
    updateBulkBar();
}

function toggleSelectAllUnassigned() {
    const accordion = document.getElementById('collapseUnassigned');
    if (!accordion) return;
    const checkboxes = accordion.querySelectorAll('.donor-checkbox');
    const selectAll = accordion.querySelector('.select-all-unassigned');
    if (!selectAll) return;
    const checked = selectAll.checked;
    
    checkboxes.forEach(cb => cb.checked = checked);
    updateBulkBar();
}

function bulkAssign() {
    const checkboxes = document.querySelectorAll('.donor-checkbox:checked');
    const agentId = document.getElementById('bulkAgentSelect').value;
    
    if (checkboxes.length === 0) {
        alert('Please select at least one donor');
        return;
    }
    
    if (agentId === '0') {
        alert('Please select an agent');
        return;
    }
    
    if (!confirm(`Assign ${checkboxes.length} donor(s) to selected agent?`)) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="bulk_action" value="assign">';
    form.innerHTML += '<input type="hidden" name="bulk_agent_id" value="' + agentId + '">';
    
    checkboxes.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'donor_ids[]';
        input.value = cb.value;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
}

function bulkUnassign() {
    const checkboxes = document.querySelectorAll('.donor-checkbox:checked');
    
    if (checkboxes.length === 0) {
        alert('Please select at least one donor');
        return;
    }
    
    if (!confirm(`Unassign ${checkboxes.length} donor(s)?`)) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="bulk_action" value="unassign">';
    
    checkboxes.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'donor_ids[]';
        input.value = cb.value;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
}
</script>
</body>
</html>
