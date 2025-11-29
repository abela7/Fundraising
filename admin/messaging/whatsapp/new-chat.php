<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    require_once __DIR__ . '/../../../shared/auth.php';
    require_once __DIR__ . '/../../../shared/csrf.php';
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../services/UltraMsgService.php';
    require_login();
} catch (Throwable $e) {
    die("<div style='background:#dc3545;color:white;padding:20px;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}

$db = db();
$current_user = current_user();
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        
        $donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;
        $phone = trim($_POST['phone'] ?? '');
        $message = trim($_POST['message'] ?? '');
        
        if (empty($phone) || empty($message)) {
            throw new Exception('Phone number and message are required.');
        }
        
        // Get UltraMsg service
        $service = UltraMsgService::fromDatabase($db);
        if (!$service) {
            throw new Exception('WhatsApp not configured. Please set up UltraMsg first.');
        }
        
        // Send message
        $result = $service->send($phone, $message);
        
        if (!$result['success']) {
            throw new Exception($result['error'] ?? 'Failed to send message');
        }
        
        // Normalize phone
        $normalizedPhone = normalizePhone($phone);
        
        // Check if conversation exists
        $stmt = $db->prepare("SELECT id FROM whatsapp_conversations WHERE phone_number = ?");
        $stmt->bind_param('s', $normalizedPhone);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        
        if ($existing) {
            $conversationId = (int)$existing['id'];
        } else {
            // Create conversation
            $isUnknown = $donor_id ? 0 : 1;
            $stmt = $db->prepare("
                INSERT INTO whatsapp_conversations 
                (phone_number, donor_id, is_unknown, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->bind_param('sii', $normalizedPhone, $donor_id, $isUnknown);
            $stmt->execute();
            $conversationId = (int)$db->insert_id;
        }
        
        // Save message
        $messageId = $result['message_id'] ?? null;
        $senderId = $current_user['id'];
        
        $stmt = $db->prepare("
            INSERT INTO whatsapp_messages 
            (conversation_id, ultramsg_id, direction, message_type, body, status, sender_id, is_from_donor, sent_at, created_at)
            VALUES (?, ?, 'outgoing', 'text', ?, 'sent', ?, 0, NOW(), NOW())
        ");
        $stmt->bind_param('issi', $conversationId, $messageId, $message, $senderId);
        $stmt->execute();
        
        // Update conversation
        $preview = mb_substr($message, 0, 255);
        $stmt = $db->prepare("
            UPDATE whatsapp_conversations 
            SET last_message_at = NOW(), last_message_preview = ?, last_message_direction = 'outgoing'
            WHERE id = ?
        ");
        $stmt->bind_param('si', $preview, $conversationId);
        $stmt->execute();
        
        // Redirect to conversation
        header("Location: inbox.php?id=$conversationId");
        exit;
        
    } catch (Throwable $e) {
        $error_message = $e->getMessage();
    }
}

// Get donors for search
$donors = [];
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $stmt = $db->prepare("SELECT id, name, phone FROM donors WHERE name LIKE ? OR phone LIKE ? LIMIT 20");
    $stmt->bind_param('ss', $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $donors[] = $row;
    }
    
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode($donors);
        exit;
    }
}

function normalizePhone(string $phone): string
{
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (strpos($phone, '+') !== 0) {
        if (strpos($phone, '44') === 0) {
            $phone = '+' . $phone;
        } elseif (strpos($phone, '0') === 0) {
            $phone = '+44' . substr($phone, 1);
        } else {
            $phone = '+' . $phone;
        }
    }
    return $phone;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New WhatsApp Chat - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/admin.css">
    <style>
        :root {
            --wa-teal: #00a884;
            --wa-dark: #111b21;
            --wa-text: #111b21;
        }
        .new-chat-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 2rem;
            max-width: 600px;
            margin: 2rem auto;
            color: var(--wa-text);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .new-chat-card .form-control,
        .new-chat-card .form-select {
            background: #ffffff;
            border: 1px solid #e9edef;
            color: var(--wa-text);
        }
        .new-chat-card .form-control:focus {
            background: #ffffff;
            border-color: var(--wa-teal);
            color: var(--wa-text);
            box-shadow: none;
        }
        .new-chat-card .form-label {
            color: #667781;
        }
        .btn-wa {
            background: var(--wa-teal);
            color: white;
            border: none;
        }
        .btn-wa:hover {
            background: #00917a;
            color: white;
        }
        .donor-result {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e9edef;
            cursor: pointer;
        }
        .donor-result:hover {
            background: #f5f6f6;
        }
        
        /* Mobile responsive */
        @media (max-width: 768px) {
            .new-chat-card {
                margin: 1rem;
                padding: 1.25rem;
            }
            .new-chat-card h2 {
                font-size: 1.25rem;
            }
        }
        
        @media (max-width: 480px) {
            .new-chat-card {
                margin: 0.5rem;
                padding: 1rem;
                border-radius: 8px;
            }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php try { include '../../includes/sidebar.php'; } catch (Throwable $e) {} ?>
    
    <div class="admin-content">
        <?php try { include '../../includes/topbar.php'; } catch (Throwable $e) {} ?>
        
        <main class="main-content p-3">
            <div class="new-chat-card">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h2 class="mb-0"><i class="fab fa-whatsapp me-2" style="color:var(--wa-teal);"></i>New Chat</h2>
                    <a href="inbox.php" class="btn btn-sm btn-outline-light">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
                
                <?php if ($error_message): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <?php echo csrf_input(); ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Search Donor</label>
                        <input type="text" id="donorSearch" class="form-control" 
                               placeholder="Type donor name or phone..." autocomplete="off">
                        <div id="donorResults" style="background:#ffffff;border-radius:0 0 8px 8px;max-height:200px;overflow-y:auto;border:1px solid #e9edef;border-top:none;"></div>
                    </div>
                    
                    <input type="hidden" name="donor_id" id="donorId">
                    
                    <div class="mb-3">
                        <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                        <input type="tel" name="phone" id="phoneNumber" class="form-control" 
                               placeholder="07123 456789" required>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Message <span class="text-danger">*</span></label>
                        <textarea name="message" class="form-control" rows="4" 
                                  placeholder="Type your message..." required></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-wa btn-lg w-100">
                        <i class="fab fa-whatsapp me-2"></i>Send WhatsApp Message
                    </button>
                </form>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const donorSearch = document.getElementById('donorSearch');
const donorResults = document.getElementById('donorResults');
const donorId = document.getElementById('donorId');
const phoneNumber = document.getElementById('phoneNumber');

let searchTimeout;

donorSearch.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value.trim();
    
    if (query.length < 2) {
        donorResults.innerHTML = '';
        return;
    }
    
    searchTimeout = setTimeout(async () => {
        try {
            const response = await fetch(`new-chat.php?search=${encodeURIComponent(query)}&ajax=1`);
            const donors = await response.json();
            
            donorResults.innerHTML = donors.map(d => `
                <div class="donor-result" onclick="selectDonor(${d.id}, '${escapeHtml(d.name)}', '${escapeHtml(d.phone)}')">
                    <strong>${escapeHtml(d.name)}</strong>
                    <span class="text-muted ms-2">${escapeHtml(d.phone)}</span>
                </div>
            `).join('') || '<div class="donor-result text-muted">No donors found</div>';
            
        } catch (err) {
            console.error(err);
        }
    }, 300);
});

function selectDonor(id, name, phone) {
    donorId.value = id;
    donorSearch.value = name;
    phoneNumber.value = phone;
    donorResults.innerHTML = '';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>

