<?php
/**
 * Donor Portal - Update Pledge Amount
 * Allows donors to request an increase to their pledge amount
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../shared/url.php';
require_once __DIR__ . '/../admin/includes/resilient_db_loader.php';
require_once __DIR__ . '/../shared/GridAllocationBatchTracker.php';

function current_donor(): ?array {
    if (isset($_SESSION['donor'])) {
        return $_SESSION['donor'];
    }
    return null;
}

function require_donor_login(): void {
    if (!current_donor()) {
        header('Location: login.php');
        exit;
    }
}

require_donor_login();
$donor = current_donor();
$page_title = 'Update Pledge Amount';
$current_donor = $donor;

// Load donation packages for amount selection
$currency = $settings['currency_code'] ?? 'GBP';
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

$pkgByLabel = [];
foreach ($pkgRows as $r) { $pkgByLabel[$r['label']] = $r; }
$pkgOne     = $pkgByLabel['1 m²']   ?? null;
$pkgHalf    = $pkgByLabel['1/2 m²'] ?? null;
$pkgQuarter = $pkgByLabel['1/4 m²'] ?? null;
$pkgCustom  = $pkgByLabel['Custom'] ?? null;

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Collect form inputs
    $notes = trim((string)($_POST['notes'] ?? '')); // Optional notes
    $sqm_unit = (string)($_POST['pack'] ?? ''); // '1', '0.5', '0.25', 'custom'
    $custom_amount = (float)($_POST['custom_amount'] ?? 0);
    $client_uuid = trim((string)($_POST['client_uuid'] ?? ''));
    if ($client_uuid === '') {
        try { $client_uuid = bin2hex(random_bytes(16)); } catch (Throwable $e) { $client_uuid = uniqid('uuid_', true); }
    }

    // Validation
    $error = '';
    if (empty($client_uuid)) {
        $error = 'A unique submission ID is required. Please refresh and try again.';
    }

    // Calculate donation amount based on selection
    $amount = 0.0;
    $selectedPackage = null;
    if ($sqm_unit === '1') { $selectedPackage = $pkgOne; }
    elseif ($sqm_unit === '0.5') { $selectedPackage = $pkgHalf; }
    elseif ($sqm_unit === '0.25') { $selectedPackage = $pkgQuarter; }
    elseif ($sqm_unit === 'custom') { $selectedPackage = $pkgCustom; }
    else { $selectedPackage = null; }

    if ($selectedPackage) {
        if ($sqm_unit === 'custom') {
            $amount = max(0, $custom_amount);
        } else {
            $amount = (float)$selectedPackage['price'];
        }
    } else {
        $error = 'Please select a valid donation package.';
    }

    if ($amount <= 0 && !$error) {
        $error = 'Please select a valid amount greater than zero.';
    }

    // Process the database transaction
    if (empty($error)) {
        // Debug: Check database connection
        if (!$db_connection_ok) {
            $error = 'Database connection is not available. Please contact support.';
        } elseif (!isset($db) || !($db instanceof mysqli)) {
            $error = 'Database object is not available. Please contact support.';
        } else {
            try {
                // Debug: Log step
                error_log("Donor pledge update: Starting transaction. Donor ID: " . ($donor['id'] ?? 'N/A') . ", Amount: $amount, Package: " . ($selectedPackage['label'] ?? 'N/A'));
                
                $db->autocommit(false);
                
                // Donor data from session
                $donorName = $donor['name'] ?? 'Anonymous';
                $donorPhone = $donor['phone'] ?? '';
                $donorEmail = null;
                
                if (empty($donorPhone)) {
                    throw new Exception("Donor phone number is missing from session.");
                }
                if (empty($donorName)) {
                    $donorName = 'Anonymous';
                }

                // Normalize notes (tombola code if provided, otherwise empty)
                $notesDigits = preg_replace('/\D+/', '', $notes);
                $final_notes = !empty($notesDigits) ? $notesDigits : '';

                // Check for duplicate UUID
                error_log("Donor pledge update: Checking for duplicate UUID: $client_uuid");
                $stmt = $db->prepare("SELECT id FROM pledges WHERE client_uuid = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare duplicate check query: " . $db->error);
                }
                $stmt->bind_param("s", $client_uuid);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to execute duplicate check: " . $stmt->error);
                }
                $result = $stmt->get_result();
                if ($result->fetch_assoc()) {
                    $stmt->close();
                    throw new Exception("Duplicate submission detected. Please do not click submit twice.");
                }
                $stmt->close();
                error_log("Donor pledge update: No duplicate found, proceeding...");

                // Check if donor_email column exists in pledges table
                error_log("Donor pledge update: Checking for donor_email column...");
                $has_donor_email_column = false;
                try {
                    $check_col = $db->query("SHOW COLUMNS FROM pledges LIKE 'donor_email'");
                    if ($check_col) {
                        $has_donor_email_column = $check_col->num_rows > 0;
                    }
                } catch (Exception $e) {
                    error_log("Donor pledge update: donor_email column check failed: " . $e->getMessage());
                    // Column doesn't exist, that's fine
                }
                
                // Check if donor_id column exists in pledges table
                error_log("Donor pledge update: Checking for donor_id column...");
                $has_donor_id_column = false;
                try {
                    $check_col = $db->query("SHOW COLUMNS FROM pledges LIKE 'donor_id'");
                    if ($check_col) {
                        $has_donor_id_column = $check_col->num_rows > 0;
                    }
                } catch (Exception $e) {
                    error_log("Donor pledge update: donor_id column check failed: " . $e->getMessage());
                    // Column doesn't exist, that's fine
                }
                
                error_log("Donor pledge update: Column check results - donor_id: " . ($has_donor_id_column ? 'YES' : 'NO') . ", donor_email: " . ($has_donor_email_column ? 'YES' : 'NO'));
            
                // Determine created_by_user_id: Use System Admin (ID 0) if exists, otherwise NULL
                error_log("Donor pledge update: Checking for System Admin user (ID 0)...");
                $created_by_user_id = null;
                try {
                    $check_system_admin = $db->prepare("SELECT id FROM users WHERE id = 0 LIMIT 1");
                    if ($check_system_admin && $check_system_admin->execute()) {
                        $sys_admin_result = $check_system_admin->get_result();
                        if ($sys_admin_result->fetch_assoc()) {
                            $created_by_user_id = 0;
                            error_log("Donor pledge update: System Admin (ID 0) found, using it as created_by_user_id");
                        } else {
                            error_log("Donor pledge update: System Admin (ID 0) not found, using NULL for created_by_user_id");
                        }
                        $check_system_admin->close();
                    }
                } catch (Exception $e) {
                    error_log("Donor pledge update: Error checking System Admin: " . $e->getMessage() . " - Using NULL");
                    // Use NULL if check fails
                }
            
                // Create new pending pledge for the additional amount
                $status = 'pending';
                $packageId = (int)($selectedPackage['id'] ?? 0);
                $packageIdNullable = $packageId > 0 ? $packageId : null;
                $donorId = (int)($donor['id'] ?? 0);
                $donorIdNullable = $donorId > 0 ? $donorId : null;
                
                // Get donor email if available
                $donorEmail = null;
                if ($has_donor_email_column && isset($donor['email']) && !empty($donor['email'])) {
                    $donorEmail = trim($donor['email']);
                }
                
                // Build INSERT query dynamically based on column existence
                // Handle NULL for created_by_user_id properly in bind_param
                $created_by_bind = $created_by_user_id;
                if ($created_by_user_id === null) {
                    // For NULL values in mysqli, we need to pass null explicitly
                    $created_by_bind = null;
                }
                
                if ($has_donor_id_column && $has_donor_email_column) {
                    $stmt = $db->prepare("
                        INSERT INTO pledges (
                          donor_id, donor_name, donor_phone, donor_email, source, anonymous,
                          amount, type, status, notes, client_uuid, created_by_user_id, package_id
                        ) VALUES (?, ?, ?, ?, 'self', 0, ?, 'pledge', ?, ?, ?, ?, ?)
                    ");
                    if (!$stmt) {
                        throw new Exception("Failed to prepare INSERT query: " . $db->error);
                    }
                    $stmt->bind_param(
                        'isssdsssii',
                        $donorIdNullable, $donorName, $donorPhone, $donorEmail,
                        $amount, $status, $final_notes, $client_uuid, $created_by_bind, $packageIdNullable
                    );
                } elseif ($has_donor_id_column && !$has_donor_email_column) {
                    $stmt = $db->prepare("
                        INSERT INTO pledges (
                          donor_id, donor_name, donor_phone, source, anonymous,
                          amount, type, status, notes, client_uuid, created_by_user_id, package_id
                        ) VALUES (?, ?, ?, 'self', 0, ?, 'pledge', ?, ?, ?, ?, ?)
                    ");
                    if (!$stmt) {
                        throw new Exception("Failed to prepare INSERT query: " . $db->error);
                    }
                    $stmt->bind_param(
                        'issdsssii',
                        $donorIdNullable, $donorName, $donorPhone,
                        $amount, $status, $final_notes, $client_uuid, $created_by_bind, $packageIdNullable
                    );
                } elseif (!$has_donor_id_column && $has_donor_email_column) {
                    $stmt = $db->prepare("
                        INSERT INTO pledges (
                          donor_name, donor_phone, donor_email, source, anonymous,
                          amount, type, status, notes, client_uuid, created_by_user_id, package_id
                        ) VALUES (?, ?, ?, 'self', 0, ?, 'pledge', ?, ?, ?, ?, ?)
                    ");
                    if (!$stmt) {
                        throw new Exception("Failed to prepare INSERT query: " . $db->error);
                    }
                    $stmt->bind_param(
                        'sssdsssii',
                        $donorName, $donorPhone, $donorEmail,
                        $amount, $status, $final_notes, $client_uuid, $created_by_bind, $packageIdNullable
                    );
                } else {
                    // Neither column exists (match registrar pattern)
                    $stmt = $db->prepare("
                        INSERT INTO pledges (
                          donor_name, donor_phone, source, anonymous,
                          amount, type, status, notes, client_uuid, created_by_user_id, package_id
                        ) VALUES (?, ?, 'self', 0, ?, 'pledge', ?, ?, ?, ?, ?)
                    ");
                    if (!$stmt) {
                        throw new Exception("Failed to prepare INSERT query: " . $db->error);
                    }
                    $stmt->bind_param(
                        'ssdsssii',
                        $donorName, $donorPhone,
                        $amount, $status, $final_notes, $client_uuid, $created_by_bind, $packageIdNullable
                    );
                }
            
                error_log("Donor pledge update: Executing INSERT statement...");
                if (!$stmt->execute()) {
                    $error_msg = $stmt->error ?: $db->error ?: 'Unknown SQL error';
                    error_log("Donor pledge update: INSERT failed - " . $error_msg);
                    $stmt->close();
                    throw new Exception('Failed to create pledge request: ' . $error_msg);
                }
                if ($stmt->affected_rows === 0) { 
                    error_log("Donor pledge update: INSERT succeeded but no rows affected!");
                    $stmt->close();
                    throw new Exception('Failed to create pledge request (no rows affected).'); 
                }
                $entityId = $db->insert_id;
                error_log("Donor pledge update: INSERT successful, new pledge ID: $entityId");
                $stmt->close();

                // Create allocation batch record for tracking
                $batchTracker = new GridAllocationBatchTracker($db);
                $donorId = (int)($donor['id'] ?? 0);
                $donorIdNullable = $donorId > 0 ? $donorId : null;
                
                // Find original approved pledge for this donor
                $originalPledgeId = null;
                $originalAmount = 0.00;
                if ($donorIdNullable) {
                    $findOriginal = $db->prepare("
                        SELECT id, amount 
                        FROM pledges 
                        WHERE donor_id = ? AND status = 'approved' AND type = 'pledge' 
                        ORDER BY approved_at DESC, id DESC 
                        LIMIT 1
                    ");
                    $findOriginal->bind_param('i', $donorIdNullable);
                    $findOriginal->execute();
                    $originalPledge = $findOriginal->get_result()->fetch_assoc();
                    $findOriginal->close();
                    if ($originalPledge) {
                        $originalPledgeId = (int)$originalPledge['id'];
                        $originalAmount = (float)$originalPledge['amount'];
                    }
                }
                
                // Create batch record
                $batchData = [
                    'batch_type' => $originalPledgeId ? 'pledge_update' : 'new_pledge',
                    'request_type' => 'donor_portal',
                    'original_pledge_id' => $originalPledgeId,
                    'new_pledge_id' => $entityId,
                    'donor_id' => $donorIdNullable,
                    'donor_name' => $donorName,
                    'donor_phone' => $normalized_phone,
                    'original_amount' => $originalAmount,
                    'additional_amount' => $amount,
                    'total_amount' => $originalAmount + $amount,
                    'requested_by_donor_id' => $donorIdNullable,
                    'request_source' => 'self',
                    'package_id' => $packageIdNullable,
                    'metadata' => [
                        'client_uuid' => $client_uuid,
                        'notes' => $final_notes
                    ]
                ];
                $batchId = $batchTracker->createBatch($batchData);
                if ($batchId) {
                    error_log("Donor pledge update: Created allocation batch #{$batchId}");
                } else {
                    error_log("Donor pledge update: WARNING - Failed to create allocation batch");
                }

                // Audit log
                error_log("Donor pledge update: Creating audit log...");
                $afterJson = json_encode([
                    'amount'=>$amount,
                    'type'=>'pledge',
                    'donor'=>$donorName,
                    'status'=>'pending',
                    'source'=>'donor_portal',
                    'current_total_pledged'=>$donor['total_pledged'] ?? 0
                ]);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(0, 'pledge', ?, 'create_pending', ?, 'donor_portal')");
                if (!$log) {
                    throw new Exception("Failed to prepare audit log query: " . $db->error);
                }
                $log->bind_param('is', $entityId, $afterJson);
                if (!$log->execute()) {
                    error_log("Donor pledge update: Audit log failed but continuing: " . $log->error);
                    // Don't fail the whole transaction if audit log fails
                }
                $log->close();

                error_log("Donor pledge update: Committing transaction...");
                $db->commit();
                $db->autocommit(true);
                
                $_SESSION['success_message'] = "Your pledge increase request for {$currency} " . number_format($amount, 2) . " has been submitted for approval!";
                error_log("Donor pledge update: Success! Redirecting...");
                header('Location: update-pledge.php');
                exit;
            } catch (mysqli_sql_exception $e) {
                if (isset($db) && $db instanceof mysqli) {
                    $db->rollback();
                    $db->autocommit(true);
                }
                $error_msg = $e->getMessage() . " | SQL Error: " . (isset($db) && $db instanceof mysqli ? ($db->error ?? 'N/A') : 'DB not available') . " | Line: " . $e->getLine();
                error_log("Donor pledge update SQL error: " . $error_msg);
                $error = 'Database error: ' . htmlspecialchars($e->getMessage()) . (isset($db) && $db instanceof mysqli && $db->error ? ' | SQL: ' . htmlspecialchars($db->error) : '');
            } catch (Exception $e) {
                if (isset($db) && $db instanceof mysqli) {
                    $db->rollback();
                    $db->autocommit(true);
                }
                $error_msg = $e->getMessage() . " on line " . $e->getLine() . " | File: " . $e->getFile();
                error_log("Donor pledge update error: " . $error_msg);
                $error = 'Error saving request: ' . htmlspecialchars($e->getMessage()) . ' (Line: ' . $e->getLine() . ')';
            }
        }
    }
}

// Check for success message from redirect
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($donor['preferred_language'] ?? 'en'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Donor Portal</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/donor.css?v=<?php echo @filemtime(__DIR__ . '/assets/donor.css'); ?>">
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="app-content">
        <?php include 'includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-handshake me-2"></i>Update Pledge Amount
                </h1>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- Current Pledge Info -->
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title">
                                <i class="fas fa-info-circle text-primary"></i>Current Pledge
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label text-muted">Total Pledged</label>
                                <p class="mb-0"><strong class="fs-4">£<?php echo number_format($donor['total_pledged'] ?? 0, 2); ?></strong></p>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted">Total Paid</label>
                                <p class="mb-0"><strong>£<?php echo number_format($donor['total_paid'] ?? 0, 2); ?></strong></p>
                            </div>
                            <div class="mb-0">
                                <label class="form-label text-muted">Remaining Balance</label>
                                <p class="mb-0"><strong>£<?php echo number_format($donor['balance'] ?? 0, 2); ?></strong></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Update Form -->
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title">
                                <i class="fas fa-plus-circle text-primary"></i>Request Pledge Increase
                            </h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-4">
                                <i class="fas fa-info-circle me-2"></i>
                                Select an additional amount to add to your current pledge. Your request will be reviewed by an administrator before approval.
                            </p>

                            <form method="POST" class="needs-validation" novalidate>
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="client_uuid" value="">
                                
                                <!-- Amount Selection -->
                                <div class="mb-4">
                                    <label class="form-label fw-bold">
                                        <i class="fas fa-pound-sign me-2"></i>Select Additional Amount <span class="text-danger">*</span>
                                    </label>
                                    
                                    <div class="quick-amounts" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; margin-bottom: 1.5rem;">
                                        <?php if ($pkgOne): ?>
                                        <label class="quick-amount-btn" data-pack="1">
                                            <input type="radio" name="pack" value="1" class="d-none" required>
                                            <span class="quick-amount-value"><?php echo $currency; ?> <?php echo number_format((float)$pkgOne['price'], 0); ?></span>
                                            <span class="quick-amount-label">1 Square Meter</span>
                                            <i class="fas fa-check-circle checkmark"></i>
                                        </label>
                                        <?php endif; ?>
                                        
                                        <?php if ($pkgHalf): ?>
                                        <label class="quick-amount-btn" data-pack="0.5">
                                            <input type="radio" name="pack" value="0.5" class="d-none" required>
                                            <span class="quick-amount-value"><?php echo $currency; ?> <?php echo number_format((float)$pkgHalf['price'], 0); ?></span>
                                            <span class="quick-amount-label">½ Square Meter</span>
                                            <i class="fas fa-check-circle checkmark"></i>
                                        </label>
                                        <?php endif; ?>
                                        
                                        <?php if ($pkgQuarter): ?>
                                        <label class="quick-amount-btn" data-pack="0.25">
                                            <input type="radio" name="pack" value="0.25" class="d-none" required>
                                            <span class="quick-amount-value"><?php echo $currency; ?> <?php echo number_format((float)$pkgQuarter['price'], 0); ?></span>
                                            <span class="quick-amount-label">¼ Square Meter</span>
                                            <i class="fas fa-check-circle checkmark"></i>
                                        </label>
                                        <?php endif; ?>
                                        
                                        <label class="quick-amount-btn" data-pack="custom">
                                            <input type="radio" name="pack" value="custom" class="d-none" required>
                                            <span class="quick-amount-value">Custom</span>
                                            <span class="quick-amount-label">Enter Amount</span>
                                            <i class="fas fa-check-circle checkmark"></i>
                                        </label>
                                    </div>
                                    
                                    <style>
                                    .quick-amount-btn {
                                        padding: 1rem;
                                        border: 2px solid #dee2e6;
                                        border-radius: 12px;
                                        background: white;
                                        cursor: pointer;
                                        transition: all 0.3s ease;
                                        text-align: center;
                                        position: relative;
                                        color: #333;
                                    }
                                    .quick-amount-btn:hover {
                                        border-color: #0a6286;
                                        background: #f0f8ff;
                                        transform: translateY(-2px);
                                        box-shadow: 0 2px 8px rgba(10, 98, 134, 0.15);
                                    }
                                    .quick-amount-btn.active {
                                        border-color: #0a6286 !important;
                                        border-width: 3px !important;
                                        background: linear-gradient(135deg, #0a6286 0%, #0d7ba8 100%) !important;
                                        color: white !important;
                                        box-shadow: 0 6px 20px rgba(10, 98, 134, 0.4) !important;
                                        transform: translateY(-3px) scale(1.02);
                                        animation: selectedPulse 0.5s ease-out;
                                    }
                                    @keyframes selectedPulse {
                                        0% {
                                            transform: translateY(-3px) scale(1);
                                            box-shadow: 0 4px 12px rgba(10, 98, 134, 0.3);
                                        }
                                        50% {
                                            transform: translateY(-3px) scale(1.05);
                                            box-shadow: 0 8px 24px rgba(10, 98, 134, 0.5);
                                        }
                                        100% {
                                            transform: translateY(-3px) scale(1.02);
                                            box-shadow: 0 6px 20px rgba(10, 98, 134, 0.4);
                                        }
                                    }
                                    .quick-amount-btn.active .quick-amount-value {
                                        color: white !important;
                                        font-size: 1.35rem;
                                        font-weight: 800;
                                    }
                                    .quick-amount-btn.active .quick-amount-label {
                                        color: rgba(255, 255, 255, 0.95) !important;
                                        font-weight: 600;
                                    }
                                    .quick-amount-btn.active .checkmark {
                                        opacity: 1 !important;
                                        transform: scale(1.2) !important;
                                        color: white !important;
                                        animation: checkmarkPop 0.4s ease-out 0.1s both;
                                    }
                                    @keyframes checkmarkPop {
                                        0% {
                                            transform: scale(0.5);
                                            opacity: 0;
                                        }
                                        50% {
                                            transform: scale(1.4);
                                        }
                                        100% {
                                            transform: scale(1.2);
                                            opacity: 1;
                                        }
                                    }
                                    .quick-amount-value {
                                        font-size: 1.25rem;
                                        font-weight: 700;
                                        display: block;
                                        margin-bottom: 0.25rem;
                                        transition: all 0.3s ease;
                                    }
                                    .quick-amount-label {
                                        font-size: 0.75rem;
                                        opacity: 0.8;
                                        transition: all 0.3s ease;
                                    }
                                    .checkmark {
                                        position: absolute;
                                        top: 8px;
                                        right: 8px;
                                        font-size: 1.2rem;
                                        color: #0a6286;
                                        opacity: 0;
                                        transform: scale(0.5);
                                        transition: all 0.3s ease;
                                    }
                                    @media (min-width: 768px) {
                                        .quick-amounts {
                                            grid-template-columns: repeat(4, 1fr) !important;
                                        }
                                    }
                                    </style>
                                    
                                    <div class="mb-3 d-none mt-3" id="customAmountDiv">
                                        <label for="custom_amount" class="form-label">Custom Amount</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><?php echo $currency; ?></span>
                                            <input type="number" class="form-control" id="custom_amount" name="custom_amount" 
                                                   min="1" step="0.01" placeholder="0.00">
                                        </div>
                                    </div>
                                </div>

                                <!-- Optional Notes -->
                                <div class="mb-4">
                                    <label for="notes" class="form-label">
                                        <i class="fas fa-sticky-note me-2"></i>Optional Notes
                                    </label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3" 
                                              placeholder="Any additional information about your pledge increase..."></textarea>
                                    <div class="form-text">This information will be visible to administrators when reviewing your request.</div>
                                </div>

                                <!-- Submit Button -->
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-paper-plane me-2"></i>Submit Request for Approval
                                    </button>
                                    <a href="<?php echo htmlspecialchars(url_for('donor/index.php')); ?>" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/donor.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Generate UUID for form
    const uuidv4 = () => {
        if (self.crypto && typeof self.crypto.randomUUID === 'function') {
            try { return self.crypto.randomUUID(); } catch (e) {}
        }
        const bytes = new Uint8Array(16);
        if (self.crypto && self.crypto.getRandomValues) {
            self.crypto.getRandomValues(bytes);
        } else {
            for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
        }
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const toHex = (n) => n.toString(16).padStart(2, '0');
        const b = Array.from(bytes, toHex);
        return `${b[0]}${b[1]}${b[2]}${b[3]}-${b[4]}${b[5]}-${b[6]}${b[7]}-${b[8]}${b[9]}-${b[10]}${b[11]}${b[12]}${b[13]}${b[14]}${b[15]}`;
    };
    
    const uuidInput = document.querySelector('input[name="client_uuid"]');
    if (uuidInput) {
        uuidInput.value = uuidv4();
    }

    // Quick amount selection
    document.querySelectorAll('.quick-amount-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.quick-amount-btn').forEach(b => {
                b.classList.remove('active');
            });
            this.classList.add('active');
            
            const pack = this.dataset.pack;
            const radio = this.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
            
            if (pack === 'custom') {
                document.getElementById('customAmountDiv').classList.remove('d-none');
                document.getElementById('custom_amount').focus();
                document.getElementById('custom_amount').required = true;
            } else {
                document.getElementById('customAmountDiv').classList.add('d-none');
                document.getElementById('custom_amount').required = false;
                document.getElementById('custom_amount').value = '';
            }
        });
    });

    // Form validation
    const form = document.querySelector('.needs-validation');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    }

    // Auto-dismiss success message
    const successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() {
            const bsAlert = new bootstrap.Alert(successAlert);
            bsAlert.close();
        }, 5000);
    }
});
</script>
</body>
</html>

