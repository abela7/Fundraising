<?php
/**
 * Test Unified Messaging System
 * 
 * Simple test script to verify SMS and WhatsApp integration
 * Run this from admin panel: /admin/tools/test-messaging.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../services/MessagingHelper.php';

// Check authentication
require_admin();

$db = db();

try {
    $msg = new MessagingHelper($db);
} catch (Exception $e) {
    die('Error initializing messaging system: ' . htmlspecialchars($e->getMessage()));
}

$current_user = current_user();
$status = [];
$send_result = null;

// Get system status
try {
    $status = $msg->getStatus();
} catch (Exception $e) {
    $status = ['error' => $e->getMessage(), 'initialized' => false, 'sms_available' => false, 'whatsapp_available' => false];
}

// Handle send test
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['phone'])) {
    $phone = trim($_POST['phone']);
    $message = trim($_POST['message']);
    $channel = $_POST['channel'] ?? 'auto';
    
    $send_result = $msg->sendDirect($phone, $message, $channel, null, 'test');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Messaging System - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0a6286 0%, #0ea5e9 100%);
            --success-gradient: linear-gradient(135deg, #059669 0%, #10b981 100%);
            --whatsapp-color: #25D366;
        }
        
        body {
            background: #f8fafc;
        }
        
        .page-header {
            background: var(--primary-gradient);
            color: white;
            padding: 1.5rem;
            border-radius: 16px;
            margin-bottom: 1.5rem;
        }
        
        .page-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }
        
        .page-header p {
            margin: 0.5rem 0 0;
            opacity: 0.9;
        }
        
        .status-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
        
        .status-header {
            background: #f8fafc;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .status-body {
            padding: 1.25rem;
        }
        
        .status-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .status-row:last-child {
            border-bottom: none;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            border-radius: 50px;
            font-size: 0.8125rem;
            font-weight: 600;
        }
        
        .status-badge.success {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-badge.danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .test-form {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e5e7eb;
        }
        
        .test-form-header {
            background: linear-gradient(135deg, #0a6286 0%, #0ea5e9 100%);
            color: white;
            padding: 1rem 1.25rem;
            border-radius: 12px 12px 0 0;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .test-form-body {
            padding: 1.25rem;
        }
        
        .char-counter {
            font-size: 0.8125rem;
            display: flex;
            justify-content: space-between;
            margin-top: 0.5rem;
        }
        
        .char-counter .count {
            font-weight: 600;
        }
        
        .char-counter.warning .count {
            color: #d97706;
        }
        
        .char-counter.danger .count {
            color: #dc2626;
        }
        
        .sms-info {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 0.8125rem;
            color: #1e40af;
            margin-top: 0.5rem;
        }
        
        .channel-btn {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            border: 2px solid #e5e7eb;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.375rem;
        }
        
        .channel-btn:hover {
            border-color: #0a6286;
        }
        
        .channel-btn.active {
            border-color: #0a6286;
            background: #e0f2fe;
        }
        
        .channel-btn i {
            font-size: 1.5rem;
        }
        
        .channel-btn span {
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .result-card {
            border-radius: 12px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .result-card.success {
            background: #dcfce7;
            border: 1px solid #86efac;
            color: #166534;
        }
        
        .result-card.error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        
        .usage-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e5e7eb;
            margin-top: 1.5rem;
        }
        
        .usage-card pre {
            background: #1e293b;
            color: #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            overflow-x: auto;
            font-size: 0.8125rem;
        }
        
        @media (max-width: 767px) {
            .page-header {
                padding: 1rem;
                border-radius: 12px;
            }
            
            .page-header h1 {
                font-size: 1.25rem;
            }
            
            .channel-btns {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 0.5rem;
            }
            
            .channel-btn {
                padding: 0.5rem;
            }
            
            .channel-btn i {
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                
                <!-- Page Header -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h1><i class="fas fa-vial me-2"></i>Test Messaging System</h1>
                            <p>Verify SMS and WhatsApp integration</p>
                        </div>
                        <a href="../donor-management/sms/" class="btn btn-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back to SMS Dashboard
                        </a>
                    </div>
                </div>
                
                <div class="row g-3 g-lg-4">
                    <!-- Status Card -->
                    <div class="col-12 col-lg-5">
                        <div class="status-card h-100">
                            <div class="status-header">
                                <i class="fas fa-signal text-primary"></i>
                                System Status
                            </div>
                            <div class="status-body">
                                <div class="status-row">
                                    <span>SMS Service</span>
                                    <?php if ($status['sms_available'] ?? false): ?>
                                        <span class="status-badge success">
                                            <i class="fas fa-check-circle"></i>Available
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge danger">
                                            <i class="fas fa-times-circle"></i>Unavailable
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="status-row">
                                    <span>WhatsApp Service</span>
                                    <?php if ($status['whatsapp_available'] ?? false): ?>
                                        <span class="status-badge success">
                                            <i class="fab fa-whatsapp"></i>Connected
                                            <?php if (!empty($status['whatsapp_status']['status'])): ?>
                                                <small>(<?= htmlspecialchars($status['whatsapp_status']['status']) ?>)</small>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge danger">
                                            <i class="fas fa-times-circle"></i>Unavailable
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="status-row">
                                    <span>Messaging Helper</span>
                                    <?php if ($status['initialized'] ?? false): ?>
                                        <span class="status-badge success">
                                            <i class="fas fa-check-circle"></i>Initialized
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge danger">
                                            <i class="fas fa-times-circle"></i>Not Ready
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!empty($status['errors'])): ?>
                                    <div class="alert alert-warning mt-3 mb-0">
                                        <strong><i class="fas fa-exclamation-triangle me-1"></i>Errors:</strong>
                                        <ul class="mb-0 mt-2">
                                            <?php foreach ($status['errors'] as $error): ?>
                                                <li><?= htmlspecialchars($error) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="mt-3 pt-3 border-top">
                                    <h6 class="mb-2"><i class="fas fa-info-circle text-info me-1"></i>Quick Links</h6>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a href="../donor-management/sms/settings.php" class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-cog me-1"></i>SMS Settings
                                        </a>
                                        <a href="../donor-management/sms/whatsapp-settings.php" class="btn btn-outline-success btn-sm">
                                            <i class="fab fa-whatsapp me-1"></i>WhatsApp Settings
                                        </a>
                                        <a href="../donor-management/message-history.php" class="btn btn-outline-info btn-sm">
                                            <i class="fas fa-history me-1"></i>Message History
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Test Send Form -->
                    <div class="col-12 col-lg-7">
                        <div class="test-form">
                            <div class="test-form-header">
                                <i class="fas fa-paper-plane"></i>
                                Send Test Message
                            </div>
                            <div class="test-form-body">
                                <form method="POST" id="testForm">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Phone Number</label>
                                        <input type="text" name="phone" id="testPhone" class="form-control form-control-lg" 
                                               placeholder="e.g., 07123456789 or +447123456789" 
                                               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                                               required>
                                        <small class="text-muted">Enter the phone number to send a test message to</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Message</label>
                                        <textarea name="message" id="testMessage" class="form-control" rows="4" 
                                                  placeholder="Type your test message here..." required><?= htmlspecialchars($_POST['message'] ?? 'Hello! This is a test message from the Fundraising system.') ?></textarea>
                                        <div class="char-counter" id="charCounter">
                                            <span>Characters: <span class="count" id="charCount">0</span>/160</span>
                                            <span>SMS parts: <span class="count" id="smsCount">1</span></span>
                                        </div>
                                        <div class="sms-info" id="smsInfo">
                                            <i class="fas fa-info-circle me-1"></i>
                                            <span id="smsInfoText">1 SMS = 160 characters (or 70 with special chars)</span>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label class="form-label fw-bold">Channel</label>
                                        <div class="channel-btns d-flex gap-2 flex-wrap">
                                            <label class="channel-btn" data-channel="auto">
                                                <input type="radio" name="channel" value="auto" class="d-none" checked>
                                                <i class="fas fa-magic text-primary"></i>
                                                <span>Auto</span>
                                            </label>
                                            <label class="channel-btn" data-channel="sms">
                                                <input type="radio" name="channel" value="sms" class="d-none">
                                                <i class="fas fa-sms text-info"></i>
                                                <span>SMS Only</span>
                                            </label>
                                            <label class="channel-btn" data-channel="whatsapp">
                                                <input type="radio" name="channel" value="whatsapp" class="d-none">
                                                <i class="fab fa-whatsapp text-success"></i>
                                                <span>WhatsApp</span>
                                            </label>
                                            <label class="channel-btn" data-channel="both">
                                                <input type="radio" name="channel" value="both" class="d-none">
                                                <i class="fas fa-layer-group text-warning"></i>
                                                <span>Both</span>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary btn-lg w-100">
                                        <i class="fas fa-paper-plane me-2"></i>Send Test Message
                                    </button>
                                </form>
                                
                                <?php if ($send_result !== null): ?>
                                    <?php if ($send_result['success']): ?>
                                        <div class="result-card success">
                                            <h6><i class="fas fa-check-circle me-1"></i>Message Sent Successfully!</h6>
                                            <p class="mb-1">Channel: <strong><?= htmlspecialchars($send_result['channel'] ?? 'unknown') ?></strong></p>
                                            <?php if (!empty($send_result['message_id'])): ?>
                                                <p class="mb-0">Message ID: <code><?= htmlspecialchars($send_result['message_id']) ?></code></p>
                                            <?php endif; ?>
                                            <?php if (($_POST['channel'] ?? '') === 'both' && isset($send_result['sms'], $send_result['whatsapp'])): ?>
                                                <hr class="my-2">
                                                <p class="mb-1">SMS: <?= $send_result['sms']['success'] ? '✅ Sent' : '❌ Failed' ?></p>
                                                <p class="mb-0">WhatsApp: <?= $send_result['whatsapp']['success'] ? '✅ Sent' : '❌ Failed' ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="result-card error">
                                            <h6><i class="fas fa-times-circle me-1"></i>Message Failed</h6>
                                            <p class="mb-0"><?= htmlspecialchars($send_result['error'] ?? 'Unknown error occurred') ?></p>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Usage Examples -->
                <div class="usage-card">
                    <div class="status-header">
                        <i class="fas fa-code text-primary"></i>
                        Usage Examples
                    </div>
                    <div class="status-body">
                        <pre><code>// Send using template
$msg = new MessagingHelper($db);
$result = $msg->sendFromTemplate(
    'payment_reminder_3day',
    $donorId,
    ['name' => 'John', 'amount' => '£50'],
    'auto'  // Smart channel selection
);

// Send direct message
$result = $msg->sendDirect(
    '07123456789',
    'Hello! Your payment is due soon.',
    'auto'
);

// Send to donor
$result = $msg->sendToDonor(
    $donorId,
    'Thank you for your payment!',
    'auto'
);</code></pre>
                        <p class="text-muted mt-3 mb-0">
                            <i class="fas fa-book me-1"></i>
                            See <code>docs/MESSAGING_SYSTEM_USAGE.md</code> for full documentation.
                        </p>
                    </div>
                </div>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// Character counter for SMS
const messageInput = document.getElementById('testMessage');
const charCount = document.getElementById('charCount');
const smsCount = document.getElementById('smsCount');
const charCounter = document.getElementById('charCounter');
const smsInfoText = document.getElementById('smsInfoText');

function hasSpecialChars(text) {
    // GSM 7-bit alphabet doesn't include these
    return /[^\x00-\x7F]|[€\[\]{}\\~\^|]/.test(text);
}

function updateCharCount() {
    const text = messageInput.value;
    const len = text.length;
    const isUnicode = hasSpecialChars(text);
    
    // SMS length limits
    const singleLimit = isUnicode ? 70 : 160;
    const multiLimit = isUnicode ? 67 : 153;
    
    charCount.textContent = len;
    charCounter.querySelector('span:first-child span:last-child').textContent = `/${singleLimit}`;
    
    let parts = 1;
    if (len > singleLimit) {
        parts = Math.ceil(len / multiLimit);
    }
    smsCount.textContent = parts;
    
    // Update info text
    if (isUnicode) {
        smsInfoText.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Special characters detected! 1 SMS = 70 chars (67 if multipart)';
        smsInfoText.parentElement.style.background = '#fef3c7';
        smsInfoText.parentElement.style.borderColor = '#fcd34d';
        smsInfoText.parentElement.style.color = '#92400e';
    } else {
        smsInfoText.innerHTML = '<i class="fas fa-info-circle me-1"></i>1 SMS = 160 characters (153 if multipart)';
        smsInfoText.parentElement.style.background = '#eff6ff';
        smsInfoText.parentElement.style.borderColor = '#bfdbfe';
        smsInfoText.parentElement.style.color = '#1e40af';
    }
    
    // Warning colors
    charCounter.classList.remove('warning', 'danger');
    if (parts > 1 && parts <= 2) {
        charCounter.classList.add('warning');
    } else if (parts > 2) {
        charCounter.classList.add('danger');
    }
}

messageInput.addEventListener('input', updateCharCount);
updateCharCount();

// Channel selection
document.querySelectorAll('.channel-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.channel-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        this.querySelector('input').checked = true;
    });
    
    // Set initial active state
    if (btn.querySelector('input').checked) {
        btn.classList.add('active');
    }
});

// Sidebar toggle fallback
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
