<?php
declare(strict_types=1);

// ============================================================
// DEBUG MODE - Enable to see all errors on page
// ============================================================
$DEBUG_MODE = true;

if ($DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

// Custom error handler to capture errors
$php_errors = [];
set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$php_errors) {
    $php_errors[] = [
        'type' => $errno,
        'message' => $errstr,
        'file' => $errfile,
        'line' => $errline
    ];
    return false;
});

// Capture fatal errors
register_shutdown_function(function() use (&$php_errors, $DEBUG_MODE) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if ($DEBUG_MODE) {
            echo '<div style="background:#ff0000;color:white;padding:20px;margin:20px;font-family:monospace;">';
            echo '<h2>FATAL ERROR</h2>';
            echo '<p><strong>Message:</strong> ' . htmlspecialchars($error['message']) . '</p>';
            echo '<p><strong>File:</strong> ' . htmlspecialchars($error['file']) . '</p>';
            echo '<p><strong>Line:</strong> ' . $error['line'] . '</p>';
            echo '</div>';
        }
    }
});

try {
    require_once __DIR__ . '/../../../shared/auth.php';
} catch (Throwable $e) {
    die('<div style="background:#ff0000;color:white;padding:20px;">Error loading auth.php: ' . htmlspecialchars($e->getMessage()) . '</div>');
}

try {
    require_once __DIR__ . '/../../../shared/csrf.php';
} catch (Throwable $e) {
    die('<div style="background:#ff0000;color:white;padding:20px;">Error loading csrf.php: ' . htmlspecialchars($e->getMessage()) . '</div>');
}

try {
    require_once __DIR__ . '/../../../config/db.php';
} catch (Throwable $e) {
    die('<div style="background:#ff0000;color:white;padding:20px;">Error loading db.php: ' . htmlspecialchars($e->getMessage()) . '</div>');
}

try {
    require_once __DIR__ . '/../../../shared/url.php';
} catch (Throwable $e) {
    die('<div style="background:#ff0000;color:white;padding:20px;">Error loading url.php: ' . htmlspecialchars($e->getMessage()) . '</div>');
}

try {
    require_login();
} catch (Throwable $e) {
    die('<div style="background:#ff0000;color:white;padding:20px;">Error in require_login(): ' . htmlspecialchars($e->getMessage()) . '</div>');
}

try {
    require_admin();
} catch (Throwable $e) {
    die('<div style="background:#ff0000;color:white;padding:20px;">Error in require_admin(): ' . htmlspecialchars($e->getMessage()) . '</div>');
}

$page_title = 'Twilio Call Settings';
$current_user = null;
$twilio_settings = null;
$error_message = null;
$success_message = $_SESSION['success_message'] ?? null;
unset($_SESSION['success_message']);
$tables_exist = false;
$db = null;

try {
    $current_user = current_user();
} catch (Throwable $e) {
    $error_message = 'Error getting current user: ' . $e->getMessage();
}

// Try to get database connection
try {
    $db = db();
    if (!$db) {
        throw new Exception('Database connection returned null');
    }
} catch (Throwable $e) {
    $error_message = 'Database connection error: ' . $e->getMessage();
    $db = null;
}

// Only proceed with database operations if we have a connection
if ($db) {
    // Check if Twilio tables exist
    try {
        $check_result = $db->query("SHOW TABLES LIKE 'twilio_settings'");
        $tables_exist = $check_result && $check_result->num_rows > 0;
    } catch (Throwable $e) {
        $error_message = 'Error checking tables: ' . $e->getMessage();
    }
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            verify_csrf();
        } catch (Throwable $e) {
            $error_message = 'CSRF verification failed: ' . $e->getMessage();
        }
        
        $action = $_POST['action'] ?? '';
        
        try {
            // Test Twilio connection
            if ($action === 'test_connection') {
                require_once __DIR__ . '/../../../services/TwilioService.php';
                
                $account_sid = trim($_POST['account_sid'] ?? '');
                $auth_token = trim($_POST['auth_token'] ?? '');
                $phone_number = trim($_POST['phone_number'] ?? '');
                
                if (empty($account_sid) || empty($auth_token) || empty($phone_number)) {
                    throw new Exception('Account SID, Auth Token, and Phone Number are required to test connection.');
                }
                
                $twilio = new TwilioService($account_sid, $auth_token, $phone_number, $db);
                $result = $twilio->testConnection();
                
                if ($result['success']) {
                    $_SESSION['success_message'] = '✅ ' . $result['message'];
                    
                    // Update last test time
                    if ($tables_exist) {
                        $stmt = $db->prepare("UPDATE twilio_settings SET last_test_at = NOW(), last_test_result = ? WHERE id = 1");
                        $stmt->bind_param('s', $result['message']);
                        $stmt->execute();
                    }
                } else {
                    throw new Exception($result['message']);
                }
                
                header('Location: settings.php');
                exit;
            }
            
            // Save Twilio settings
            if ($action === 'save_settings') {
                if (!$tables_exist) {
                    throw new Exception('Twilio settings table does not exist. Please run the database setup script first.');
                }
                
                $account_sid = trim($_POST['account_sid'] ?? '');
                $auth_token = trim($_POST['auth_token'] ?? '');
                $phone_number = trim($_POST['phone_number'] ?? '');
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $recording_enabled = isset($_POST['recording_enabled']) ? 1 : 0;
                $transcription_enabled = isset($_POST['transcription_enabled']) ? 1 : 0;
                
                if (empty($account_sid)) {
                    throw new Exception('Account SID is required');
                }
                if (empty($auth_token)) {
                    throw new Exception('Auth Token is required');
                }
                if (empty($phone_number)) {
                    throw new Exception('Phone Number is required');
                }
                
                // Generate webhook URLs
                $base_url = 'https://donate.abuneteklehaymanot.org';
                $status_callback_url = $base_url . '/admin/call-center/api/twilio-status-callback.php';
                $recording_callback_url = $base_url . '/admin/call-center/api/twilio-recording-callback.php';
                
                // Check if settings exist
                $existing = $db->query("SELECT id FROM twilio_settings LIMIT 1");
                
                if ($existing && $existing->num_rows > 0) {
                    // Update existing
                    $stmt = $db->prepare("
                        UPDATE twilio_settings 
                        SET account_sid = ?, auth_token = ?, phone_number = ?, 
                            is_active = ?, recording_enabled = ?, transcription_enabled = ?,
                            status_callback_url = ?, recording_callback_url = ?,
                            updated_at = NOW()
                        WHERE id = 1
                    ");
                    $stmt->bind_param('sssiiiss', 
                        $account_sid, $auth_token, $phone_number,
                        $is_active, $recording_enabled, $transcription_enabled,
                        $status_callback_url, $recording_callback_url
                    );
                } else {
                    // Insert new
                    $stmt = $db->prepare("
                        INSERT INTO twilio_settings 
                        (account_sid, auth_token, phone_number, is_active, recording_enabled, 
                         transcription_enabled, status_callback_url, recording_callback_url)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param('sssiiiss',
                        $account_sid, $auth_token, $phone_number,
                        $is_active, $recording_enabled, $transcription_enabled,
                        $status_callback_url, $recording_callback_url
                    );
                }
                
                if (!$stmt->execute()) {
                    throw new Exception('Failed to save settings: ' . $stmt->error);
                }
                
                $_SESSION['success_message'] = 'Twilio settings saved successfully!';
                header('Location: settings.php');
                exit;
            }
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
    
    // Get current Twilio settings
    if ($tables_exist) {
        try {
            $result = $db->query("SELECT * FROM twilio_settings ORDER BY id DESC LIMIT 1");
            if ($result && $result->num_rows > 0) {
                $twilio_settings = $result->fetch_assoc();
            }
        } catch (Throwable $e) {
            $error_message = 'Error loading settings: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/admin.css">
    <style>
        .settings-card {
            border: none;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }
        
        .settings-header {
            background: linear-gradient(135deg, #0a6286 0%, #0d7aa8 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .settings-header h5 {
            margin: 0;
            font-weight: 600;
        }
        
        .info-box {
            background: #f0f9ff;
            border-left: 4px solid #0ea5e9;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .info-box.warning {
            background: #fffbeb;
            border-left-color: #f59e0b;
        }
        
        .info-box.success {
            background: #f0fdf4;
            border-left-color: #10b981;
        }
        
        .credential-input {
            font-family: 'Courier New', monospace;
            background: #f8fafc;
        }
        
        .webhook-url {
            background: #1e293b;
            color: #e2e8f0;
            padding: 0.75rem;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            word-break: break-all;
            position: relative;
        }
        
        .copy-btn {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            padding: 0.25rem 0.625rem;
            font-size: 0.75rem;
        }
        
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        
        .status-indicator.active {
            background: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
        }
        
        .status-indicator.inactive {
            background: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2);
        }
        
        .debug-panel {
            background: #1e293b;
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            margin-top: 1rem;
        }
        
        @media (max-width: 768px) {
            .settings-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
        
        <div class="admin-content">
            <?php include __DIR__ . '/../../includes/topbar.php'; ?>
            
            <main class="main-content">
                <div class="container-fluid p-3 p-md-4">
                <!-- Header -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
                    <div>
                        <h1 class="h4 mb-1">
                            <i class="fas fa-phone-alt text-primary me-2"></i>Twilio Call Settings
                        </h1>
                        <p class="text-muted mb-0 small">Configure Twilio for click-to-call functionality</p>
                    </div>
                    <a href="<?php echo url_for('admin/call-center/'); ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back to Call Center
                    </a>
                </div>
                
                <?php if ($DEBUG_MODE && !empty($php_errors)): ?>
                <div class="debug-panel mb-3">
                    <strong>⚠️ PHP Errors Detected:</strong>
                    <?php foreach ($php_errors as $err): ?>
                    <div class="mt-2">
                        <strong>Type:</strong> <?php echo $err['type']; ?><br>
                        <strong>Message:</strong> <?php echo htmlspecialchars($err['message']); ?><br>
                        <strong>File:</strong> <?php echo htmlspecialchars($err['file']); ?><br>
                        <strong>Line:</strong> <?php echo $err['line']; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if (!$tables_exist): ?>
                <div class="info-box warning">
                    <h5><i class="fas fa-database me-2"></i>Database Setup Required</h5>
                    <p class="mb-2">The Twilio database tables are not set up yet. Please run the setup script first:</p>
                    <ol class="mb-2">
                        <li>Open phpMyAdmin</li>
                        <li>Select your database: <code>abunetdg_fundraising</code></li>
                        <li>Go to the <strong>SQL</strong> tab</li>
                        <li>Copy and paste the contents of <code>database/twilio_integration_tables.sql</code></li>
                        <li>Click <strong>Go</strong></li>
                        <li>Refresh this page</li>
                    </ol>
                </div>
                <?php else: ?>
                
                <!-- Getting Started Info -->
                <?php if (!$twilio_settings): ?>
                <div class="info-box">
                    <h5><i class="fas fa-rocket me-2"></i>Getting Started with Twilio</h5>
                    <p class="mb-2">Follow these steps to set up Twilio click-to-call:</p>
                    <ol class="mb-0">
                        <li><strong>Create Twilio Account:</strong> Sign up at <a href="https://www.twilio.com/try-twilio" target="_blank">twilio.com</a></li>
                        <li><strong>Buy Phone Number:</strong> Purchase a Liverpool UK number (+44 151)</li>
                        <li><strong>Get Credentials:</strong> Copy your Account SID and Auth Token from the console</li>
                        <li><strong>Add Credit:</strong> Add at least £50 for calling</li>
                        <li><strong>Configure Below:</strong> Enter your credentials and test the connection</li>
                    </ol>
                </div>
                <?php endif; ?>
                
                <!-- Twilio Settings Form -->
                <div class="card settings-card">
                    <div class="settings-header">
                        <h5>
                            <i class="fas fa-cog me-2"></i>Twilio Configuration
                        </h5>
                        <?php if ($twilio_settings): ?>
                        <span class="badge bg-<?php echo $twilio_settings['is_active'] ? 'success' : 'secondary'; ?>">
                            <span class="status-indicator <?php echo $twilio_settings['is_active'] ? 'active' : 'inactive'; ?>"></span>
                            <?php echo $twilio_settings['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" id="settingsForm">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="save_settings">
                            
                            <div class="row g-3">
                                <!-- Account SID -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        Account SID <span class="text-danger">*</span>
                                        <i class="fas fa-info-circle text-muted ms-1" 
                                           data-bs-toggle="tooltip" 
                                           title="Your Twilio Account SID (starts with AC)"></i>
                                    </label>
                                    <input type="text" name="account_sid" class="form-control credential-input" 
                                           placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" required
                                           pattern="AC[a-f0-9]{32}"
                                           value="<?php echo htmlspecialchars($twilio_settings['account_sid'] ?? ''); ?>">
                                    <small class="text-muted">Find this in your Twilio Console</small>
                                </div>
                                
                                <!-- Auth Token -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        Auth Token <span class="text-danger">*</span>
                                        <i class="fas fa-info-circle text-muted ms-1" 
                                           data-bs-toggle="tooltip" 
                                           title="Your Twilio Auth Token (keep this secret!)"></i>
                                    </label>
                                    <input type="password" name="auth_token" class="form-control credential-input" 
                                           placeholder="Your Auth Token" required
                                           value="<?php echo htmlspecialchars($twilio_settings['auth_token'] ?? ''); ?>">
                                    <small class="text-muted">Click the eye icon in Twilio Console to reveal</small>
                                </div>
                                
                                <!-- Phone Number -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        Twilio Phone Number <span class="text-danger">*</span>
                                        <i class="fas fa-info-circle text-muted ms-1" 
                                           data-bs-toggle="tooltip" 
                                           title="Your purchased Twilio number in E.164 format"></i>
                                    </label>
                                    <input type="tel" name="phone_number" class="form-control credential-input" 
                                           placeholder="+44151XXXXXXX" required
                                           pattern="\+44[0-9]{10,11}"
                                           value="<?php echo htmlspecialchars($twilio_settings['phone_number'] ?? ''); ?>">
                                    <small class="text-muted">Liverpool number format: +44151XXXXXXX</small>
                                </div>
                                
                                <!-- Status -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Status</label>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive" 
                                               <?php echo ($twilio_settings['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="isActive">
                                            Enable Twilio Calling
                                        </label>
                                    </div>
                                    <small class="text-muted">Toggle to enable/disable click-to-call functionality</small>
                                </div>
                                
                                <!-- Recording -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Call Recording</label>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" name="recording_enabled" id="recordingEnabled" 
                                               <?php echo ($twilio_settings['recording_enabled'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="recordingEnabled">
                                            Auto-record all calls
                                        </label>
                                    </div>
                                    <small class="text-muted">Recommended: Keep recordings for quality and compliance</small>
                                </div>
                                
                                <!-- Transcription -->
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Transcription</label>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" name="transcription_enabled" id="transcriptionEnabled" 
                                               <?php echo ($twilio_settings['transcription_enabled'] ?? 0) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="transcriptionEnabled">
                                            Auto-transcribe recordings to text
                                        </label>
                                    </div>
                                    <small class="text-muted">Optional: Costs extra (£0.04/min)</small>
                                </div>
                            </div>
                            
                            <hr class="my-4">
                            
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i>Save Settings
                                </button>
                                <button type="button" class="btn btn-success" onclick="testConnection()">
                                    <i class="fas fa-plug me-1"></i>Test Connection
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Webhook Configuration -->
                <?php if ($twilio_settings): ?>
                <div class="card settings-card">
                    <div class="settings-header">
                        <h5><i class="fas fa-link me-2"></i>Webhook Configuration</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="info-box">
                            <h6><i class="fas fa-info-circle me-2"></i>What are Webhooks?</h6>
                            <p class="mb-0">Webhooks allow Twilio to send real-time updates about your calls back to your system. This enables automatic timer tracking, call recording, and status updates.</p>
                        </div>
                        
                        <h6 class="mt-4 mb-3">Configure these URLs in your Twilio Console:</h6>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Status Callback URL</label>
                            <div class="webhook-url position-relative">
                                <button type="button" class="btn btn-sm btn-light copy-btn" onclick="copyToClipboard('statusUrl')">
                                    <i class="fas fa-copy"></i> Copy
                                </button>
                                <span id="statusUrl"><?php echo htmlspecialchars($twilio_settings['status_callback_url'] ?? ''); ?></span>
                            </div>
                            <small class="text-muted">Receives call status updates (ringing, answered, completed)</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Recording Callback URL</label>
                            <div class="webhook-url position-relative">
                                <button type="button" class="btn btn-sm btn-light copy-btn" onclick="copyToClipboard('recordingUrl')">
                                    <i class="fas fa-copy"></i> Copy
                                </button>
                                <span id="recordingUrl"><?php echo htmlspecialchars($twilio_settings['recording_callback_url'] ?? ''); ?></span>
                            </div>
                            <small class="text-muted">Receives recording URLs when calls are recorded</small>
                        </div>
                        
                        <div class="alert alert-warning mt-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Manual Setup Required:</strong> You need to configure these webhook URLs in your Twilio Console. 
                            <a href="https://www.twilio.com/console/voice/settings/general" target="_blank" class="alert-link">Open Twilio Settings →</a>
                        </div>
                    </div>
                </div>
                
                <!-- Connection Status -->
                <?php if ($twilio_settings['last_test_at']): ?>
                <div class="card settings-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-check-circle text-success fa-2x me-3"></i>
                            <div>
                                <h6 class="mb-1">Last Connection Test</h6>
                                <p class="text-muted mb-0">
                                    <?php echo htmlspecialchars($twilio_settings['last_test_result'] ?? 'Success'); ?>
                                    <br>
                                    <small>Tested on <?php echo date('M j, Y g:i A', strtotime($twilio_settings['last_test_at'])); ?></small>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                
                <?php endif; ?>
                </div>
            </main>
        </div>
    </div>
    
    <?php include __DIR__ . '/../../includes/fab.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/admin.js"></script>
    <script>
    // Initialize tooltips
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltips.forEach(el => new bootstrap.Tooltip(el));
    
    // Test connection
    function testConnection() {
        const form = document.getElementById('settingsForm');
        const formData = new FormData(form);
        formData.set('action', 'test_connection');
        
        // Show loading state
        const btn = event.target;
        const originalHTML = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing...';
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(() => {
            window.location.reload();
        })
        .catch(error => {
            alert('Error testing connection: ' + error);
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        });
    }
    
    // Copy to clipboard
    function copyToClipboard(elementId) {
        const text = document.getElementById(elementId).textContent;
        navigator.clipboard.writeText(text).then(() => {
            const btn = event.target.closest('button');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
            setTimeout(() => {
                btn.innerHTML = originalHTML;
            }, 2000);
        });
    }
    </script>
</body>
</html>

