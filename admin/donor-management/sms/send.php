<?php
declare(strict_types=1);

// Enable error display for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    require_once __DIR__ . '/../../../shared/auth.php';
} catch (Throwable $e) {
    die('Error loading auth.php: ' . $e->getMessage());
}

try {
    require_once __DIR__ . '/../../../shared/csrf.php';
} catch (Throwable $e) {
    die('Error loading csrf.php: ' . $e->getMessage());
}

try {
    require_once __DIR__ . '/../../../config/db.php';
} catch (Throwable $e) {
    die('Error loading db.php: ' . $e->getMessage());
}

try {
    require_login();
    require_admin();
} catch (Throwable $e) {
    die('Auth error: ' . $e->getMessage());
}

$page_title = 'Send SMS';
$current_user = current_user();

try {
    $db = db();
} catch (Throwable $e) {
    die('Database connection error: ' . $e->getMessage());
}

$templates = [];
$donors = [];
$error_message = null;
$success_message = null;
$tables_exist = false;

// Check if SMS tables exist
try {
    $check = $db->query("SHOW TABLES LIKE 'sms_templates'");
    $tables_exist = $check && $check->num_rows > 0;
} catch (Throwable $e) {
    // Ignore
}

// Get templates
if ($tables_exist) {
    try {
        $result = $db->query("SELECT id, template_key, name, message_en FROM sms_templates WHERE is_active = 1 ORDER BY name");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $templates[] = $row;
            }
        }
    } catch (Exception $e) {
        // Ignore
    }
}

// Handle search for donors
$search_results = [];
if (isset($_GET['search_donor']) && !empty($_GET['search_donor'])) {
    $search = '%' . $_GET['search_donor'] . '%';
    $stmt = $db->prepare("
        SELECT id, name, phone, sms_opt_in 
        FROM donors 
        WHERE (name LIKE ? OR phone LIKE ?) AND sms_opt_in = 1
        LIMIT 10
    ");
    $stmt->bind_param('ss', $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $search_results[] = $row;
    }
    
    // Return JSON for AJAX
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode($search_results);
        exit;
    }
}

// Check if sms_queue table exists
$queue_table_exists = false;
try {
    $check = $db->query("SHOW TABLES LIKE 'sms_queue'");
    $queue_table_exists = $check && $check->num_rows > 0;
} catch (Throwable $e) {
    // Ignore
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    try {
        $donor_id = isset($_POST['donor_id']) && $_POST['donor_id'] !== '' ? (int)$_POST['donor_id'] : null;
        $phone_number = trim($_POST['phone_number'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $template_id = isset($_POST['template_id']) && $_POST['template_id'] !== '' ? (int)$_POST['template_id'] : null;
        $priority = (int)($_POST['priority'] ?? 5);
        $schedule = $_POST['schedule'] ?? 'now';
        $scheduled_for = $schedule === 'later' ? ($_POST['scheduled_for'] ?? null) : null;
        
        // Validation
        if (empty($phone_number)) {
            throw new Exception('Phone number is required.');
        }
        
        if (empty($message)) {
            throw new Exception('Message is required.');
        }
        
        if (mb_strlen($message) > 918) {
            throw new Exception('Message is too long (max 918 characters / 6 SMS segments).');
        }
        
        // Get donor name if donor_id provided
        $recipient_name = null;
        if ($donor_id) {
            $stmt = $db->prepare("SELECT name FROM donors WHERE id = ?");
            $stmt->bind_param('i', $donor_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $recipient_name = $row['name'];
            }
        }
        
        // If scheduled for later, add to queue
        if ($schedule === 'later' && $scheduled_for) {
            if (!$queue_table_exists) {
                throw new Exception('SMS queue table not found. Cannot schedule messages.');
            }
            
            $stmt = $db->prepare("
                INSERT INTO sms_queue 
                (donor_id, phone_number, recipient_name, template_id, message_content, message_language,
                 source_type, priority, scheduled_for, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, 'en', 'admin_manual', ?, ?, 'pending', ?, NOW())
            ");
            $stmt->bind_param('issisisi', 
                $donor_id, $phone_number, $recipient_name, $template_id, $message, 
                $priority, $scheduled_for, $current_user['id']
            );
            $stmt->execute();
            
            $success_message = 'SMS scheduled for ' . date('M j, g:i A', strtotime($scheduled_for));
        } else {
            // Send immediately via default SMS provider
            require_once __DIR__ . '/../../../services/SMSServiceFactory.php';
            
            $sms = SMSServiceFactory::getDefaultService($db);
            
            if (!$sms) {
                throw new Exception('No SMS provider configured. Please add provider credentials in Settings.');
            }
            
            $result = $sms->send($phone_number, $message, [
                'donor_id' => $donor_id,
                'template_id' => $template_id,
                'source_type' => 'admin_manual',
                'log' => true
            ]);
            
            if ($result['success']) {
                $success_message = '✅ SMS sent successfully! (Used ' . $result['credits_used'] . ' credit' . ($result['credits_used'] > 1 ? 's' : '') . ')';
            } else {
                throw new Exception('Failed to send SMS: ' . ($result['error'] ?? 'Unknown error'));
            }
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Pre-fill if donor_id passed
$prefill_donor = null;
if (isset($_GET['donor_id'])) {
    $donor_id = (int)$_GET['donor_id'];
    $stmt = $db->prepare("SELECT id, name, phone, preferred_language FROM donors WHERE id = ?");
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $prefill_donor = $stmt->get_result()->fetch_assoc();
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
    <style>
        .send-form-card {
            max-width: 600px;
            margin: 0 auto;
        }
        .char-counter {
            font-size: 0.8125rem;
            color: #64748b;
        }
        .char-counter.warning { color: #f59e0b; }
        .char-counter.danger { color: #ef4444; }
        .template-preview {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        .donor-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            z-index: 100;
            max-height: 200px;
            overflow-y: auto;
        }
        .donor-search-item {
            padding: 0.625rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
        }
        .donor-search-item:hover {
            background: #f8fafc;
        }
        .donor-search-item:last-child {
            border-bottom: none;
        }
    </style>
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
                            <li class="breadcrumb-item active">Send SMS</li>
                        </ol>
                    </nav>
                    <h1 class="h3 mb-0">
                        <i class="fas fa-paper-plane text-primary me-2"></i>Send SMS
                    </h1>
                </div>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show" style="max-width: 600px; margin: 0 auto 1rem;">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show" style="max-width: 600px; margin: 0 auto 1rem;">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card send-form-card">
                    <div class="card-body p-4">
                        <form method="POST" id="sendForm">
                            <?php echo csrf_input(); ?>
                            
                            <!-- Recipient -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Recipient</label>
                                <div class="position-relative">
                                    <input type="text" id="donorSearch" class="form-control mb-2" 
                                           placeholder="Search donor by name or phone..."
                                           value="<?php echo $prefill_donor ? htmlspecialchars($prefill_donor['name']) : ''; ?>"
                                           autocomplete="off">
                                    <div id="searchResults" class="donor-search-results d-none"></div>
                                </div>
                                <input type="hidden" name="donor_id" id="donorId" 
                                       value="<?php echo $prefill_donor['id'] ?? ''; ?>">
                                <input type="tel" name="phone_number" id="phoneNumber" class="form-control" 
                                       placeholder="07XXX XXX XXX" required
                                       value="<?php echo $prefill_donor ? htmlspecialchars($prefill_donor['phone']) : ''; ?>">
                                <div class="form-text">UK mobile number only</div>
                            </div>
                            
                            <!-- Template Selection -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Use Template (Optional)</label>
                                <select name="template_id" id="templateSelect" class="form-select">
                                    <option value="">-- Write custom message --</option>
                                    <?php foreach ($templates as $template): ?>
                                        <option value="<?php echo $template['id']; ?>" 
                                                data-message="<?php echo htmlspecialchars($template['message_en']); ?>">
                                            <?php echo htmlspecialchars($template['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="templatePreview" class="template-preview d-none"></div>
                            </div>
                            
                            <!-- Message -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Message <span class="text-danger">*</span></label>
                                <textarea name="message" id="messageText" class="form-control" rows="4" required
                                          placeholder="Type your message here..."
                                          maxlength="480"></textarea>
                                <div class="d-flex justify-content-between mt-1">
                                    <div class="form-text">Variables: {name}, {amount}, {due_date}, {portal_link}</div>
                                    <span id="charCounter" class="char-counter">0 / 160</span>
                                </div>
                            </div>
                            
                            <!-- Priority -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Priority</label>
                                <select name="priority" class="form-select">
                                    <option value="3">Low</option>
                                    <option value="5" selected>Normal</option>
                                    <option value="8">High</option>
                                    <option value="10">Urgent</option>
                                </select>
                            </div>
                            
                            <!-- Schedule -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold">When to Send</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="schedule" value="now" id="scheduleNow" checked>
                                    <label class="form-check-label" for="scheduleNow">Send immediately</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="schedule" value="later" id="scheduleLater">
                                    <label class="form-check-label" for="scheduleLater">Schedule for later</label>
                                </div>
                                <div id="scheduleDateTime" class="mt-2 d-none">
                                    <input type="datetime-local" name="scheduled_for" class="form-control">
                                </div>
                            </div>
                            
                            <!-- Submit -->
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-grow-1">
                                    <i class="fas fa-paper-plane me-2"></i>Send SMS
                                </button>
                                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/admin.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const messageText = document.getElementById('messageText');
    const charCounter = document.getElementById('charCounter');
    const templateSelect = document.getElementById('templateSelect');
    const templatePreview = document.getElementById('templatePreview');
    const donorSearch = document.getElementById('donorSearch');
    const searchResults = document.getElementById('searchResults');
    const donorId = document.getElementById('donorId');
    const phoneNumber = document.getElementById('phoneNumber');
    const scheduleNow = document.getElementById('scheduleNow');
    const scheduleLater = document.getElementById('scheduleLater');
    const scheduleDateTime = document.getElementById('scheduleDateTime');
    
    // Character counter
    function updateCharCounter() {
        const len = messageText.value.length;
        const segments = Math.ceil(len / 160) || 1;
        charCounter.textContent = len + ' / ' + (segments * 160) + ' (' + segments + ' SMS)';
        
        charCounter.classList.remove('warning', 'danger');
        if (len > 320) charCounter.classList.add('danger');
        else if (len > 160) charCounter.classList.add('warning');
    }
    messageText.addEventListener('input', updateCharCounter);
    updateCharCounter();
    
    // Template selection
    templateSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        const message = option.dataset.message || '';
        
        if (message) {
            templatePreview.textContent = message;
            templatePreview.classList.remove('d-none');
            messageText.value = message;
            updateCharCounter();
        } else {
            templatePreview.classList.add('d-none');
        }
    });
    
    // Donor search
    let searchTimeout;
    donorSearch.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();
        
        if (query.length < 2) {
            searchResults.classList.add('d-none');
            return;
        }
        
        searchTimeout = setTimeout(async function() {
            try {
                const response = await fetch('send.php?search_donor=' + encodeURIComponent(query) + '&ajax=1');
                const results = await response.json();
                
                if (results.length > 0) {
                    searchResults.innerHTML = results.map(d => `
                        <div class="donor-search-item" data-id="${d.id}" data-name="${d.name}" data-phone="${d.phone}">
                            <div class="fw-semibold">${d.name}</div>
                            <small class="text-muted">${d.phone}</small>
                        </div>
                    `).join('');
                    searchResults.classList.remove('d-none');
                    
                    // Click handlers
                    searchResults.querySelectorAll('.donor-search-item').forEach(item => {
                        item.addEventListener('click', function() {
                            donorId.value = this.dataset.id;
                            donorSearch.value = this.dataset.name;
                            phoneNumber.value = this.dataset.phone;
                            searchResults.classList.add('d-none');
                        });
                    });
                } else {
                    searchResults.classList.add('d-none');
                }
            } catch (e) {
                console.error(e);
            }
        }, 300);
    });
    
    // Hide search results on outside click
    document.addEventListener('click', function(e) {
        if (!donorSearch.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.classList.add('d-none');
        }
    });
    
    // Schedule toggle
    scheduleLater.addEventListener('change', function() {
        scheduleDateTime.classList.toggle('d-none', !this.checked);
    });
    scheduleNow.addEventListener('change', function() {
        scheduleDateTime.classList.toggle('d-none', this.checked);
    });
});
</script>
</body>
</html>

