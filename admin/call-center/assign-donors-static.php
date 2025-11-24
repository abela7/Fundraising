<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Assign Donors to Agents</title>
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
                                    <h4 class="mb-0">206</h4>
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
                                    <h4 class="mb-0">0</h4>
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
                                    <h4 class="mb-0">206</h4>
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
                            <input type="text" name="search" class="form-control" placeholder="Name, phone, email..." value="">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Assignment</label>
                            <select name="assignment" class="form-select">
                                <option value="all" selected>All Donors</option>
                                <option value="unassigned">Unassigned</option>
                                <option value="assigned">Assigned</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Agent</label>
                            <select name="agent" class="form-select">
                                <option value="0" selected>All Agents</option>
                                <option value="1">John Admin</option>
                                <option value="2">Jane Registrar</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Church</label>
                            <select name="church" class="form-select">
                                <option value="0" selected>All Churches</option>
                                <option value="1">Liverpool Church</option>
                                <option value="2">Manchester Church</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Status</label>
                            <select name="status" class="form-select">
                                <option value="" selected>All Status</option>
                                <option value="not_started">Not Started</option>
                                <option value="paying">Paying</option>
                                <option value="overdue">Overdue</option>
                                <option value="completed">Completed</option>
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
                            <option value="1">John Admin (Admin)</option>
                            <option value="2">Jane Registrar (Registrar)</option>
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
                        Donors (50)
                    </h6>
                    <div>
                        <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll(this)">
                        <label class="form-check-label ms-1" for="selectAll">Select All</label>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <!-- Hardcoded Donor 1 -->
                        <div class="list-group-item donor-card" data-donor-id="1">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <input type="checkbox" class="form-check-input donor-checkbox" value="1" onchange="updateSelection()">
                                </div>
                                <div class="col-md-3 col-12">
                                    <div class="fw-bold">John Doe</div>
                                    <small class="text-muted">
                                        <i class="fas fa-phone me-1"></i>07123456789
                                    </small>
                                </div>
                                <div class="col-md-2 col-6">
                                    <small class="text-muted d-block">Balance</small>
                                    <span class="badge bg-warning">£500.00</span>
                                </div>
                                <div class="col-md-2 col-6 d-none d-md-block">
                                    <small class="text-muted d-block">Church</small>
                                    <small>Liverpool Church</small>
                                </div>
                                <div class="col-md-3 col-12 mt-2 mt-md-0">
                                    <small class="text-muted d-block">Assigned Agent</small>
                                    <span class="badge bg-secondary agent-badge">Unassigned</span>
                                </div>
                                <div class="col-md-2 col-12 text-md-end mt-2 mt-md-0">
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="quickAssign(1)" title="Quick Assign">
                                            <i class="fas fa-user-plus"></i>
                                        </button>
                                        <a href="../donor-management/view-donor.php?id=1" class="btn btn-outline-secondary btn-sm" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Hardcoded Donor 2 -->
                        <div class="list-group-item donor-card" data-donor-id="2">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <input type="checkbox" class="form-check-input donor-checkbox" value="2" onchange="updateSelection()">
                                </div>
                                <div class="col-md-3 col-12">
                                    <div class="fw-bold">Jane Smith</div>
                                    <small class="text-muted">
                                        <i class="fas fa-phone me-1"></i>07987654321
                                    </small>
                                </div>
                                <div class="col-md-2 col-6">
                                    <small class="text-muted d-block">Balance</small>
                                    <span class="badge bg-warning">£750.50</span>
                                </div>
                                <div class="col-md-2 col-6 d-none d-md-block">
                                    <small class="text-muted d-block">Church</small>
                                    <small>Manchester Church</small>
                                </div>
                                <div class="col-md-3 col-12 mt-2 mt-md-0">
                                    <small class="text-muted d-block">Assigned Agent</small>
                                    <span class="badge bg-secondary agent-badge">Unassigned</span>
                                </div>
                                <div class="col-md-2 col-12 text-md-end mt-2 mt-md-0">
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="quickAssign(2)" title="Quick Assign">
                                            <i class="fas fa-user-plus"></i>
                                        </button>
                                        <a href="../donor-management/view-donor.php?id=2" class="btn btn-outline-secondary btn-sm" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Hardcoded Donor 3 -->
                        <div class="list-group-item donor-card" data-donor-id="3">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <input type="checkbox" class="form-check-input donor-checkbox" value="3" onchange="updateSelection()">
                                </div>
                                <div class="col-md-3 col-12">
                                    <div class="fw-bold">Michael Johnson</div>
                                    <small class="text-muted">
                                        <i class="fas fa-phone me-1"></i>07111222333
                                    </small>
                                </div>
                                <div class="col-md-2 col-6">
                                    <small class="text-muted d-block">Balance</small>
                                    <span class="badge bg-success">£0.00</span>
                                </div>
                                <div class="col-md-2 col-6 d-none d-md-block">
                                    <small class="text-muted d-block">Church</small>
                                    <small>Liverpool Church</small>
                                </div>
                                <div class="col-md-3 col-12 mt-2 mt-md-0">
                                    <small class="text-muted d-block">Assigned Agent</small>
                                    <span class="badge bg-primary agent-badge">
                                        <i class="fas fa-user me-1"></i>John Admin
                                    </span>
                                </div>
                                <div class="col-md-2 col-12 text-md-end mt-2 mt-md-0">
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="quickAssign(3)" title="Quick Assign">
                                            <i class="fas fa-user-plus"></i>
                                        </button>
                                        <a href="../donor-management/view-donor.php?id=3" class="btn btn-outline-secondary btn-sm" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="card-footer">
                    <nav>
                        <ul class="pagination pagination-sm mb-0 justify-content-center">
                            <li class="page-item disabled">
                                <a class="page-link" href="#">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            <li class="page-item active">
                                <a class="page-link" href="#">1</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="#">2</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="#">3</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="#">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <div class="text-center mt-2">
                        <small class="text-muted">
                            Page 1 of 5 (50 total donors)
                        </small>
                    </div>
                </div>
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
                        <option value="1">John Admin (Admin)</option>
                        <option value="2">Jane Registrar (Registrar)</option>
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

<!-- Load Bootstrap JS FIRST -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Define toggleSidebar fallback BEFORE loading admin.js -->
<script>
console.log('[STATIC] JavaScript loading started');

// Fallback for toggleSidebar if admin.js fails
if (typeof toggleSidebar === 'undefined') {
    window.toggleSidebar = function() {
        console.log('[STATIC] toggleSidebar called (fallback)');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        if (sidebar) {
            sidebar.classList.toggle('active');
            if (overlay) overlay.classList.toggle('active');
        }
    };
    console.log('[STATIC] toggleSidebar fallback defined');
}
</script>

<!-- Load admin.js -->
<script src="../assets/admin.js"></script>

<!-- Main JavaScript Functions -->
<script>
console.log('[STATIC] Main script executing');

let selectedDonors = new Set();

function updateSelection() {
    console.log('[STATIC] updateSelection() called');
    selectedDonors.clear();
    document.querySelectorAll('.donor-checkbox:checked').forEach(cb => {
        selectedDonors.add(cb.value);
        cb.closest('.donor-card').classList.add('selected');
    });
    
    document.querySelectorAll('.donor-checkbox:not(:checked)').forEach(cb => {
        cb.closest('.donor-card').classList.remove('selected');
    });
    
    const countEl = document.getElementById('selectedCount');
    const bar = document.getElementById('bulkActionsBar');
    if (countEl) countEl.textContent = selectedDonors.size;
    if (bar) bar.style.display = selectedDonors.size > 0 ? 'block' : 'none';
}

function toggleSelectAll(checkbox) {
    console.log('[STATIC] toggleSelectAll() called');
    document.querySelectorAll('.donor-checkbox').forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateSelection();
}

function clearSelection() {
    console.log('[STATIC] clearSelection() called');
    document.querySelectorAll('.donor-checkbox').forEach(cb => {
        cb.checked = false;
    });
    const selectAll = document.getElementById('selectAll');
    if (selectAll) selectAll.checked = false;
    updateSelection();
}

function assignBulk() {
    console.log('[STATIC] assignBulk() called');
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
    
    alert(`[STATIC MODE] Would assign ${selectedDonors.size} donor(s) to agent ${agentId}`);
    console.log('[STATIC] Bulk assign:', {donors: Array.from(selectedDonors), agent: agentId});
}

function unassignBulk() {
    console.log('[STATIC] unassignBulk() called');
    if (selectedDonors.size === 0) {
        alert('Please select at least one donor');
        return;
    }
    
    if (!confirm(`Remove agent assignment from ${selectedDonors.size} donor(s)?`)) {
        return;
    }
    
    alert(`[STATIC MODE] Would unassign ${selectedDonors.size} donor(s)`);
    console.log('[STATIC] Bulk unassign:', Array.from(selectedDonors));
}

function quickAssign(donorId) {
    console.log('[STATIC] quickAssign() called', donorId);
    const input = document.getElementById('quickAssignDonorId');
    if (input) input.value = donorId;
    
    const modalEl = document.getElementById('quickAssignModal');
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
}

function confirmQuickAssign() {
    console.log('[STATIC] confirmQuickAssign() called');
    const donorId = document.getElementById('quickAssignDonorId').value;
    const agentId = document.getElementById('quickAssignAgentSelect').value;
    
    if (!agentId) {
        alert('Please select an agent');
        return;
    }
    
    alert(`[STATIC MODE] Would assign donor ${donorId} to agent ${agentId}`);
    console.log('[STATIC] Quick assign:', {donor: donorId, agent: agentId});
    
    // Close modal
    const modalEl = document.getElementById('quickAssignModal');
    if (modalEl) {
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
    }
}

// Verify Bootstrap loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('[STATIC] DOMContentLoaded fired');
    
    if (typeof bootstrap !== 'undefined') {
        console.log('[STATIC] ✅ Bootstrap loaded');
    } else {
        console.error('[STATIC] ❌ Bootstrap NOT loaded!');
    }
    
    if (typeof toggleSidebar !== 'undefined') {
        console.log('[STATIC] ✅ toggleSidebar function exists');
    } else {
        console.error('[STATIC] ❌ toggleSidebar function NOT found!');
    }
    
    console.log('[STATIC] ✅ Page fully loaded and ready');
});
</script>
</body>
</html>

