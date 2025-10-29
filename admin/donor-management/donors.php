<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

// Resiliently load settings and check for DB errors
require_once __DIR__ . '/../includes/resilient_db_loader.php';

$page_title = 'Donor List';
$current_user = current_user();
$db = db();

$success_message = '';
$error_message = '';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    // Verify CSRF
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    $action = $_POST['ajax_action'];
    
    try {
        $db->begin_transaction();
        
        if ($action === 'add_donor') {
            // Validate inputs
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $preferred_language = $_POST['preferred_language'] ?? 'en';
            $preferred_payment_method = $_POST['preferred_payment_method'] ?? 'bank_transfer';
            $source = 'admin'; // Always admin since added via admin panel
            
            if (empty($name)) {
                throw new Exception('Donor name is required');
            }
            
            if (empty($phone)) {
                throw new Exception('Phone number is required');
            }
            
            // Check for duplicate
            $check = $db->prepare("SELECT id FROM donors WHERE phone = ? LIMIT 1");
            $check->bind_param('s', $phone);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                throw new Exception('A donor with this phone number already exists');
            }
            
            // Insert donor
            $stmt = $db->prepare("
                INSERT INTO donors (
                    name, phone, preferred_language, 
                    preferred_payment_method, source, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->bind_param('sssss', $name, $phone, 
                $preferred_language, $preferred_payment_method, $source);
            $stmt->execute();
            
            $donor_id = $db->insert_id;
            
            // Audit log
            $user_id = (int)$current_user['id'];
            $after = json_encode(['name' => $name, 'phone' => $phone]);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(?, 'donor', ?, 'create', ?, 'admin')");
            $audit->bind_param('iis', $user_id, $donor_id, $after);
            $audit->execute();
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Donor added successfully']);
            exit;
            
        } elseif ($action === 'update_donor') {
            $donor_id = (int)($_POST['donor_id'] ?? 0);
            
            if ($donor_id <= 0) {
                throw new Exception('Invalid donor ID');
            }
            
            // Get existing donor
            $existing = $db->prepare("SELECT * FROM donors WHERE id = ? FOR UPDATE");
            $existing->bind_param('i', $donor_id);
            $existing->execute();
            $donor = $existing->get_result()->fetch_assoc();
            
            if (!$donor) {
                throw new Exception('Donor not found');
            }
            
            // Validate inputs
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $preferred_language = $_POST['preferred_language'] ?? 'en';
            $preferred_payment_method = $_POST['preferred_payment_method'] ?? 'bank_transfer';
            
            if (empty($name)) {
                throw new Exception('Donor name is required');
            }
            
            if (empty($phone)) {
                throw new Exception('Phone number is required');
            }
            
            // Check for duplicate phone (excluding current donor)
            $check = $db->prepare("SELECT id FROM donors WHERE phone = ? AND id != ? LIMIT 1");
            $check->bind_param('si', $phone, $donor_id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                throw new Exception('Another donor with this phone number already exists');
            }
            
            // Update donor
            $stmt = $db->prepare("
                UPDATE donors SET 
                    name = ?, phone = ?, 
                    preferred_language = ?, preferred_payment_method = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('ssssi', $name, $phone, 
                $preferred_language, $preferred_payment_method, $donor_id);
            $stmt->execute();
            
            // Audit log
            $user_id = (int)$current_user['id'];
            $before = json_encode($donor);
            $after = json_encode(['name' => $name, 'phone' => $phone]);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'donor', ?, 'update', ?, ?, 'admin')");
            $audit->bind_param('iiss', $user_id, $donor_id, $before, $after);
            $audit->execute();
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Donor updated successfully']);
            exit;
            
        } elseif ($action === 'delete_donor') {
            $donor_id = (int)($_POST['donor_id'] ?? 0);
            
            if ($donor_id <= 0) {
                throw new Exception('Invalid donor ID');
            }
            
            // Get existing donor
            $existing = $db->prepare("SELECT * FROM donors WHERE id = ? FOR UPDATE");
            $existing->bind_param('i', $donor_id);
            $existing->execute();
            $donor = $existing->get_result()->fetch_assoc();
            
            if (!$donor) {
                throw new Exception('Donor not found');
            }
            
            // Safety check: Don't delete if has pledges or payments
            $check_pledges = $db->prepare("SELECT COUNT(*) as cnt FROM pledges WHERE donor_phone = ?");
            $check_pledges->bind_param('s', $donor['phone']);
            $check_pledges->execute();
            $pledge_count = $check_pledges->get_result()->fetch_assoc()['cnt'];
            
            $check_payments = $db->prepare("SELECT COUNT(*) as cnt FROM payments WHERE donor_phone = ?");
            $check_payments->bind_param('s', $donor['phone']);
            $check_payments->execute();
            $payment_count = $check_payments->get_result()->fetch_assoc()['cnt'];
            
            if ($pledge_count > 0 || $payment_count > 0) {
                throw new Exception('Cannot delete donor with existing pledges or payments. Please archive instead.');
            }
            
            // Delete donor
            $stmt = $db->prepare("DELETE FROM donors WHERE id = ?");
            $stmt->bind_param('i', $donor_id);
            $stmt->execute();
            
            // Audit log
            $user_id = (int)$current_user['id'];
            $before = json_encode($donor);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, source) VALUES(?, 'donor', ?, 'delete', ?, 'admin')");
            $audit->bind_param('iis', $user_id, $donor_id, $before);
            $audit->execute();
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Donor deleted successfully']);
            exit;
        }
        
    } catch (Exception $e) {
        $db->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Get donors list
$donors = [];
try {
    $donors_result = $db->query("
        SELECT 
            id, name, phone, preferred_language, 
            preferred_payment_method, source, total_pledged, total_paid, 
            balance, payment_status, created_at, updated_at
        FROM donors 
        ORDER BY created_at DESC
    ");
    
    if ($donors_result) {
        $donors = $donors_result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    $error_message = "Error loading donors: " . $e->getMessage();
}

// Calculate stats (using total_pledged to determine donor type)
$total_donors = count($donors);
$pledge_donors = 0;
$immediate_payers = 0;
$donors_with_phone = 0;

foreach ($donors as &$donor) {
    // Determine donor type based on pledges
    if ((float)$donor['total_pledged'] > 0) {
        $pledge_donors++;
        $donor['donor_type'] = 'pledge'; // Add for display
    } else {
        $immediate_payers++;
        $donor['donor_type'] = 'immediate_payment'; // Add for display
    }
    
    if (!empty($donor['phone'])) {
        $donors_with_phone++;
    }
}
unset($donor); // Break reference
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Donor List - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/donor-management.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                <?php include '../includes/db_error_banner.php'; ?>
                
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1 text-primary">
                            <i class="fas fa-list me-2"></i>Donor List
                        </h1>
                        <p class="text-muted mb-0">Manage all donors and their information</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDonorModal">
                            <i class="fas fa-plus me-2"></i>Add Donor
                        </button>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card" style="color: #0a6286;">
                            <div class="stat-icon bg-primary">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?php echo number_format($total_donors); ?></h3>
                                <p class="stat-label">Total Donors</p>
                                <div class="stat-trend text-muted">
                                    <i class="fas fa-database"></i> In system
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card" style="color: #b88a1a;">
                            <div class="stat-icon bg-warning">
                                <i class="fas fa-handshake"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?php echo number_format($pledge_donors); ?></h3>
                                <p class="stat-label">Pledge Donors</p>
                                <div class="stat-trend text-warning">
                                    <i class="fas fa-hourglass-half"></i> Tracking
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card" style="color: #0d7f4d;">
                            <div class="stat-icon bg-success">
                                <i class="fas fa-check-double"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?php echo number_format($immediate_payers); ?></h3>
                                <p class="stat-label">Immediate Payers</p>
                                <div class="stat-trend text-success">
                                    <i class="fas fa-bolt"></i> Direct
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card" style="color: #b91c1c;">
                            <div class="stat-icon bg-danger">
                                <i class="fas fa-phone"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?php echo number_format($donors_with_phone); ?></h3>
                                <p class="stat-label">With Phone</p>
                                <div class="stat-trend text-danger">
                                    <i class="fas fa-mobile-alt"></i> Reachable
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Donors Table -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-table me-2 text-primary"></i>
                                All Donors
                            </h5>
                            <button class="btn btn-sm btn-outline-primary" id="toggleFilter" type="button">
                                <i class="fas fa-filter me-2"></i>Filters
                                <i class="fas fa-chevron-down ms-1" id="filterIcon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Filter Panel -->
                    <div id="filterPanel" class="border-bottom" style="display: none;">
                        <div class="p-3 bg-light">
                            <div class="row g-3">
                                <div class="col-12 col-md-6 col-lg-3">
                                    <label class="form-label small fw-bold">Donor Type</label>
                                    <select class="form-select form-select-sm" id="filter_donor_type">
                                        <option value="">All Types</option>
                                        <option value="pledge">Pledge Donors</option>
                                        <option value="immediate_payment">Immediate Payers</option>
                                    </select>
                                </div>
                                
                                <div class="col-12 col-md-6 col-lg-3">
                                    <label class="form-label small fw-bold">Payment Status</label>
                                    <select class="form-select form-select-sm" id="filter_payment_status">
                                        <option value="">All Statuses</option>
                                        <option value="no_pledge">No Pledge</option>
                                        <option value="not_started">Not Started</option>
                                        <option value="paying">Paying</option>
                                        <option value="overdue">Overdue</option>
                                        <option value="completed">Completed</option>
                                        <option value="defaulted">Defaulted</option>
                                    </select>
                                </div>
                                
                                <div class="col-12 col-md-6 col-lg-2">
                                    <label class="form-label small fw-bold">Language</label>
                                    <select class="form-select form-select-sm" id="filter_language">
                                        <option value="">All Languages</option>
                                        <option value="en">English</option>
                                        <option value="am">Amharic</option>
                                        <option value="ti">Tigrinya</option>
                                    </select>
                                </div>
                                
                                <div class="col-12 col-md-6 col-lg-2">
                                    <label class="form-label small fw-bold">Payment Method</label>
                                    <select class="form-select form-select-sm" id="filter_payment_method">
                                        <option value="">All Methods</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="cash">Cash</option>
                                        <option value="card">Card</option>
                                    </select>
                                </div>
                                
                                <div class="col-12 col-md-6 col-lg-2">
                                    <label class="form-label small fw-bold">Balance</label>
                                    <select class="form-select form-select-sm" id="filter_balance">
                                        <option value="">All</option>
                                        <option value="has_balance">Has Balance (> £0)</option>
                                        <option value="no_balance">No Balance (£0)</option>
                                    </select>
                                </div>
                                
                                <div class="col-12">
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-danger" id="clearFilters">
                                            <i class="fas fa-times me-1"></i>Clear All Filters
                                        </button>
                                        <span class="ms-auto text-muted small align-self-center" id="filterResultCount"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="donorsTable" class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Phone</th>
                                        <th>Status</th>
                                        <th>Pledged</th>
                                        <th>Paid</th>
                                        <th>Balance</th>
                                        <th>Language</th>
                                        <th>Payment Method</th>
                                        <th>Added</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($donors as $donor): ?>
                                    <tr>
                                        <td><?php echo (int)$donor['id']; ?></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($donor['name']); ?></td>
                                        <td>
                                            <?php if ($donor['donor_type'] === 'pledge'): ?>
                                                <span class="badge bg-warning text-dark">Pledge</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Immediate</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($donor['phone'] ?? '-'); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo match($donor['payment_status'] ?? 'no_pledge') {
                                                    'completed' => 'success',
                                                    'paying' => 'primary',
                                                    'overdue' => 'danger',
                                                    'not_started' => 'warning',
                                                    default => 'secondary'
                                                };
                                            ?>">
                                                <?php echo ucwords(str_replace('_', ' ', $donor['payment_status'] ?? 'no_pledge')); ?>
                                            </span>
                                        </td>
                                        <td>£<?php echo number_format((float)$donor['total_pledged'], 2); ?></td>
                                        <td>£<?php echo number_format((float)$donor['total_paid'], 2); ?></td>
                                        <td>£<?php echo number_format((float)$donor['balance'], 2); ?></td>
                                        <td><?php echo strtoupper($donor['preferred_language'] ?? 'EN'); ?></td>
                                        <td><?php echo ucwords(str_replace('_', ' ', $donor['preferred_payment_method'] ?? 'Bank Transfer')); ?></td>
                                        <td><?php echo date('d M Y', strtotime($donor['created_at'])); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary edit-donor" 
                                                    data-donor='<?php echo htmlspecialchars(json_encode($donor), ENT_QUOTES); ?>'>
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger delete-donor" 
                                                    data-id="<?php echo (int)$donor['id']; ?>" 
                                                    data-name="<?php echo htmlspecialchars($donor['name']); ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Add Donor Modal -->
<div class="modal fade" id="addDonorModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus me-2 text-primary"></i>Add New Donor
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addDonorForm">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="ajax_action" value="add_donor">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Donor Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Phone (UK Format) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="phone" placeholder="07XXXXXXXXX" pattern="07[0-9]{9}" required>
                            <small class="text-muted">Format: 07XXXXXXXXX</small>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Preferred Language</label>
                            <select class="form-select" name="preferred_language">
                                <option value="en">English</option>
                                <option value="am">Amharic</option>
                                <option value="ti">Tigrinya</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Preferred Payment Method</label>
                            <select class="form-select" name="preferred_payment_method">
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                            </select>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> Donor type (Pledge vs Immediate) is automatically determined based on whether they make pledges.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveAddDonor">
                    <i class="fas fa-save me-2"></i>Save Donor
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Donor Modal -->
<div class="modal fade" id="editDonorModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-edit me-2 text-primary"></i>Edit Donor
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editDonorForm">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="ajax_action" value="update_donor">
                    <input type="hidden" name="donor_id" id="edit_donor_id">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Donor Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Phone (UK Format) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="phone" id="edit_phone" placeholder="07XXXXXXXXX" pattern="07[0-9]{9}" required>
                            <small class="text-muted">Format: 07XXXXXXXXX</small>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Preferred Language</label>
                            <select class="form-select" name="preferred_language" id="edit_preferred_language">
                                <option value="en">English</option>
                                <option value="am">Amharic</option>
                                <option value="ti">Tigrinya</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Preferred Payment Method</label>
                            <select class="form-select" name="preferred_payment_method" id="edit_preferred_payment_method">
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                            </select>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Type:</strong> <span id="edit_donor_type_display"></span>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveEditDonor">
                    <i class="fas fa-save me-2"></i>Update Donor
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/donor-management.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    const table = $('#donorsTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25,
        language: {
            search: "Search donors:",
            lengthMenu: "Show _MENU_ donors per page"
        }
    });
    
    // Toggle Filter Panel
    $('#toggleFilter').click(function() {
        $('#filterPanel').slideToggle(300);
        const icon = $('#filterIcon');
        if (icon.hasClass('fa-chevron-down')) {
            icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
        } else {
            icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
        }
    });
    
    // Custom filter function
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        const donorType = $('#filter_donor_type').val();
        const paymentStatus = $('#filter_payment_status').val();
        const language = $('#filter_language').val();
        const paymentMethod = $('#filter_payment_method').val();
        const balance = $('#filter_balance').val();
        
        // Get data from table columns
        // Column indices: 0=ID, 1=Name, 2=Type, 3=Phone, 4=Status, 5=Pledged, 6=Paid, 7=Balance, 8=Language, 9=Payment Method, 10=Added, 11=Actions
        const rowDonorType = data[2].toLowerCase(); // Type column
        const rowPaymentStatus = data[4].toLowerCase(); // Status column
        const rowBalance = parseFloat(data[7].replace('£', '').replace(',', '')); // Balance column
        const rowLanguage = data[8].toLowerCase(); // Language column
        const rowPaymentMethod = data[9].toLowerCase(); // Payment Method column
        
        // Apply filters
        if (donorType && !rowDonorType.includes(donorType.toLowerCase())) {
            return false;
        }
        
        if (paymentStatus && !rowPaymentStatus.includes(paymentStatus.toLowerCase().replace('_', ' '))) {
            return false;
        }
        
        if (language && rowLanguage !== language.toLowerCase()) {
            return false;
        }
        
        if (paymentMethod && !rowPaymentMethod.includes(paymentMethod.toLowerCase().replace('_', ' '))) {
            return false;
        }
        
        if (balance === 'has_balance' && rowBalance <= 0) {
            return false;
        }
        
        if (balance === 'no_balance' && rowBalance == 0) {
            return false;
        }
        
        return true;
    });
    
    // Filter change handlers
    $('#filter_donor_type, #filter_payment_status, #filter_language, #filter_payment_method, #filter_balance').on('change', function() {
        table.draw();
        updateFilterCount();
    });
    
    // Clear filters
    $('#clearFilters').click(function() {
        $('#filter_donor_type').val('');
        $('#filter_payment_status').val('');
        $('#filter_language').val('');
        $('#filter_payment_method').val('');
        $('#filter_balance').val('');
        table.draw();
        updateFilterCount();
    });
    
    // Update filter result count
    function updateFilterCount() {
        const info = table.page.info();
        const activeFilters = [];
        
        if ($('#filter_donor_type').val()) activeFilters.push('Type');
        if ($('#filter_payment_status').val()) activeFilters.push('Status');
        if ($('#filter_language').val()) activeFilters.push('Language');
        if ($('#filter_payment_method').val()) activeFilters.push('Payment Method');
        if ($('#filter_balance').val()) activeFilters.push('Balance');
        
        if (activeFilters.length > 0) {
            $('#filterResultCount').html(
                '<i class="fas fa-filter me-1"></i>' +
                'Showing ' + info.recordsDisplay + ' of ' + info.recordsTotal + ' donors ' +
                '(' + activeFilters.length + ' filter' + (activeFilters.length > 1 ? 's' : '') + ' active)'
            );
        } else {
            $('#filterResultCount').html('');
        }
    }
    
    // Initialize count
    updateFilterCount();
    
    // Add Donor
    $('#saveAddDonor').click(function() {
        const formData = $('#addDonorForm').serialize();
        
        $.post('', formData, function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error: ' + response.message);
            }
        }, 'json').fail(function() {
            alert('Server error. Please try again.');
        });
    });
    
    // Edit Donor
    $('.edit-donor').click(function() {
        const donor = JSON.parse($(this).attr('data-donor'));
        
        $('#edit_donor_id').val(donor.id);
        $('#edit_name').val(donor.name);
        $('#edit_phone').val(donor.phone || '');
        $('#edit_preferred_language').val(donor.preferred_language);
        $('#edit_preferred_payment_method').val(donor.preferred_payment_method);
        
        // Display donor type (readonly)
        const donorTypeText = donor.donor_type === 'pledge' 
            ? '<span class="badge bg-warning">Pledge Donor</span>' 
            : '<span class="badge bg-success">Immediate Payer</span>';
        $('#edit_donor_type_display').html(donorTypeText);
        
        $('#editDonorModal').modal('show');
    });
    
    $('#saveEditDonor').click(function() {
        const formData = $('#editDonorForm').serialize();
        
        $.post('', formData, function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error: ' + response.message);
            }
        }, 'json').fail(function() {
            alert('Server error. Please try again.');
        });
    });
    
    // Delete Donor
    $('.delete-donor').click(function() {
        const donorId = $(this).data('id');
        const donorName = $(this).data('name');
        
        if (!confirm('Are you sure you want to delete "' + donorName + '"?\n\nThis action cannot be undone.')) {
            return;
        }
        
        const formData = $('#addDonorForm').serialize() + '&ajax_action=delete_donor&donor_id=' + donorId;
        
        $.post('', formData, function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error: ' + response.message);
            }
        }, 'json').fail(function() {
            alert('Server error. Please try again.');
        });
    });
});

// Fallback for sidebar toggle
if (typeof window.toggleSidebar !== 'function') {
  window.toggleSidebar = function() {
    var body = document.body;
    var sidebar = document.getElementById('sidebar');
    var overlay = document.querySelector('.sidebar-overlay');
    if (window.innerWidth <= 991.98) {
      if (sidebar) sidebar.classList.toggle('active');
      if (overlay) overlay.classList.toggle('active');
      body.style.overflow = (sidebar && sidebar.classList.contains('active')) ? 'hidden' : '';
    } else {
      body.classList.toggle('sidebar-collapsed');
    }
  };
}
</script>
</body>
</html>

