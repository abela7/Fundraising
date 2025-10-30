<?php
/**
 * Donor Portal - Profile
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
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

// Handle preferences update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_preferences') {
    verify_csrf();
    
    $preferred_language = trim($_POST['preferred_language'] ?? 'en');
    $preferred_payment_method = trim($_POST['preferred_payment_method'] ?? 'bank_transfer');
    $preferred_payment_day = (int)($_POST['preferred_payment_day'] ?? 1);
    $sms_opt_in = isset($_POST['sms_opt_in']) ? 1 : 0;
    
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
                $db->begin_transaction();
                
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
                $update_stmt->execute();
                
                // Audit log
                $audit_data = json_encode([
                    'donor_id' => $donor['id'],
                    'preferred_language' => $preferred_language,
                    'preferred_payment_method' => $preferred_payment_method,
                    'preferred_payment_day' => $preferred_payment_day,
                    'sms_opt_in' => $sms_opt_in
                ], JSON_UNESCAPED_SLASHES);
                $audit_stmt = $db->prepare("
                    INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) 
                    VALUES(?, 'donor', ?, 'update_preferences', ?, 'donor_portal')
                ");
                $user_id = 0;
                $audit_stmt->bind_param('iis', $user_id, $donor['id'], $audit_data);
                $audit_stmt->execute();
                
                $db->commit();
                
                // Refresh donor data
                $refresh_stmt = $db->prepare("
                    SELECT id, name, phone, total_pledged, total_paid, balance, 
                           has_active_plan, active_payment_plan_id, plan_monthly_amount,
                           plan_duration_months, plan_start_date, plan_next_due_date,
                           payment_status, preferred_payment_method, preferred_language,
                           preferred_payment_day, sms_opt_in
                    FROM donors 
                    WHERE id = ?
                    LIMIT 1
                ");
                $refresh_stmt->bind_param('i', $donor['id']);
                $refresh_stmt->execute();
                $refresh_result = $refresh_stmt->get_result();
                $updated_donor = $refresh_result->fetch_assoc();
                if ($updated_donor) {
                    $_SESSION['donor'] = $updated_donor;
                    $donor = $updated_donor;
                }
                
                $success_message = 'Preferences updated successfully!';
            } catch (Exception $e) {
                $db->rollback();
                $error_message = 'An error occurred while updating your preferences. Please try again.';
            }
        }
    }
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
                    <!-- Preferences Form -->
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fas fa-cog text-primary"></i>Update Preferences
                                </h5>
                            </div>
                            <div class="card-body">
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
                                               value="<?php echo $donor['preferred_payment_day'] ?? 1; ?>"
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

                    <!-- Account Information (Read-only) -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fas fa-info-circle text-primary"></i>Account Information
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label text-muted">Name</label>
                                    <p class="mb-0"><strong><?php echo htmlspecialchars($donor['name']); ?></strong></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted">Phone</label>
                                    <p class="mb-0"><strong><?php echo htmlspecialchars($donor['phone']); ?></strong></p>
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
</body>
</html>

