<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../shared/auth.php';
require_once __DIR__ . '/../../../shared/csrf.php';
require_once __DIR__ . '/../../../config/db.php';
require_login();
require_admin();

$page_title = 'SMS Settings';
$current_user = current_user();
$db = db();

$providers = [];
$settings = [];
$error_message = null;
$success_message = $_SESSION['success_message'] ?? null;
unset($_SESSION['success_message']);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'save_provider') {
            $id = isset($_POST['provider_id']) ? (int)$_POST['provider_id'] : 0;
            $name = trim($_POST['name'] ?? '');
            $display_name = trim($_POST['display_name'] ?? '');
            $api_key = trim($_POST['api_key'] ?? '');
            $api_secret = trim($_POST['api_secret'] ?? '');
            $sender_id = trim($_POST['sender_id'] ?? '');
            $cost_per_sms = (float)($_POST['cost_per_sms'] ?? 3.00);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            
            if (empty($name) || empty($display_name)) {
                throw new Exception('Provider name is required.');
            }
            
            // If setting as default, unset others
            if ($is_default) {
                $db->query("UPDATE sms_providers SET is_default = 0");
            }
            
            if ($id > 0) {
                // Update
                $stmt = $db->prepare("
                    UPDATE sms_providers 
                    SET name = ?, display_name = ?, api_key = ?, api_secret = ?, 
                        sender_id = ?, cost_per_sms_pence = ?, is_active = ?, is_default = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param('sssssdiiii', $name, $display_name, $api_key, $api_secret, 
                    $sender_id, $cost_per_sms, $is_active, $is_default, $id);
            } else {
                // Insert
                $stmt = $db->prepare("
                    INSERT INTO sms_providers 
                    (name, display_name, api_key, api_secret, sender_id, cost_per_sms_pence, is_active, is_default, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->bind_param('ssssssdii', $name, $display_name, $api_key, $api_secret, 
                    $sender_id, $cost_per_sms, $is_active, $is_default);
            }
            $stmt->execute();
            
            $_SESSION['success_message'] = 'Provider saved successfully!';
            header('Location: settings.php');
            exit;
        }
        
        if ($action === 'delete_provider') {
            $id = (int)$_POST['provider_id'];
            $stmt = $db->prepare("DELETE FROM sms_providers WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $_SESSION['success_message'] = 'Provider deleted.';
            header('Location: settings.php');
            exit;
        }
        
        if ($action === 'save_settings') {
            $settings_to_save = [
                'sms_daily_limit' => (int)($_POST['sms_daily_limit'] ?? 1000),
                'sms_quiet_hours_start' => $_POST['sms_quiet_hours_start'] ?? '21:00',
                'sms_quiet_hours_end' => $_POST['sms_quiet_hours_end'] ?? '09:00',
                'sms_reminder_3day_enabled' => isset($_POST['sms_reminder_3day_enabled']) ? '1' : '0',
                'sms_reminder_dueday_enabled' => isset($_POST['sms_reminder_dueday_enabled']) ? '1' : '0',
                'sms_overdue_7day_enabled' => isset($_POST['sms_overdue_7day_enabled']) ? '1' : '0',
            ];
            
            foreach ($settings_to_save as $key => $value) {
                $stmt = $db->prepare("
                    INSERT INTO sms_settings (setting_key, setting_value, updated_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
                ");
                $stmt->bind_param('sss', $key, $value, $value);
                $stmt->execute();
            }
            
            $_SESSION['success_message'] = 'Settings saved!';
            header('Location: settings.php');
            exit;
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Get providers
try {
    $result = $db->query("SELECT * FROM sms_providers ORDER BY is_default DESC, name");
    while ($row = $result->fetch_assoc()) {
        $providers[] = $row;
    }
} catch (Exception $e) {
    // Table might not exist yet
}

// Get settings
try {
    $result = $db->query("SELECT setting_key, setting_value FROM sms_settings");
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Table might not exist yet
}

// Defaults
$settings = array_merge([
    'sms_daily_limit' => '1000',
    'sms_quiet_hours_start' => '21:00',
    'sms_quiet_hours_end' => '09:00',
    'sms_reminder_3day_enabled' => '1',
    'sms_reminder_dueday_enabled' => '1',
    'sms_overdue_7day_enabled' => '1',
], $settings);

$edit_provider = null;
if (isset($_GET['edit_provider'])) {
    $edit_id = (int)$_GET['edit_provider'];
    foreach ($providers as $p) {
        if ($p['id'] == $edit_id) {
            $edit_provider = $p;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/admin.css">
    <link rel="stylesheet" href="../assets/donor-management.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                
                <!-- Header -->
                <div class="mb-4">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-2">
                            <li class="breadcrumb-item"><a href="index.php">SMS Dashboard</a></li>
                            <li class="breadcrumb-item active">Settings</li>
                        </ol>
                    </nav>
                    <h1 class="h3 mb-0">
                        <i class="fas fa-cog text-primary me-2"></i>SMS Settings
                    </h1>
                </div>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="row g-4">
                    <!-- Providers -->
                    <div class="col-12 col-lg-6">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-plug me-2"></i>SMS Providers</h5>
                                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#providerModal">
                                    <i class="fas fa-plus me-1"></i>Add
                                </button>
                            </div>
                            <div class="card-body">
                                <?php if (empty($providers)): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-plug fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No SMS providers configured yet.</p>
                                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#providerModal">
                                            <i class="fas fa-plus me-2"></i>Add Provider
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($providers as $provider): ?>
                                        <div class="d-flex align-items-center justify-content-between p-3 border rounded mb-2">
                                            <div>
                                                <div class="fw-semibold">
                                                    <?php echo htmlspecialchars($provider['display_name']); ?>
                                                    <?php if ($provider['is_default']): ?>
                                                        <span class="badge bg-primary ms-1">Default</span>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($provider['name']); ?> · 
                                                    <?php echo number_format($provider['cost_per_sms_pence'], 1); ?>p/SMS
                                                </small>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <?php if ($provider['is_active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                                <a href="?edit_provider=<?php echo $provider['id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- General Settings -->
                    <div class="col-12 col-lg-6">
                        <div class="card h-100">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-sliders-h me-2"></i>General Settings</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="save_settings">
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Daily SMS Limit</label>
                                        <input type="number" name="sms_daily_limit" class="form-control" 
                                               value="<?php echo htmlspecialchars($settings['sms_daily_limit']); ?>">
                                        <div class="form-text">Maximum SMS to send per day (0 = unlimited)</div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-6">
                                            <label class="form-label fw-semibold">Quiet Hours Start</label>
                                            <input type="time" name="sms_quiet_hours_start" class="form-control"
                                                   value="<?php echo htmlspecialchars($settings['sms_quiet_hours_start']); ?>">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label fw-semibold">Quiet Hours End</label>
                                            <input type="time" name="sms_quiet_hours_end" class="form-control"
                                                   value="<?php echo htmlspecialchars($settings['sms_quiet_hours_end']); ?>">
                                        </div>
                                    </div>
                                    
                                    <hr class="my-4">
                                    
                                    <h6 class="mb-3">Automated Reminders</h6>
                                    
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" name="sms_reminder_3day_enabled" id="reminder3day"
                                               <?php echo $settings['sms_reminder_3day_enabled'] === '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="reminder3day">3-Day Payment Reminder</label>
                                    </div>
                                    
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" name="sms_reminder_dueday_enabled" id="reminderDue"
                                               <?php echo $settings['sms_reminder_dueday_enabled'] === '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="reminderDue">Due Day Reminder</label>
                                    </div>
                                    
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="sms_overdue_7day_enabled" id="overdue7day"
                                               <?php echo $settings['sms_overdue_7day_enabled'] === '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="overdue7day">7-Day Overdue Notice</label>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Save Settings
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Cron Setup Info -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Cron Job Setup</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">Add these cron jobs in your cPanel to enable automated SMS:</p>
                        
                        <div class="bg-dark text-light p-3 rounded mb-3" style="font-family: monospace; font-size: 0.875rem;">
                            <div class="mb-2"># Process SMS queue (every 5 minutes)</div>
                            <code>*/5 * * * * /usr/local/bin/php /home/YOUR_USER/public_html/Fundraising/cron/process-sms-queue.php</code>
                            
                            <div class="mt-3 mb-2"># Schedule payment reminders (daily at 8am)</div>
                            <code>0 8 * * * /usr/local/bin/php /home/YOUR_USER/public_html/Fundraising/cron/schedule-payment-reminders.php</code>
                        </div>
                        
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            Replace <code>YOUR_USER</code> with your cPanel username. The cron scripts will be created in the next phase.
                        </div>
                    </div>
                </div>
                
            </div>
        </main>
    </div>
</div>

<!-- Provider Modal -->
<div class="modal fade" id="providerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_provider">
                <input type="hidden" name="provider_id" value="<?php echo $edit_provider['id'] ?? ''; ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <?php echo $edit_provider ? 'Edit Provider' : 'Add SMS Provider'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Provider Key <span class="text-danger">*</span></label>
                        <select name="name" class="form-select" required>
                            <option value="">Select provider...</option>
                            <option value="twilio" <?php echo ($edit_provider['name'] ?? '') === 'twilio' ? 'selected' : ''; ?>>Twilio</option>
                            <option value="textlocal" <?php echo ($edit_provider['name'] ?? '') === 'textlocal' ? 'selected' : ''; ?>>Textlocal</option>
                            <option value="vonage" <?php echo ($edit_provider['name'] ?? '') === 'vonage' ? 'selected' : ''; ?>>Vonage (Nexmo)</option>
                            <option value="aws_sns" <?php echo ($edit_provider['name'] ?? '') === 'aws_sns' ? 'selected' : ''; ?>>AWS SNS</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Display Name <span class="text-danger">*</span></label>
                        <input type="text" name="display_name" class="form-control" required
                               placeholder="e.g., Twilio Production"
                               value="<?php echo htmlspecialchars($edit_provider['display_name'] ?? ''); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">API Key / Account SID</label>
                        <input type="text" name="api_key" class="form-control"
                               placeholder="Enter API key..."
                               value="<?php echo htmlspecialchars($edit_provider['api_key'] ?? ''); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">API Secret / Auth Token</label>
                        <input type="password" name="api_secret" class="form-control"
                               placeholder="Enter API secret..."
                               value="<?php echo htmlspecialchars($edit_provider['api_secret'] ?? ''); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Sender ID / Phone</label>
                        <input type="text" name="sender_id" class="form-control"
                               placeholder="e.g., ATEOTC or +447123456789"
                               value="<?php echo htmlspecialchars($edit_provider['sender_id'] ?? ''); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Cost per SMS (pence)</label>
                        <input type="number" name="cost_per_sms" class="form-control" step="0.1"
                               value="<?php echo htmlspecialchars($edit_provider['cost_per_sms_pence'] ?? '3.0'); ?>">
                    </div>
                    
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="is_active" id="providerActive"
                               <?php echo ($edit_provider['is_active'] ?? 0) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="providerActive">Provider is active</label>
                    </div>
                    
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_default" id="providerDefault"
                               <?php echo ($edit_provider['is_default'] ?? 0) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="providerDefault">Set as default provider</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <?php if ($edit_provider): ?>
                        <button type="submit" name="action" value="delete_provider" class="btn btn-outline-danger me-auto"
                                onclick="return confirm('Delete this provider?');">
                            <i class="fas fa-trash"></i>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Provider</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/admin.js"></script>
<?php if ($edit_provider): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('providerModal')).show();
});
</script>
<?php endif; ?>
</body>
</html>

