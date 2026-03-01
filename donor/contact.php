<?php
/**
 * Donor Portal - Contact Support / Support Requests
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../shared/url.php';
require_once __DIR__ . '/../shared/audit_helper.php';
require_once __DIR__ . '/../admin/includes/resilient_db_loader.php';
require_once __DIR__ . '/../services/UltraMsgService.php';
require_once __DIR__ . '/../services/TwilioService.php';
require_once __DIR__ . '/../services/SMSHelper.php';

// Default fallback if no phone is configured via env/settings.
define('DEFAULT_ADMIN_NOTIFICATION_PHONE', '07360436171');

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

function normalize_phone_for_notification(string $phone): string {
    $phone = trim($phone);
    if ($phone === '') {
        return '';
    }
    // Keep leading + if present, strip other non-digits.
    if (strpos($phone, '+') === 0) {
        return '+' . preg_replace('/\D+/', '', substr($phone, 1));
    }
    return preg_replace('/\D+/', '', $phone);
}

function resolve_admin_notification_phone(array $settings): string {
    $candidates = [
        (string)($_ENV['ADMIN_NOTIFICATION_PHONE'] ?? ''),
        (string)getenv('ADMIN_NOTIFICATION_PHONE'),
        (string)($settings['admin_notification_phone'] ?? ''),
        (string)($settings['support_notification_phone'] ?? ''),
        DEFAULT_ADMIN_NOTIFICATION_PHONE
    ];

    foreach ($candidates as $candidate) {
        $normalized = normalize_phone_for_notification($candidate);
        if ($normalized !== '') {
            return $normalized;
        }
    }

    return DEFAULT_ADMIN_NOTIFICATION_PHONE;
}

function resolve_public_origin(array $settings): string {
    $candidates = [
        (string)($_ENV['APP_URL'] ?? ''),
        (string)getenv('APP_URL'),
        (string)($settings['app_url'] ?? ''),
        (string)($settings['site_url'] ?? ''),
        (string)($settings['public_base_url'] ?? '')
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        $parts = parse_url($candidate);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            continue;
        }
        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            continue;
        }
        $host = (string)$parts['host'];
        $port = isset($parts['port']) ? (':' . (int)$parts['port']) : '';
        return $scheme . '://' . $host . $port;
    }

    // Safe fallback for production. For local development allow localhost host:port.
    $raw_host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (preg_match('/^(localhost|127\.0\.0\.1)(:\d{1,5})?$/', $raw_host) === 1) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $raw_host;
    }

    return 'https://donate.abuneteklehaymanot.org';
}

function build_absolute_url(string $origin, string $path): string {
    return rtrim($origin, '/') . '/' . ltrim($path, '/');
}

function get_support_rate_limit_message(mysqli $db, int $donor_id, string $action): string {
    $checks = [];

    if ($action === 'submit_request') {
        $checks = [
            [
                'sql' => "SELECT COUNT(*) AS cnt FROM donor_support_requests WHERE donor_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
                'max' => 3,
                'message' => 'Please wait a few minutes before sending another request.'
            ],
            [
                'sql' => "SELECT COUNT(*) AS cnt FROM donor_support_requests WHERE donor_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
                'max' => 20,
                'message' => 'Daily request limit reached. Please try again tomorrow or call support.'
            ]
        ];
    } elseif ($action === 'add_reply') {
        $checks = [
            [
                'sql' => "SELECT COUNT(*) AS cnt FROM donor_support_replies WHERE donor_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
                'max' => 8,
                'message' => 'Please slow down. You are sending replies too quickly.'
            ],
            [
                'sql' => "SELECT COUNT(*) AS cnt FROM donor_support_replies WHERE donor_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
                'max' => 60,
                'message' => 'Daily reply limit reached. Please continue later.'
            ]
        ];
    }

    foreach ($checks as $check) {
        $stmt = $db->prepare($check['sql']);
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('i', $donor_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $count = (int)($row['cnt'] ?? 0);
        if ($count >= (int)$check['max']) {
            return (string)$check['message'];
        }
    }

    return '';
}

require_donor_login();
validate_donor_device(); // Check if device was revoked
$donor = current_donor();
$page_title = 'Contact Support';
$current_donor = $donor;
$admin_notification_phone = resolve_admin_notification_phone($settings ?? []);
$public_origin = resolve_public_origin($settings ?? []);

$success_message = '';
$error_message = '';

// Check if support tables exist
$tables_exist = false;
if ($db_connection_ok) {
    $table_check = $db->query("SHOW TABLES LIKE 'donor_support_requests'");
    $tables_exist = $table_check && $table_check->num_rows > 0;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_connection_ok) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $rate_limit_message = get_support_rate_limit_message($db, (int)$donor['id'], $action);
    if ($rate_limit_message !== '') {
        $error_message = $rate_limit_message;
    }
    
    if ($error_message === '' && $action === 'submit_request' && $tables_exist) {
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $category = trim($_POST['category'] ?? 'general');
        $allowed_categories = ['payment', 'plan', 'account', 'general', 'other'];
        if (!in_array($category, $allowed_categories, true)) {
            $category = 'general';
        }
        
        if (empty($subject) || empty($message)) {
            $error_message = 'Please fill in all required fields.';
        } elseif (strlen($subject) > 255) {
            $error_message = 'Subject is too long (max 255 characters).';
        } elseif (strlen($subject) < 3) {
            $error_message = 'Subject is too short.';
        } elseif (strlen($message) > 5000) {
            $error_message = 'Message is too long (max 5000 characters).';
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO donor_support_requests (donor_id, category, subject, message, status, priority)
                    VALUES (?, ?, ?, ?, 'open', 'normal')
                ");
                $stmt->bind_param('isss', $donor['id'], $category, $subject, $message);
                $stmt->execute();
                $request_id = $db->insert_id;
                $stmt->close();
                
                // Audit log
                log_audit($db, 'create', 'support_request', $request_id, null, [
                    'donor_id' => $donor['id'],
                    'category' => $category,
                    'subject' => $subject
                ], 'donor_portal', 0);
                
                // ===========================================
                // ROBUST NOTIFICATION SYSTEM
                // Priority: 1. WhatsApp → 2. Phone Call → 3. SMS
                // ===========================================
                $notification_sent = false;
                $notification_method = '';
                $notification_error = '';
                
                $category_labels = [
                    'payment' => 'Payment Question',
                    'plan' => 'Payment Plan',
                    'account' => 'Account Issue',
                    'general' => 'General Inquiry',
                    'other' => 'Other'
                ];
                $cat_label = $category_labels[$category] ?? 'General';
                
                // Step 1: Try WhatsApp first
                try {
                    $whatsapp = UltraMsgService::fromDatabase($db);
                    if ($whatsapp) {
                        $wa_message = "🆘 *NEW SUPPORT REQUEST* 🆘\n\n";
                        $wa_message .= "*Request ID:* #{$request_id}\n";
                        $wa_message .= "*From:* {$donor['name']}\n";
                        $wa_message .= "*Phone:* {$donor['phone']}\n";
                        $wa_message .= "*Category:* {$cat_label}\n";
                        $wa_message .= "*Subject:* {$subject}\n\n";
                        $wa_message .= "*Message:*\n{$message}\n\n";
                        $wa_message .= "━━━━━━━━━━━━━━━\n";
                        $view_url = build_absolute_url($public_origin, url_for('admin/donor-management/support-requests.php')) . '?view=' . urlencode((string)$request_id);
                        $wa_message .= "View: {$view_url}";
                        
                        $wa_result = $whatsapp->send($admin_notification_phone, $wa_message);
                        
                        if ($wa_result['success']) {
                            $notification_sent = true;
                            $notification_method = 'whatsapp';
                            error_log("Support notification sent via WhatsApp for request #{$request_id}");
                        } else {
                            throw new Exception($wa_result['error'] ?? 'WhatsApp send failed');
                        }
                    } else {
                        throw new Exception('WhatsApp service not configured');
                    }
                } catch (Exception $wa_error) {
                    $notification_error = "WhatsApp failed: " . $wa_error->getMessage();
                    error_log($notification_error);
                }
                
                // Step 2: If WhatsApp failed, try Phone Call
                if (!$notification_sent) {
                    try {
                        $twilio = TwilioService::fromDatabase($db);
                        if ($twilio) {
                            // Build TwiML URL for the notification message
                            $twiml_url = build_absolute_url($public_origin, url_for('admin/call-center/api/twilio-support-notification.php'));
                            $twiml_url .= '?request_id=' . urlencode((string)$request_id);
                            $twiml_url .= '&donor_name=' . urlencode($donor['name']);
                            
                            $call_result = $twilio->makeNotificationCall($admin_notification_phone, $twiml_url);
                            
                            if ($call_result['success']) {
                                $notification_sent = true;
                                $notification_method = 'phone_call';
                                error_log("Support notification sent via Phone Call for request #{$request_id}, CallSid: " . $call_result['call_sid']);
                            } else {
                                throw new Exception($call_result['error'] ?? 'Call failed');
                            }
                        } else {
                            throw new Exception('Twilio service not configured');
                        }
                    } catch (Exception $call_error) {
                        $notification_error .= " | Call failed: " . $call_error->getMessage();
                        error_log("Phone call notification failed: " . $call_error->getMessage());
                    }
                }
                
                // Step 3: If both WhatsApp and Call failed, try SMS
                if (!$notification_sent) {
                    try {
                        $sms = new SMSHelper($db);
                        if ($sms->isReady()) {
                            $sms_message = "🆘 SUPPORT REQUEST #{$request_id}\n";
                            $sms_message .= "From: {$donor['name']} ({$donor['phone']})\n";
                            $sms_message .= "Category: {$cat_label}\n";
                            $sms_message .= "Subject: {$subject}\n";
                            $sms_message .= "Please check the donor portal.";
                            
                            $sms_result = $sms->sendDirect($admin_notification_phone, $sms_message);
                            
                            if ($sms_result['success']) {
                                $notification_sent = true;
                                $notification_method = 'sms';
                                error_log("Support notification sent via SMS for request #{$request_id}");
                            } else {
                                throw new Exception($sms_result['error'] ?? 'SMS send failed');
                            }
                        } else {
                            throw new Exception('SMS service not ready');
                        }
                    } catch (Exception $sms_error) {
                        $notification_error .= " | SMS failed: " . $sms_error->getMessage();
                        error_log("SMS notification failed: " . $sms_error->getMessage());
                    }
                }
                
                // Log final notification status
                if ($notification_sent) {
                    error_log("Support request #{$request_id} notification SUCCESS via {$notification_method}");
                } else {
                    error_log("Support request #{$request_id} notification FAILED - All methods exhausted: {$notification_error}");
                }
                
                $success_message = 'Your support request has been submitted successfully! We will respond as soon as possible.';
                $_POST = []; // Clear form
            } catch (Exception $e) {
                error_log("Support request error: " . $e->getMessage());
                $error_message = 'An error occurred. Please try again later.';
            }
        }
    } elseif ($error_message === '' && $action === 'add_reply' && $tables_exist) {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $message = trim($_POST['reply_message'] ?? '');
        
        if ($request_id <= 0 || empty($message)) {
            $error_message = 'Please enter a message.';
        } elseif (strlen($message) > 3000) {
            $error_message = 'Reply is too long (max 3000 characters).';
        } else {
            // Verify request belongs to donor
            $check = $db->prepare("SELECT id, status FROM donor_support_requests WHERE id = ? AND donor_id = ?");
            $check->bind_param('ii', $request_id, $donor['id']);
            $check->execute();
            $request = $check->get_result()->fetch_assoc();
            $check->close();
            
            if ($request && $request['status'] !== 'closed') {
                try {
                    // Add reply
                    $stmt = $db->prepare("
                        INSERT INTO donor_support_replies (request_id, donor_id, message, is_internal)
                        VALUES (?, ?, ?, 0)
                    ");
                    $stmt->bind_param('iis', $request_id, $donor['id'], $message);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Update request status if it was resolved
                    if ($request['status'] === 'resolved') {
                        $reopen_stmt = $db->prepare("UPDATE donor_support_requests SET status = 'open' WHERE id = ?");
                        if ($reopen_stmt) {
                            $reopen_stmt->bind_param('i', $request_id);
                            $reopen_stmt->execute();
                            $reopen_stmt->close();
                        }
                    }
                    
                    $success_message = 'Your reply has been added.';
                } catch (Exception $e) {
                    error_log("Support reply error: " . $e->getMessage());
                    $error_message = 'An error occurred. Please try again.';
                }
            } else {
                $error_message = 'Cannot reply to this request.';
            }
        }
    }
}

// Fetch donor's support requests
$requests = [];
$view_request = null;
if ($tables_exist && $db_connection_ok) {
    // Check if viewing a specific request
    if (isset($_GET['view']) && is_numeric($_GET['view'])) {
        $view_id = (int)$_GET['view'];
        $stmt = $db->prepare("
            SELECT sr.*, u.name as assigned_name
            FROM donor_support_requests sr
            LEFT JOIN users u ON sr.assigned_to = u.id
            WHERE sr.id = ? AND sr.donor_id = ?
        ");
        $stmt->bind_param('ii', $view_id, $donor['id']);
        $stmt->execute();
        $view_request = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Fetch replies
        if ($view_request) {
            $replies_stmt = $db->prepare("
                SELECT r.*, u.name as admin_name
                FROM donor_support_replies r
                LEFT JOIN users u ON r.user_id = u.id
                WHERE r.request_id = ? AND r.is_internal = 0
                ORDER BY r.created_at ASC
            ");
            $replies_stmt->bind_param('i', $view_id);
            $replies_stmt->execute();
            $view_request['replies'] = $replies_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $replies_stmt->close();
        }
    }
    
    // Fetch all requests
    $stmt = $db->prepare("
        SELECT id, category, subject, status, priority, created_at, updated_at,
               (SELECT COUNT(*) FROM donor_support_replies WHERE request_id = donor_support_requests.id AND is_internal = 0) as reply_count
        FROM donor_support_requests
        WHERE donor_id = ?
        ORDER BY 
            CASE status 
                WHEN 'open' THEN 1 
                WHEN 'in_progress' THEN 2 
                WHEN 'resolved' THEN 3 
                WHEN 'closed' THEN 4 
            END,
            created_at DESC
        LIMIT 50
    ");
    $stmt->bind_param('i', $donor['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    $stmt->close();
}

// Category labels
$categories = [
    'payment' => ['label' => 'Payment Question', 'icon' => 'fa-credit-card', 'color' => 'primary'],
    'plan' => ['label' => 'Payment Plan', 'icon' => 'fa-calendar-alt', 'color' => 'info'],
    'account' => ['label' => 'Account Issue', 'icon' => 'fa-user-cog', 'color' => 'warning'],
    'general' => ['label' => 'General Inquiry', 'icon' => 'fa-question-circle', 'color' => 'secondary'],
    'other' => ['label' => 'Other', 'icon' => 'fa-ellipsis-h', 'color' => 'dark']
];

// Status labels
$statuses = [
    'open' => ['label' => 'Open', 'color' => 'warning'],
    'in_progress' => ['label' => 'In Progress', 'color' => 'info'],
    'resolved' => ['label' => 'Resolved', 'color' => 'success'],
    'closed' => ['label' => 'Closed', 'color' => 'secondary']
];

$view_category_key = 'other';
$view_status_key = 'open';
if (is_array($view_request)) {
    $raw_view_category = (string)($view_request['category'] ?? '');
    $raw_view_status = (string)($view_request['status'] ?? '');
    if (array_key_exists($raw_view_category, $categories)) {
        $view_category_key = $raw_view_category;
    }
    if (array_key_exists($raw_view_status, $statuses)) {
        $view_status_key = $raw_view_status;
    }
}

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', $time);
}
?>
<!DOCTYPE html>
<html lang="<?php echo h($donor['preferred_language'] ?? 'en'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($page_title); ?> - Donor Portal</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/donor.css?v=<?php echo @filemtime(__DIR__ . '/assets/donor.css'); ?>">
    <style>
        .request-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
            display: block;
            color: inherit;
        }
        .request-card:hover {
            border-color: var(--primary-color, #0a6286);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            color: inherit;
        }
        .request-card.status-open { border-left: 4px solid #f59e0b; }
        .request-card.status-in_progress { border-left: 4px solid #3b82f6; }
        .request-card.status-resolved { border-left: 4px solid #10b981; }
        .request-card.status-closed { border-left: 4px solid #6b7280; }
        .reply-bubble {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            max-width: 85%;
        }
        .reply-donor {
            background: linear-gradient(135deg, #0a6286 0%, #084d68 100%);
            color: white;
            margin-left: auto;
            border-bottom-right-radius: 4px;
        }
        .reply-admin {
            background: #f1f5f9;
            color: #1e293b;
            border-bottom-left-radius: 4px;
        }
        .reply-meta {
            font-size: 0.75rem;
            opacity: 0.8;
            margin-top: 0.5rem;
        }
        .conversation-container {
            max-height: 400px;
            overflow-y: auto;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 12px;
        }
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #64748b;
        }
        .empty-state i {
            font-size: 3rem;
            opacity: 0.3;
            margin-bottom: 1rem;
        }
        .setup-alert {
            background: linear-gradient(135deg, #fef3c7 0%, #fef9c3 100%);
            border: 1px solid #fcd34d;
            border-radius: 12px;
            padding: 1.5rem;
        }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="app-content">
        <?php include 'includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                <div class="page-header mb-4">
                    <h1 class="page-title">
                        <i class="fas fa-headset me-2 text-primary"></i>Contact Support
                    </h1>
                </div>

                <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo h($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo h($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if (!$tables_exist): ?>
                <!-- Setup Required -->
                <div class="setup-alert">
                    <div class="d-flex gap-3">
                        <i class="fas fa-info-circle fa-2x text-warning"></i>
                        <div>
                            <h5 class="mb-2">Support System Coming Soon</h5>
                            <p class="mb-0">The support request system is being set up. Please contact the church office directly for assistance in the meantime.</p>
                        </div>
                    </div>
                </div>
                
                <?php elseif ($view_request): ?>
                <!-- View Single Request -->
                <div class="mb-3">
                    <a href="contact.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back to All Requests
                    </a>
                </div>
                
                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-ticket-alt me-2"></i>Request #<?php echo $view_request['id']; ?>
                                </h5>
                                <span class="badge bg-<?php echo h($statuses[$view_status_key]['color']); ?>">
                                    <?php echo h($statuses[$view_status_key]['label']); ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <span class="badge bg-<?php echo h($categories[$view_category_key]['color']); ?>">
                                        <i class="fas <?php echo h($categories[$view_category_key]['icon']); ?> me-1"></i>
                                        <?php echo h($categories[$view_category_key]['label']); ?>
                                    </span>
                                    <small class="text-muted ms-2">
                                        <i class="fas fa-clock me-1"></i><?php echo timeAgo($view_request['created_at']); ?>
                                    </small>
                                </div>
                                <h5><?php echo h($view_request['subject']); ?></h5>
                                <p class="text-muted"><?php echo nl2br(h($view_request['message'])); ?></p>
                            </div>
                        </div>
                        
                        <!-- Conversation -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-comments me-2"></i>Conversation
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($view_request['replies'])): ?>
                                <div class="conversation-container mb-3">
                                    <?php foreach ($view_request['replies'] as $reply): ?>
                                    <div class="reply-bubble <?php echo $reply['donor_id'] ? 'reply-donor' : 'reply-admin'; ?>">
                                        <div><?php echo nl2br(h($reply['message'])); ?></div>
                                        <div class="reply-meta">
                                            <?php if ($reply['donor_id']): ?>
                                            <i class="fas fa-user me-1"></i>You
                                            <?php else: ?>
                                            <i class="fas fa-headset me-1"></i><?php echo h($reply['admin_name'] ?? 'Support'); ?>
                                            <?php endif; ?>
                                            · <?php echo timeAgo($reply['created_at']); ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <p class="text-muted text-center py-3">
                                    <i class="fas fa-comments opacity-25 fa-2x d-block mb-2"></i>
                                    No replies yet. We'll respond soon!
                                </p>
                                <?php endif; ?>
                                
                                <?php if ($view_request['status'] !== 'closed'): ?>
                                <form method="POST">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="add_reply">
                                    <input type="hidden" name="request_id" value="<?php echo $view_request['id']; ?>">
                                    <div class="mb-3">
                                        <textarea class="form-control" name="reply_message" rows="3" 
                                                  placeholder="Type your reply..." required></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane me-1"></i>Send Reply
                                    </button>
                                </form>
                                <?php else: ?>
                                <div class="alert alert-secondary mb-0">
                                    <i class="fas fa-lock me-2"></i>This request is closed. Open a new request if you need further assistance.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title mb-0">Request Details</h6>
                            </div>
                            <div class="card-body">
                                <dl class="row mb-0">
                                    <dt class="col-5 text-muted">Status</dt>
                                    <dd class="col-7">
                                        <span class="badge bg-<?php echo h($statuses[$view_status_key]['color']); ?>">
                                            <?php echo h($statuses[$view_status_key]['label']); ?>
                                        </span>
                                    </dd>
                                    <dt class="col-5 text-muted">Category</dt>
                                    <dd class="col-7"><?php echo h($categories[$view_category_key]['label']); ?></dd>
                                    <dt class="col-5 text-muted">Created</dt>
                                    <dd class="col-7"><?php echo date('M j, Y g:i A', strtotime($view_request['created_at'])); ?></dd>
                                    <?php if ($view_request['assigned_name']): ?>
                                    <dt class="col-5 text-muted">Assigned To</dt>
                                    <dd class="col-7"><?php echo h($view_request['assigned_name']); ?></dd>
                                    <?php endif; ?>
                                    <?php if ($view_request['resolved_at']): ?>
                                    <dt class="col-5 text-muted">Resolved</dt>
                                    <dd class="col-7"><?php echo date('M j, Y', strtotime($view_request['resolved_at'])); ?></dd>
                                    <?php endif; ?>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php else: ?>
                <!-- Request List & New Request Form -->
                <div class="row g-4">
                    <!-- New Request Form -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-plus-circle text-primary me-2"></i>New Support Request
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="submit_request">
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Category <span class="text-danger">*</span></label>
                                        <select class="form-select" name="category" required>
                                            <option value="">Select a category...</option>
                                            <?php foreach ($categories as $key => $cat): ?>
                                            <option value="<?php echo $key; ?>">
                                                <?php echo $cat['label']; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Subject <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="subject" 
                                               placeholder="Brief description of your question..." 
                                               maxlength="255" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Message <span class="text-danger">*</span></label>
                                        <textarea class="form-control" name="message" rows="5" 
                                                  placeholder="Please provide details about your question or issue..."
                                                  required></textarea>
                                    </div>

                                    <div class="alert alert-info py-2">
                                        <small>
                                            <i class="fas fa-info-circle me-1"></i>
                                            Your contact info: <strong><?php echo h($donor['name']); ?></strong> (<?php echo h($donor['phone']); ?>)
                                        </small>
                                    </div>

                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-paper-plane me-2"></i>Submit Request
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Previous Requests -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-history text-primary me-2"></i>Your Requests
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($requests)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <p class="mb-0">No support requests yet</p>
                                </div>
                                <?php else: ?>
                                <?php foreach ($requests as $req): ?>
                                <?php
                                $req_category_key = array_key_exists((string)($req['category'] ?? ''), $categories) ? (string)$req['category'] : 'other';
                                $req_status_key = array_key_exists((string)($req['status'] ?? ''), $statuses) ? (string)$req['status'] : 'open';
                                ?>
                                <a href="contact.php?view=<?php echo $req['id']; ?>" 
                                   class="request-card status-<?php echo h($req_status_key); ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <span class="badge bg-<?php echo h($categories[$req_category_key]['color']); ?> bg-opacity-75">
                                            <i class="fas <?php echo h($categories[$req_category_key]['icon']); ?> me-1"></i>
                                            <?php echo h($categories[$req_category_key]['label']); ?>
                                        </span>
                                        <span class="badge bg-<?php echo h($statuses[$req_status_key]['color']); ?>">
                                            <?php echo h($statuses[$req_status_key]['label']); ?>
                                        </span>
                                    </div>
                                    <h6 class="mb-1"><?php echo h($req['subject']); ?></h6>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted">
                                            <i class="fas fa-clock me-1"></i><?php echo timeAgo($req['created_at']); ?>
                                        </small>
                                        <?php if ($req['reply_count'] > 0): ?>
                                        <small class="text-muted">
                                            <i class="fas fa-comments me-1"></i><?php echo $req['reply_count']; ?> replies
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/donor.js"></script>
</body>
</html>
