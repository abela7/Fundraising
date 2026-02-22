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
$is_admin = ($current_user['role'] ?? '') === 'admin';
$user_id = (int)($current_user['id'] ?? 0);
$success_message = '';
$error_message = '';
$has_assigned_agent_col = false;

$assigned_col_check = $db->query("SHOW COLUMNS FROM whatsapp_conversations LIKE 'assigned_agent_id'");
if ($assigned_col_check && $assigned_col_check->num_rows > 0) {
    $has_assigned_agent_col = true;
}

$assigned_donors = [];
if (!$is_admin) {
    $assigned_stmt = $db->prepare("
        SELECT id, name, phone, balance
        FROM donors
        WHERE agent_id = ? AND phone IS NOT NULL AND phone <> ''
        ORDER BY name ASC
        LIMIT 200
    ");
    if ($assigned_stmt) {
        $assigned_stmt->bind_param('i', $user_id);
        $assigned_stmt->execute();
        $assigned_result = $assigned_stmt->get_result();
        while ($row = $assigned_result->fetch_assoc()) {
            $assigned_donors[] = $row;
        }
        $assigned_stmt->close();
    }
}

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

        // Restrict registrar/agent users to only their assigned donors.
        if (!$is_admin) {
            if ($donor_id <= 0) {
                throw new Exception('Please select one of your assigned donors.');
            }

            $agent_donor_stmt = $db->prepare("
                SELECT id, phone
                FROM donors
                WHERE id = ? AND agent_id = ?
                LIMIT 1
            ");
            $agent_donor_stmt->bind_param('ii', $donor_id, $user_id);
            $agent_donor_stmt->execute();
            $agent_donor = $agent_donor_stmt->get_result()->fetch_assoc();
            $agent_donor_stmt->close();

            if (!$agent_donor) {
                throw new Exception('You can only start chats with your assigned donors.');
            }

            if (!empty($agent_donor['phone'])) {
                $phone = (string)$agent_donor['phone'];
            }
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
        $stmt->close();
        
        if ($existing) {
            $conversationId = (int)$existing['id'];

            // Non-admin users can only continue conversations in their own scope.
            if (!$is_admin) {
                $access_stmt = $db->prepare("
                    SELECT wc.id
                    FROM whatsapp_conversations wc
                    LEFT JOIN donors d ON wc.donor_id = d.id
                    WHERE wc.id = ?
                      AND (wc.assigned_agent_id = ? OR d.agent_id = ?)
                    LIMIT 1
                ");
                $access_stmt->bind_param('iii', $conversationId, $user_id, $user_id);
                $access_stmt->execute();
                $allowed = $access_stmt->get_result()->fetch_assoc();
                $access_stmt->close();

                if (!$allowed) {
                    throw new Exception('Access denied for this conversation.');
                }
            }
        } else {
            // Create conversation
            $isUnknown = $donor_id ? 0 : 1;
            if (!$is_admin && $has_assigned_agent_col) {
                $stmt = $db->prepare("
                    INSERT INTO whatsapp_conversations 
                    (phone_number, donor_id, is_unknown, assigned_agent_id, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->bind_param('siii', $normalizedPhone, $donor_id, $isUnknown, $user_id);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO whatsapp_conversations 
                    (phone_number, donor_id, is_unknown, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->bind_param('sii', $normalizedPhone, $donor_id, $isUnknown);
            }
            $stmt->execute();
            $conversationId = (int)$db->insert_id;
            $stmt->close();
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
        $stmt->close();
        
        // Update conversation
        $preview = mb_substr($message, 0, 255);
        $stmt = $db->prepare("
            UPDATE whatsapp_conversations 
            SET last_message_at = NOW(), last_message_preview = ?, last_message_direction = 'outgoing'
            WHERE id = ?
        ");
        $stmt->bind_param('si', $preview, $conversationId);
        $stmt->execute();
        $stmt->close();
        
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
    if ($is_admin) {
        $stmt = $db->prepare("SELECT id, name, phone FROM donors WHERE name LIKE ? OR phone LIKE ? LIMIT 20");
        $stmt->bind_param('ss', $search, $search);
    } else {
        $stmt = $db->prepare("
            SELECT id, name, phone
            FROM donors
            WHERE agent_id = ? AND (name LIKE ? OR phone LIKE ?)
            LIMIT 20
        ");
        $stmt->bind_param('iss', $user_id, $search, $search);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $donors[] = $row;
    }
    $stmt->close();
    
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
            padding: 1rem;
            max-width: 1000px;
            margin: 0.75rem auto;
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
        .donor-pick-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
        .donor-panel {
            border: 1px solid #e9edef;
            border-radius: 10px;
            background: #fff;
            overflow: hidden;
        }
        .donor-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.65rem 0.85rem;
            border-bottom: 1px solid #e9edef;
            background: #f8fbfa;
            font-size: 0.92rem;
            font-weight: 600;
            color: #3b4a54;
        }
        .assigned-list {
            max-height: 260px;
            overflow-y: auto;
        }
        .assigned-item {
            width: 100%;
            border: 0;
            background: #fff;
            text-align: left;
            border-bottom: 1px solid #f0f2f4;
            padding: 0.7rem 0.85rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.5rem;
        }
        .assigned-item:active,
        .assigned-item:hover {
            background: #f5f9f8;
        }
        .assigned-name {
            font-weight: 600;
            font-size: 0.92rem;
            color: #1b2a32;
        }
        .assigned-meta {
            font-size: 0.8rem;
            color: #667781;
        }
        .assigned-empty {
            padding: 0.8rem;
            color: #667781;
            font-size: 0.9rem;
        }
        
        /* Mobile responsive */
        @media (max-width: 768px) {
            .new-chat-card {
                margin: 0.5rem;
                padding: 0.9rem;
            }
            .new-chat-card h2 {
                font-size: 1.1rem;
            }
        }
        
        @media (min-width: 992px) {
            .new-chat-card {
                padding: 1.2rem;
            }
            .donor-pick-grid {
                grid-template-columns: 1fr 1fr;
                align-items: start;
            }
        }

        @media (max-width: 480px) {
            .new-chat-card {
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
                    
                    <div class="mb-3 donor-pick-grid">
                        <div class="donor-panel">
                            <div class="donor-panel-header">
                                <span><i class="fas fa-search me-1"></i><?php echo $is_admin ? 'Search Donor' : 'Search Assigned Donor'; ?></span>
                            </div>
                            <div class="p-2">
                                <input type="text" id="donorSearch" class="form-control" 
                                       placeholder="<?php echo $is_admin ? 'Type donor name or phone...' : 'Type assigned donor name or phone...'; ?>" autocomplete="off">
                                <div id="donorResults" style="background:#ffffff;border-radius:0 0 8px 8px;max-height:200px;overflow-y:auto;border:1px solid #e9edef;border-top:none;"></div>
                            </div>
                        </div>
                        <div class="donor-panel">
                            <div class="donor-panel-header">
                                <span><i class="fas fa-users me-1"></i><?php echo $is_admin ? 'Quick Donors' : 'My Assigned Donors'; ?></span>
                                <span class="text-muted"><?php echo !$is_admin ? count($assigned_donors) : 0; ?></span>
                            </div>
                            <div class="assigned-list" id="assignedDonorList">
                                <?php if (!$is_admin && !empty($assigned_donors)): ?>
                                    <?php foreach ($assigned_donors as $d): ?>
                                        <button type="button" class="assigned-item"
                                                onclick="selectDonor(<?php echo (int)$d['id']; ?>, <?php echo json_encode((string)$d['name']); ?>, <?php echo json_encode((string)$d['phone']); ?>)">
                                            <span>
                                                <span class="assigned-name"><?php echo htmlspecialchars((string)$d['name']); ?></span><br>
                                                <span class="assigned-meta"><?php echo htmlspecialchars((string)$d['phone']); ?></span>
                                            </span>
                                            <i class="fas fa-arrow-right text-muted"></i>
                                        </button>
                                    <?php endforeach; ?>
                                <?php elseif (!$is_admin): ?>
                                    <div class="assigned-empty">No donors are currently assigned to you.</div>
                                <?php else: ?>
                                    <div class="assigned-empty">Use search to find a donor.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <input type="hidden" name="donor_id" id="donorId" value="<?php echo isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : ''; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                        <input type="tel" name="phone" id="phoneNumber" class="form-control" 
                               placeholder="07123 456789" 
                               value="<?php echo htmlspecialchars($_GET['phone'] ?? ''); ?>"
                               required>
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

// Pre-fill donor search if donor_id is provided
<?php 
if (isset($_GET['donor_id']) && !empty($_GET['donor_id'])): 
    $prefill_donor_id = (int)$_GET['donor_id'];
    // Fetch donor info in user scope
    if ($is_admin) {
        $prefill_stmt = $db->prepare("SELECT id, name, phone FROM donors WHERE id = ?");
        $prefill_stmt->bind_param('i', $prefill_donor_id);
    } else {
        $prefill_stmt = $db->prepare("SELECT id, name, phone FROM donors WHERE id = ? AND agent_id = ?");
        $prefill_stmt->bind_param('ii', $prefill_donor_id, $user_id);
    }
    $prefill_stmt->execute();
    $prefill_donor = $prefill_stmt->get_result()->fetch_assoc();
    $prefill_stmt->close();
?>
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($prefill_donor): ?>
    donorSearch.value = <?php echo json_encode($prefill_donor['name']); ?>;
    donorId.value = <?php echo $prefill_donor['id']; ?>;
    <?php endif; ?>
});
<?php endif; ?>

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

