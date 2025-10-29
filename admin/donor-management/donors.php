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
            // Validate basic inputs
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $preferred_language = $_POST['preferred_language'] ?? 'en';
            $preferred_payment_method = $_POST['preferred_payment_method'] ?? 'bank_transfer';
            $sms_opt_in = isset($_POST['sms_opt_in']) ? (int)$_POST['sms_opt_in'] : 1;
            $admin_notes = trim($_POST['admin_notes'] ?? '');
            $donation_type = $_POST['donation_type'] ?? 'pledge';
            $source = 'admin';
            
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
            
            // Prepare donor data based on donation type
            $total_pledged = 0;
            $total_paid = 0;
            $balance = 0;
            $payment_status = 'no_pledge';
            $has_active_plan = 0;
            $plan_monthly_amount = null;
            $plan_duration_months = null;
            $preferred_payment_day = isset($_POST['preferred_payment_day']) ? (int)$_POST['preferred_payment_day'] : 1;
            
            if ($donation_type === 'pledge') {
                $pledge_amount = isset($_POST['pledge_amount']) ? (float)$_POST['pledge_amount'] : 0;
                if ($pledge_amount <= 0) {
                    throw new Exception('Pledge amount must be greater than 0');
                }
                $total_pledged = $pledge_amount;
                $balance = $pledge_amount;
                $payment_status = 'not_started';
                
                // Payment plan
                if (isset($_POST['create_payment_plan']) && $_POST['create_payment_plan']) {
                    $has_active_plan = 1;
                    $plan_monthly_amount = isset($_POST['plan_monthly_amount']) ? (float)$_POST['plan_monthly_amount'] : 0;
                    $plan_duration_months = isset($_POST['plan_duration_months']) ? (int)$_POST['plan_duration_months'] : 0;
                }
            } elseif ($donation_type === 'immediate') {
                $payment_amount = isset($_POST['payment_amount']) ? (float)$_POST['payment_amount'] : 0;
                if ($payment_amount <= 0) {
                    throw new Exception('Payment amount must be greater than 0');
                }
                $total_paid = $payment_amount;
                $payment_status = 'completed';
            }
            
            // Insert donor
            $stmt = $db->prepare("
                INSERT INTO donors (
                    name, phone, preferred_language, preferred_payment_method, 
                    sms_opt_in, admin_notes, source, 
                    total_pledged, total_paid, balance, payment_status,
                    has_active_plan, plan_monthly_amount, plan_duration_months, 
                    preferred_payment_day, registered_by_user_id,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->bind_param('ssssissdddsidiii', 
                $name, $phone, $preferred_language, $preferred_payment_method,
                $sms_opt_in, $admin_notes, $source,
                $total_pledged, $total_paid, $balance, $payment_status,
                $has_active_plan, $plan_monthly_amount, $plan_duration_months,
                $preferred_payment_day, $current_user['id']
            );
            $stmt->execute();
            
            $donor_id = $db->insert_id;
            
            // Create pledge record if pledge donation
            if ($donation_type === 'pledge') {
                $pledge_amount = (float)$_POST['pledge_amount'];
                $pledge_date = $_POST['pledge_date'] ?? date('Y-m-d');
                $pledge_notes = trim($_POST['pledge_notes'] ?? '');
                
                $pledge_stmt = $db->prepare("
                    INSERT INTO pledges (
                        donor_phone, donor_name, amount, status, 
                        approved_by_user_id, pledge_date, notes, created_at
                    ) VALUES (?, ?, ?, 'approved', ?, ?, ?, NOW())
                ");
                $pledge_stmt->bind_param('ssdiss', $phone, $name, $pledge_amount, 
                    $current_user['id'], $pledge_date, $pledge_notes);
                $pledge_stmt->execute();
                
                $pledge_id = $db->insert_id;
                
                // Update donor's last_pledge_id and pledge_count
                $update_donor = $db->prepare("UPDATE donors SET last_pledge_id = ?, pledge_count = 1 WHERE id = ?");
                $update_donor->bind_param('ii', $pledge_id, $donor_id);
                $update_donor->execute();
                
                // Audit pledge creation
                $audit_pledge = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(?, 'pledge', ?, 'create', ?, 'admin')");
                $pledge_data = json_encode(['amount' => $pledge_amount, 'donor' => $name, 'phone' => $phone]);
                $audit_pledge->bind_param('iis', $current_user['id'], $pledge_id, $pledge_data);
                $audit_pledge->execute();
            }
            
            // Create payment record if immediate payment
            if ($donation_type === 'immediate') {
                $payment_amount = (float)$_POST['payment_amount'];
                $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
                $payment_method_used = $_POST['payment_method_used'] ?? 'bank_transfer';
                $transaction_ref = trim($_POST['transaction_ref'] ?? '');
                $payment_notes = trim($_POST['payment_notes'] ?? '');
                
                $payment_stmt = $db->prepare("
                    INSERT INTO payments (
                        donor_phone, donor_name, amount, payment_method, 
                        transaction_ref, status, approved_by_user_id, 
                        payment_date, notes, created_at
                    ) VALUES (?, ?, ?, ?, ?, 'approved', ?, ?, ?, NOW())
                ");
                $payment_stmt->bind_param('ssdsisis', $phone, $name, $payment_amount, 
                    $payment_method_used, $transaction_ref, $current_user['id'], 
                    $payment_date, $payment_notes);
                $payment_stmt->execute();
                
                $payment_id = $db->insert_id;
                
                // Update donor's payment_count and last_payment_date
                $update_donor = $db->prepare("UPDATE donors SET payment_count = 1, last_payment_date = ? WHERE id = ?");
                $update_donor->bind_param('si', $payment_date, $donor_id);
                $update_donor->execute();
                
                // Audit payment creation
                $audit_payment = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(?, 'payment', ?, 'create', ?, 'admin')");
                $payment_data = json_encode(['amount' => $payment_amount, 'donor' => $name, 'phone' => $phone, 'method' => $payment_method_used]);
                $audit_payment->bind_param('iis', $current_user['id'], $payment_id, $payment_data);
                $audit_payment->execute();
            }
            
            // Audit donor creation
            $user_id = (int)$current_user['id'];
            $donor_data = json_encode([
                'name' => $name, 
                'phone' => $phone, 
                'type' => $donation_type,
                'total_pledged' => $total_pledged,
                'total_paid' => $total_paid
            ]);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(?, 'donor', ?, 'create', ?, 'admin')");
            $audit->bind_param('iis', $user_id, $donor_id, $donor_data);
            $audit->execute();
            
            $db->commit();
            
            $message = $donation_type === 'pledge' 
                ? 'Donor and pledge record created successfully' 
                : 'Donor and payment record created successfully';
            
            echo json_encode(['success' => true, 'message' => $message]);
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
                        <a href="add-donor.php" class="btn btn-primary btn-lg">
                            <i class="fas fa-plus me-2"></i>Add New Donor
                        </a>
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
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h5 class="mb-0">
                                <i class="fas fa-table me-2 text-primary"></i>
                                All Donors
                            </h5>
                            <button class="btn btn-sm btn-outline-primary" id="toggleFilter" type="button">
                                <i class="fas fa-filter me-1"></i>Filters
                                <i class="fas fa-chevron-down ms-1" id="filterIcon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Filter Panel -->
                    <div id="filterPanel" class="border-bottom" style="display: none;">
                        <div class="p-3 bg-light">
                            <div class="row g-2">
                                <div class="col-12 col-sm-6 col-lg-3">
                                    <label class="form-label small fw-bold mb-1">Donor Type</label>
                                    <select class="form-select form-select-sm" id="filter_donor_type">
                                        <option value="">All Types</option>
                                        <option value="pledge">Pledge Donors</option>
                                        <option value="immediate">Immediate Payers</option>
                                    </select>
                                </div>
                                
                                <div class="col-12 col-sm-6 col-lg-3">
                                    <label class="form-label small fw-bold mb-1">Payment Status</label>
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
                                
                                <div class="col-12 col-sm-6 col-lg-2">
                                    <label class="form-label small fw-bold mb-1">Language</label>
                                    <select class="form-select form-select-sm" id="filter_language">
                                        <option value="">All Languages</option>
                                        <option value="en">English</option>
                                        <option value="am">Amharic</option>
                                        <option value="ti">Tigrinya</option>
                                    </select>
                                </div>
                                
                                <div class="col-12 col-sm-6 col-lg-2">
                                    <label class="form-label small fw-bold mb-1">Payment Method</label>
                                    <select class="form-select form-select-sm" id="filter_payment_method">
                                        <option value="">All Methods</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="cash">Cash</option>
                                        <option value="card">Card</option>
                                    </select>
                                </div>
                                
                                <div class="col-12 col-sm-6 col-lg-2">
                                    <label class="form-label small fw-bold mb-1">Balance</label>
                                    <select class="form-select form-select-sm" id="filter_balance">
                                        <option value="">All</option>
                                        <option value="has_balance">Has Balance</option>
                                        <option value="no_balance">No Balance</option>
                                    </select>
                                </div>
                                
                                <div class="col-12 mt-2">
                                    <div class="d-flex flex-wrap gap-2 align-items-center">
                                        <button class="btn btn-sm btn-danger" id="clearFilters">
                                            <i class="fas fa-times me-1"></i>Clear
                                        </button>
                                        <span class="text-muted small" id="filterResultCount"></span>
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
                                        <th>Payment Method</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($donors as $donor): ?>
                                    <tr class="donor-row" style="cursor: pointer;" data-donor='<?php echo htmlspecialchars(json_encode($donor), ENT_QUOTES); ?>' title="Click to view details">
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
                                        <td><?php echo ucwords(str_replace('_', ' ', $donor['preferred_payment_method'] ?? 'Bank Transfer')); ?></td>
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

<!-- Add Donor is now a separate page: add-donor.php -->

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

<!-- Donor Detail Modal -->
<div class="modal fade" id="donorDetailModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-user-circle me-2"></i>Donor Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4">
                    <!-- Left Column: Basic Info -->
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-id-card me-2 text-primary"></i>Basic Information</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <td class="text-muted" style="width: 40%;"><i class="fas fa-hashtag me-2"></i>Donor ID</td>
                                        <td class="fw-bold" id="detail_id">-</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><i class="fas fa-user me-2"></i>Full Name</td>
                                        <td class="fw-bold" id="detail_name">-</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><i class="fas fa-phone me-2"></i>Phone</td>
                                        <td id="detail_phone">-</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><i class="fas fa-tag me-2"></i>Donor Type</td>
                                        <td id="detail_type">-</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><i class="fas fa-language me-2"></i>Preferred Language</td>
                                        <td id="detail_language">-</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><i class="fas fa-credit-card me-2"></i>Payment Method</td>
                                        <td id="detail_payment_method">-</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><i class="fas fa-source me-2"></i>Registration Source</td>
                                        <td id="detail_source">-</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column: Financial Info -->
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-pound-sign me-2 text-success"></i>Financial Summary</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <td class="text-muted" style="width: 40%;"><i class="fas fa-handshake me-2"></i>Total Pledged</td>
                                        <td class="fw-bold text-warning" id="detail_pledged">£0.00</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><i class="fas fa-check-circle me-2"></i>Total Paid</td>
                                        <td class="fw-bold text-success" id="detail_paid">£0.00</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><i class="fas fa-balance-scale me-2"></i>Balance</td>
                                        <td class="fw-bold text-danger" id="detail_balance">£0.00</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><i class="fas fa-flag me-2"></i>Payment Status</td>
                                        <td id="detail_status">-</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><i class="fas fa-trophy me-2"></i>Achievement Badge</td>
                                        <td id="detail_badge">-</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><i class="fas fa-file-invoice me-2"></i>Total Pledges</td>
                                        <td id="detail_pledge_count">0</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted"><i class="fas fa-receipt me-2"></i>Total Payments</td>
                                        <td id="detail_payment_count">0</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Plan Info (if applicable) -->
                    <div class="col-12" id="payment_plan_section" style="display: none;">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-calendar-alt me-2 text-info"></i>Payment Plan</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <small class="text-muted d-block">Monthly Amount</small>
                                        <strong id="detail_plan_amount">-</strong>
                                    </div>
                                    <div class="col-md-3">
                                        <small class="text-muted d-block">Duration</small>
                                        <strong id="detail_plan_duration">-</strong>
                                    </div>
                                    <div class="col-md-3">
                                        <small class="text-muted d-block">Start Date</small>
                                        <strong id="detail_plan_start">-</strong>
                                    </div>
                                    <div class="col-md-3">
                                        <small class="text-muted d-block">Next Due</small>
                                        <strong id="detail_plan_next">-</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- System & Audit Info -->
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-info-circle me-2 text-secondary"></i>System Information</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <small class="text-muted d-block"><i class="fas fa-user-plus me-1"></i>Registered By</small>
                                        <strong id="detail_registered_by">System</strong>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted d-block"><i class="fas fa-calendar-plus me-1"></i>Created At</small>
                                        <strong id="detail_created_at">-</strong>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted d-block"><i class="fas fa-calendar-check me-1"></i>Last Updated</small>
                                        <strong id="detail_updated_at">-</strong>
                                    </div>
                                </div>
                                <div class="row mt-3" id="last_activity_section">
                                    <div class="col-md-4">
                                        <small class="text-muted d-block"><i class="fas fa-money-bill-wave me-1"></i>Last Payment</small>
                                        <strong id="detail_last_payment">Never</strong>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted d-block"><i class="fas fa-sms me-1"></i>Last SMS Sent</small>
                                        <strong id="detail_last_sms">Never</strong>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted d-block"><i class="fas fa-sign-in-alt me-1"></i>Portal Logins</small>
                                        <strong id="detail_login_count">0</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Admin Notes (if any) -->
                    <div class="col-12" id="admin_notes_section" style="display: none;">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-sticky-note me-2 text-warning"></i>Admin Notes</h6>
                            </div>
                            <div class="card-body">
                                <p id="detail_admin_notes" class="mb-0 text-muted">-</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Close
                </button>
                <button type="button" class="btn btn-primary" id="btnEditFromDetail">
                    <i class="fas fa-edit me-2"></i>Edit Donor
                </button>
                <button type="button" class="btn btn-danger" id="btnDeleteFromDetail">
                    <i class="fas fa-trash me-2"></i>Delete Donor
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
        lengthMenu: [[25, 50, 100, 250, 500, -1], [25, 50, 100, 250, 500, "All"]],
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
        // Column indices: 0=ID, 1=Name, 2=Type, 3=Phone, 4=Status, 5=Pledged, 6=Paid, 7=Balance, 8=Payment Method, 9=Actions
        const rowDonorType = data[2].toLowerCase(); // Type column
        const rowPaymentStatus = data[4].toLowerCase(); // Status column
        const rowBalance = parseFloat(data[7].replace('£', '').replace(',', '')); // Balance column
        const rowPaymentMethod = data[8].toLowerCase(); // Payment Method column
        
        // Apply filters
        if (donorType && !rowDonorType.includes(donorType.toLowerCase())) {
            return false;
        }
        
        if (paymentStatus && !rowPaymentStatus.includes(paymentStatus.toLowerCase().replace('_', ' '))) {
            return false;
        }
        
        if (language) {
            // Language filter - check from donor data on row itself
            const row = table.row(dataIndex).node();
            const donorData = $(row).attr('data-donor');
            if (donorData) {
                const donor = JSON.parse(donorData);
                if (donor.preferred_language && donor.preferred_language.toLowerCase() !== language.toLowerCase()) {
                    return false;
                }
            }
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
    
    // Form toggles moved to add-donor.php
    
    // Click on table row to view details
    let currentDonorData = null;
    
    $(document).on('click', '.donor-row', function() {
        const donor = JSON.parse($(this).attr('data-donor'));
        currentDonorData = donor;
        
        // Populate basic info
        $('#detail_id').text(donor.id);
        $('#detail_name').text(donor.name);
        $('#detail_phone').text(donor.phone || '-');
        
        // Donor type badge
        const typeHtml = donor.donor_type === 'pledge' 
            ? '<span class="badge bg-warning text-dark">Pledge Donor</span>' 
            : '<span class="badge bg-success">Immediate Payer</span>';
        $('#detail_type').html(typeHtml);
        
        $('#detail_language').text((donor.preferred_language || 'en').toUpperCase());
        $('#detail_payment_method').text((donor.preferred_payment_method || 'bank_transfer').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()));
        $('#detail_source').text((donor.source || 'public_form').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()));
        
        // Financial info
        $('#detail_pledged').text('£' + parseFloat(donor.total_pledged || 0).toFixed(2));
        $('#detail_paid').text('£' + parseFloat(donor.total_paid || 0).toFixed(2));
        $('#detail_balance').text('£' + parseFloat(donor.balance || 0).toFixed(2));
        
        // Payment status badge
        const statusMap = {
            'completed': 'success',
            'paying': 'primary',
            'overdue': 'danger',
            'not_started': 'warning'
        };
        const statusColor = statusMap[donor.payment_status] || 'secondary';
        const statusText = (donor.payment_status || 'no_pledge').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        $('#detail_status').html('<span class="badge bg-' + statusColor + '">' + statusText + '</span>');
        
        // Achievement badge
        const badgeText = (donor.achievement_badge || 'pending').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        $('#detail_badge').html('<span class="badge bg-info">' + badgeText + '</span>');
        
        $('#detail_pledge_count').text(donor.pledge_count || 0);
        $('#detail_payment_count').text(donor.payment_count || 0);
        
        // Payment plan (show only if has active plan)
        if (donor.has_active_plan == 1) {
            $('#detail_plan_amount').text('£' + parseFloat(donor.plan_monthly_amount || 0).toFixed(2));
            $('#detail_plan_duration').text((donor.plan_duration_months || 0) + ' months');
            $('#detail_plan_start').text(donor.plan_start_date || '-');
            $('#detail_plan_next').text(donor.plan_next_due_date || '-');
            $('#payment_plan_section').show();
        } else {
            $('#payment_plan_section').hide();
        }
        
        // System info
        $('#detail_registered_by').text(donor.registered_by_user_id ? 'User #' + donor.registered_by_user_id : 'System');
        $('#detail_created_at').text(donor.created_at ? new Date(donor.created_at).toLocaleString() : '-');
        $('#detail_updated_at').text(donor.updated_at ? new Date(donor.updated_at).toLocaleString() : '-');
        
        // Last activity
        $('#detail_last_payment').text(donor.last_payment_date ? new Date(donor.last_payment_date).toLocaleString() : 'Never');
        $('#detail_last_sms').text(donor.last_sms_sent_at ? new Date(donor.last_sms_sent_at).toLocaleString() : 'Never');
        $('#detail_login_count').text(donor.login_count || 0);
        
        // Admin notes
        if (donor.admin_notes && donor.admin_notes.trim() !== '') {
            $('#detail_admin_notes').text(donor.admin_notes);
            $('#admin_notes_section').show();
        } else {
            $('#admin_notes_section').hide();
        }
        
        // Show modal
        $('#donorDetailModal').modal('show');
    });
    
    // Edit button in detail modal
    $('#btnEditFromDetail').click(function() {
        if (currentDonorData) {
            $('#donorDetailModal').modal('hide');
            
            // Populate edit modal
            $('#edit_donor_id').val(currentDonorData.id);
            $('#edit_name').val(currentDonorData.name);
            $('#edit_phone').val(currentDonorData.phone || '');
            $('#edit_preferred_language').val(currentDonorData.preferred_language || 'en');
            $('#edit_preferred_payment_method').val(currentDonorData.preferred_payment_method || 'bank_transfer');
            
            const donorTypeText = currentDonorData.donor_type === 'pledge' 
                ? '<span class="badge bg-warning">Pledge Donor</span>' 
                : '<span class="badge bg-success">Immediate Payer</span>';
            $('#edit_donor_type_display').html(donorTypeText);
            
            $('#editDonorModal').modal('show');
        }
    });
    
    // Delete button in detail modal
    $('#btnDeleteFromDetail').click(function() {
        if (currentDonorData) {
            $('#donorDetailModal').modal('hide');
            
            if (!confirm('Are you sure you want to delete "' + currentDonorData.name + '"?\n\nThis action cannot be undone.')) {
                return;
            }
            
            const formData = $('#editDonorForm').serialize() + '&ajax_action=delete_donor&donor_id=' + currentDonorData.id;
            
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
        }
    });
    
    // Add Donor is now on a separate page (add-donor.php)
    
    // Save Edit Donor
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

