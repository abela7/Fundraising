<?php
/**
 * Donor Portal - Profile
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../shared/url.php';
require_once __DIR__ . '/../shared/audit_helper.php';
require_once __DIR__ . '/../admin/includes/resilient_db_loader.php';

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

// Refresh donor data from database to ensure latest values
if ($donor && $db_connection_ok) {
    try {
        // Simply fetch all columns - this ensures we get baptism_name, church_id etc. if they exist
        // without needing to check for each one individually for the SELECT statement
        $refresh_stmt = $db->prepare("SELECT * FROM donors WHERE id = ? LIMIT 1");
        $refresh_stmt->bind_param('i', $donor['id']);
        $refresh_stmt->execute();
        $fresh_donor = $refresh_stmt->get_result()->fetch_assoc();
        $refresh_stmt->close();
        
        if ($fresh_donor) {
            $_SESSION['donor'] = $fresh_donor;
            $donor = $fresh_donor;
        }
    } catch (Exception $e) {
        error_log("Failed to refresh donor session: " . $e->getMessage());
    }
}

$page_title = 'Preferences';
$current_donor = $donor;

$success_message = '';
$error_message = '';

// Fetch Church and Representative Info if assigned
$assigned_church = null;
$assigned_rep = null;

if ($db_connection_ok && !empty($donor['church_id'])) {
    try {
        // Get Church
        $church_stmt = $db->prepare("SELECT name, city FROM churches WHERE id = ?");
        $church_stmt->bind_param('i', $donor['church_id']);
        $church_stmt->execute();
        $assigned_church = $church_stmt->get_result()->fetch_assoc();
        $church_stmt->close();
        
        // Get Representative
        if (!empty($donor['representative_id'])) {
            $rep_stmt = $db->prepare("SELECT name, role, phone, email FROM church_representatives WHERE id = ?");
            $rep_stmt->bind_param('i', $donor['representative_id']);
            $rep_stmt->execute();
            $assigned_rep = $rep_stmt->get_result()->fetch_assoc();
            $rep_stmt->close();
        }
    } catch (Exception $e) {
        // Ignore
    }
}

// Handle profile update (name, baptism_name, phone, email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    verify_csrf();
    
    $name = trim($_POST['name'] ?? '');
    $baptism_name = trim($_POST['baptism_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    // Validation
    if (empty($name)) {
        $error_message = 'Name is required.';
    } elseif (strlen($name) < 2) {
        $error_message = 'Name must be at least 2 characters.';
    } elseif (empty($phone)) {
        $error_message = 'Phone number is required.';
    } else {
        // Normalize phone number (remove all non-digits, handle +44)
        $username = preg_replace('/[^0-9]/', '', $phone);
        if (substr($username, 0, 2) === '44') {
            $username = '0' . substr($username, 2);
        }
        
        // Validate UK mobile format
        if (strlen($username) !== 11 || substr($username, 0, 2) !== '07') {
            $error_message = 'Please enter a valid UK mobile number (e.g., 07123456789).';
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = 'Please enter a valid email address.';
        } else {
            // Update profile
            if ($db_connection_ok) {
                try {
                    // Start transaction by disabling autocommit
                    $db->autocommit(false);
                    
                    // Check columns existence
                    $check_email = $db->query("SHOW COLUMNS FROM donors LIKE 'email'");
                    $has_email_column = $check_email->num_rows > 0;
                    $check_email->close();
                    
                    $check_baptism = $db->query("SHOW COLUMNS FROM donors LIKE 'baptism_name'");
                    $has_baptism_column = $check_baptism->num_rows > 0;
                    $check_baptism->close();
                    
                    // Normalize inputs
                    $email_normalized = !empty($email) ? trim($email) : null;
                    $baptism_normalized = !empty($baptism_name) ? trim($baptism_name) : null;
                    
                    // Build UPDATE query dynamically
                    $update_fields = ["name = ?", "phone = ?", "updated_at = NOW()"];
                    $update_types = "ss";
                    $update_params = [$name, $username];
                    
                    if ($has_email_column) {
                        $update_fields[] = "email = ?";
                        $update_types .= "s";
                        $update_params[] = $email_normalized;
                    }
                    
                    if ($has_baptism_column) {
                        $update_fields[] = "baptism_name = ?";
                        $update_types .= "s";
                        $update_params[] = $baptism_normalized;
                    }
                    
                    // Add ID to params
                    $update_types .= "i";
                    $update_params[] = $donor['id'];
                    
                    $sql = "UPDATE donors SET " . implode(", ", $update_fields) . " WHERE id = ?";
                    $update_stmt = $db->prepare($sql);
                    $update_stmt->bind_param($update_types, ...$update_params);
                    
                    if (!$update_stmt->execute()) {
                        throw new RuntimeException('Failed to update donor profile: ' . $update_stmt->error);
                    }
                    $update_stmt->close();
                    
                    // Audit log
                    $beforeData = [
                        'name' => $donor['name'],
                        'phone' => $donor['phone']
                    ];
                    if ($has_email_column) $beforeData['email'] = $donor['email'] ?? null;
                    if ($has_baptism_column) $beforeData['baptism_name'] = $donor['baptism_name'] ?? null;
                    
                    $afterData = [
                        'name' => $name,
                        'phone' => $username
                    ];
                    if ($has_email_column) $afterData['email'] = $email_normalized;
                    if ($has_baptism_column) $afterData['baptism_name'] = $baptism_normalized;
                    
                    log_audit(
                        $db,
                        'update',
                        'donor',
                        $donor['id'],
                        $beforeData,
                        $afterData,
                        'donor_portal',
                        0
                    );
                    
                    $db->commit();
                    $db->autocommit(true);
                    
                    // Refresh donor data again to update session
                    $refresh_stmt = $db->prepare("SELECT * FROM donors WHERE id = ? LIMIT 1");
                    $refresh_stmt->bind_param('i', $donor['id']);
                    $refresh_stmt->execute();
                    $updated_donor = $refresh_stmt->get_result()->fetch_assoc();
                    $refresh_stmt->close();
                    
                    if ($updated_donor) {
                        $_SESSION['donor'] = $updated_donor;
                        $donor = $updated_donor;
                    }
                    
                    $success_message = 'Profile updated successfully!';
                } catch (Exception $e) {
                    $db->rollback();
                    $db->autocommit(true);
                    error_log('Profile update error: ' . $e->getMessage());
                    $error_message = 'An error occurred while updating your profile. Please try again.';
                }
            }
        }
    }
}

// Handle preferences update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_preferences') {
    verify_csrf();
    
    $preferred_language = trim($_POST['preferred_language'] ?? 'en');
    $preferred_payment_method = trim($_POST['preferred_payment_method'] ?? 'bank_transfer');
    $preferred_payment_day = (int)($_POST['preferred_payment_day'] ?? 1);
    $sms_opt_in = isset($_POST['sms_opt_in']) ? 1 : 0;
    $email_opt_in = isset($_POST['email_opt_in']) ? 1 : 0;
    
    if (!in_array($preferred_language, ['en', 'am', 'ti'])) {
        $error_message = 'Invalid language selection.';
    } elseif (!in_array($preferred_payment_method, ['cash', 'bank_transfer', 'card'])) {
        $error_message = 'Invalid payment method selection.';
    } elseif ($preferred_payment_day < 1 || $preferred_payment_day > 28) {
        $error_message = 'Preferred payment day must be between 1 and 28.';
    } else {
        // Update preferences
        if ($db_connection_ok) {
            try {
                $db->autocommit(false);
                
                $check_email_opt_in = $db->query("SHOW COLUMNS FROM donors LIKE 'email_opt_in'");
                $has_email_opt_in_column = $check_email_opt_in->num_rows > 0;
                $check_email_opt_in->close();
                
                if ($has_email_opt_in_column) {
                    $update_stmt = $db->prepare("UPDATE donors SET preferred_language=?, preferred_payment_method=?, preferred_payment_day=?, sms_opt_in=?, email_opt_in=?, updated_at=NOW() WHERE id=?");
                    $update_stmt->bind_param('ssiiii', $preferred_language, $preferred_payment_method, $preferred_payment_day, $sms_opt_in, $email_opt_in, $donor['id']);
                } else {
                    $update_stmt = $db->prepare("UPDATE donors SET preferred_language=?, preferred_payment_method=?, preferred_payment_day=?, sms_opt_in=?, updated_at=NOW() WHERE id=?");
                    $update_stmt->bind_param('ssiii', $preferred_language, $preferred_payment_method, $preferred_payment_day, $sms_opt_in, $donor['id']);
                }
                
                if (!$update_stmt->execute()) throw new RuntimeException($update_stmt->error);
                $update_stmt->close();
                
                // Audit log preferences update
                $beforeData = [
                    'preferred_language' => $donor['preferred_language'] ?? 'en',
                    'preferred_payment_method' => $donor['preferred_payment_method'] ?? 'bank_transfer',
                    'preferred_payment_day' => $donor['preferred_payment_day'] ?? 1,
                    'sms_opt_in' => $donor['sms_opt_in'] ?? 1
                ];
                if ($has_email_opt_in_column) {
                    $beforeData['email_opt_in'] = $donor['email_opt_in'] ?? 1;
                }
                
                $afterData = [
                    'preferred_language' => $preferred_language,
                    'preferred_payment_method' => $preferred_payment_method,
                    'preferred_payment_day' => $preferred_payment_day,
                    'sms_opt_in' => $sms_opt_in
                ];
                if ($has_email_opt_in_column) {
                    $afterData['email_opt_in'] = $email_opt_in;
                }
                
                log_audit(
                    $db,
                    'update',
                    'donor',
                    $donor['id'],
                    $beforeData,
                    $afterData,
                    'donor_portal',
                    0
                );
                
                $db->commit();
                $db->autocommit(true);
                
                // Refresh
                $refresh_stmt = $db->prepare("SELECT * FROM donors WHERE id = ? LIMIT 1");
                $refresh_stmt->bind_param('i', $donor['id']);
                $refresh_stmt->execute();
                $updated_donor = $refresh_stmt->get_result()->fetch_assoc();
                $refresh_stmt->close();
                
                if ($updated_donor) {
                    $_SESSION['donor'] = $updated_donor;
                    $donor = $updated_donor;
                }
                
                $success_message = 'Preferences updated successfully!';
            } catch (Exception $e) {
                $db->rollback();
                $db->autocommit(true);
                error_log('Preferences update error: ' . $e->getMessage());
                $error_message = 'Error updating preferences. Please try again.';
            }
        }
    }
}

// Check optional columns for UI display
$has_email_column = false;
$has_email_opt_in_column = false;
$has_baptism_column = false;

if ($db_connection_ok) {
    try {
        $check_email = $db->query("SHOW COLUMNS FROM donors LIKE 'email'");
        $has_email_column = $check_email->num_rows > 0;
        $check_email->close();
        
        $check_email_opt_in = $db->query("SHOW COLUMNS FROM donors LIKE 'email_opt_in'");
        $has_email_opt_in_column = $check_email_opt_in->num_rows > 0;
        $check_email_opt_in->close();
        
        $check_baptism = $db->query("SHOW COLUMNS FROM donors LIKE 'baptism_name'");
        $has_baptism_column = $check_baptism->num_rows > 0;
        $check_baptism->close();
    } catch (Exception $e) {}
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
                    <h1 class="page-title">My Profile</h1>
                </div>

                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <!-- Profile Information Form -->
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fas fa-user-edit text-primary"></i>Personal Information
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="profileForm" class="mb-4">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="update_profile">
                                    
                                    <div class="row">
                                    <!-- Name -->
                                        <div class="col-md-6 mb-4">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-user me-2"></i>Full Name <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" 
                                               class="form-control" 
                                               name="name" 
                                               value="<?php echo htmlspecialchars($donor['name']); ?>"
                                               required
                                               minlength="2"
                                               placeholder="Enter your full name">
                                        </div>

                                        <!-- Baptism Name (if column exists) -->
                                        <?php if ($has_baptism_column): ?>
                                        <div class="col-md-6 mb-4">
                                            <label class="form-label fw-bold">
                                                <i class="fas fa-water me-2"></i>Baptism Name
                                            </label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   name="baptism_name" 
                                                   value="<?php echo htmlspecialchars($donor['baptism_name'] ?? ''); ?>"
                                                   placeholder="Enter baptism name">
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="row">
                                    <!-- Phone -->
                                        <div class="col-md-6 mb-4">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-phone me-2"></i>Phone Number <span class="text-danger">*</span>
                                        </label>
                                        <input type="tel" 
                                               class="form-control" 
                                               name="phone" 
                                               value="<?php echo htmlspecialchars($donor['phone']); ?>"
                                               required
                                               pattern="[0-9+\-\s\(\)]+"
                                               placeholder="07123456789">
                                        <div class="form-text">UK mobile number (e.g., 07123456789)</div>
                                    </div>

                                    <!-- Email (if column exists) -->
                                    <?php if ($has_email_column): ?>
                                        <div class="col-md-6 mb-4">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-envelope me-2"></i>Email Address
                                        </label>
                                        <input type="email" 
                                               class="form-control" 
                                               name="email" 
                                               value="<?php echo htmlspecialchars($donor['email'] ?? ''); ?>"
                                               placeholder="your.email@example.com">
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Submit Button -->
                                    <div class="d-flex justify-content-end">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Save Profile
                                        </button>
                                    </div>
                                </form>

                                <hr class="my-4">

                                <!-- Preferences Form -->
                                <h5 class="mb-3">
                                    <i class="fas fa-cog text-primary"></i>Preferences
                                </h5>
                                <form method="POST" id="preferencesForm">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="update_preferences">
                                    
                                    <div class="row">
                                    <!-- Language Preference -->
                                        <div class="col-md-6 mb-4">
                                        <label class="form-label fw-bold">
                                                <i class="fas fa-language me-2"></i>Language <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" name="preferred_language" required>
                                            <option value="en" <?php echo ($donor['preferred_language'] ?? 'en') === 'en' ? 'selected' : ''; ?>>
                                                🇬🇧 English
                                            </option>
                                            <option value="am" <?php echo ($donor['preferred_language'] ?? 'en') === 'am' ? 'selected' : ''; ?>>
                                                🇪🇹 Amharic
                                            </option>
                                            <option value="ti" <?php echo ($donor['preferred_language'] ?? 'en') === 'ti' ? 'selected' : ''; ?>>
                                                🇪🇷 Tigrinya
                                            </option>
                                        </select>
                                    </div>

                                    <!-- Preferred Payment Method -->
                                        <div class="col-md-6 mb-4">
                                        <label class="form-label fw-bold">
                                                <i class="fas fa-credit-card me-2"></i>Payment Method <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" name="preferred_payment_method" required>
                                            <option value="bank_transfer" <?php echo ($donor['preferred_payment_method'] ?? 'bank_transfer') === 'bank_transfer' ? 'selected' : ''; ?>>
                                                Bank Transfer
                                            </option>
                                            <option value="cash" <?php echo ($donor['preferred_payment_method'] ?? 'bank_transfer') === 'cash' ? 'selected' : ''; ?>>
                                                Cash
                                            </option>
                                            <option value="card" <?php echo ($donor['preferred_payment_method'] ?? 'bank_transfer') === 'card' ? 'selected' : ''; ?>>
                                                Card
                                            </option>
                                        </select>
                                        </div>
                                    </div>

                                    <div class="row">
                                    <!-- Preferred Payment Day -->
                                        <div class="col-md-6 mb-4">
                                        <label class="form-label fw-bold">
                                                <i class="fas fa-calendar-day me-2"></i>Payment Day <span class="text-danger">*</span>
                                        </label>
                                        <input type="number" 
                                               class="form-control" 
                                               name="preferred_payment_day" 
                                               value="<?php echo $donor['preferred_payment_day'] ?? 1; ?>"
                                               min="1" 
                                               max="28" 
                                               required>
                                            <div class="form-text">Day of month (1-28)</div>
                                        </div>
                                    </div>

                                    <div class="row">
                                    <!-- SMS Opt-in -->
                                        <div class="col-md-6 mb-4">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" 
                                                   type="checkbox" 
                                                   name="sms_opt_in" 
                                                   id="sms_opt_in"
                                                   <?php echo ($donor['sms_opt_in'] ?? 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label fw-bold" for="sms_opt_in">
                                                <i class="fas fa-sms me-2"></i>Enable SMS Reminders
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Email Opt-in (if column exists) -->
                                    <?php if ($has_email_opt_in_column): ?>
                                        <div class="col-md-6 mb-4">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" 
                                                   type="checkbox" 
                                                   name="email_opt_in" 
                                                   id="email_opt_in"
                                                   <?php echo ($donor['email_opt_in'] ?? 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label fw-bold" for="email_opt_in">
                                                <i class="fas fa-envelope me-2"></i>Enable Email Notifications
                                            </label>
                                        </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Submit Button -->
                                    <div class="d-flex justify-content-end">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Save Preferences
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar Column -->
                    <div class="col-lg-4">
                        <!-- Account Summary -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fas fa-info-circle text-primary"></i>Account Summary
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label text-muted">Total Pledged</label>
                                    <p class="mb-0"><strong>£<?php echo number_format($donor['total_pledged'] ?? 0, 2); ?></strong></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted">Total Paid</label>
                                    <p class="mb-0"><strong>£<?php echo number_format($donor['total_paid'] ?? 0, 2); ?></strong></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted">Remaining Balance</label>
                                    <p class="mb-0"><strong>£<?php echo number_format($donor['balance'] ?? 0, 2); ?></strong></p>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label text-muted">Account Status</label>
                                    <p class="mb-0">
                                        <span class="badge bg-success">Active</span>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Security Settings -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fas fa-shield-alt text-primary"></i>Security
                                </h5>
                            </div>
                            <div class="card-body">
                                <a href="trusted-devices.php" class="d-flex align-items-center text-decoration-none text-dark p-2 rounded hover-bg">
                                    <div class="me-3">
                                        <i class="fas fa-mobile-alt fa-lg text-primary"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <strong>Trusted Devices</strong>
                                        <p class="mb-0 small text-muted">Manage devices that can access your account</p>
                                    </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                </a>
                                <hr class="my-2">
                                <a href="logout.php?forget=1" class="d-flex align-items-center text-decoration-none text-danger p-2 rounded hover-bg" onclick="return confirm('This will log you out and require SMS verification on next login. Continue?');">
                                    <div class="me-3">
                                        <i class="fas fa-sign-out-alt fa-lg"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <strong>Sign Out Everywhere</strong>
                                        <p class="mb-0 small text-muted">Log out and forget this device</p>
                                    </div>
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </div>
                        </div>

                        <!-- Church Assignment (Read Only) -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fas fa-church text-primary"></i>Assigned Church
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if ($assigned_church): ?>
                                    <div class="mb-3">
                                        <label class="form-label text-muted">Church</label>
                                        <p class="mb-0 fw-bold"><?php echo htmlspecialchars($assigned_church['name']); ?></p>
                                        <small class="text-muted"><?php echo htmlspecialchars($assigned_church['city']); ?></small>
                                    </div>
                                    
                                    <?php if ($assigned_rep): ?>
                                    <div class="mb-3">
                                        <label class="form-label text-muted">Representative</label>
                                        <p class="mb-0 fw-bold"><?php echo htmlspecialchars($assigned_rep['name']); ?></p>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($assigned_rep['role']); ?></small>
                                        <?php if (!empty($assigned_rep['phone'])): ?>
                                            <small class="d-block mt-1">
                                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($assigned_rep['phone']); ?>
                                            </small>
                                        <?php endif; ?>
                                        <?php if (!empty($assigned_rep['email'])): ?>
                                            <small class="d-block">
                                                <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($assigned_rep['email']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-warning mb-0">
                                        <small><i class="fas fa-exclamation-circle me-1"></i>No representative assigned yet.</small>
                                    </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="text-center py-3 text-muted">
                                        <i class="fas fa-church fa-2x mb-2 opacity-25"></i>
                                        <p class="mb-0">Not assigned to a church yet.</p>
                                    </div>
                                <?php endif; ?>
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
// Phone number formatting for better UX
document.addEventListener('DOMContentLoaded', function() {
    const phoneInput = document.querySelector('input[name="phone"]');
    if (phoneInput) {
        phoneInput.addEventListener('input', function(e) {
            // Remove all non-digits
            let value = e.target.value.replace(/\D/g, '');
            
            // Handle +44 or 44 prefix
            if (value.startsWith('44') && value.length === 12) {
                value = '0' + value.substring(2);
            }
            
            // Format: 07XXX XXX XXX (if UK format)
            if (value.length > 2 && value.startsWith('07')) {
                if (value.length <= 5) {
                    e.target.value = value;
                } else if (value.length <= 8) {
                    e.target.value = value.substring(0, 5) + ' ' + value.substring(5);
                } else {
                    e.target.value = value.substring(0, 5) + ' ' + value.substring(5, 8) + ' ' + value.substring(8, 11);
                }
            } else {
                e.target.value = value;
            }
        });
        
        // Normalize before form submission
        const profileForm = document.getElementById('profileForm');
        if (profileForm) {
            profileForm.addEventListener('submit', function(e) {
                if (phoneInput.value) {
                    // Remove all non-digits before submit
                    let normalized = phoneInput.value.replace(/\D/g, '');
                    if (normalized.startsWith('44') && normalized.length === 12) {
                        normalized = '0' + normalized.substring(2);
                    }
                    phoneInput.value = normalized;
                }
            });
        }
    }
    
    // Show success message fade out after 5 seconds
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
