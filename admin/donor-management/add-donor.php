<?php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_once __DIR__ . '/../includes/resilient_db_loader.php';

require_login();
require_admin();

$page_title = 'Add New Donor';
$current_user = current_user();
$db = db();

// Load donation packages
$pkgRows = [];
if ($db_connection_ok) {
    try {
        $pkg_table_exists = $db->query("SHOW TABLES LIKE 'donation_packages'")->num_rows > 0;
        if ($pkg_table_exists) {
            $pkgRows = $db->query("SELECT id, label, sqm_meters, price FROM donation_packages WHERE active=1 ORDER BY sort_order, id")->fetch_all(MYSQLI_ASSOC);
        }
    } catch(Exception $e) {
        // Silent fail
    }
}

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_connection_ok) {
    verify_csrf();
    
    $db->begin_transaction();
    try {
        // Step 1: Collect and sanitize inputs
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $notes = trim($_POST['notes'] ?? ''); // Reference/tombola
        $anonymous = isset($_POST['anonymous']) ? 1 : 0;
        $donation_type = $_POST['donation_type'] ?? 'pledge'; // 'pledge' or 'paid'
        $package_id = isset($_POST['package_id']) ? (int)$_POST['package_id'] : null;
        $custom_amount = isset($_POST['custom_amount']) ? (float)$_POST['custom_amount'] : 0;
        $payment_method_input = trim($_POST['payment_method'] ?? '');
        $additional_donation = isset($_POST['additional_donation']) && $_POST['additional_donation'] === '1';
        
        // Generate client UUID for pledges
        $client_uuid = '';
        try { 
            $client_uuid = bin2hex(random_bytes(16)); 
        } catch (Throwable $e) { 
            $client_uuid = uniqid('uuid_', true); 
        }
        
        // Step 2: Validation
        if (empty($name)) {
            throw new Exception('Full name is required');
        }
        if (empty($phone)) {
            throw new Exception('Phone number is required');
        }
        
        // Normalize and validate UK mobile phone
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        if (strpos($phone, '+44') === 0) {
            $phone = '0' . substr($phone, 3);
        }
        if (!preg_match('/^07\d{9}$/', $phone)) {
            throw new Exception('Please enter a valid UK mobile number starting with 07');
        }
        
        // Validate and normalize payment method for paid donations
        $payment_method = null;
        if ($donation_type === 'paid') {
            if ($payment_method_input === 'transfer') $payment_method_input = 'bank';
            if ($payment_method_input === 'cheque') $payment_method_input = 'other';
            $valid_methods = ['cash', 'card', 'bank', 'other'];
            if (in_array($payment_method_input, $valid_methods, true)) {
                $payment_method = $payment_method_input;
            } else {
                throw new Exception('Please choose a valid payment method for paid donations');
            }
        }
        
        // Step 3: Calculate amount
        $amount = 0.0;
        $selectedPackage = null;
        
        if ($package_id) {
            foreach ($pkgRows as $pkg) {
                if ($pkg['id'] == $package_id) {
                    $selectedPackage = $pkg;
                    // If it's a custom package, use custom amount
                    if (strtolower($pkg['label']) === 'custom') {
                        $amount = max(0, $custom_amount);
                    } else {
                        $amount = (float)$pkg['price'];
                    }
                    break;
                }
            }
        } else {
            throw new Exception('Please select a donation package');
        }
        
        if ($amount <= 0) {
            throw new Exception('Amount must be greater than zero');
        }
        
        // Step 4: Duplicate check
        if ($phone && !$additional_donation) {
            $q1 = $db->prepare("SELECT id FROM pledges WHERE donor_phone=? AND status IN ('pending','approved') LIMIT 1");
            $q1->bind_param('s', $phone);
            $q1->execute();
            $existsPledge = (bool)$q1->get_result()->fetch_assoc();
            $q1->close();
            
            $q2 = $db->prepare("SELECT id FROM payments WHERE donor_phone=? AND status IN ('pending','approved') LIMIT 1");
            $q2->bind_param('s', $phone);
            $q2->execute();
            $existsPayment = (bool)$q2->get_result()->fetch_assoc();
            $q2->close();
            
            if ($existsPledge || $existsPayment) {
                throw new Exception('This donor already has a registered pledge/payment. Check "Additional Donation" if this is intentional.');
            }
        }
        
        // Step 5: Insert into database (PENDING status)
        $createdBy = (int)$current_user['id'];
        $donorName = $anonymous ? 'Anonymous' : $name;
        $donorPhone = $phone;
        $donorEmail = null;
        
        if ($donation_type === 'paid') {
            // Insert PENDING payment
            $sql = "INSERT INTO payments (donor_name, donor_phone, donor_email, amount, method, package_id, reference, status, received_by_user_id, received_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())";
            $pmt = $db->prepare($sql);
            $reference = $notes;
            $packageIdNullable = $package_id > 0 ? $package_id : null;
            $pmt->bind_param('sssdsisi', $donorName, $donorPhone, $donorEmail, $amount, $payment_method, $packageIdNullable, $reference, $createdBy);
            $pmt->execute();
            if ($pmt->affected_rows === 0) {
                throw new Exception('Failed to record payment');
            }
            $entityId = $db->insert_id;
            
            // Audit
            log_audit(
                $db,
                'create_pending',
                'payment',
                $entityId,
                null,
                ['amount' => $amount, 'method' => $payment_method, 'donor' => $donorName, 'status' => 'pending', 'package_id' => $packageIdNullable],
                'admin_portal',
                $createdBy
            );
            
        } else {
            // Insert PENDING pledge
            // Check for duplicate UUID
            $stmt = $db->prepare("SELECT id FROM pledges WHERE client_uuid = ?");
            $stmt->bind_param("s", $client_uuid);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                throw new Exception("Duplicate submission detected. Please refresh and try again.");
            }
            $stmt->close();
            
            $stmt = $db->prepare("
                INSERT INTO pledges (
                  donor_name, donor_phone, donor_email, source, anonymous,
                  amount, type, status, notes, client_uuid, created_by_user_id, package_id
                ) VALUES (?, ?, ?, 'admin', ?, ?, 'pledge', 'pending', ?, ?, ?, ?)
            ");
            $packageIdNullable = $package_id > 0 ? $package_id : null;
            $stmt->bind_param(
                'sssidsssii',
                $donorName, $donorPhone, $donorEmail, $anonymous,
                $amount, $notes, $client_uuid, $createdBy, $packageIdNullable
            );
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                throw new Exception('Failed to insert pledge');
            }
            $entityId = $db->insert_id;
            
            // Audit
            log_audit(
                $db,
                'create_pending',
                'pledge',
                $entityId,
                null,
                ['amount' => $amount, 'type' => 'pledge', 'anonymous' => $anonymous, 'donor' => $donorName, 'status' => 'pending', 'package_id' => $packageIdNullable],
                'admin_portal',
                $createdBy
            );
        }
        
        $db->commit();
        $_SESSION['success_message'] = "Registration submitted for approval successfully! (£" . number_format($amount, 2) . ")";
        header('Location: donors.php');
        exit;
        
    } catch (Exception $e) {
        $db->rollback();
        $error_message = $e->getMessage();
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
                            <i class="fas fa-user-plus me-2 text-primary"></i>Add New Donor Registration
                        </h1>
                        <p class="text-muted mb-0">Submit a pending registration for admin approval</p>
                    </div>
                    <a href="donors.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to List
                    </a>
                </div>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Add Donor Form -->
                <form method="POST" action="" id="addDonorForm">
                    <?php echo csrf_input(); ?>
                    
                    <div class="row g-4">
                        <!-- Left Column -->
                        <div class="col-lg-8">
                            <!-- Step 1: Donor Information -->
                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0"><i class="fas fa-user me-2"></i>Step 1: Donor Information</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Full Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="name" required>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Phone (UK Mobile) <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="phone" placeholder="07XXXXXXXXX" pattern="07[0-9]{9}" required>
                                            <small class="text-muted">Format: 07XXXXXXXXX</small>
                                        </div>
                                        
                                        <div class="col-md-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="anonymous" id="anonymous">
                                                <label class="form-check-label" for="anonymous">
                                                    <i class="fas fa-user-secret me-1"></i> Anonymous Donor
                                                </label>
                                            </div>
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
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" name="donation_type" id="type_pledge" value="pledge" checked>
                                        <label class="btn btn-outline-warning btn-lg" for="type_pledge">
                                            <i class="fas fa-handshake me-2"></i>Pledge (Pay Later)
                                        </label>
                                        
                                        <input type="radio" class="btn-check" name="donation_type" id="type_paid" value="paid">
                                        <label class="btn btn-outline-success btn-lg" for="type_paid">
                                            <i class="fas fa-money-bill-wave me-2"></i>Immediate Payment
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Step 3: Donation Package -->
                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-header bg-info text-white">
                                    <h5 class="mb-0"><i class="fas fa-gift me-2"></i>Step 3: Donation Package <span class="text-danger">*</span></h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <?php foreach ($pkgRows as $pkg): ?>
                                            <div class="col-md-6">
                                                <div class="form-check card border">
                                                    <div class="card-body">
                                                        <input class="form-check-input" type="radio" name="package_id" id="pkg_<?php echo $pkg['id']; ?>" value="<?php echo $pkg['id']; ?>" <?php echo $pkg === reset($pkgRows) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label w-100" for="pkg_<?php echo $pkg['id']; ?>">
                                                            <strong><?php echo htmlspecialchars($pkg['label']); ?></strong>
                                                            <?php if (strtolower($pkg['label']) !== 'custom'): ?>
                                                                <div class="text-muted">£<?php echo number_format($pkg['price'], 2); ?></div>
                                                            <?php endif; ?>
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        <div class="col-12" id="customAmountField" style="display: none;">
                                            <label class="form-label fw-bold">Custom Amount (£) <span class="text-danger">*</span></label>
                                            <input type="number" class="form-control" name="custom_amount" id="custom_amount" min="0" step="0.01" placeholder="0.00">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Payment Method (for paid only) -->
                            <div id="paymentMethodSection" class="card border-0 shadow-sm mb-4" style="display: none;">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0"><i class="fas fa-credit-card me-2"></i>Step 4: Payment Method <span class="text-danger">*</span></h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <div class="form-check card border">
                                                <div class="card-body py-2 px-3">
                                                    <input class="form-check-input" type="radio" name="payment_method" id="method_cash" value="cash" checked>
                                                    <label class="form-check-label" for="method_cash">
                                                        <i class="fas fa-money-bill me-1"></i> Cash
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check card border">
                                                <div class="card-body py-2 px-3">
                                                    <input class="form-check-input" type="radio" name="payment_method" id="method_card" value="card">
                                                    <label class="form-check-label" for="method_card">
                                                        <i class="fas fa-credit-card me-1"></i> Card
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check card border">
                                                <div class="card-body py-2 px-3">
                                                    <input class="form-check-input" type="radio" name="payment_method" id="method_bank" value="bank">
                                                    <label class="form-check-label" for="method_bank">
                                                        <i class="fas fa-university me-1"></i> Bank
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check card border">
                                                <div class="card-body py-2 px-3">
                                                    <input class="form-check-input" type="radio" name="payment_method" id="method_other" value="other">
                                                    <label class="form-check-label" for="method_other">
                                                        <i class="fas fa-ellipsis-h me-1"></i> Other
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right Column -->
                        <div class="col-lg-4">
                            <!-- Reference/Notes -->
                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="fas fa-sticky-note me-2"></i>Reference/Notes</h6>
                                </div>
                                <div class="card-body">
                                    <label class="form-label">Reference (Optional)</label>
                                    <textarea class="form-control" name="notes" rows="3" placeholder="e.g., Tombola number, transaction reference..."></textarea>
                                    <small class="text-muted">Internal reference for tracking</small>
                                </div>
                            </div>
                            
                            <!-- Additional Donation -->
                            <div class="card border-0 shadow-sm mb-4 bg-light">
                                <div class="card-body">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="additional_donation" value="1" id="additional_donation">
                                        <label class="form-check-label fw-bold" for="additional_donation">
                                            <i class="fas fa-plus-circle me-1 text-warning"></i> Additional Donation
                                        </label>
                                    </div>
                                    <small class="text-muted">Check this if the donor already exists and this is an additional contribution</small>
                                </div>
                            </div>
                            
                            <!-- Info Card -->
                            <div class="card border-0 shadow-sm bg-info text-white">
                                <div class="card-body">
                                    <h6 class="card-title"><i class="fas fa-info-circle me-2"></i>Important</h6>
                                    <ul class="small mb-0">
                                        <li>All registrations require approval</li>
                                        <li>Donors will appear after approval</li>
                                        <li>Phone number must be UK mobile</li>
                                        <li>Check for duplicates before submitting</li>
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
                                            <i class="fas fa-paper-plane me-2"></i>Submit for Approval
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
    // Show/hide payment method section based on donation type
    $('input[name="donation_type"]').change(function() {
        if ($(this).val() === 'paid') {
            $('#paymentMethodSection').slideDown(300);
        } else {
            $('#paymentMethodSection').slideUp(300);
        }
    });
    
    // Show/hide custom amount field based on package selection
    $('input[name="package_id"]').change(function() {
        const selectedLabel = $(this).closest('.form-check').find('label strong').text().toLowerCase();
        if (selectedLabel.includes('custom')) {
            $('#customAmountField').slideDown(300);
            $('#custom_amount').prop('required', true);
        } else {
            $('#customAmountField').slideUp(300);
            $('#custom_amount').prop('required', false);
        }
    });
    
    // Trigger on page load for custom package
    $('input[name="package_id"]:checked').trigger('change');
});
</script>
</body>
</html>

