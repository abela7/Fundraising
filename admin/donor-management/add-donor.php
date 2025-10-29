<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

// Resiliently load settings and check for DB errors
require_once __DIR__ . '/../includes/resilient_db_loader.php';

$page_title = 'Add New Donor';
$current_user = current_user();
$db = db();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_donor'])) {
    $success_message = '';
    $error_message = '';
    
    // Verify CSRF
    if (!verify_csrf()) {
        $error_message = 'Invalid CSRF token';
    } else {
        try {
            $db->begin_transaction();
            
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
            
            $success_message = $donation_type === 'pledge' 
                ? 'Donor and pledge record created successfully!' 
                : 'Donor and payment record created successfully!';
            
            // Redirect after 2 seconds
            header("refresh:2;url=donors.php");
            
        } catch (Exception $e) {
            $db->rollback();
            $error_message = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/donor-management.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <?php include '../includes/db_error_banner.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../dashboard/">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="./">Donor Management</a></li>
                        <li class="breadcrumb-item"><a href="donors.php">Donor List</a></li>
                        <li class="breadcrumb-item active">Add New Donor</li>
                    </ol>
                </nav>

                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1">
                            <i class="fas fa-user-plus me-2 text-primary"></i>Add New Donor
                        </h1>
                        <p class="text-muted mb-0">Complete the form below to register a new donor</p>
                    </div>
                    <a href="donors.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to List
                    </a>
                </div>

                <?php if (isset($success_message) && $success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                        <br><small>Redirecting to donor list...</small>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($error_message) && $error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Add Donor Form -->
                <form method="POST" action="" id="addDonorForm">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="submit_donor" value="1">
                    
                    <div class="row g-4">
                        <!-- Left Column -->
                        <div class="col-lg-8">
                            <!-- Step 1: Basic Information -->
                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0"><i class="fas fa-user me-2"></i>Step 1: Basic Information</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Full Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="name" required>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Phone (UK Format) <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="phone" placeholder="07XXXXXXXXX" pattern="07[0-9]{9}" required>
                                            <small class="text-muted">Format: 07XXXXXXXXX</small>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Preferred Language</label>
                                            <select class="form-select" name="preferred_language">
                                                <option value="en">English</option>
                                                <option value="am">Amharic</option>
                                                <option value="ti">Tigrinya</option>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Preferred Payment Method</label>
                                            <select class="form-select" name="preferred_payment_method">
                                                <option value="bank_transfer">Bank Transfer</option>
                                                <option value="cash">Cash</option>
                                                <option value="card">Card</option>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">SMS Opt-In</label>
                                            <select class="form-select" name="sms_opt_in">
                                                <option value="1">Yes - Send SMS Reminders</option>
                                                <option value="0">No - Do Not Send SMS</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Step 2: Donation Type -->
                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-header bg-warning">
                                    <h5 class="mb-0"><i class="fas fa-hand-holding-usd me-2"></i>Step 2: Donation Type <span class="text-danger">*</span></h5>
                                </div>
                                <div class="card-body">
                                    <label class="form-label fw-bold">How is this donor contributing?</label>
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" name="donation_type" id="type_pledge" value="pledge" checked>
                                        <label class="btn btn-outline-warning btn-lg" for="type_pledge">
                                            <i class="fas fa-handshake me-2"></i>Pledge (Pay Later)
                                        </label>
                                        
                                        <input type="radio" class="btn-check" name="donation_type" id="type_immediate" value="immediate">
                                        <label class="btn btn-outline-success btn-lg" for="type_immediate">
                                            <i class="fas fa-bolt me-2"></i>Immediate Payment
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Pledge Section -->
                            <div id="pledgeSection" class="card border-0 shadow-sm mb-4">
                                <div class="card-header bg-info text-white">
                                    <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Step 3: Pledge Details</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Pledge Amount (£) <span class="text-danger">*</span></label>
                                            <input type="number" class="form-control" name="pledge_amount" id="pledge_amount" min="0" step="0.01" placeholder="0.00">
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Pledge Date</label>
                                            <input type="date" class="form-control" name="pledge_date" value="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        
                                        <div class="col-12">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="create_payment_plan" id="create_payment_plan">
                                                <label class="form-check-label fw-bold" for="create_payment_plan">
                                                    Create Payment Plan (Optional)
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div id="paymentPlanFields" style="display: none;" class="col-12">
                                            <div class="alert alert-light border">
                                                <div class="row g-3">
                                                    <div class="col-md-4">
                                                        <label class="form-label">Monthly Amount (£)</label>
                                                        <input type="number" class="form-control" name="plan_monthly_amount" min="0" step="0.01" placeholder="0.00">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">Duration (Months)</label>
                                                        <input type="number" class="form-control" name="plan_duration_months" min="1" placeholder="12">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">Preferred Payment Day</label>
                                                        <input type="number" class="form-control" name="preferred_payment_day" min="1" max="28" value="1" placeholder="1">
                                                        <small class="text-muted">Day of month (1-28)</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-12">
                                            <label class="form-label fw-bold">Pledge Notes (Optional)</label>
                                            <textarea class="form-control" name="pledge_notes" rows="3" placeholder="Any additional notes about this pledge..."></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Immediate Payment Section -->
                            <div id="immediateSection" class="card border-0 shadow-sm mb-4" style="display: none;">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Step 3: Payment Details</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Payment Amount (£) <span class="text-danger">*</span></label>
                                            <input type="number" class="form-control" name="payment_amount" id="payment_amount" min="0" step="0.01" placeholder="0.00">
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Payment Date</label>
                                            <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Payment Method Used</label>
                                            <select class="form-select" name="payment_method_used">
                                                <option value="bank_transfer">Bank Transfer</option>
                                                <option value="cash">Cash</option>
                                                <option value="card">Card</option>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label">Transaction Reference (Optional)</label>
                                            <input type="text" class="form-control" name="transaction_ref" placeholder="e.g., Receipt #123">
                                        </div>
                                        
                                        <div class="col-12">
                                            <label class="form-label fw-bold">Payment Notes (Optional)</label>
                                            <textarea class="form-control" name="payment_notes" rows="3" placeholder="Any additional notes about this payment..."></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right Column -->
                        <div class="col-lg-4">
                            <!-- Admin Notes -->
                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="fas fa-sticky-note me-2"></i>Admin Notes</h6>
                                </div>
                                <div class="card-body">
                                    <textarea class="form-control" name="admin_notes" rows="5" placeholder="Internal notes for admin reference only..."></textarea>
                                </div>
                            </div>
                            
                            <!-- Help Card -->
                            <div class="card border-0 shadow-sm bg-light">
                                <div class="card-body">
                                    <h6 class="card-title"><i class="fas fa-info-circle me-2 text-info"></i>Quick Guide</h6>
                                    <ul class="small mb-0">
                                        <li><strong>Pledge Donors:</strong> Track promises to pay later</li>
                                        <li><strong>Immediate Payers:</strong> Record instant payments</li>
                                        <li><strong>Payment Plans:</strong> Optional for pledge donors</li>
                                        <li><strong>SMS Opt-In:</strong> Allow automated reminders</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Submit Buttons -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body bg-light">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <a href="donors.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-times me-2"></i>Cancel
                                        </a>
                                        <button type="submit" class="btn btn-primary btn-lg px-5">
                                            <i class="fas fa-save me-2"></i>Save Donor & Create Records
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="../assets/admin.js"></script>

<script>
$(document).ready(function() {
    // Toggle donation type sections
    $('input[name="donation_type"]').change(function() {
        if ($(this).val() === 'pledge') {
            $('#pledgeSection').slideDown(300);
            $('#immediateSection').slideUp(300);
            $('#pledge_amount').prop('required', true);
            $('#payment_amount').prop('required', false);
        } else {
            $('#pledgeSection').slideUp(300);
            $('#immediateSection').slideDown(300);
            $('#pledge_amount').prop('required', false);
            $('#payment_amount').prop('required', true);
        }
    });
    
    // Toggle payment plan fields
    $('#create_payment_plan').change(function() {
        if ($(this).is(':checked')) {
            $('#paymentPlanFields').slideDown(300);
        } else {
            $('#paymentPlanFields').slideUp(300);
        }
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

