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
                    // Note: Plan details will be stored in donor_payment_plans table
                    // Only flag is set here, actual plan creation happens separately
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
                    has_active_plan, preferred_payment_day, registered_by_user_id,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->bind_param('ssssissdddsiii', 
                $name, $phone, $preferred_language, $preferred_payment_method,
                $sms_opt_in, $admin_notes, $source,
                $total_pledged, $total_paid, $balance, $payment_status,
                $has_active_plan, $preferred_payment_day, $current_user['id']
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

// Get donors list with payment plan details
$donors = [];
try {
    $donors_result = $db->query("
        SELECT 
            d.id, d.name, d.phone, d.email, d.city, d.baptism_name, d.church_id,
            d.preferred_language, d.preferred_payment_method, d.source, 
            d.total_pledged, d.total_paid, d.balance, d.payment_status, 
            d.created_at, d.updated_at, d.has_active_plan, d.active_payment_plan_id,
            d.last_payment_date, d.last_sms_sent_at, d.login_count, d.admin_notes,
            d.registered_by_user_id, d.pledge_count, d.payment_count, d.achievement_badge,
            d.donor_type,
            -- Church name
            c.name as church_name,
            -- Payment plan details (from master table only)
            pp.id as plan_id, pp.total_amount as plan_total_amount,
            pp.monthly_amount as plan_monthly_amount, pp.total_months as plan_total_months,
            pp.total_payments as plan_total_payments, pp.start_date as plan_start_date,
            pp.payment_day as plan_payment_day, pp.payment_method as plan_payment_method,
            pp.next_payment_due as plan_next_payment_due, pp.last_payment_date as plan_last_payment_date,
            pp.status as plan_status, pp.plan_frequency_unit, pp.plan_frequency_number,
            pp.plan_payment_day_type, pp.template_id,
            pp.next_reminder_date, pp.miss_notification_date, pp.overdue_reminder_date,
            pp.payments_made, pp.amount_paid,
            -- Template name if exists
            t.name as template_name
        FROM donors d
        LEFT JOIN churches c ON d.church_id = c.id
        LEFT JOIN donor_payment_plans pp ON d.active_payment_plan_id = pp.id AND pp.status = 'active'
        LEFT JOIN payment_plan_templates t ON pp.template_id = t.id
        ORDER BY d.created_at DESC
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
    // Count by donor type (use actual donor_type from database)
    if ($donor['donor_type'] === 'pledge') {
        $pledge_donors++;
    } else {
        $immediate_payers++;
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
    <style>
        /* Responsive Modal Styles */
        @media (max-width: 768px) {
            #donorDetailModal .modal-dialog {
                margin: 0.5rem;
                max-width: calc(100% - 1rem);
            }
            
            #donorDetailModal .modal-body {
                padding: 1rem !important;
            }
            
            #donorDetailModal .card-body {
                padding: 0.75rem !important;
            }
            
            #donorDetailModal h4 {
                font-size: 1.25rem !important;
            }
            
            #donorDetailModal h5, #donorDetailModal h6 {
                font-size: 1rem !important;
            }
            
            #donorDetailModal .row.g-3,
            #donorDetailModal .row.g-4 {
                margin-left: -0.5rem !important;
                margin-right: -0.5rem !important;
            }
            
            #donorDetailModal .row.g-3 > *,
            #donorDetailModal .row.g-4 > * {
                padding-left: 0.5rem !important;
                padding-right: 0.5rem !important;
            }
            
            #donorDetailModal small {
                font-size: 0.75rem !important;
            }
            
            #donorDetailModal .modal-footer {
                padding: 0.75rem !important;
                flex-direction: column;
                gap: 0.5rem !important;
            }
            
            #donorDetailModal .modal-footer .btn {
                width: 100%;
                margin: 0 !important;
            }
            
            #donorDetailModal .modal-footer > div {
                width: 100%;
                margin: 0 !important;
            }
            
            #donorDetailModal .modal-footer .d-flex {
                flex-direction: column !important;
                width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            #donorDetailModal .modal-dialog {
                margin: 0.25rem;
                max-width: calc(100% - 0.5rem);
            }
            
            #donorDetailModal .border-start {
                border-left-width: 3px !important;
                padding-left: 0.5rem !important;
            }
            
            #donorDetailModal h5.mb-0 {
                font-size: 1rem !important;
            }
        }
        
        /* Ensure modal is scrollable */
        #donorDetailModal .modal-body {
            max-height: calc(100vh - 200px);
            overflow-y: auto;
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
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Pledged</th>
                                        <th>Paid</th>
                                        <th>Balance</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($donors as $donor): ?>
                                    <tr class="donor-row" style="cursor: pointer;" data-donor='<?php echo htmlspecialchars(json_encode($donor), ENT_QUOTES); ?>' title="Click to view details">
                                        <td class="fw-bold"><?php echo htmlspecialchars($donor['name']); ?></td>
                                        <td>
                                            <?php if ($donor['donor_type'] === 'pledge'): ?>
                                                <span class="badge bg-warning text-dark">Pledge</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Immediate</span>
                                            <?php endif; ?>
                                        </td>
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
                                        <td>
                                            <?php if (!empty($donor['phone'])): ?>
                                            <a href="../call-center/make-call.php?donor_id=<?php echo $donor['id']; ?>" 
                                               class="btn btn-sm btn-success" 
                                               onclick="event.stopPropagation();">
                                                <i class="fas fa-phone-alt me-1"></i>Call
                                            </a>
                                            <?php else: ?>
                                            <span class="text-muted small" onclick="event.stopPropagation();">No Phone</span>
                                            <?php endif; ?>
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
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <div>
                    <h5 class="modal-title mb-1">
                        <i class="fas fa-user-circle me-2"></i>Donor Details
                    </h5>
                    <small class="text-white-50">Complete donor information and financial summary</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3 p-md-4">
                <!-- Donor Header Card -->
                <div class="card border-primary mb-3 mb-md-4">
                    <div class="card-body p-3">
                        <h4 class="mb-2 mb-md-3 text-primary" id="detail_name" style="word-break: break-word;">-</h4>
                        <div class="row g-2 small">
                            <div class="col-4 col-sm-4">
                                <div class="text-muted mb-1"><i class="fas fa-hashtag me-1"></i>ID</div>
                                <strong id="detail_id" class="d-block">-</strong>
                            </div>
                            <div class="col-8 col-sm-4">
                                <div class="text-muted mb-1"><i class="fas fa-phone me-1"></i>Phone</div>
                                <strong id="detail_phone" class="d-block" style="word-break: break-all;">-</strong>
                            </div>
                            <div class="col-12 col-sm-4">
                                <div class="text-muted mb-1"><i class="fas fa-tag me-1"></i>Type</div>
                                <span id="detail_type" class="d-block">-</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Two Column Layout -->
                <div class="row g-3">
                    <!-- Left Column: Contact & Additional Info -->
                    <div class="col-12 col-lg-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-light p-2 p-md-3">
                                <h6 class="mb-0 small"><i class="fas fa-address-book me-2 text-primary"></i>Contact & Information</h6>
                            </div>
                            <div class="card-body p-3">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-envelope me-2"></i>Email</small>
                                        <strong id="detail_email">-</strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-map-marker-alt me-2"></i>City</small>
                                        <strong id="detail_city">-</strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-water me-2"></i>Baptism Name</small>
                                        <strong id="detail_baptism_name">-</strong>
                                    </div>
                                    <div class="col-12">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-church me-2"></i>Church</small>
                                        <strong id="detail_church">-</strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-language me-2"></i>Language</small>
                                        <strong id="detail_language">-</strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-credit-card me-2"></i>Payment Method</small>
                                        <strong id="detail_payment_method">-</strong>
                                    </div>
                                    <div class="col-12">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-source me-2"></i>Source</small>
                                        <strong id="detail_source">-</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column: Financial Info -->
                    <div class="col-12 col-lg-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-light p-2 p-md-3">
                                <h6 class="mb-0 small"><i class="fas fa-pound-sign me-2 text-success"></i>Financial Summary</h6>
                            </div>
                            <div class="card-body p-3">
                                <!-- Key Financial Metrics -->
                                <div class="row g-3 mb-4">
                                    <div class="col-12 col-sm-4">
                                        <div class="border-start border-4 border-warning ps-3">
                                            <small class="text-muted d-block mb-1"><i class="fas fa-handshake me-1"></i>Pledged</small>
                                            <h5 class="mb-0 text-warning" id="detail_pledged">£0.00</h5>
                                        </div>
                                    </div>
                                    <div class="col-12 col-sm-4">
                                        <div class="border-start border-4 border-success ps-3">
                                            <small class="text-muted d-block mb-1"><i class="fas fa-check-circle me-1"></i>Paid</small>
                                            <h5 class="mb-0 text-success" id="detail_paid">£0.00</h5>
                                        </div>
                                    </div>
                                    <div class="col-12 col-sm-4">
                                        <div class="border-start border-4 border-danger ps-3">
                                            <small class="text-muted d-block mb-1"><i class="fas fa-balance-scale me-1"></i>Balance</small>
                                            <h5 class="mb-0 text-danger" id="detail_balance">£0.00</h5>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Additional Metrics -->
                                <div class="row g-3">
                                    <div class="col-6">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-flag me-1"></i>Status</small>
                                        <span id="detail_status">-</span>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-trophy me-1"></i>Badge</small>
                                        <span id="detail_badge">-</span>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-file-invoice me-1"></i>Pledges</small>
                                        <strong id="detail_pledge_count">0</strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-receipt me-1"></i>Payments</small>
                                        <strong id="detail_payment_count">0</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Plan Info (if applicable) -->
                    <div class="col-12" id="payment_plan_section" style="display: none;">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light p-2 p-md-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <h6 class="mb-0 small">
                                    <i class="fas fa-calendar-alt me-2 text-info"></i>Payment Plan
                                </h6>
                                <span class="badge bg-info" id="detail_plan_type_badge">Standard</span>
                            </div>
                            <div class="card-body p-3">
                                <!-- Plan Summary -->
                                <div class="row g-3 mb-3">
                                    <div class="col-6 col-md-3">
                                        <small class="text-muted d-block mb-1">
                                            <i class="fas fa-pound-sign me-1"></i>Monthly
                                        </small>
                                        <strong class="d-block" id="detail_plan_amount">-</strong>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <small class="text-muted d-block mb-1">
                                            <i class="fas fa-clock me-1"></i>Duration
                                        </small>
                                        <strong class="d-block" id="detail_plan_duration">-</strong>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <small class="text-muted d-block mb-1">
                                            <i class="fas fa-calendar-check me-1"></i>Start
                                        </small>
                                        <strong class="d-block" id="detail_plan_start">-</strong>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <small class="text-muted d-block mb-1">
                                            <i class="fas fa-calendar-day me-1"></i>Day
                                        </small>
                                        <strong class="d-block" id="detail_plan_payment_day">-</strong>
                                    </div>
                                </div>
                                
                                <!-- Payment Schedule & Reminders -->
                                <div class="card border-primary mb-3">
                                    <div class="card-header bg-primary bg-opacity-10">
                                        <h6 class="mb-0 text-primary small">
                                            <i class="fas fa-bell me-2"></i>Schedule & Reminders
                                        </h6>
                                    </div>
                                    <div class="card-body p-3">
                                        <div class="row g-3">
                                            <div class="col-12 col-md-6 col-lg-4">
                                                <div class="border-start border-4 border-primary ps-3">
                                                    <small class="text-muted d-block mb-1">
                                                        <i class="fas fa-calendar-alt me-1"></i>Next Due
                                                    </small>
                                                    <strong class="text-primary d-block" id="detail_plan_next_due">-</strong>
                                                </div>
                                            </div>
                                            <div class="col-12 col-md-6 col-lg-4">
                                                <div class="border-start border-4 border-warning ps-3">
                                                    <small class="text-muted d-block mb-1">
                                                        <i class="fas fa-bell me-1"></i>Reminder
                                                        <span class="text-muted">(2d before)</span>
                                                    </small>
                                                    <strong class="text-warning d-block" id="detail_plan_reminder_date">-</strong>
                                                </div>
                                            </div>
                                            <div class="col-12 col-md-6 col-lg-4">
                                                <div class="border-start border-4 border-warning ps-3">
                                                    <small class="text-muted d-block mb-1">
                                                        <i class="fas fa-bell-slash me-1"></i>Miss
                                                        <span class="text-muted">(1d after)</span>
                                                    </small>
                                                    <strong class="text-warning d-block" id="detail_plan_miss_notification">-</strong>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="border-start border-4 border-danger ps-3">
                                                    <small class="text-muted d-block mb-1">
                                                        <i class="fas fa-exclamation-triangle me-1"></i>Overdue
                                                        <span class="text-muted">(7d after if not paid)</span>
                                                    </small>
                                                    <strong class="text-danger d-block" id="detail_plan_overdue_notification">-</strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Plan Details -->
                                <div class="row g-3">
                                    <div class="col-6 col-md-4">
                                        <small class="text-muted d-block mb-1">Type</small>
                                        <strong class="d-block" id="detail_plan_type">-</strong>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <small class="text-muted d-block mb-1">Status</small>
                                        <span id="detail_plan_status">-</span>
                                    </div>
                                    <div class="col-6 col-md-4" id="detail_plan_frequency_section" style="display: none;">
                                        <small class="text-muted d-block mb-1">Frequency</small>
                                        <strong class="d-block" id="detail_plan_frequency">-</strong>
                                    </div>
                                    <div class="col-6 col-md-4" id="detail_plan_template_section" style="display: none;">
                                        <small class="text-muted d-block mb-1">Template</small>
                                        <strong class="d-block" id="detail_plan_template">-</strong>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <small class="text-muted d-block mb-1">Progress</small>
                                        <strong class="d-block"><span id="detail_plan_payments_made">0</span> / <span id="detail_plan_total_payments">0</span></strong>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <small class="text-muted d-block mb-1">Paid / Total</small>
                                        <strong class="d-block text-success">£<span id="detail_plan_amount_paid">0.00</span></strong> / <span class="text-muted">£<span id="detail_plan_total_amount">0.00</span></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- System & Audit Info -->
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light p-2 p-md-3">
                                <h6 class="mb-0 small"><i class="fas fa-info-circle me-2 text-secondary"></i>System Information</h6>
                            </div>
                            <div class="card-body p-3">
                                <div class="row g-3">
                                    <div class="col-6 col-md-4">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-user-plus me-1"></i>Registered By</small>
                                        <strong class="d-block" id="detail_registered_by">System</strong>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-calendar-plus me-1"></i>Created</small>
                                        <strong class="d-block" id="detail_created_at">-</strong>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-calendar-check me-1"></i>Updated</small>
                                        <strong class="d-block" id="detail_updated_at">-</strong>
                                    </div>
                                    <div class="col-6 col-md-4" id="last_activity_section">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-money-bill-wave me-1"></i>Last Payment</small>
                                        <strong class="d-block" id="detail_last_payment">Never</strong>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-sms me-1"></i>Last SMS</small>
                                        <strong class="d-block" id="detail_last_sms">Never</strong>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <small class="text-muted d-block mb-1"><i class="fas fa-sign-in-alt me-1"></i>Logins</small>
                                        <strong class="d-block" id="detail_login_count">0</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Admin Notes (if any) -->
                    <div class="col-12" id="admin_notes_section" style="display: none;">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light p-2 p-md-3">
                                <h6 class="mb-0 small"><i class="fas fa-sticky-note me-2 text-warning"></i>Admin Notes</h6>
                            </div>
                            <div class="card-body p-3">
                                <p id="detail_admin_notes" class="mb-0 text-muted small" style="word-break: break-word;">-</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light flex-wrap gap-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Close
                </button>
                <div class="d-flex flex-wrap gap-2 ms-auto">
                    <a href="#" class="btn btn-light border" style="color: #0a6286; border-color: #0a6286 !important;" id="btnViewProfile">
                        <i class="fas fa-user-circle me-1"></i>Profile
                    </a>
                    <button type="button" class="btn btn-success text-white" id="btnCallFromDetail">
                        <i class="fas fa-phone-alt me-1"></i>Call
                    </button>
                    <button type="button" class="btn btn-primary" id="btnEditFromDetail">
                        <i class="fas fa-edit me-1"></i>Edit
                    </button>
                    <button type="button" class="btn btn-danger" id="btnDeleteFromDetail">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                </div>
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
        // Column indices: 0=Name, 1=Type, 2=Status, 3=Pledged, 4=Paid, 5=Balance, 6=Actions
        const rowDonorType = data[1].toLowerCase(); // Type column
        const rowPaymentStatus = data[2].toLowerCase(); // Status column
        const rowBalance = parseFloat(data[5].replace('£', '').replace(',', '')); // Balance column
        
        // Get donor data from row to check payment method and language
        const $row = $(settings.aoData[dataIndex].nTr);
        const donorData = $row.data('donor');
        const rowPaymentMethod = donorData && donorData.preferred_payment_method ? donorData.preferred_payment_method.toLowerCase() : '';
        const rowLanguage = donorData && donorData.preferred_language ? donorData.preferred_language.toLowerCase() : '';
        
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
        
        // Additional contact info (handle null/undefined properly)
        $('#detail_email').text(donor.email && donor.email !== 'null' ? donor.email : '-');
        $('#detail_city').text(donor.city && donor.city !== 'null' ? donor.city : '-');
        $('#detail_baptism_name').text(donor.baptism_name && donor.baptism_name !== 'null' ? donor.baptism_name : '-');
        $('#detail_church').text(donor.church_name && donor.church_name !== 'null' ? donor.church_name : '-');
        
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
        if (donor.has_active_plan == 1 && donor.plan_id) {
            // Basic plan info
            const monthlyAmount = parseFloat(donor.plan_monthly_amount || 0);
            const totalAmount = parseFloat(donor.plan_total_amount || 0);
            const amountPaid = parseFloat(donor.amount_paid || 0);
            const paymentsMade = parseInt(donor.payments_made || 0);
            const totalPayments = parseInt(donor.plan_total_payments || donor.plan_total_months || 0);
            const totalMonths = parseInt(donor.plan_total_months || 0);
            
            $('#detail_plan_amount').text('£' + monthlyAmount.toFixed(2));
            
            // Duration display
            if (totalMonths > 0) {
                $('#detail_plan_duration').text(totalMonths + ' month' + (totalMonths !== 1 ? 's' : ''));
            } else if (totalPayments > 0) {
                $('#detail_plan_duration').text(totalPayments + ' payment' + (totalPayments !== 1 ? 's' : ''));
            } else {
                $('#detail_plan_duration').text('-');
            }
            
            // Dates
            const formatDate = (dateStr) => {
                if (!dateStr || dateStr === '-') return '-';
                try {
                    const date = new Date(dateStr);
                    return date.toLocaleDateString('en-GB', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
                } catch {
                    return dateStr;
                }
            };
            
            $('#detail_plan_start').text(formatDate(donor.plan_start_date));
            $('#detail_plan_next_due').text(formatDate(donor.plan_next_payment_due || donor.plan_next_due_date));
            $('#detail_plan_payment_day').text(donor.plan_payment_day ? 'Day ' + donor.plan_payment_day : '-');
            
            // Reminder dates
            $('#detail_plan_reminder_date').text(formatDate(donor.next_reminder_date));
            $('#detail_plan_miss_notification').text(formatDate(donor.miss_notification_date));
            $('#detail_plan_overdue_notification').text(formatDate(donor.overdue_reminder_date));
            
            // Plan type - Check if template was used
            const hasTemplate = donor.template_id && donor.template_id > 0;
            
            if (hasTemplate) {
                // Template-based plan
                $('#detail_plan_type_badge').removeClass('bg-warning').addClass('bg-info').text('Template');
                $('#detail_plan_type').text(donor.template_name || 'Template Plan');
                $('#detail_plan_frequency_section').hide();
                
                // Show template name
                if (donor.template_name) {
                    $('#detail_plan_template').text(donor.template_name);
                    $('#detail_plan_template_section').show();
                } else {
                    $('#detail_plan_template_section').hide();
                }
            } else {
                // Custom plan (no template)
                $('#detail_plan_type_badge').removeClass('bg-info').addClass('bg-warning').text('Custom');
                $('#detail_plan_type').text('Custom Payment Plan');
                $('#detail_plan_template_section').hide();
                
                // Show frequency info for custom plans
                if (donor.plan_frequency_unit && donor.plan_frequency_number) {
                    const frequencyText = `Every ${donor.plan_frequency_number} ${donor.plan_frequency_unit}${donor.plan_frequency_number > 1 ? 's' : ''}`;
                    $('#detail_plan_frequency').text(frequencyText);
                    $('#detail_plan_frequency_section').show();
                } else {
                    $('#detail_plan_frequency_section').hide();
                }
            }
            
            // Status
            const statusMap = {
                'active': { text: 'Active', class: 'bg-success' },
                'completed': { text: 'Completed', class: 'bg-primary' },
                'paused': { text: 'Paused', class: 'bg-warning' },
                'defaulted': { text: 'Defaulted', class: 'bg-danger' },
                'cancelled': { text: 'Cancelled', class: 'bg-secondary' }
            };
            const status = statusMap[donor.plan_status] || { text: (donor.plan_status || 'Unknown'), class: 'bg-secondary' };
            $('#detail_plan_status').html(`<span class="badge ${status.class}">${status.text}</span>`);
            
            // Payments made
            $('#detail_plan_payments_made').text(paymentsMade);
            $('#detail_plan_total_payments').text(totalPayments || totalMonths || '?');
            
            // Amount paid
            $('#detail_plan_amount_paid').text(amountPaid.toFixed(2));
            $('#detail_plan_total_amount').text(totalAmount.toFixed(2));
            
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
        
        // Update Profile button link
        $('#btnViewProfile').attr('href', 'view-donor.php?id=' + donor.id);
        
        // Show modal
        $('#donorDetailModal').modal('show');
    });
    
    // Call button in detail modal
    $('#btnCallFromDetail').click(function() {
        if (currentDonorData && currentDonorData.id) {
            window.location.href = '../call-center/make-call.php?donor_id=' + currentDonorData.id;
        }
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

