<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_admin();

$db = db();

// Pagination parameters
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = max(5, min(50, (int)($_GET['per_page'] ?? 20))); // Between 5-50 items per page
$offset = ($page - 1) * $per_page;

// Search/filter parameters
$search = trim($_GET['search'] ?? '');
$type_filter = $_GET['type'] ?? 'all'; // all, pledge, payment
$amount_filter = $_GET['amount'] ?? 'all'; // all, small, medium, large

// Build WHERE clause for filters
$where_conditions = ["p.status = 'pending'"];
$bind_params = [];
$bind_types = '';

if ($search !== '') {
    $where_conditions[] = "(p.donor_name LIKE ? OR p.donor_phone LIKE ? OR p.notes LIKE ?)";
    $search_param = "%{$search}%";
    $bind_params[] = $search_param;
    $bind_params[] = $search_param;
    $bind_params[] = $search_param;
    $bind_types .= 'sss';
}

if ($type_filter === 'pledge') {
    $where_conditions[] = "p.type = 'pledge'";
} elseif ($type_filter === 'payment') {
    $where_conditions[] = "p.type = 'paid'";
}

if ($amount_filter === 'small') {
    $where_conditions[] = "p.amount < 100";
} elseif ($amount_filter === 'medium') {
    $where_conditions[] = "p.amount BETWEEN 100 AND 500";
} elseif ($amount_filter === 'large') {
    $where_conditions[] = "p.amount > 500";
}

$where_clause = implode(' AND ', $where_conditions);

// Count total records for pagination
$count_sql = "SELECT COUNT(*) as total FROM pledges p WHERE {$where_clause}";
$count_stmt = $db->prepare($count_sql);
if ($bind_types) {
    $count_stmt->bind_param($bind_types, ...$bind_params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_records / $per_page);

// Main query with pagination and optimized joins
$sql = "
    SELECT 
        p.id, p.amount, p.type, p.notes, p.created_at, p.anonymous,
        p.donor_name, p.donor_phone, p.donor_email,
        u.name as registrar_name,
        dp.label AS package_label, dp.price AS package_price, dp.sqm_meters AS package_sqm
    FROM pledges p
    LEFT JOIN users u ON p.created_by_user_id = u.id
    LEFT JOIN donation_packages dp ON dp.id = p.package_id
    WHERE {$where_clause}
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $db->prepare($sql);
$all_bind_params = array_merge($bind_params, [$per_page, $offset]);
$all_bind_types = $bind_types . 'ii';
if ($all_bind_types) {
    $stmt->bind_param($all_bind_types, ...$all_bind_params);
}
$stmt->execute();
$result = $stmt->get_result();

$pending_pledges = [];
while ($row = $result->fetch_assoc()) {
    $pending_pledges[] = $row;
}

// Format decimal square meters to mixed fraction
function format_sqm_fraction(float $value): string {
    if ($value <= 0) return '0';
    $whole = (int)floor($value);
    $fractionPart = $value - $whole;

    if ($fractionPart > 0) {
        if (abs($fractionPart - 0.25) < 0.01) return ($whole > 0 ? $whole . ' ' : '') . '1/4';
        if (abs($fractionPart - 0.5) < 0.01) return ($whole > 0 ? $whole . ' ' : '') . '1/2';
        if (abs($fractionPart - 0.75) < 0.01) return ($whole > 0 ? $whole . ' ' : '') . '3/4';
    }
    
    return $whole > 0 ? (string)$whole : number_format($value, 2);
}
?>

<!-- Filters and Search -->
<div class="approval-controls mb-3">
    <div class="row">
        <div class="col-md-4">
            <div class="input-group">
                <input type="text" class="form-control" id="searchInput" placeholder="Search name, phone, notes..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                <button class="btn btn-outline-secondary" type="button" onclick="applyFilters()">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </div>
        <div class="col-md-2">
            <select class="form-select" id="typeFilter" onchange="applyFilters()">
                <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                <option value="pledge" <?php echo $type_filter === 'pledge' ? 'selected' : ''; ?>>Pledges</option>
                <option value="payment" <?php echo $type_filter === 'payment' ? 'selected' : ''; ?>>Payments</option>
            </select>
        </div>
        <div class="col-md-2">
            <select class="form-select" id="amountFilter" onchange="applyFilters()">
                <option value="all" <?php echo $amount_filter === 'all' ? 'selected' : ''; ?>>All Amounts</option>
                <option value="small" <?php echo $amount_filter === 'small' ? 'selected' : ''; ?>>< £100</option>
                <option value="medium" <?php echo $amount_filter === 'medium' ? 'selected' : ''; ?>>£100-500</option>
                <option value="large" <?php echo $amount_filter === 'large' ? 'selected' : ''; ?>> £500</option>
            </select>
        </div>
        <div class="col-md-2">
            <select class="form-select" id="perPageSelect" onchange="applyFilters()">
                <option value="10" <?php echo $per_page === 10 ? 'selected' : ''; ?>>10 per page</option>
                <option value="20" <?php echo $per_page === 20 ? 'selected' : ''; ?>>20 per page</option>
                <option value="50" <?php echo $per_page === 50 ? 'selected' : ''; ?>>50 per page</option>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-primary w-100" onclick="refreshApprovals()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
    </div>
</div>

<!-- Results Summary -->
<div class="approval-summary mb-3">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <strong><?php echo number_format($total_records); ?></strong> pending approvals
            <?php if ($search || $type_filter !== 'all' || $amount_filter !== 'all'): ?>
                <span class="text-muted">(filtered)</span>
                <button class="btn btn-sm btn-link p-0" onclick="clearFilters()">Clear filters</button>
            <?php endif; ?>
        </div>
        <div>
            Page <?php echo $page; ?> of <?php echo max(1, $total_pages); ?>
        </div>
    </div>
</div>

<?php if (empty($pending_pledges)): ?>
    <div class="text-center p-5 text-muted">
        <?php if ($search || $type_filter !== 'all' || $amount_filter !== 'all'): ?>
            <i class="fas fa-search fa-3x mb-3"></i>
            <h4>No Results Found</h4>
            <p>Try adjusting your search criteria or filters.</p>
            <button class="btn btn-outline-primary" onclick="clearFilters()">Clear Filters</button>
        <?php else: ?>
            <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
            <h4>All Caught Up!</h4>
            <p>There are no pending approvals.</p>
        <?php endif; ?>
    </div>
<?php else: ?>

<!-- Approval Items -->
<div class="approval-list" id="approvalList">
    <?php foreach ($pending_pledges as $pledge): ?>
        <?php
            $pledge_id = (int)($pledge['id'] ?? 0);
            $pledge_amount = (float)($pledge['amount'] ?? 0);
            $pledge_type = strtolower((string)($pledge['type'] ?? 'pledge'));
            $pledge_notes = (string)($pledge['notes'] ?? '');
            $pledge_created = (string)($pledge['created_at'] ?? '');
            $pledge_sqm = (float)($pledge['sqm_meters'] ?? 0);
            $pledge_anonymous = (int)($pledge['anonymous'] ?? 0);
            $pledge_donor_name = (string)($pledge['donor_name'] ?? '');
            $pledge_donor_phone = (string)($pledge['donor_phone'] ?? '');
            $pledge_donor_email = (string)($pledge['donor_email'] ?? '');
            $pledge_registrar = (string)($pledge['registrar_name'] ?? 'System');

            $isPayment = ($pledge_type === 'payment');
            $isPaid = ($pledge_type === 'paid') || $isPayment;
            $isPledge = ($pledge_type === 'pledge');
            $isAnonPaid = ($isPaid && $pledge_anonymous === 1);
            $isAnonPledge = ($isPledge && $pledge_anonymous === 1);
            $showAnonChip = $isAnonPaid || $isAnonPledge;
            $displayName = $isAnonPaid ? 'Anonymous' : ($pledge_donor_name ?: 'N/A');
            $displayPhone = $isAnonPaid ? '' : ($pledge_donor_phone ?: '');

            // Compute meters
            $meters = 0.0;
            if ($isPledge) {
                if (isset($pledge['package_sqm'])) {
                    $meters = (float)$pledge['package_sqm'];
                }
                $packagePrice = isset($pledge['package_price']) ? (float)$pledge['package_price'] : 0.0;
                if ($meters <= 0 && $pledge_amount > 0 && $packagePrice > 0.0) {
                    $meters = (float)$pledge_amount / $packagePrice;
                }
            }
        ?>
        <div class="approval-item" id="pledge-<?php echo $pledge_id; ?>"
             role="button" tabindex="0"
             data-pledge-id="<?php echo $pledge_id; ?>"
             data-type="<?php echo htmlspecialchars($pledge_type, ENT_QUOTES); ?>"
             data-amount="<?php echo $pledge_amount; ?>"
             data-anonymous="<?php echo $pledge_anonymous; ?>"
             data-donor-name="<?php echo htmlspecialchars($pledge_donor_name, ENT_QUOTES); ?>"
             data-donor-phone="<?php echo htmlspecialchars($pledge_donor_phone, ENT_QUOTES); ?>"
             data-donor-email="<?php echo htmlspecialchars($pledge_donor_email ?? '', ENT_QUOTES); ?>"
             data-notes="<?php echo htmlspecialchars($pledge_notes ?? '', ENT_QUOTES); ?>"
             data-package-label="<?php echo htmlspecialchars((string)($pledge['package_label'] ?? ''), ENT_QUOTES); ?>"
             data-package-sqm="<?php echo isset($pledge['package_sqm']) ? (float)$pledge['package_sqm'] : 0; ?>"
             data-package-price="<?php echo isset($pledge['package_price']) ? (float)$pledge['package_price'] : 0; ?>"
             data-method="<?php echo htmlspecialchars((string)($pledge['method'] ?? ''), ENT_QUOTES); ?>"
             data-sqm-meters="<?php echo $meters; ?>"
             data-created-at="<?php echo htmlspecialchars($pledge_created, ENT_QUOTES); ?>"
             data-registrar="<?php echo htmlspecialchars($pledge_registrar, ENT_QUOTES); ?>"
        >
            <div class="approval-content">
                <div class="amount-section">
                    <div class="amount">£<?php echo number_format($pledge_amount, 0); ?></div>
                    <?php
                        $badgeClass = 'secondary';
                        if ($isPayment || $pledge_type === 'paid') { $badgeClass = 'success'; }
                        elseif ($isPledge) { $badgeClass = 'warning'; }
                        $label = $isPayment ? 'Payment' : ($isPledge ? 'Pledge' : ucfirst($pledge_type));
                    ?>
                    <div class="type-badge">
                        <span class="badge bg-<?php echo $badgeClass; ?>"><?php echo $label; ?></span>
                    </div>
                </div>
                
                <div class="donor-section">
                    <div class="donor-name">
                        <?php echo htmlspecialchars($displayName); ?>
                        <?php if ($showAnonChip): ?>
                            <span class="anon-chip ms-2"><i class="fas fa-user-secret"></i> Anonymous</span>
                        <?php endif; ?>
                    </div>
                    <div class="donor-phone">
                        <?php echo htmlspecialchars($displayPhone); ?>
                    </div>
                </div>
                
                <div class="details-section">
                    <?php if ($isPledge): ?>
                      <?php if (!empty($pledge['package_label'])): ?>
                        <div class="sqm"><?php echo htmlspecialchars($pledge['package_label']); ?></div>
                      <?php else: ?>
                        <div class="sqm"><?php echo htmlspecialchars(format_sqm_fraction($meters)); ?> m²</div>
                      <?php endif; ?>
                    <?php else: ?>
                      <div class="sqm">
                        <?php if (!empty($pledge['package_label'])): ?>
                          <?php echo htmlspecialchars($pledge['package_label']); ?>
                        <?php else: ?>
                          Method: <?php echo htmlspecialchars(ucfirst((string)($pledge['method'] ?? ''))); ?>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                    <div class="time"><?php echo $pledge_created ? date('H:i', strtotime($pledge_created)) : '--:--'; ?></div>
                    <div class="registrar">
                        <?php if (empty(trim($pledge_registrar))): ?>
                            <span class="badge bg-info" style="font-size: 0.7rem;">Self Pledged</span>
                        <?php else: ?>
                            <?php echo htmlspecialchars(substr($pledge_registrar, 0, 15)); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="approval-actions">
                <button type="button" class="btn btn-edit" data-bs-toggle="modal" data-bs-target="#editModal" 
                        onclick="openEditModal(<?php echo $pledge_id; ?>, '<?php echo htmlspecialchars($pledge_donor_name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($pledge_donor_phone, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($pledge_donor_email ?? '', ENT_QUOTES); ?>', <?php echo $pledge_amount; ?>, <?php echo $pledge_sqm; ?>, '<?php echo htmlspecialchars($pledge_notes ?? '', ENT_QUOTES); ?>', '<?php echo $isPledge ? 'pledge' : 'payment'; ?>')">
                    <i class="fas fa-edit"></i>
                </button>
                <button type="button" class="btn btn-reject" onclick="rejectPledge(<?php echo $pledge_id; ?>, '<?php echo $isPledge ? 'pledge' : 'payment'; ?>')">
                    <i class="fas fa-times"></i>
                </button>
                <button type="button" class="btn btn-approve" onclick="approvePledge(<?php echo $pledge_id; ?>, '<?php echo $isPledge ? 'pledge' : 'payment'; ?>')">
                    <i class="fas fa-check"></i>
                </button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<nav aria-label="Approval pagination" class="mt-4">
    <ul class="pagination justify-content-center">
        <!-- Previous button -->
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <button class="page-link" onclick="loadPage(<?php echo $page - 1; ?>)" <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                <i class="fas fa-chevron-left"></i> Previous
            </button>
        </li>
        
        <?php
        // Calculate page range to show
        $start_page = max(1, $page - 2);
        $end_page = min($total_pages, $page + 2);
        
        // Show first page if not in range
        if ($start_page > 1): ?>
            <li class="page-item">
                <button class="page-link" onclick="loadPage(1)">1</button>
            </li>
            <?php if ($start_page > 2): ?>
                <li class="page-item disabled">
                    <span class="page-link">...</span>
                </li>
            <?php endif;
        endif;
        
        // Show page range
        for ($i = $start_page; $i <= $end_page; $i++): ?>
            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                <button class="page-link" onclick="loadPage(<?php echo $i; ?>)"><?php echo $i; ?></button>
            </li>
        <?php endfor;
        
        // Show last page if not in range
        if ($end_page < $total_pages): ?>
            <?php if ($end_page < $total_pages - 1): ?>
                <li class="page-item disabled">
                    <span class="page-link">...</span>
                </li>
            <?php endif; ?>
            <li class="page-item">
                <button class="page-link" onclick="loadPage(<?php echo $total_pages; ?>)"><?php echo $total_pages; ?></button>
            </li>
        <?php endif; ?>
        
        <!-- Next button -->
        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
            <button class="page-link" onclick="loadPage(<?php echo $page + 1; ?>)" <?php echo $page >= $total_pages ? 'disabled' : ''; ?>>
                Next <i class="fas fa-chevron-right"></i>
            </button>
        </li>
    </ul>
</nav>
<?php endif; ?>

<?php endif; ?>

<script>
// AJAX functionality for approvals
function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const type = document.getElementById('typeFilter').value;
    const amount = document.getElementById('amountFilter').value;
    const perPage = document.getElementById('perPageSelect').value;
    
    const params = new URLSearchParams({
        search: search,
        type: type,
        amount: amount,
        per_page: perPage,
        page: 1 // Reset to page 1 when filtering
    });
    
    loadApprovals(params.toString());
}

function clearFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('typeFilter').value = 'all';
    document.getElementById('amountFilter').value = 'all';
    document.getElementById('perPageSelect').value = '20';
    loadApprovals('');
}

function loadPage(page) {
    const search = document.getElementById('searchInput').value;
    const type = document.getElementById('typeFilter').value;
    const amount = document.getElementById('amountFilter').value;
    const perPage = document.getElementById('perPageSelect').value;
    
    const params = new URLSearchParams({
        search: search,
        type: type,
        amount: amount,
        per_page: perPage,
        page: page
    });
    
    loadApprovals(params.toString());
}

function loadApprovals(queryString = '') {
    const container = document.querySelector('.card-body');
    
    // Show loading state
    const loading = document.createElement('div');
    loading.className = 'text-center p-4';
    loading.innerHTML = '<i class="fas fa-spinner fa-spin fa-2x"></i><p class="mt-2">Loading...</p>';
    container.appendChild(loading);
    
    fetch(`partial_list_improved.php?${queryString}`)
        .then(response => response.text())
        .then(html => {
            container.innerHTML = html;
        })
        .catch(error => {
            console.error('Error loading approvals:', error);
            container.innerHTML = '<div class="alert alert-danger">Error loading approvals. Please refresh the page.</div>';
        });
}

function refreshApprovals() {
    const currentUrl = new URL(window.location.href);
    const params = currentUrl.searchParams;
    
    // Preserve current filters when refreshing
    const queryString = params.toString();
    loadApprovals(queryString);
}

// AJAX approval functions
function approvePledge(id, type) {
    if (!confirm('Are you sure you want to approve this ' + type + '?')) return;
    
    const formData = new FormData();
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    formData.append('action', type === 'pledge' ? 'approve' : 'approve_payment');
    if (type === 'pledge') {
        formData.append('pledge_id', id);
    } else {
        formData.append('payment_id', id);
    }
    
    fetch('debug_approve.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove the approved item from the list
            const item = document.getElementById('pledge-' + id);
            if (item) {
                item.style.transition = 'opacity 0.3s ease';
                item.style.opacity = '0';
                setTimeout(() => item.remove(), 300);
            }
            
            // Show success message
            showToast('Success', data.message || 'Approved successfully', 'success');
            
            // Refresh the list to update pagination
            setTimeout(refreshApprovals, 500);
        } else {
            showToast('Error', data.message || 'Approval failed', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error', 'Network error occurred', 'error');
    });
}

function rejectPledge(id, type) {
    if (!confirm('Are you sure you want to reject this ' + type + '?')) return;
    
    const formData = new FormData();
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    formData.append('action', type === 'pledge' ? 'reject' : 'reject_payment');
    if (type === 'pledge') {
        formData.append('pledge_id', id);
    } else {
        formData.append('payment_id', id);
    }
    
    fetch('debug_approve.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove the rejected item from the list
            const item = document.getElementById('pledge-' + id);
            if (item) {
                item.style.transition = 'opacity 0.3s ease';
                item.style.opacity = '0';
                setTimeout(() => item.remove(), 300);
            }
            
            showToast('Success', data.message || 'Rejected successfully', 'success');
            
            // Refresh the list to update pagination
            setTimeout(refreshApprovals, 500);
        } else {
            showToast('Error', data.message || 'Rejection failed', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error', 'Network error occurred', 'error');
    });
}

function showToast(title, message, type = 'info') {
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show position-fixed`;
    toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    toast.innerHTML = `
        <strong>${title}:</strong> ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(toast);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (toast.parentNode) {
            toast.remove();
        }
    }, 5000);
}
</script>
