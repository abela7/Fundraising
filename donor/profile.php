<?php
/**
 * Donor Portal - Profile
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../shared/url.php';
require_once __DIR__ . '/../shared/security.php';
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
$page_title = 'Preferences';
$current_donor = $donor;

$success_message = '';
$error_message = '';

// Handle profile update (name, phone, email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    // Security: Verify CSRF token
    if (!validate_csrf()) {
        http_response_code(403);
        die('Invalid security token. Please refresh the page and try again.');
    }
    
    // Security: Rate limiting
    $rate_limit_key = 'profile_update_' . get_client_ip() . '_' . ($donor['id'] ?? '0');
    if (!check_rate_limit($rate_limit_key, 5, 300)) {
        $error_message = 'Too many requests. Please wait a few minutes before trying again.';
    } else {
        // Security: Sanitize inputs
        $name_raw = $_POST['name'] ?? '';
        $phone_raw = $_POST['phone'] ?? '';
        $email_raw = $_POST['email'] ?? '';
        
        // Security: Check for SQL injection patterns
        if (contains_sql_injection($name_raw) || contains_sql_injection($phone_raw) || contains_sql_injection($email_raw)) {
            error_log('SQL injection attempt detected from IP: ' . get_client_ip());
            $error_message = 'Invalid input detected. Please try again.';
        } else {
            // Sanitize name
            $name = sanitize_name($name_raw, 255);
            
            // Sanitize phone
            $phone_normalized = sanitize_phone($phone_raw);
            
            // Sanitize email
            $email = sanitize_email($email_raw, 255);
            
            // Validation
            if (empty($name)) {
                $error_message = 'Name is required.';
            } elseif (!validate_length($name, 2, 255)) {
                $error_message = 'Name must be between 2 and 255 characters.';
            } elseif (empty($phone_normalized)) {
                $error_message = 'Phone number is required.';
            } elseif (!validate_uk_mobile($phone_normalized)) {
                $error_message = 'Please enter a valid UK mobile number (e.g., 07123456789).';
            } elseif ($email !== null && !validate_email($email)) {
                $error_message = 'Please enter a valid email address.';
            } else {
                // Use sanitized values
                $username = $phone_normalized;
                
                // Update profile
                if ($db_connection_ok) {
                try {
                    // Start transaction by disabling autocommit
                    $db->autocommit(false);
                    
                    // Check if email column exists
                    $check_email = $db->query("SHOW COLUMNS FROM donors LIKE 'email'");
                    $has_email_column = $check_email->num_rows > 0;
                    $check_email->close();
                    
                    // Email is already sanitized and validated, ensure NULL if empty
                    $email_normalized = !empty($email) ? $email : null;
                    
                    // Build UPDATE query based on whether email column exists
                    if ($has_email_column) {
                        $update_stmt = $db->prepare("
                            UPDATE donors SET 
                                name = ?,
                                phone = ?,
                                email = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $update_stmt->bind_param('sssi', $name, $username, $email_normalized, $donor['id']);
                    } else {
                        $update_stmt = $db->prepare("
                            UPDATE donors SET 
                                name = ?,
                                phone = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $update_stmt->bind_param('ssi', $name, $username, $donor['id']);
                    }
                    if (!$update_stmt->execute()) {
                        throw new RuntimeException('Failed to update donor profile: ' . $update_stmt->error);
                    }
                    $update_stmt->close();
                    
                    // Audit log (wrap in try-catch to not fail profile update if audit fails)
                    try {
                        $audit_data = json_encode([
                            'donor_id' => (int)$donor['id'],
                            'name' => escape_html($name),
                            'phone' => escape_html($username),
                            'email' => $has_email_column && $email_normalized ? escape_html($email_normalized) : null,
                            'ip_address' => get_client_ip()
                        ], JSON_UNESCAPED_SLASHES);
                        $audit_stmt = $db->prepare("
                            INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) 
                            VALUES(?, 'donor', ?, 'update_profile', ?, 'donor_portal')
                        ");
                        if ($audit_stmt) {
                            $user_id = 0;
                            $audit_stmt->bind_param('iis', $user_id, $donor['id'], $audit_data);
                            if (!$audit_stmt->execute()) {
                                error_log('Audit log insert failed: ' . $audit_stmt->error);
                            }
                            $audit_stmt->close();
                        } else {
                            error_log('Audit log prepare failed: ' . $db->error);
                        }
                    } catch (Exception $audit_ex) {
                        // Log but don't fail the update
                        error_log('Audit log error (non-fatal): ' . $audit_ex->getMessage());
                    }
                    
                    $db->commit();
                    $db->autocommit(true); // Re-enable autocommit
                    
                    // Refresh donor data with all fields including email if column exists
                    $refresh_fields = "id, name, phone, total_pledged, total_paid, balance, 
                           has_active_plan, active_payment_plan_id, plan_monthly_amount,
                           plan_duration_months, plan_start_date, plan_next_due_date,
                           payment_status, preferred_payment_method, preferred_language,
                           preferred_payment_day, sms_opt_in";
                    if ($has_email_column) {
                        $refresh_fields .= ", email";
                    }
                    $refresh_stmt = $db->prepare("
                        SELECT $refresh_fields
                        FROM donors 
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $refresh_stmt->bind_param('i', $donor['id']);
                    $refresh_stmt->execute();
                    $refresh_result = $refresh_stmt->get_result();
                    $updated_donor = $refresh_result->fetch_assoc();
                    $refresh_stmt->close();
                    if ($updated_donor) {
                        $_SESSION['donor'] = $updated_donor;
                        $donor = $updated_donor;
                    }
                    
                    $success_message = 'Profile updated successfully!';
                } catch (mysqli_sql_exception $e) {
                    $db->rollback();
                    $db->autocommit(true); // Re-enable autocommit
                    error_log('Profile update SQL error: ' . $e->getMessage());
                    error_log('SQL State: ' . $e->getSqlState());
                    error_log('SQL Error Number: ' . $e->getCode());
                    $error_message = 'Database error: ' . escape_html($e->getMessage()) . '. Please try again.';
                } catch (Exception $e) {
                    $db->rollback();
                    $db->autocommit(true); // Re-enable autocommit
                    // Log the actual error for debugging
                    error_log('Profile update error: ' . $e->getMessage());
                    error_log('Profile update trace: ' . $e->getTraceAsString());
                    // Display detailed error message for debugging
                    $error_message = 'An error occurred while updating your profile: ' . escape_html($e->getMessage()) . '. Please try again.';
                }
            }
        }
    }
}

// Handle preferences update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_preferences') {
    // Security: Verify CSRF token
    if (!validate_csrf()) {
        http_response_code(403);
        die('Invalid security token. Please refresh the page and try again.');
    }
    
    // Security: Rate limiting
    $rate_limit_key = 'preferences_update_' . get_client_ip() . '_' . ($donor['id'] ?? '0');
    if (!check_rate_limit($rate_limit_key, 10, 300)) {
        $error_message = 'Too many requests. Please wait a few minutes before trying again.';
    } else {
        // Security: Sanitize and validate inputs
        $preferred_language_raw = $_POST['preferred_language'] ?? 'en';
        $preferred_payment_method_raw = $_POST['preferred_payment_method'] ?? 'bank_transfer';
        $preferred_payment_day_raw = $_POST['preferred_payment_day'] ?? 1;
        
        // Security: Check for SQL injection patterns
        if (contains_sql_injection($preferred_language_raw) || contains_sql_injection($preferred_payment_method_raw)) {
            error_log('SQL injection attempt detected from IP: ' . get_client_ip());
            $error_message = 'Invalid input detected. Please try again.';
        } else {
            // Sanitize enum values
            $preferred_language = sanitize_enum($preferred_language_raw, ['en', 'am', 'ti'], 'en');
            $preferred_payment_method = sanitize_enum($preferred_payment_method_raw, ['cash', 'bank_transfer', 'card'], 'bank_transfer');
            
            // Sanitize integer
            $preferred_payment_day = sanitize_int($preferred_payment_day_raw, 1, 28);
            
            // Sanitize boolean values
            $sms_opt_in = sanitize_bool($_POST['sms_opt_in'] ?? false) ? 1 : 0;
            $email_opt_in = sanitize_bool($_POST['email_opt_in'] ?? false) ? 1 : 0;
            
            if ($preferred_language === null) {
                $error_message = 'Invalid language selection.';
            } elseif ($preferred_payment_method === null) {
                $error_message = 'Invalid payment method selection.';
            } elseif ($preferred_payment_day === null) {
                $error_message = 'Preferred payment day must be between 1 and 28.';
            } else {
                // Update preferences
                if ($db_connection_ok) {
            try {
                // Start transaction by disabling autocommit
                $db->autocommit(false);
                
                // Check if email_opt_in column exists
                $check_email_opt_in = $db->query("SHOW COLUMNS FROM donors LIKE 'email_opt_in'");
                $has_email_opt_in_column = $check_email_opt_in->num_rows > 0;
                $check_email_opt_in->close();
                
                // Build UPDATE query based on whether email_opt_in column exists
                if ($has_email_opt_in_column) {
                    $update_stmt = $db->prepare("
                        UPDATE donors SET 
                            preferred_language = ?,
                            preferred_payment_method = ?,
                            preferred_payment_day = ?,
                            sms_opt_in = ?,
                            email_opt_in = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $update_stmt->bind_param(
                        'ssiiii',
                        $preferred_language,
                        $preferred_payment_method,
                        $preferred_payment_day,
                        $sms_opt_in,
                        $email_opt_in,
                        $donor['id']
                    );
                } else {
                    $update_stmt = $db->prepare("
                        UPDATE donors SET 
                            preferred_language = ?,
                            preferred_payment_method = ?,
                            preferred_payment_day = ?,
                            sms_opt_in = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $update_stmt->bind_param(
                        'ssiii',
                        $preferred_language,
                        $preferred_payment_method,
                        $preferred_payment_day,
                        $sms_opt_in,
                        $donor['id']
                    );
                }
                if (!$update_stmt->execute()) {
                    throw new RuntimeException('Failed to update preferences: ' . $update_stmt->error);
                }
                $update_stmt->close();
                
                    // Audit log (wrap in try-catch to not fail preferences update if audit fails)
                    try {
                        $audit_data = json_encode([
                            'donor_id' => (int)$donor['id'],
                            'preferred_language' => escape_html($preferred_language),
                            'preferred_payment_method' => escape_html($preferred_payment_method),
                            'preferred_payment_day' => (int)$preferred_payment_day,
                            'sms_opt_in' => (int)$sms_opt_in,
                            'email_opt_in' => $has_email_opt_in_column ? (int)$email_opt_in : null,
                            'ip_address' => get_client_ip()
                        ], JSON_UNESCAPED_SLASHES);
                    $audit_stmt = $db->prepare("
                        INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) 
                        VALUES(?, 'donor', ?, 'update_preferences', ?, 'donor_portal')
                    ");
                    if ($audit_stmt) {
                        $user_id = 0;
                        $audit_stmt->bind_param('iis', $user_id, $donor['id'], $audit_data);
                        if (!$audit_stmt->execute()) {
                            error_log('Audit log insert failed: ' . $audit_stmt->error);
                        }
                        $audit_stmt->close();
                    } else {
                        error_log('Audit log prepare failed: ' . $db->error);
                    }
                } catch (Exception $audit_ex) {
                    // Log but don't fail the update
                    error_log('Audit log error (non-fatal): ' . $audit_ex->getMessage());
                }
                
                $db->commit();
                $db->autocommit(true); // Re-enable autocommit
                
                // Refresh donor data with all fields including email and email_opt_in if columns exist
                $check_email = $db->query("SHOW COLUMNS FROM donors LIKE 'email'");
                $has_email_for_refresh = $check_email->num_rows > 0;
                $check_email->close();
                
                $check_email_opt_in_refresh = $db->query("SHOW COLUMNS FROM donors LIKE 'email_opt_in'");
                $has_email_opt_in_for_refresh = $check_email_opt_in_refresh->num_rows > 0;
                $check_email_opt_in_refresh->close();
                
                $refresh_fields = "id, name, phone, total_pledged, total_paid, balance, 
                           has_active_plan, active_payment_plan_id, plan_monthly_amount,
                           plan_duration_months, plan_start_date, plan_next_due_date,
                           payment_status, preferred_payment_method, preferred_language,
                           preferred_payment_day, sms_opt_in";
                if ($has_email_for_refresh) {
                    $refresh_fields .= ", email";
                }
                if ($has_email_opt_in_for_refresh) {
                    $refresh_fields .= ", email_opt_in";
                }
                $refresh_stmt = $db->prepare("
                    SELECT $refresh_fields
                    FROM donors 
                    WHERE id = ?
                    LIMIT 1
                ");
                $refresh_stmt->bind_param('i', $donor['id']);
                $refresh_stmt->execute();
                $refresh_result = $refresh_stmt->get_result();
                $updated_donor = $refresh_result->fetch_assoc();
                $refresh_stmt->close();
                if ($updated_donor) {
                    $_SESSION['donor'] = $updated_donor;
                    $donor = $updated_donor;
                }
                
                $success_message = 'Preferences updated successfully!';
            } catch (mysqli_sql_exception $e) {
                $db->rollback();
                $db->autocommit(true); // Re-enable autocommit
                error_log('Preferences update SQL error: ' . $e->getMessage());
                error_log('SQL State: ' . $e->getSqlState());
                error_log('SQL Error Number: ' . $e->getCode());
                $error_message = 'Database error: ' . escape_html($e->getMessage()) . '. Please try again.';
            } catch (Exception $e) {
                $db->rollback();
                $db->autocommit(true); // Re-enable autocommit
                // Log the actual error for debugging
                error_log('Preferences update error: ' . $e->getMessage());
                error_log('Preferences update trace: ' . $e->getTraceAsString());
                // Display detailed error message for debugging
                $error_message = 'An error occurred while updating your preferences: ' . escape_html($e->getMessage()) . '. Please try again.';
            }
                }
            }
        }
    }
}

// Check if email and email_opt_in columns exist for UI display
$has_email_column = false;
$has_email_opt_in_column = false;
if ($db_connection_ok) {
    try {
        $check_email = $db->query("SHOW COLUMNS FROM donors LIKE 'email'");
        $has_email_column = $check_email->num_rows > 0;
        $check_email->close();
        
        $check_email_opt_in = $db->query("SHOW COLUMNS FROM donors LIKE 'email_opt_in'");
        $has_email_opt_in_column = $check_email_opt_in->num_rows > 0;
        $check_email_opt_in->close();
    } catch (Exception $e) {
        // Silent fail
    }
}

// Ensure donor has email and email_opt_in fields in session if columns exist (for initial page load)
if ($has_email_column && !isset($donor['email'])) {
    $donor['email'] = '';
}
if ($has_email_opt_in_column && !isset($donor['email_opt_in'])) {
    $donor['email_opt_in'] = 1; // Default to ON
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
                    <h1 class="page-title">Preferences</h1>
                </div>

                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo escape_html($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo escape_html($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <!-- Profile Information Form -->
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fas fa-user-edit text-primary"></i>Update Profile Information
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="profileForm" class="mb-4">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="update_profile">
                                    
                                    <!-- Name -->
                                    <div class="mb-4">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-user me-2"></i>Full Name <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" 
                                               class="form-control" 
                                               name="name" 
                                               value="<?php echo escape_html($donor['name']); ?>"
                                               required
                                               minlength="2"
                                               placeholder="Enter your full name">
                                        <div class="form-text">Your full name as it appears on your account</div>
                                    </div>

                                    <!-- Phone -->
                                    <div class="mb-4">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-phone me-2"></i>Phone Number <span class="text-danger">*</span>
                                        </label>
                                        <input type="tel" 
                                               class="form-control" 
                                               name="phone" 
                                               value="<?php echo escape_html($donor['phone']); ?>"
                                               required
                                               pattern="[0-9+\-\s\(\)]{10,15}"
                                               maxlength="15"
                                               placeholder="07123456789">
                                        <div class="form-text">UK mobile number (e.g., 07123456789)</div>
                                    </div>

                                    <!-- Email (if column exists) -->
                                    <?php if ($has_email_column): ?>
                                    <div class="mb-4">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-envelope me-2"></i>Email Address
                                        </label>
                                        <input type="email" 
                                               class="form-control" 
                                               name="email" 
                                               value="<?php echo escape_html($donor['email'] ?? ''); ?>"
                                               maxlength="255"
                                               placeholder="your.email@example.com">
                                        <div class="form-text">Optional: Your email address for notifications</div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Submit Button -->
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary btn-lg">
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
                                    
                                    <!-- Language Preference -->
                                    <div class="mb-4">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-language me-2"></i>Language Preference <span class="text-danger">*</span>
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
                                        <div class="form-text">Choose your preferred language for the portal</div>
                                    </div>

                                    <!-- Preferred Payment Method -->
                                    <div class="mb-4">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-credit-card me-2"></i>Preferred Payment Method <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" name="preferred_payment_method" required>
                                            <option value="bank_transfer" <?php echo escape_html($donor['preferred_payment_method'] ?? 'bank_transfer') === 'bank_transfer' ? 'selected' : ''; ?>>
                                                Bank Transfer
                                            </option>
                                            <option value="cash" <?php echo escape_html($donor['preferred_payment_method'] ?? 'bank_transfer') === 'cash' ? 'selected' : ''; ?>>
                                                Cash
                                            </option>
                                            <option value="card" <?php echo escape_html($donor['preferred_payment_method'] ?? 'bank_transfer') === 'card' ? 'selected' : ''; ?>>
                                                Card
                                            </option>
                                        </select>
                                        <div class="form-text">Your preferred method for making payments</div>
                                    </div>

                                    <!-- Preferred Payment Day -->
                                    <div class="mb-4">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-calendar-day me-2"></i>Preferred Payment Day <span class="text-danger">*</span>
                                        </label>
                                        <input type="number" 
                                               class="form-control" 
                                               name="preferred_payment_day" 
                                               value="<?php echo (int)($donor['preferred_payment_day'] ?? 1); ?>"
                                               min="1" 
                                               max="28" 
                                               required>
                                        <div class="form-text">Day of the month (1-28) when you prefer to make payments</div>
                                    </div>

                                    <!-- SMS Opt-in -->
                                    <div class="mb-4">
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
                                        <div class="form-text">Receive SMS reminders about upcoming payments and important updates</div>
                                    </div>

                                    <!-- Email Opt-in (if column exists) -->
                                    <?php if ($has_email_opt_in_column): ?>
                                    <div class="mb-4">
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
                                        <div class="form-text">Receive email notifications about upcoming payments and important updates</div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Submit Button -->
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="fas fa-save me-2"></i>Save Preferences
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Account Summary (Read-only) -->
                    <div class="col-lg-4">
                        <div class="card">
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

