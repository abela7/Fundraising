<?php
declare(strict_types=1);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// Set up error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return false;
});

// Capture fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "<div style='background:#dc3545;color:white;padding:20px;margin:20px;border-radius:8px;'>";
        echo "<h3>Fatal Error</h3>";
        echo "<p><strong>Message:</strong> " . htmlspecialchars($error['message']) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($error['file']) . " on line " . $error['line'] . "</p>";
        echo "</div>";
    }
});

try {
    require_once __DIR__ . '/../../../shared/auth.php';
    require_once __DIR__ . '/../../../shared/csrf.php';
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../services/UltraMsgService.php';
    require_login();
} catch (Throwable $e) {
    die("<div style='background:#dc3545;color:white;padding:20px;margin:20px;border-radius:8px;'>
        <h3>Include Error</h3>
        <p>" . htmlspecialchars($e->getMessage()) . "</p>
        <p>File: " . htmlspecialchars($e->getFile()) . " Line: " . $e->getLine() . "</p>
    </div>");
}

$page_title = 'WhatsApp Settings';
$db = db();

$success_message = '';
$error_message = '';
$debug_info = [];

// Check if table exists
$table_exists = false;
try {
    $check = $db->query("SHOW TABLES LIKE 'whatsapp_providers'");
    $table_exists = $check && $check->num_rows > 0;
} catch (Throwable $e) {
    $debug_info[] = "Table check error: " . $e->getMessage();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $table_exists) {
    try {
        verify_csrf();
        
        $action = $_POST['action'] ?? '';
        
        if ($action === 'save_provider') {
            $instance_id = trim($_POST['instance_id'] ?? '');
            $api_token = trim($_POST['api_token'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($instance_id) || empty($api_token)) {
                $error_message = 'Instance ID and API Token are required.';
            } else {
                // Check if provider exists
                $existing = $db->query("SELECT id FROM whatsapp_providers WHERE provider_name = 'ultramsg' LIMIT 1");
                
                if ($existing && $existing->num_rows > 0) {
                    $row = $existing->fetch_assoc();
                    $stmt = $db->prepare("
                        UPDATE whatsapp_providers 
                        SET instance_id = ?, api_token = ?, is_active = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->bind_param('ssii', $instance_id, $api_token, $is_active, $row['id']);
                    $stmt->execute();
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO whatsapp_providers (provider_name, display_name, instance_id, api_token, is_active, is_default, created_at)
                        VALUES ('ultramsg', 'UltraMsg', ?, ?, ?, 1, NOW())
                    ");
                    $stmt->bind_param('ssi', $instance_id, $api_token, $is_active);
                    $stmt->execute();
                }
                
                $success_message = 'WhatsApp settings saved successfully!';
            }
        } elseif ($action === 'test_connection') {
            $instance_id = trim($_POST['instance_id'] ?? '');
            $api_token = trim($_POST['api_token'] ?? '');
            
            if (empty($instance_id) || empty($api_token)) {
                $error_message = 'Instance ID and API Token are required for testing.';
            } else {
                $service = new UltraMsgService($instance_id, $api_token);
                $result = $service->testConnection();
                
                if ($result['success']) {
                    $success_message = $result['message'];
                } else {
                    $error_message = $result['message'];
                }
            }
        } elseif ($action === 'send_test') {
            $test_phone = trim($_POST['test_phone'] ?? '');
            $test_message = trim($_POST['test_message'] ?? 'Hello! This is a test message from Liverpool Abune Teklehaymanot Church fundraising system.');
            
            if (empty($test_phone)) {
                $error_message = 'Phone number is required for test message.';
            } else {
                $service = UltraMsgService::fromDatabase($db);
                
                if (!$service) {
                    $error_message = 'WhatsApp provider not configured. Please save settings first.';
                } else {
                    $result = $service->send($test_phone, $test_message, ['log' => true]);
                    
                    if ($result['success']) {
                        $success_message = "Test message sent successfully! Message ID: " . ($result['message_id'] ?? 'N/A');
                    } else {
                        $error_message = "Failed to send test message: " . ($result['error'] ?? 'Unknown error');
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $error_message = 'Error: ' . $e->getMessage();
        $debug_info[] = "Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
    }
}

// Get current provider settings
$provider = null;
if ($table_exists) {
    try {
        $result = $db->query("SELECT * FROM whatsapp_providers WHERE provider_name = 'ultramsg' LIMIT 1");
        if ($result && $result->num_rows > 0) {
            $provider = $result->fetch_assoc();
        }
    } catch (Throwable $e) {
        $debug_info[] = "Provider fetch error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/admin.css">
    <style>
        .whatsapp-header {
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
            color: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .whatsapp-header h1 {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
        }
        .whatsapp-header p {
            margin: 0.5rem 0 0 0;
            opacity: 0.9;
        }
        .settings-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .settings-card-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #128C7E;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .form-label {
            font-weight: 500;
            color: #374151;
        }
        .btn-whatsapp {
            background: #25D366;
            border-color: #25D366;
            color: white;
        }
        .btn-whatsapp:hover {
            background: #128C7E;
            border-color: #128C7E;
            color: white;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .status-connected {
            background: #dcfce7;
            color: #166534;
        }
        .status-disconnected {
            background: #fee2e2;
            color: #991b1b;
        }
        .help-text {
            font-size: 0.8125rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }
        .debug-bar {
            background: #dbeafe;
            border: 1px solid #3b82f6;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            font-size: 0.8125rem;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php try { include '../../includes/sidebar.php'; } catch (Throwable $e) { echo "<!-- Sidebar error: " . htmlspecialchars($e->getMessage()) . " -->"; } ?>
    
    <div class="admin-content">
        <?php try { include '../../includes/topbar.php'; } catch (Throwable $e) { echo "<!-- Topbar error: " . htmlspecialchars($e->getMessage()) . " -->"; } ?>
        
        <main class="main-content p-4">
            <!-- Debug Info -->
            <?php if (!empty($debug_info)): ?>
            <div class="debug-bar">
                <strong><i class="fas fa-bug me-1"></i> Debug Info:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($debug_info as $info): ?>
                    <li><?php echo htmlspecialchars($info); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <!-- Header -->
            <div class="whatsapp-header">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <h1><i class="fab fa-whatsapp me-2"></i><?php echo htmlspecialchars($page_title); ?></h1>
                        <p>Configure UltraMsg WhatsApp API for sending messages</p>
                    </div>
                    <a href="index.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-1"></i> Back to SMS Dashboard
                    </a>
                </div>
            </div>
            
            <!-- Alerts -->
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
            
            <?php if (!$table_exists): ?>
            <div class="alert alert-warning">
                <i class="fas fa-database me-2"></i>
                <strong>Database Setup Required!</strong><br>
                Please run the WhatsApp database migration SQL first. 
                <a href="index.php" class="alert-link">Go to SMS Dashboard</a> for instructions.
            </div>
            <?php else: ?>
            
            <div class="row">
                <div class="col-lg-8">
                    <!-- Provider Settings -->
                    <div class="settings-card">
                        <h3 class="settings-card-title">
                            <i class="fas fa-cog"></i> UltraMsg Configuration
                        </h3>
                        
                        <form method="POST">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="save_provider">
                            
                            <div class="mb-3">
                                <label for="instance_id" class="form-label">Instance ID <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="instance_id" name="instance_id" 
                                       value="<?php echo htmlspecialchars($provider['instance_id'] ?? ''); ?>" 
                                       placeholder="e.g., instance12345" required>
                                <div class="help-text">Find this in your UltraMsg dashboard</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="api_token" class="form-label">API Token <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="api_token" name="api_token" 
                                           value="<?php echo htmlspecialchars($provider['api_token'] ?? ''); ?>" 
                                           placeholder="Your API token" required>
                                    <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('api_token')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="help-text">Keep this secret - never share it publicly</div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                           <?php echo ($provider['is_active'] ?? 0) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_active">Enable WhatsApp Messaging</label>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="submit" class="btn btn-whatsapp">
                                    <i class="fas fa-save me-1"></i> Save Settings
                                </button>
                                <button type="submit" class="btn btn-outline-primary" name="action" value="test_connection">
                                    <i class="fas fa-plug me-1"></i> Test Connection
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Send Test Message -->
                    <?php if ($provider): ?>
                    <div class="settings-card">
                        <h3 class="settings-card-title">
                            <i class="fas fa-paper-plane"></i> Send Test Message
                        </h3>
                        
                        <form method="POST">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="send_test">
                            
                            <div class="mb-3">
                                <label for="test_phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" id="test_phone" name="test_phone" 
                                       placeholder="e.g., 07123456789" required>
                                <div class="help-text">UK mobile number to send test message to</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="test_message" class="form-label">Message</label>
                                <textarea class="form-control" id="test_message" name="test_message" rows="3" 
                                          placeholder="Test message content...">Hello! This is a test message from Liverpool Abune Teklehaymanot Church fundraising system. 🙏</textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-whatsapp">
                                <i class="fab fa-whatsapp me-1"></i> Send Test WhatsApp
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="col-lg-4">
                    <!-- Status Card -->
                    <div class="settings-card">
                        <h3 class="settings-card-title">
                            <i class="fas fa-info-circle"></i> Connection Status
                        </h3>
                        
                        <?php if ($provider && $provider['is_active']): ?>
                            <?php if ($provider['last_success_at']): ?>
                            <div class="status-badge status-connected mb-3">
                                <i class="fas fa-check-circle"></i> Connected
                            </div>
                            <p class="mb-1"><strong>Last Success:</strong></p>
                            <p class="text-muted"><?php echo date('M j, Y g:i A', strtotime($provider['last_success_at'])); ?></p>
                            <p class="mb-1"><strong>Messages Sent:</strong></p>
                            <p class="text-muted"><?php echo number_format((int)$provider['messages_sent']); ?></p>
                            <?php else: ?>
                            <div class="status-badge status-disconnected mb-3">
                                <i class="fas fa-clock"></i> Pending First Message
                            </div>
                            <p class="text-muted">Configure settings and send a test message to verify connection.</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="status-badge status-disconnected mb-3">
                                <i class="fas fa-times-circle"></i> Not Configured
                            </div>
                            <p class="text-muted">Enter your UltraMsg credentials to enable WhatsApp messaging.</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Help Card -->
                    <div class="settings-card">
                        <h3 class="settings-card-title">
                            <i class="fas fa-question-circle"></i> Setup Guide
                        </h3>
                        
                        <ol class="mb-0" style="padding-left: 1.25rem;">
                            <li class="mb-2">Go to <a href="https://ultramsg.com" target="_blank">ultramsg.com</a></li>
                            <li class="mb-2">Create an account & instance</li>
                            <li class="mb-2">Scan QR code with WhatsApp</li>
                            <li class="mb-2">Copy Instance ID & Token</li>
                            <li class="mb-2">Paste credentials above</li>
                            <li>Send a test message!</li>
                        </ol>
                        
                        <hr>
                        
                        <p class="mb-2"><strong>Documentation:</strong></p>
                        <a href="https://docs.ultramsg.com/" target="_blank" class="btn btn-sm btn-outline-secondary w-100">
                            <i class="fas fa-book me-1"></i> UltraMsg API Docs
                        </a>
                    </div>
                </div>
            </div>
            
            <?php endif; ?>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const icon = field.nextElementSibling.querySelector('i');
    
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}
</script>
</body>
</html>

