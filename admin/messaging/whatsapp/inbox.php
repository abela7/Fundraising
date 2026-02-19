<?php
declare(strict_types=1);

// Enable full error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// Fatal error handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "<div style='background:#dc3545;color:white;padding:20px;margin:20px;border-radius:8px;font-family:Arial;'>";
        echo "<h3>⚠️ Fatal Error</h3>";
        echo "<p><strong>Message:</strong> " . htmlspecialchars($error['message']) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($error['file']) . " on line " . $error['line'] . "</p>";
        echo "</div>";
    }
});

$debug_info = [];

try {
    require_once __DIR__ . '/../../../shared/auth.php';
    require_once __DIR__ . '/../../../shared/csrf.php';
    require_once __DIR__ . '/../../../config/db.php';
    require_login();
} catch (Throwable $e) {
    die("<div style='background:#dc3545;color:white;padding:20px;margin:20px;border-radius:8px;'>
        <h3>Include Error</h3>
        <p>" . htmlspecialchars($e->getMessage()) . "</p>
        <p>File: " . htmlspecialchars($e->getFile()) . " Line: " . $e->getLine() . "</p>
    </div>");
}

$page_title = 'WhatsApp Inbox';

try {
    $db = db();
} catch (Throwable $e) {
    die("<div style='background:#dc3545;color:white;padding:20px;margin:20px;border-radius:8px;'>
        <h3>Database Error</h3>
        <p>" . htmlspecialchars($e->getMessage()) . "</p>
    </div>");
}

$current_user = current_user();
$is_admin = ($current_user['role'] ?? '') === 'admin';

// Check if tables exist
$tables_exist = false;
$error_message = null;

try {
    $check = $db->query("SHOW TABLES LIKE 'whatsapp_conversations'");
    $tables_exist = $check && $check->num_rows > 0;
    $debug_info[] = "Tables exist: " . ($tables_exist ? 'Yes' : 'No');
} catch (Throwable $e) {
    $debug_info[] = "Table check error: " . $e->getMessage();
}

// Get filter from URL
$filter = $_GET['filter'] ?? 'all'; // all, unread, unknown, mine
$search = trim($_GET['search'] ?? '');

// Build query for conversations
$conversations = [];
$stats = ['total' => 0, 'unread' => 0, 'unknown' => 0];

if ($tables_exist) {
    try {
        // Get stats
        $stats_query = $db->query("
            SELECT 
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN unread_count > 0 THEN 1 ELSE 0 END), 0) as unread,
                COALESCE(SUM(CASE WHEN is_unknown = 1 THEN 1 ELSE 0 END), 0) as unknown
            FROM whatsapp_conversations
            WHERE status = 'active'
        ");
        if ($stats_query) {
            $row = $stats_query->fetch_assoc();
            if ($row) {
                $stats = [
                    'total' => (int)($row['total'] ?? 0),
                    'unread' => (int)($row['unread'] ?? 0),
                    'unknown' => (int)($row['unknown'] ?? 0)
                ];
            }
        }
        $debug_info[] = "Stats loaded: total={$stats['total']}, unread={$stats['unread']}";
        
        // Build WHERE clause
        $where = ["wc.status = 'active'"];
        $params = [];
        $types = '';
        
        // Filter by assignment (agents see only their donors)
        if (!$is_admin) {
            $where[] = "(wc.assigned_agent_id = ? OR wc.donor_id IN (SELECT id FROM donors WHERE agent_id = ?))";
            $params[] = $current_user['id'];
            $params[] = $current_user['id'];
            $types .= 'ii';
        }
        
        // Apply filter
        if ($filter === 'unread') {
            $where[] = "wc.unread_count > 0";
        } elseif ($filter === 'unknown') {
            $where[] = "wc.is_unknown = 1";
        } elseif ($filter === 'mine') {
            $where[] = "wc.assigned_agent_id = ?";
            $params[] = $current_user['id'];
            $types .= 'i';
        }
        
        // Apply search
        if (!empty($search)) {
            $where[] = "(wc.phone_number LIKE ? OR wc.contact_name LIKE ? OR d.name LIKE ?)";
            $searchParam = '%' . $search . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= 'sss';
        }
        
        $whereClause = implode(' AND ', $where);
        
        $query = "
            SELECT 
                wc.*,
                d.name as donor_name,
                d.phone as donor_phone,
                d.balance as donor_balance,
                u.name as agent_name
            FROM whatsapp_conversations wc
            LEFT JOIN donors d ON wc.donor_id = d.id
            LEFT JOIN users u ON wc.assigned_agent_id = u.id
            WHERE $whereClause
            ORDER BY wc.unread_count DESC, wc.last_message_at DESC
            LIMIT 100
        ";
        
        if (!empty($params)) {
            $stmt = $db->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $db->query($query);
        }
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $conversations[] = $row;
            }
            $debug_info[] = "Loaded " . count($conversations) . " conversations";
        }
        
    } catch (Throwable $e) {
        $error_message = "Error loading conversations: " . $e->getMessage();
        $debug_info[] = $error_message;
        error_log("WhatsApp Inbox Error: " . $e->getMessage());
    }
}

// Get selected conversation ID
$selected_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$selected_conversation = null;
$messages = [];

// Store donor template data for variable replacement
$donor_template_data = [];

if ($selected_id && $tables_exist) {
    try {
        // Get conversation details with donor info
        $stmt = $db->prepare("
            SELECT wc.*, d.name as donor_name, d.phone as donor_phone, d.balance as donor_balance, 
                   d.id as donor_id
            FROM whatsapp_conversations wc
            LEFT JOIN donors d ON wc.donor_id = d.id
            WHERE wc.id = ?
        ");
        
        if (!$stmt) {
            throw new Exception("Failed to prepare conversation query: " . $db->error);
        }
        
        $stmt->bind_param('i', $selected_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $selected_conversation = $result ? $result->fetch_assoc() : null;
        
        if ($selected_conversation) {
            // Get messages
            $msg_stmt = $db->prepare("
                SELECT wm.*, u.name as sender_name
                FROM whatsapp_messages wm
                LEFT JOIN users u ON wm.sender_id = u.id
                WHERE wm.conversation_id = ?
                ORDER BY wm.created_at ASC
                LIMIT 200
            ");
            
            if ($msg_stmt) {
                $msg_stmt->bind_param('i', $selected_id);
                $msg_stmt->execute();
                $msg_result = $msg_stmt->get_result();
                if ($msg_result) {
                    while ($msg = $msg_result->fetch_assoc()) {
                        $messages[] = $msg;
                    }
                }
            }
            
            // Mark as read
            $db->query("UPDATE whatsapp_conversations SET unread_count = 0 WHERE id = " . (int)$selected_id);
            $debug_info[] = "Loaded conversation with " . count($messages) . " messages";
            
            // Prepare donor data for template variable replacement
            $donor_name = $selected_conversation['donor_name'] ?? $selected_conversation['contact_name'] ?? 'Donor';
            $first_name = explode(' ', trim($donor_name))[0];
            $donor_id = (int)($selected_conversation['donor_id'] ?? 0);
            $bank_account_name = 'LMKATH';
            $bank_account_number = '85455687';
            $sort_code = '53-70-44';
            $amount = (float)($selected_conversation['donor_balance'] ?? 0);
            $payment_plan = null;
            $payment_day = '';
            $due_date = '';

            // Resolve reference number from latest approved pledge notes
            $reference_number = '';
            if ($donor_id > 0) {
                $referenceStmt = $db->prepare("
                    SELECT notes
                    FROM pledges
                    WHERE donor_id = ? AND status = 'approved'
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                if ($referenceStmt) {
                    $referenceStmt->bind_param('i', $donor_id);
                    $referenceStmt->execute();
                    $refRow = $referenceStmt->get_result()->fetch_assoc();
                    if (!empty($refRow['notes'])) {
                        $reference_number = preg_replace('/\\D+/', '', (string)$refRow['notes']);
                    }
                    $referenceStmt->close();
                }
            }
            if ($reference_number === '') {
                $reference_number = str_pad((string)$donor_id, 4, '0', STR_PAD_LEFT);
            }
            
            if ($donor_id > 0) {
                // Get latest payment plan if exists
                $tableCheck = $db->query("SHOW TABLES LIKE 'payment_plans'");
                if ($tableCheck && $tableCheck->num_rows > 0) {
                    $plan_stmt = $db->prepare("
                        SELECT pp.*, pm.name as payment_method_name
                        FROM payment_plans pp
                        LEFT JOIN payment_methods pm ON pp.payment_method_id = pm.id
                        WHERE pp.donor_id = ?
                        ORDER BY pp.created_at DESC
                        LIMIT 1
                    ");
                    if ($plan_stmt) {
                        $plan_stmt->bind_param('i', $donor_id);
                        $plan_stmt->execute();
                        $plan_result = $plan_stmt->get_result();
                        $payment_plan = $plan_result ? $plan_result->fetch_assoc() : null;
                        if ($payment_plan) {
                            if (isset($payment_plan['amount'])) {
                                $amount = (float)$payment_plan['amount'];
                            }
                            if (!empty($payment_plan['payment_day'])) {
                                $payment_day = (string)$payment_plan['payment_day'];
                            }
                            if (!empty($payment_plan['next_due_date'])) {
                                $due_date = date('j M Y', strtotime((string)$payment_plan['next_due_date']));
                            } elseif (!empty($payment_plan['start_date'])) {
                                $due_date = date('j M Y', strtotime((string)$payment_plan['start_date']));
                            }
                        }
                    }
                }
            }
            
            if ($payment_day === '') {
                $payment_day = date('j');
            }
            $payment_day_num = (int)$payment_day;
            $suffix = 'th';
            if (!in_array($payment_day_num % 100, [11, 12, 13], true)) {
                $last = $payment_day_num % 10;
                if ($last === 1) {
                    $suffix = 'st';
                } elseif ($last === 2) {
                    $suffix = 'nd';
                } elseif ($last === 3) {
                    $suffix = 'rd';
                }
            }
            $payment_day_label = $payment_day_num > 0 ? ($payment_day_num . $suffix) : $payment_day;
            if ($due_date === '') {
                $due_date = date('j M Y');
            }
            
            // Build template data
            $frequency_labels = [
                'weekly' => 'weekly',
                'monthly' => 'monthly',
                'quarterly' => 'quarterly',
                'annually' => 'annually',
                'one_time' => 'one-time'
            ];
            
            $donor_template_data = [
                'name' => $donor_name,
                'donor_name' => $donor_name,
                'first_name' => $first_name,
                'phone' => $selected_conversation['donor_phone'] ?? $selected_conversation['phone_number'] ?? '',
                'balance' => '£' . number_format((float)($selected_conversation['donor_balance'] ?? 0), 2),
                'amount' => $payment_plan ? '£' . number_format((float)($payment_plan['amount'] ?? 0), 2) : '£0.00',
                'frequency' => $payment_plan ? ($frequency_labels[$payment_plan['frequency']] ?? $payment_plan['frequency']) : 'monthly',
                'start_date' => $payment_plan && $payment_plan['start_date'] ? date('j M Y', strtotime($payment_plan['start_date'])) : date('j M Y'),
                'payment_method' => $payment_plan['payment_method_name'] ?? 'Not set',
                'portal_link' => 'https://bit.ly/4p0J1gf',
                'church_name' => 'Liverpool Abune Teklehaymanot Church',
                'callback_date' => date('l, j M'),
                'callback_time' => date('g:i A'),
            ];
            $donor_template_data['balance'] = '£' . number_format((float)($selected_conversation['donor_balance'] ?? 0), 2);
            $donor_template_data['amount'] = '£' . number_format($amount, 2);
            $donor_template_data['donor_id'] = (string)$donor_id;
            $donor_template_data['due_date'] = $due_date;
            $donor_template_data['payment_day'] = $payment_day_label;
            $donor_template_data['reference_number'] = $reference_number;
            $donor_template_data['reference'] = $reference_number;
            $donor_template_data['bank_account_name'] = $bank_account_name;
            $donor_template_data['bank_account_number'] = $bank_account_number;
            $donor_template_data['sort_code'] = $sort_code;
            $donor_template_data['balance'] = chr(163) . number_format((float)($selected_conversation['donor_balance'] ?? 0), 2);
            $donor_template_data['amount'] = chr(163) . number_format($amount, 2);
        }
    } catch (Throwable $e) {
        $error_message = "Error loading conversation: " . $e->getMessage();
        $debug_info[] = $error_message;
        error_log("WhatsApp Conversation Error: " . $e->getMessage());
    }
}

// Safe JSON payload for client-side template replacement
$donor_template_data_json = json_encode(
    $donor_template_data,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($donor_template_data_json === false) {
    $donor_template_data_json = '{}';
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
    <!-- Lamejs for MP3 encoding -->
    <script src="https://cdn.jsdelivr.net/npm/lamejs@1.2.1/lame.min.js"></script>
    <style>
        :root {
            --wa-green: #25D366;
            --wa-teal: #00a884;
            --wa-dark: #111b21;
            --wa-darker: #f0f2f5;
            --wa-light: #d9fdd3;
            --wa-chat-bg: #efeae2;
            --wa-bubble-out: #d9fdd3;
            --wa-bubble-in: #ffffff;
            --wa-sidebar-bg: #ffffff;
            --wa-header-bg: #f0f2f5;
            --wa-border: #e9edef;
            --wa-text: #111b21;
            --wa-text-secondary: #667781;
            --wa-hover: #f5f6f6;
            --wa-input-bg: #ffffff;
            --wa-search-bg: #f0f2f5;
        }
        
        .inbox-container {
            display: flex;
            height: calc(100vh - 120px);
            background: var(--wa-darker);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: relative;
        }
        
        /* Sidebar - Conversation List */
        .inbox-sidebar {
            width: 380px;
            min-width: 320px;
            background: var(--wa-sidebar-bg);
            border-right: 1px solid var(--wa-border);
            display: flex;
            flex-direction: column;
        }
        
        .inbox-header {
            background: var(--wa-header-bg);
            color: var(--wa-text);
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 60px;
            border-bottom: 1px solid var(--wa-border);
        }
        
        .inbox-header h1 {
            font-size: 1.25rem;
            margin: 0;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .inbox-header .btn {
            background: transparent;
            border: none;
            color: var(--wa-text-secondary);
            padding: 0.5rem;
            border-radius: 50%;
        }
        
        .inbox-header .btn:hover {
            background: var(--wa-hover);
            color: var(--wa-teal);
        }
        
        .inbox-search {
            padding: 0.5rem 0.75rem;
            background: var(--wa-sidebar-bg);
        }
        
        .inbox-search input {
            width: 100%;
            padding: 0.5rem 1rem 0.5rem 2.5rem;
            border: 1px solid var(--wa-border);
            border-radius: 8px;
            background: var(--wa-search-bg);
            font-size: 0.875rem;
            color: var(--wa-text);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%23667781' viewBox='0 0 24 24' width='18' height='18'%3E%3Cpath d='M15.9 14.3H15l-.3-.3c1-1.1 1.6-2.7 1.6-4.3 0-3.7-3-6.7-6.7-6.7S3 6 3 9.7s3 6.7 6.7 6.7c1.6 0 3.2-.6 4.3-1.6l.3.3v.8l5.1 5.1 1.5-1.5-5-5.2zm-6.2 0c-2.6 0-4.6-2.1-4.6-4.6s2.1-4.6 4.6-4.6 4.6 2.1 4.6 4.6-2 4.6-4.6 4.6z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: 0.75rem center;
        }
        
        .inbox-search input::placeholder {
            color: var(--wa-text-secondary);
        }
        
        .inbox-search input:focus {
            outline: none;
            background-color: var(--wa-input-bg);
        }
        
        .inbox-filters {
            display: flex;
            padding: 0.5rem 0.75rem;
            gap: 0.5rem;
            background: var(--wa-sidebar-bg);
            border-bottom: 1px solid var(--wa-border);
        }
        
        /* Bulk Actions */
        .bulk-mode-btn {
            width: 36px;
            height: 36px;
            border: none;
            background: var(--wa-search-bg);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--wa-text-secondary);
            transition: all 0.2s;
            flex-shrink: 0;
        }
        
        .bulk-mode-btn:hover {
            background: var(--wa-teal);
            color: white;
        }
        
        .bulk-mode-btn.active {
            background: var(--wa-teal);
            color: white;
        }
        
        .conv-checkbox {
            width: 22px;
            height: 22px;
            border: 2px solid #d1d5db;
            border-radius: 50%;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s;
            margin-right: 0.5rem;
        }
        
        .bulk-mode .conv-checkbox {
            display: flex;
        }
        
        .bulk-mode .conv-avatar {
            display: none;
        }
        
        .conv-checkbox.checked {
            background: var(--wa-teal);
            border-color: var(--wa-teal);
        }
        
        .conv-checkbox.checked::after {
            content: '✓';
            color: white;
            font-size: 0.75rem;
            font-weight: 700;
        }
        
        /* Bulk Action Bar */
        .bulk-action-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid var(--wa-border);
            padding: 1rem;
            display: none;
            z-index: 1000;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.1);
            animation: slideUpBar 0.3s ease;
        }
        
        @keyframes slideUpBar {
            from { transform: translateY(100%); }
            to { transform: translateY(0); }
        }
        
        .bulk-action-bar.active {
            display: block;
        }
        
        .bulk-action-content {
            max-width: 500px;
            margin: 0 auto;
        }
        
        .bulk-action-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }
        
        .bulk-action-count {
            font-weight: 600;
            color: var(--wa-text);
        }
        
        .bulk-action-close {
            background: none;
            border: none;
            color: var(--wa-text-secondary);
            cursor: pointer;
            padding: 0.25rem;
            font-size: 1.25rem;
        }
        
        .bulk-action-buttons {
            display: flex;
            gap: 0.75rem;
        }
        
        .bulk-action-btn {
            flex: 1;
            padding: 0.875rem 1rem;
            border: none;
            border-radius: 12px;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }
        
        .bulk-action-btn.read {
            background: #e0f2fe;
            color: #0369a1;
        }
        
        .bulk-action-btn.read:hover {
            background: #bae6fd;
        }
        
        .bulk-action-btn.delete {
            background: linear-gradient(135deg, #ff6b6b 0%, #dc3545 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.25);
        }
        
        .bulk-action-btn.delete:hover {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }
        
        .bulk-action-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        /* Select All Row */
        .select-all-row {
            display: none;
            padding: 0.75rem 1rem;
            background: #f0f9ff;
            border-bottom: 1px solid var(--wa-border);
            align-items: center;
            gap: 0.75rem;
        }
        
        .bulk-mode .select-all-row {
            display: flex;
        }
        
        .select-all-label {
            font-size: 0.875rem;
            color: var(--wa-text);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .inbox-filter {
            flex: 1;
            padding: 0.375rem 0.75rem;
            border: none;
            background: var(--wa-search-bg);
            border-radius: 18px;
            font-size: 0.8125rem;
            font-weight: 400;
            color: var(--wa-text-secondary);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            text-align: center;
        }
        
        .inbox-filter:hover {
            background: var(--wa-hover);
            color: var(--wa-text);
        }
        
        .inbox-filter.active {
            background: var(--wa-teal);
            color: white;
        }
        
        .inbox-filter .badge {
            font-size: 0.65rem;
            padding: 0.15rem 0.4rem;
            margin-left: 0.25rem;
        }
        
        .conversation-list {
            flex: 1;
            overflow-y: auto;
        }
        
        .conversation-list::-webkit-scrollbar {
            width: 6px;
        }
        
        .conversation-list::-webkit-scrollbar-thumb {
            background: var(--wa-border);
            border-radius: 3px;
        }
        
        .conversation-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: background 0.15s, transform 0.2s ease-out;
            text-decoration: none;
            color: var(--wa-text);
            border-bottom: 1px solid var(--wa-border);
        }
        
        .conversation-item:hover {
            background: var(--wa-hover);
        }
        
        .conversation-item.active {
            background: var(--wa-input-bg);
        }
        
        .conversation-item.unread {
            background: rgba(0, 168, 132, 0.08);
        }
        
        .conv-avatar {
            width: 49px;
            height: 49px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--wa-teal), var(--wa-green));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
            font-size: 1.25rem;
            margin-right: 0.875rem;
            flex-shrink: 0;
        }
        
        .conv-avatar.unknown {
            background: linear-gradient(135deg, #6b7280, #4b5563);
        }
        
        .conv-content {
            flex: 1;
            min-width: 0;
        }
        
        .conv-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.125rem;
        }
        
        .conv-name {
            font-weight: 400;
            font-size: 1rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--wa-text);
        }
        
        .conv-time {
            font-size: 0.75rem;
            color: var(--wa-text-secondary);
            flex-shrink: 0;
            margin-left: 0.5rem;
        }
        
        .conv-time.unread {
            color: var(--wa-teal);
        }
        
        .conv-preview {
            font-size: 0.875rem;
            color: var(--wa-text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .conv-unread-badge {
            background: var(--wa-teal);
            color: white;
            font-size: 0.75rem;
            min-width: 20px;
            height: 20px;
            padding: 0 0.375rem;
            border-radius: 10px;
            font-weight: 500;
            margin-left: auto;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Chat Area */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--wa-chat-bg);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 303 172'%3E%3Cpath fill='%23efeae2' d='M0 0h303v172H0z'/%3E%3Cpath fill='%23d9d4cc' opacity='0.4' d='M157 34l17-6-12-14-7 2-5 13 7 5zM35 72l-12-5-4 11 10 8 6-14zm212 43l8-8-6-10-11 5 9 13zm-170 8l-5-10-14 4 7 12 12-6zm95-89l10 5 7-12-14-3-3 10zM78 118l-8 8 6 10 11-5-9-13z'/%3E%3C/svg%3E");
            background-size: 412.5px auto;
        }
        
        .chat-header {
            background: var(--wa-header-bg);
            padding: 0.625rem 1rem;
            display: flex;
            align-items: center;
            border-bottom: 1px solid var(--wa-border);
            min-height: 60px;
        }
        
        /* Chat header delete action */
        .chat-header-actions {
            margin-left: auto;
            flex-shrink: 0;
        }
        .cha-btn {
            width: 36px;
            height: 36px;
            border: none;
            background: transparent;
            color: var(--wa-text-secondary, #8696a0);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .85rem;
            cursor: pointer;
            transition: background .15s, color .15s;
        }
        .cha-btn:hover {
            background: rgba(220,53,69,.1);
            color: #dc3545;
        }
        
        /* Conversation item context menu (long-press / right-click) */
        .conv-ctx-menu {
            position: fixed;
            z-index: 9999;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,.18), 0 2px 8px rgba(0,0,0,.08);
            padding: 6px 0;
            min-width: 170px;
            display: none;
            animation: ctxFadeIn .15s ease;
        }
        .conv-ctx-menu.show { display: block; }
        @keyframes ctxFadeIn { from { opacity:0; transform:scale(.95); } to { opacity:1; transform:scale(1); } }
        .conv-ctx-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            font-size: .875rem;
            color: #333;
            cursor: pointer;
            transition: background .12s;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
        }
        .conv-ctx-item:hover { background: #f0f2f5; }
        .conv-ctx-item.danger { color: #dc3545; }
        .conv-ctx-item.danger:hover { background: #fef2f2; }
        .conv-ctx-item i { width: 18px; text-align: center; font-size: .8rem; }
        
        .chat-header-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--wa-teal), var(--wa-green));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
            font-size: 1.125rem;
            margin-right: 0.875rem;
        }
        
        .chat-header-link {
            display: flex;
            align-items: center;
            text-decoration: none;
            flex: 1;
            min-width: 0;
        }
        
        .chat-header-link:hover .chat-header-name {
            color: var(--wa-teal);
        }
        
        .chat-header-info {
            flex: 1;
            min-width: 0;
        }
        
        .chat-header-name {
            font-weight: 400;
            font-size: 1rem;
            color: var(--wa-text);
            transition: color 0.2s;
        }
        
        .chat-header-status {
            font-size: 0.8125rem;
            color: var(--wa-text-secondary);
        }
        
        .chat-header .btn-outline-primary {
            border-color: var(--wa-teal);
            color: var(--wa-teal);
            background: transparent;
        }
        
        .chat-header .btn-outline-primary:hover {
            background: var(--wa-teal);
            color: var(--wa-dark);
        }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 4rem;
            display: flex;
            flex-direction: column;
            gap: 0.125rem;
        }
        
        .chat-messages::-webkit-scrollbar {
            width: 6px;
        }
        
        .chat-messages::-webkit-scrollbar-thumb {
            background: var(--wa-border);
            border-radius: 3px;
        }
        
        .message {
            max-width: 65%;
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            position: relative;
            margin-bottom: 0.375rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }
        
        .message.incoming {
            background: var(--wa-bubble-in);
            align-self: flex-start;
            border-top-left-radius: 0;
            color: var(--wa-text);
            box-shadow: 0 1px 0.5px rgba(0, 0, 0, 0.13);
        }
        
        .message.incoming::before {
            content: '';
            position: absolute;
            top: 0;
            left: -8px;
            width: 0;
            height: 0;
            border-style: solid;
            border-width: 0 8px 8px 0;
            border-color: transparent var(--wa-bubble-in) transparent transparent;
        }
        
        .message.outgoing {
            background: var(--wa-bubble-out);
            align-self: flex-end;
            border-top-right-radius: 0;
            color: var(--wa-text);
            box-shadow: 0 1px 0.5px rgba(0, 0, 0, 0.13);
        }
        
        .message.outgoing::before {
            content: '';
            position: absolute;
            top: 0;
            right: -8px;
            width: 0;
            height: 0;
            border-style: solid;
            border-width: 8px 8px 0 0;
            border-color: var(--wa-bubble-out) transparent transparent transparent;
        }
        
        .message-content {
            display: flex;
            flex-direction: column;
        }
        
        .message-sender {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--wa-teal);
            margin-bottom: 0.125rem;
        }
        
        .message.incoming .message-sender {
            color: #6b7280;
        }
        
        .message-text {
            font-size: 0.9375rem;
            line-height: 1.4;
            word-wrap: break-word;
            margin-bottom: 0.25rem;
        }
        
        .message-meta {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.375rem;
            font-size: 0.6875rem;
            color: var(--wa-text-secondary);
            margin-top: 0.125rem;
        }
        
        .message.incoming .message-meta {
            color: #9ca3af;
        }
        
        .message-status {
            font-size: 0.875rem;
            display: flex;
            align-items: center;
        }
        
        .message-status .fa-check {
            color: var(--wa-text-secondary);
        }
        
        .message-status .fa-check-double {
            color: #53bdeb;
        }
        
        .message-media {
            max-width: 330px;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 0.375rem;
        }
        
        .message-media img {
            width: 100%;
            display: block;
            cursor: pointer;
            transition: all 0.2s;
            border-radius: 8px;
        }
        
        .message-media img:hover {
            opacity: 0.9;
            transform: scale(1.02);
        }
        
        .message-media video {
            width: 100%;
            max-width: 300px;
            display: block;
            border-radius: 8px;
        }
        
        .message-media.document {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem;
            background: rgba(0,0,0,0.04);
            border-radius: 8px;
            min-width: 200px;
        }
        
        .message-media.document i {
            font-size: 1.5rem;
            color: #667781;
        }
        
        .message-media.document i.fa-file-pdf { color: #dc3545; }
        .message-media.document i.fa-file-word { color: #0d6efd; }
        .message-media.document i.fa-file-excel { color: #198754; }
        
        .message-media.document div {
            flex: 1;
            min-width: 0;
        }
        
        .message-media.document div > div:first-child {
            font-weight: 500;
            font-size: 0.875rem;
            color: var(--wa-text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .message-media.document a {
            color: var(--wa-teal);
            font-size: 0.8125rem;
        }
        
        .message-media.document a:hover {
            text-decoration: underline;
        }
        
        .message-media audio {
            width: 280px;
            height: 40px;
            border-radius: 20px;
        }
        
        /* Voice message styling */
        .message-media.voice {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem;
            background: transparent;
        }
        
        .message-media.voice .voice-play-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--wa-teal);
            border: none;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
        }
        
        .message-media.voice .voice-play-btn:hover {
            background: #00917a;
        }
        
        .message-media.voice .voice-waveform {
            flex: 1;
            height: 30px;
            display: flex;
            align-items: center;
            gap: 2px;
        }
        
        .message-media.voice .voice-waveform .bar {
            width: 3px;
            background: var(--wa-teal);
            border-radius: 2px;
        }
        
        .message-media.voice .voice-duration {
            font-size: 0.75rem;
            color: var(--wa-text-secondary);
            min-width: 35px;
        }
        
        /* Location styling */
        .message-media.location {
            padding: 0.75rem;
            background: rgba(0,0,0,0.04);
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .message-media.location a {
            color: var(--wa-text);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .message-media.location a:hover {
            color: var(--wa-teal);
        }
        
        /* Message wrapper - clickable for details */
        .message-wrapper {
            position: relative;
            display: flex;
            width: 100%;
        }
        
        .message-wrapper.incoming {
            justify-content: flex-start;
        }
        
        .message-wrapper.outgoing {
            justify-content: flex-end;
        }
        
        .message-wrapper .message {
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        
        .message-wrapper .message:active {
            transform: scale(0.98);
        }
        
        /* Message Detail Modal */
        .message-detail-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 10000;
            align-items: flex-end;
            justify-content: center;
            padding: 0;
        }
        
        .message-detail-modal.active {
            display: flex;
            animation: fadeIn 0.2s ease;
        }
        
        .message-detail-content {
            background: white;
            border-radius: 20px 20px 0 0;
            width: 100%;
            max-width: 500px;
            max-height: 80vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: slideUpMobile 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .message-detail-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e9edef;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        
        .message-detail-header h5 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            color: var(--wa-text);
        }
        
        .message-detail-close {
            width: 32px;
            height: 32px;
            border: none;
            background: #f0f2f5;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--wa-text-secondary);
            transition: all 0.2s;
        }
        
        .message-detail-close:hover {
            background: #e4e6e9;
            color: var(--wa-text);
        }
        
        .message-detail-body {
            flex: 1;
            overflow-y: auto;
            padding: 1.25rem;
        }
        
        .message-detail-preview {
            background: #f0f2f5;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.25rem;
        }
        
        .message-detail-preview.outgoing {
            background: var(--wa-bubble-out);
        }
        
        .message-detail-text {
            font-size: 0.9375rem;
            line-height: 1.5;
            color: var(--wa-text);
            word-break: break-word;
        }
        
        .message-detail-media {
            max-width: 100%;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        
        .message-detail-media img,
        .message-detail-media video {
            max-width: 100%;
            border-radius: 8px;
        }
        
        .message-detail-media audio {
            width: 100%;
        }
        
        .message-detail-info {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .message-detail-row {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        
        .message-detail-icon {
            width: 36px;
            height: 36px;
            background: #f0f2f5;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--wa-text-secondary);
            flex-shrink: 0;
        }
        
        .message-detail-row-content {
            flex: 1;
            min-width: 0;
        }
        
        .message-detail-label {
            font-size: 0.75rem;
            color: var(--wa-text-secondary);
            margin-bottom: 0.125rem;
        }
        
        .message-detail-value {
            font-size: 0.875rem;
            color: var(--wa-text);
            word-break: break-word;
        }
        
        .message-detail-footer {
            padding: 1rem 1.25rem 1.5rem;
            border-top: 1px solid #e9edef;
            flex-shrink: 0;
        }
        
        .message-detail-delete {
            width: 100%;
            padding: 0.875rem;
            background: linear-gradient(135deg, #ff6b6b 0%, #dc3545 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.25);
        }
        
        .message-detail-delete:hover {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(220, 53, 69, 0.35);
        }
        
        .message-detail-delete:active {
            transform: translateY(0);
        }
        
        .message-detail-delete:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Status badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .status-badge.sent {
            background: #e0f2fe;
            color: #0369a1;
        }
        
        .status-badge.delivered {
            background: #d1fae5;
            color: #047857;
        }
        
        .status-badge.read {
            background: #dbeafe;
            color: #1d4ed8;
        }
        
        .status-badge.failed {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .status-badge.pending {
            background: #fef3c7;
            color: #d97706;
        }
        
        /* Desktop adjustments */
        @media (min-width: 769px) {
            .message-detail-modal {
                align-items: center;
                padding: 1rem;
            }
            
            .message-detail-content {
                border-radius: 20px;
                max-height: 600px;
            }
        }
        
        /* Delete Confirmation Modal - Professional Design */
        .delete-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        
        .delete-modal.active {
            display: flex;
            opacity: 1;
            animation: fadeIn 0.2s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(20px) scale(0.95);
            }
            to { 
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .delete-modal-content {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            max-width: 340px;
            width: 100%;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: slideUp 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .delete-modal-icon {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #dc3545;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin: 0 auto 1.25rem;
            box-shadow: 0 8px 16px rgba(220, 53, 69, 0.15);
        }
        
        .delete-modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--wa-text);
            margin-bottom: 0.625rem;
        }
        
        .delete-modal-text {
            color: var(--wa-text-secondary);
            font-size: 0.9375rem;
            margin-bottom: 1.75rem;
            line-height: 1.5;
        }
        
        .delete-modal-actions {
            display: flex;
            gap: 0.75rem;
            flex-direction: column;
        }
        
        .delete-modal-btn {
            padding: 0.875rem 1.5rem;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .delete-modal-btn.delete {
            background: linear-gradient(135deg, #ff6b6b 0%, #dc3545 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
            order: 1;
        }
        
        .delete-modal-btn.delete:hover {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(220, 53, 69, 0.4);
        }
        
        .delete-modal-btn.delete:active {
            transform: translateY(0);
        }
        
        .delete-modal-btn.cancel {
            background: transparent;
            color: var(--wa-text-secondary);
            order: 2;
        }
        
        .delete-modal-btn.cancel:hover {
            background: #f0f2f5;
            color: var(--wa-text);
        }
        
        .delete-modal-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }
        
        /* Mobile modal adjustments */
        @media (max-width: 480px) {
            .delete-modal {
                padding: 0;
                align-items: flex-end;
            }
            
            .delete-modal-content {
                max-width: 100%;
                border-radius: 24px 24px 0 0;
                padding: 1.5rem 1.5rem 2rem;
                animation: slideUpMobile 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            }
            
            @keyframes slideUpMobile {
                from { 
                    transform: translateY(100%);
                }
                to { 
                    transform: translateY(0);
                }
            }
            
            .delete-modal-icon {
                width: 64px;
                height: 64px;
                font-size: 1.5rem;
            }
            
            .delete-modal-title {
                font-size: 1.125rem;
            }
            
            .delete-modal-btn {
                padding: 1rem 1.5rem;
            }
        }
        
        /* Image Gallery Modal */
        .image-gallery-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.95);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        
        .image-gallery-modal.active {
            display: flex;
        }
        
        .gallery-container {
            position: relative;
            width: 100%;
            max-width: 90vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            pointer-events: none; /* Allow clicks to pass through to backdrop */
        }
        
        .gallery-container > * {
            pointer-events: auto; /* But make children clickable */
        }
        
        .gallery-image-wrapper {
            position: relative;
            width: 100%;
            max-width: 100%;
            max-height: calc(90vh - 120px);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }
        
        .gallery-image {
            max-width: 100%;
            max-height: calc(90vh - 120px);
            object-fit: contain;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
        }
        
        .gallery-caption {
            color: white;
            text-align: center;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .gallery-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            transition: all 0.2s;
            backdrop-filter: blur(10px);
        }
        
        .gallery-nav:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-50%) scale(1.1);
        }
        
        .gallery-nav.prev {
            left: 1rem;
        }
        
        .gallery-nav.next {
            right: 1rem;
        }
        
        .gallery-nav:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        .gallery-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            transition: all 0.2s;
            backdrop-filter: blur(10px);
            z-index: 10001;
            pointer-events: auto;
        }
        
        .gallery-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }
        
        .gallery-close:active {
            transform: scale(0.95);
        }
        
        .gallery-counter {
            position: absolute;
            bottom: 1rem;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.6);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            backdrop-filter: blur(10px);
        }
        
        .gallery-thumbnails {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            flex-wrap: wrap;
            max-width: 100%;
            padding: 0.5rem;
            overflow-x: auto;
        }
        
        .gallery-thumbnail {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 6px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.2s;
            opacity: 0.6;
        }
        
        .gallery-thumbnail:hover {
            opacity: 1;
            transform: scale(1.1);
        }
        
        .gallery-thumbnail.active {
            border-color: var(--wa-teal);
            opacity: 1;
        }
        
        /* Mobile gallery adjustments */
        @media (max-width: 768px) {
            .gallery-container {
                max-width: 100vw;
                max-height: 100vh;
                padding: 0;
            }
            
            .gallery-image-wrapper {
                max-height: calc(100vh - 200px);
            }
            
            .gallery-image {
                max-height: calc(100vh - 200px);
            }
            
            .gallery-nav {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }
            
            .gallery-nav.prev {
                left: 0.5rem;
            }
            
            .gallery-nav.next {
                right: 0.5rem;
            }
            
            .gallery-close {
                top: 0.5rem;
                right: 0.5rem;
                width: 36px;
                height: 36px;
            }
            
            .gallery-thumbnails {
                gap: 0.25rem;
            }
            
            .gallery-thumbnail {
                width: 50px;
                height: 50px;
            }
        }
        
        .date-divider {
            text-align: center;
            margin: 0.75rem 0;
        }
        
        .date-divider span {
            background: var(--wa-input-bg);
            padding: 0.375rem 0.75rem;
            border-radius: 8px;
            font-size: 0.75rem;
            color: var(--wa-text-secondary);
            box-shadow: 0 1px 0.5px rgba(11, 20, 26, 0.13);
        }
        
        /* Input Area */
        .chat-input {
            background: var(--wa-header-bg);
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .chat-input-field {
            flex: 1;
            padding: 0.625rem 1rem;
            border: none;
            border-radius: 21px;
            font-size: 1rem;
            outline: none;
            background: white;
            color: var(--wa-text);
            min-height: 42px;
            max-height: 120px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
            resize: none;
            overflow-y: hidden;
            line-height: 1.4;
            font-family: inherit;
            scrollbar-width: thin;
            scrollbar-color: rgba(0, 0, 0, 0.2) transparent;
        }
        
        /* Webkit (Chrome, Safari) thin scrollbar */
        .chat-input-field::-webkit-scrollbar {
            width: 4px;
        }
        
        .chat-input-field::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .chat-input-field::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 4px;
        }
        
        .chat-input-field::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 0, 0, 0.3);
        }
        
        .chat-input-field:focus {
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
        }
        
        .chat-input-field::placeholder {
            color: var(--wa-text-secondary);
        }
        
        .chat-input-btn {
            width: 42px;
            height: 42px;
            min-width: 42px;
            min-height: 42px;
            max-width: 42px;
            max-height: 42px;
            border: none;
            border-radius: 50%;
            background: var(--wa-teal);
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            font-size: 1.125rem;
            flex-shrink: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        .chat-input-btn:hover {
            transform: scale(1.05);
            background: #00917a;
        }
        
        .chat-input-btn:disabled {
            background: #dfe5e7;
            cursor: not-allowed;
            color: var(--wa-text-secondary);
            transform: none;
        }
        
        .chat-input-btn.attachment,
        .chat-input-btn.templates {
            width: 36px;
            height: 36px;
            min-width: 36px;
            min-height: 36px;
            max-width: 36px;
            max-height: 36px;
            background: transparent;
            color: var(--wa-text-secondary);
            transform: none;
            font-size: 1.125rem;
        }
        
        .chat-input-btn.attachment:hover,
        .chat-input-btn.templates:hover {
            color: var(--wa-teal);
            background: rgba(0, 168, 132, 0.08);
        }
        
        /* Templates Menu */
        .templates-menu {
            position: absolute;
            bottom: 100%;
            left: 0.5rem;
            right: 0.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            display: none;
            z-index: 100;
            margin-bottom: 0.5rem;
            max-height: 400px;
            overflow: hidden;
            flex-direction: column;
        }
        
        .templates-menu.active {
            display: flex;
        }
        
        .templates-header {
            padding: 1rem;
            border-bottom: 1px solid #e9edef;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        
        .templates-header h5 {
            margin: 0;
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--wa-text);
        }
        
        .templates-close {
            background: none;
            border: none;
            color: var(--wa-text-secondary);
            cursor: pointer;
            padding: 0.25rem;
            font-size: 1rem;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s;
        }
        
        .templates-close:hover {
            background: #f0f2f5;
            color: var(--wa-text);
        }
        
        .templates-content {
            flex: 1;
            overflow-y: auto;
            padding: 0.5rem;
        }
        
        .templates-loading {
            text-align: center;
            padding: 2rem;
            color: var(--wa-text-secondary);
        }
        
        .templates-category {
            margin-bottom: 1rem;
        }
        
        .templates-category-title {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--wa-text-secondary);
            text-transform: uppercase;
            padding: 0.5rem 0.75rem;
            margin-bottom: 0.25rem;
        }
        
        .template-item {
            padding: 0.8rem 0.9rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
            margin-bottom: 0.25rem;
            min-height: 44px;
            display: flex;
            align-items: center;
        }
        
        .template-item:hover {
            background: #f0f2f5;
            border-color: var(--wa-teal);
        }
        
        .template-name {
            font-weight: 600;
            font-size: 0.9375rem;
            color: var(--wa-text);
            margin: 0;
        }
        
        
        
        .templates-empty {
            text-align: center;
            padding: 2rem;
            color: var(--wa-text-secondary);
        }
        
        .templates-empty i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
        }
        
        /* Mobile templates menu */
        @media (max-width: 768px) {
            .templates-menu {
                left: 0;
                right: 0;
                max-height: 65vh;
                border-radius: 12px 12px 0 0;
            }
            
            .template-item {
                min-height: 48px;
                padding: 1rem;
            }

            .template-name {
                font-size: 1rem;
            }
        }
        
        .chat-input-btn.voice {
            width: 42px;
            height: 42px;
            min-width: 42px;
            min-height: 42px;
            max-width: 42px;
            max-height: 42px;
            background: transparent;
            color: var(--wa-text-secondary);
            transform: none;
        }
        
        .chat-input-btn.voice:hover {
            color: var(--wa-teal);
            background: rgba(0, 168, 132, 0.08);
        }
        
        .chat-input-btn.voice.recording {
            color: #dc3545;
            animation: pulse 1s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        /* Voice Recording Indicator */
        .voice-recording-indicator {
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            bottom: 0;
            background: #fee2e2;
            border-radius: 24px;
            display: flex;
            align-items: center;
            padding: 0 1rem;
            gap: 0.75rem;
            z-index: 10;
        }
        
        .recording-dot {
            width: 12px;
            height: 12px;
            background: #dc3545;
            border-radius: 50%;
            animation: pulse 1s infinite;
        }
        
        .recording-time {
            font-size: 1rem;
            font-weight: 600;
            color: #dc3545;
            flex: 1;
            text-align: center;
        }
        
        .recording-cancel {
            background: rgba(220, 53, 69, 0.1);
            border: none;
            color: #dc3545;
            cursor: pointer;
            width: 40px;
            height: 40px;
            font-size: 1rem;
            border-radius: 50%;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .recording-cancel:hover {
            background: rgba(220, 53, 69, 0.2);
        }
        
        .recording-send {
            background: var(--wa-teal);
            border: none;
            color: white;
            cursor: pointer;
            width: 44px;
            height: 44px;
            font-size: 1.125rem;
            border-radius: 50%;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-left: auto;
        }
        
        .recording-send:hover {
            background: #00917a;
            transform: scale(1.05);
        }
        
        /* Attachment Menu */
        .attachment-menu {
            position: absolute;
            bottom: 100%;
            left: 0.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            padding: 0.5rem;
            display: none;
            z-index: 100;
            margin-bottom: 0.5rem;
        }
        
        .attachment-menu.active {
            display: flex;
            gap: 0.5rem;
        }
        
        .attachment-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0.75rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            background: transparent;
            color: var(--wa-text);
            min-width: 60px;
        }
        
        .attachment-option:hover {
            background: #f0f2f5;
        }
        
        .attachment-option i {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.25rem;
            font-size: 1.1rem;
            color: white;
        }
        
        .attachment-option span {
            font-size: 0.7rem;
            color: var(--wa-text-secondary);
        }
        
        .attachment-option .photo-icon { background: #7f66ff; }
        .attachment-option .document-icon { background: #5157ae; }
        .attachment-option .camera-icon { background: #ec407a; }
        
        /* Media Preview */
        .media-preview {
            position: absolute;
            bottom: 100%;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid #e9edef;
            padding: 1rem;
            display: none;
            z-index: 100;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.1);
            animation: slideUp 0.2s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .media-preview.active {
            display: block;
        }
        
        .media-preview-inner {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .media-preview-thumb {
            width: 70px;
            height: 70px;
            border-radius: 12px;
            object-fit: cover;
            background: linear-gradient(135deg, #f0f2f5 0%, #e4e6e9 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .media-preview-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .media-preview-thumb i {
            font-size: 1.75rem;
            color: var(--wa-text-secondary);
        }
        
        .media-preview-info {
            flex: 1;
            min-width: 0;
        }
        
        .media-preview-name {
            font-weight: 600;
            font-size: 0.9375rem;
            color: var(--wa-text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 0.25rem;
        }
        
        .media-preview-size {
            font-size: 0.8125rem;
            color: var(--wa-text-secondary);
            margin-bottom: 0.5rem;
        }
        
        .media-preview-caption {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #e9edef;
            border-radius: 20px;
            font-size: 0.875rem;
            outline: none;
            background: #f0f2f5;
        }
        
        .media-preview-caption:focus {
            border-color: var(--wa-teal);
            background: white;
        }
        
        .media-preview-caption::placeholder {
            color: var(--wa-text-secondary);
        }
        
        .media-preview-close {
            width: 36px;
            height: 36px;
            background: #fee2e2;
            border: none;
            border-radius: 50%;
            color: #dc3545;
            cursor: pointer;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s;
        }
        
        .media-preview-close:hover {
            background: #dc3545;
            color: white;
        }
        
        /* Voice Recording UI */
        .voice-recording-ui {
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            bottom: 0;
            background: var(--wa-header-bg);
            display: none;
            align-items: center;
            padding: 0.625rem 1rem;
            gap: 1rem;
        }
        
        .voice-recording-ui.active {
            display: flex;
        }
        
        .recording-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #dc3545;
            font-weight: 600;
        }
        
        .recording-dot {
            width: 10px;
            height: 10px;
            background: #dc3545;
            border-radius: 50%;
            animation: pulse 1s infinite;
        }
        
        .recording-timer {
            font-family: monospace;
            font-size: 1rem;
        }
        
        .recording-waveform {
            flex: 1;
            height: 30px;
            display: flex;
            align-items: center;
            gap: 2px;
        }
        
        .waveform-bar {
            width: 3px;
            background: var(--wa-teal);
            border-radius: 2px;
            animation: waveform 0.5s ease-in-out infinite;
        }
        
        @keyframes waveform {
            0%, 100% { height: 5px; }
            50% { height: 20px; }
        }
        
        .recording-cancel {
            padding: 0.5rem 1rem;
            background: none;
            border: 1px solid #dc3545;
            color: #dc3545;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.875rem;
        }
        
        .recording-cancel:hover {
            background: #dc3545;
            color: white;
        }
        
        .recording-send {
            width: 44px;
            height: 44px;
            background: var(--wa-teal);
            border: none;
            border-radius: 50%;
            color: white;
            cursor: pointer;
        }
        
        /* Empty State */
        .empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--wa-text-secondary);
            text-align: center;
            padding: 2rem 1rem;
            background: var(--wa-chat-bg);
        }
        
        .empty-state i {
            font-size: 4rem;
            color: var(--wa-teal);
            opacity: 0.4;
            margin-bottom: 1rem;
        }
        
        .empty-state h3 {
            font-size: 1.25rem;
            color: var(--wa-text);
            margin-bottom: 0.5rem;
            font-weight: 400;
        }
        
        .empty-state p {
            font-size: 0.875rem;
            max-width: 350px;
            margin-bottom: 1.25rem;
            line-height: 1.5;
        }
        
        .empty-state .btn {
            padding: 0.5rem 1.25rem;
            font-size: 0.875rem;
            border-radius: 20px;
            font-weight: 500;
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(0, 168, 132, 0.2);
        }
        
        .empty-state .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 168, 132, 0.3);
        }
        
        /* ========================================
           MOBILE RESPONSIVE STYLES
           ======================================== */
        
        /* Tablet */
        @media (max-width: 992px) {
            .chat-messages {
                padding: 1rem 1.5rem;
            }
            
            .inbox-sidebar {
                width: 320px;
                min-width: 280px;
            }
        }
        
        /* Mobile - Main breakpoint */
        @media (max-width: 768px) {
            .inbox-container {
                height: calc(100vh - 80px);
                border-radius: 0;
            }
            
            .inbox-sidebar {
                width: 100%;
                min-width: 100%;
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 10;
            }
            
            .chat-area {
                display: none;
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 20;
            }
            
            .inbox-container.has-selection .inbox-sidebar {
                display: none;
            }
            
            .inbox-container.has-selection .chat-area {
                display: flex;
            }
            
            .chat-area {
                overflow: hidden;
            }
            
            .chat-input {
                position: relative;
                z-index: 10;
            }
            
            .inbox-header {
                padding: 0.5rem 0.75rem;
                min-height: 56px;
            }
            
            .inbox-header h1 {
                font-size: 1.125rem;
            }
            
            .inbox-search {
                padding: 0.5rem;
            }
            
            .inbox-filters {
                padding: 0.5rem;
                gap: 0.375rem;
                overflow-x: auto;
                flex-wrap: nowrap;
            }
            
            .inbox-filter {
                flex: 0 0 auto;
                padding: 0.3rem 0.625rem;
                font-size: 0.75rem;
            }
            
            .conversation-item {
                padding: 0.625rem 0.75rem;
            }
            
            .conv-avatar {
                width: 45px;
                height: 45px;
                font-size: 1.125rem;
                margin-right: 0.75rem;
            }
            
            .conv-name {
                font-size: 0.9375rem;
            }
            
            .conv-preview {
                font-size: 0.8125rem;
            }
            
            .conv-time {
                font-size: 0.6875rem;
            }
            
            /* Chat area mobile */
            .chat-header {
                padding: 0.5rem 0.75rem;
                min-height: 56px;
            }
            
            .chat-header-avatar {
                width: 36px;
                height: 36px;
                font-size: 1rem;
                margin-right: 0.625rem;
            }
            
            .chat-header-name {
                font-size: 0.9375rem;
            }
            
            .chat-header-status {
                font-size: 0.75rem;
            }
            
            .chat-messages {
                padding: 0.75rem;
            }
            
            .message {
                max-width: 85%;
                padding: 0.375rem 0.5rem 0.25rem 0.5rem;
            }
            
            .message-text {
                font-size: 0.875rem;
            }
            
            .message-sender {
                font-size: 0.6875rem;
            }
            
            .message-meta {
                font-size: 0.625rem;
            }
            
            .message-media {
                max-width: 250px;
            }
            
            .message-media audio {
                width: 200px;
            }
            
            /* Media preview mobile */
            .media-preview-inner {
                flex-wrap: wrap;
            }
            
            .media-preview-thumb {
                width: 60px;
                height: 60px;
            }
            
            .media-preview-info {
                width: 100%;
                order: 3;
                margin-top: 0.5rem;
            }
            
            /* Attachment menu mobile */
            .attachment-menu {
                left: 0;
                right: 0;
                justify-content: center;
            }
            
            .attachment-option {
                padding: 0.5rem;
                min-width: 50px;
            }
            
            .attachment-option i {
                width: 35px;
                height: 35px;
                font-size: 1rem;
            }
            
            /* Voice recording mobile */
            .voice-recording-ui {
                padding: 0.5rem;
            }
            
            .recording-cancel {
                padding: 0.375rem 0.75rem;
                font-size: 0.8rem;
            }
            
            .recording-send {
                width: 38px;
                height: 38px;
            }
            
            .chat-input {
                padding: 0.5rem 0.625rem;
                gap: 0.375rem;
            }
            
            .chat-input-field {
                padding: 0.5rem 0.875rem;
                font-size: 0.9375rem;
                min-height: 40px;
                max-height: 100px;
                border-radius: 20px;
                flex: 1;
                min-width: 0;
            }
            
            .chat-input-btn {
                width: 40px;
                height: 40px;
                min-width: 40px;
                min-height: 40px;
                max-width: 40px;
                max-height: 40px;
                font-size: 1rem;
                flex-shrink: 0;
            }
            
            .chat-input-btn.attachment,
            .chat-input-btn.templates {
                width: 32px;
                height: 32px;
                min-width: 32px;
                min-height: 32px;
                max-width: 32px;
                max-height: 32px;
                font-size: 1rem;
            }
            
            .chat-input-btn.voice {
                width: 40px;
                height: 40px;
                min-width: 40px;
                min-height: 40px;
                max-width: 40px;
                max-height: 40px;
            }
            
            .voice-recording-indicator {
                padding: 0 0.75rem;
            }
            
            /* Back button for mobile */
            .mobile-back-btn {
                display: flex !important;
            }
            
            /* Empty state mobile */
            .empty-state {
                padding: 1.5rem 1rem;
            }
            
            .empty-state i {
                font-size: 3rem;
                margin-bottom: 0.875rem;
            }
            
            .empty-state h3 {
                font-size: 1.125rem;
                margin-bottom: 0.375rem;
            }
            
            .empty-state p {
                font-size: 0.8125rem;
                margin-bottom: 1rem;
                max-width: 280px;
            }
            
            .empty-state .btn {
                padding: 0.4375rem 1rem;
                font-size: 0.8125rem;
            }
            
            /* Date divider mobile */
            .date-divider span {
                font-size: 0.6875rem;
                padding: 0.25rem 0.625rem;
            }
        }
        
        /* Small mobile */
        @media (max-width: 480px) {
            .inbox-container {
                height: calc(100vh - 60px);
            }
            
            .inbox-header h1 {
                font-size: 1rem;
            }
            
            .inbox-filter {
                padding: 0.25rem 0.5rem;
                font-size: 0.6875rem;
            }
            
            .conv-avatar {
                width: 40px;
                height: 40px;
                font-size: 1rem;
                margin-right: 0.625rem;
            }
            
            .message {
                max-width: 90%;
            }
            
            .message-text {
                font-size: 0.8125rem;
            }
            
            .message-sender {
                font-size: 0.625rem;
            }
            
            .chat-input {
                padding: 0.5rem;
                gap: 0.25rem;
            }
            
            .chat-input-field {
                padding: 0.5rem 0.75rem;
                font-size: 0.9375rem;
                min-height: 38px;
                max-height: 80px;
                border-radius: 19px;
            }
            
            .chat-input-btn {
                width: 38px;
                height: 38px;
                min-width: 38px;
                min-height: 38px;
                max-width: 38px;
                max-height: 38px;
                font-size: 1rem;
            }
            
            .chat-input-btn.attachment,
            .chat-input-btn.templates {
                width: 30px;
                height: 30px;
                min-width: 30px;
                min-height: 30px;
                max-width: 30px;
                max-height: 30px;
                font-size: 0.9375rem;
            }
            
            .chat-input-btn.voice {
                width: 38px;
                height: 38px;
                min-width: 38px;
                min-height: 38px;
                max-width: 38px;
                max-height: 38px;
            }
            
            .voice-recording-indicator {
                padding: 0 0.5rem;
                gap: 0.5rem;
            }
            
            .recording-time {
                font-size: 0.875rem;
            }
            
            .recording-cancel,
            .recording-send {
                width: 36px;
                height: 36px;
                font-size: 0.9375rem;
            }
            
            /* Empty state small mobile */
            .empty-state {
                padding: 1.25rem 0.75rem;
            }
            
            .empty-state i {
                font-size: 2.5rem;
                margin-bottom: 0.75rem;
            }
            
            .empty-state h3 {
                font-size: 1rem;
            }
            
            .empty-state p {
                font-size: 0.75rem;
                max-width: 250px;
            }
            
            .empty-state .btn {
                padding: 0.375rem 0.875rem;
                font-size: 0.75rem;
            }
        }
        
        /* Mobile back button - hidden on desktop */
        .mobile-back-btn {
            display: none;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border: none;
            background: transparent;
            color: var(--wa-text-secondary);
            border-radius: 50%;
            margin-right: 0.5rem;
            cursor: pointer;
        }
        
        .mobile-back-btn:hover {
            background: var(--wa-hover);
            color: var(--wa-teal);
        }
        
        /* Setup Warning */
        .setup-warning {
            background: #f8f9fa;
            border: 1px solid var(--wa-teal);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            color: var(--wa-text);
        }
        
        .setup-warning h5 {
            color: var(--wa-teal);
        }
        
        .setup-warning code {
            background: var(--wa-search-bg);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            color: var(--wa-teal);
        }
        
        @media (max-width: 768px) {
            .setup-warning {
                padding: 1rem;
                font-size: 0.875rem;
            }
        }
        
        /* Typing indicator */
        .typing-indicator {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.5rem;
            color: var(--wa-text-secondary);
            font-size: 0.8125rem;
            font-style: italic;
        }
        
        .typing-dots {
            display: flex;
            gap: 0.125rem;
        }
        
        .typing-dots span {
            width: 6px;
            height: 6px;
            background: var(--wa-text-secondary);
            border-radius: 50%;
            animation: typing 1.4s infinite ease-in-out both;
        }
        
        .typing-dots span:nth-child(1) { animation-delay: 0s; }
        .typing-dots span:nth-child(2) { animation-delay: 0.2s; }
        .typing-dots span:nth-child(3) { animation-delay: 0.4s; }
        
        @keyframes typing {
            0%, 80%, 100% { transform: scale(0.6); opacity: 0.5; }
            40% { transform: scale(1); opacity: 1; }
        }
        
        /* Reaction bubble */
        .message-reaction {
            position: absolute;
            bottom: -10px;
            right: 4px;
            background: var(--wa-input-bg);
            border-radius: 12px;
            padding: 0.125rem 0.375rem;
            font-size: 0.875rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        
        /* Hide FAB on this page - it covers the send button on mobile */
        .fab-container {
            display: none !important;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php try { include '../../includes/sidebar.php'; } catch (Throwable $e) {} ?>
    
    <div class="admin-content">
        <?php try { include '../../includes/topbar.php'; } catch (Throwable $e) {} ?>
        
        <main class="main-content p-2 p-md-3">
            <!-- Error Message -->
            <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>
            
            <?php if (!$tables_exist): ?>
            <div class="setup-warning">
                <h5><i class="fas fa-database me-2"></i>Database Setup Required</h5>
                <p class="mb-2">Please run the WhatsApp database migration to create the required tables.</p>
                <p class="mb-0"><strong>File:</strong> <code>database/whatsapp_conversation_tables.sql</code></p>
            </div>
            <?php else: ?>
            
            <div class="inbox-container <?php echo $selected_id ? 'has-selection' : ''; ?>">
                <!-- Sidebar - Conversation List -->
                <div class="inbox-sidebar">
                    <div class="inbox-header">
                        <h1><i class="fab fa-whatsapp me-2"></i>WhatsApp</h1>
                        <div class="d-flex gap-1">
                            <a href="templates.php" class="btn btn-sm btn-light" title="Templates">
                                <i class="fas fa-file-alt"></i>
                            </a>
                            <?php if ($is_admin): ?>
                            <a href="../../donor-management/sms/whatsapp-settings.php" class="btn btn-sm btn-light" title="Settings">
                                <i class="fas fa-cog"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="inbox-search">
                        <form method="GET" id="searchForm">
                            <input type="text" name="search" placeholder="Search name or phone..." 
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   onchange="this.form.submit()">
                            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        </form>
                    </div>
                    
                    <div class="inbox-filters">
                        <a href="?filter=all" class="inbox-filter <?php echo $filter === 'all' ? 'active' : ''; ?>">
                            All <span class="badge bg-secondary"><?php echo $stats['total']; ?></span>
                        </a>
                        <a href="?filter=unread" class="inbox-filter <?php echo $filter === 'unread' ? 'active' : ''; ?>">
                            Unread <span class="badge bg-success"><?php echo $stats['unread']; ?></span>
                        </a>
                        <a href="?filter=unknown" class="inbox-filter <?php echo $filter === 'unknown' ? 'active' : ''; ?>">
                            Unknown <span class="badge bg-warning text-dark"><?php echo $stats['unknown']; ?></span>
                        </a>
                        <?php if (!$is_admin): ?>
                        <a href="?filter=mine" class="inbox-filter <?php echo $filter === 'mine' ? 'active' : ''; ?>">
                            Mine
                        </a>
                        <?php endif; ?>
                        <button type="button" class="bulk-mode-btn" id="bulkModeBtn" onclick="toggleBulkMode()" title="Select multiple">
                            <i class="fas fa-check-double"></i>
                        </button>
                    </div>
                    
                    <!-- Select All Row -->
                    <div class="select-all-row">
                        <div class="conv-checkbox" id="selectAllCheckbox" onclick="toggleSelectAll()"></div>
                        <label class="select-all-label" onclick="toggleSelectAll()">Select All</label>
                    </div>
                    
                    <div class="conversation-list">
                        <?php if (empty($conversations)): ?>
                        <div class="empty-state">
                            <i class="fab fa-whatsapp"></i>
                            <h3>No Conversations Yet</h3>
                            <p>When donors message you on WhatsApp, they'll appear here.</p>
                            <a href="new-chat.php" class="btn" style="background:var(--wa-teal);color:white;border:none;">
                                <i class="fas fa-plus me-1"></i>Start New Chat
                            </a>
                        </div>
                        <?php else: ?>
                            <?php foreach ($conversations as $conv): ?>
                            <?php
                            $name = $conv['donor_name'] ?: $conv['contact_name'] ?: 'Unknown';
                            $initials = strtoupper(substr($name, 0, 1));
                            $isUnread = (int)$conv['unread_count'] > 0;
                            $isActive = $selected_id === (int)$conv['id'];
                            $isUnknown = (int)$conv['is_unknown'] === 1;
                            $lastTime = $conv['last_message_at'] ? date('M j, g:i A', strtotime($conv['last_message_at'])) : '';
                            ?>
                            <a href="?id=<?php echo $conv['id']; ?>&filter=<?php echo $filter; ?>" 
                               class="conversation-item <?php echo $isActive ? 'active' : ''; ?> <?php echo $isUnread ? 'unread' : ''; ?>"
                               data-conversation-id="<?php echo $conv['id']; ?>"
                               onclick="return handleConversationClick(event, this)">
                                <div class="conv-checkbox" data-id="<?php echo $conv['id']; ?>" onclick="event.preventDefault(); event.stopPropagation(); toggleConversationSelect(this);"></div>
                                <div class="conv-avatar <?php echo $isUnknown ? 'unknown' : ''; ?>">
                                    <?php echo $initials; ?>
                                </div>
                                <div class="conv-content">
                                    <div class="conv-header">
                                        <span class="conv-name"><?php echo htmlspecialchars($name); ?></span>
                                        <span class="conv-time <?php echo $isUnread ? 'unread' : ''; ?>"><?php echo $lastTime; ?></span>
                                    </div>
                                    <div class="conv-preview">
                                        <?php if ($conv['last_message_direction'] === 'outgoing'): ?>
                                        <i class="fas fa-check-double text-primary" style="font-size: 0.7rem;"></i>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($conv['last_message_preview'] ?: 'No messages'); ?>
                                        <?php if ($isUnread): ?>
                                        <span class="conv-unread-badge"><?php echo $conv['unread_count']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Chat Area -->
                <div class="chat-area">
                    <?php if ($selected_conversation): ?>
                    <?php
                    $chatName = $selected_conversation['donor_name'] ?: $selected_conversation['contact_name'] ?: 'Unknown';
                    $chatInitials = strtoupper(substr($chatName, 0, 1));
                    $chatStatus = $selected_conversation['donor_id'] 
                        ? 'Donor • £' . number_format((float)($selected_conversation['donor_balance'] ?? 0), 2) . ' balance'
                        : 'Unknown Contact';
                    ?>
                    <div class="chat-header">
                        <button type="button" class="mobile-back-btn" onclick="goBackToList()">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <?php if ($selected_conversation['donor_id']): ?>
                        <a href="../../donor-management/view-donor.php?id=<?php echo $selected_conversation['donor_id']; ?>" 
                           class="chat-header-link">
                            <div class="chat-header-avatar"><?php echo $chatInitials; ?></div>
                            <div class="chat-header-info">
                                <div class="chat-header-name"><?php echo htmlspecialchars($chatName); ?></div>
                                <div class="chat-header-status"><?php echo $chatStatus; ?></div>
                            </div>
                        </a>
                        <?php else: ?>
                        <div class="chat-header-avatar"><?php echo $chatInitials; ?></div>
                        <div class="chat-header-info">
                            <div class="chat-header-name"><?php echo htmlspecialchars($chatName); ?></div>
                            <div class="chat-header-status"><?php echo $chatStatus; ?></div>
                        </div>
                        <?php endif; ?>
                        <!-- Chat header actions -->
                        <div class="chat-header-actions">
                            <button type="button" class="cha-btn" title="Delete conversation" onclick="event.stopPropagation(); deleteConversation(<?php echo $selected_id; ?>);">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="chat-messages" id="chatMessages">
                        <?php
                        $lastDate = '';
                        foreach ($messages as $msg):
                            $msgDate = date('Y-m-d', strtotime($msg['created_at']));
                            if ($msgDate !== $lastDate):
                                $lastDate = $msgDate;
                                $displayDate = date('F j, Y', strtotime($msg['created_at']));
                        ?>
                        <div class="date-divider"><span><?php echo $displayDate; ?></span></div>
                        <?php endif; ?>
                        
                        <div class="message-wrapper <?php echo $msg['direction']; ?>">
                            <div class="message <?php echo $msg['direction'] === 'incoming' ? 'incoming' : 'outgoing'; ?>" 
                                 data-message-id="<?php echo $msg['id']; ?>"
                                 data-direction="<?php echo $msg['direction']; ?>"
                                 data-type="<?php echo htmlspecialchars($msg['message_type'] ?? 'text'); ?>"
                                 data-status="<?php echo htmlspecialchars($msg['status'] ?? 'sent'); ?>"
                                 data-time="<?php echo date('g:i A', strtotime($msg['created_at'])); ?>"
                                 data-date="<?php echo date('M j, Y', strtotime($msg['created_at'])); ?>"
                                 data-sender="<?php echo htmlspecialchars($msg['sender_name'] ?? ''); ?>"
                                 data-body="<?php echo htmlspecialchars($msg['body'] ?? ''); ?>"
                                 data-media-url="<?php echo htmlspecialchars($msg['media_url'] ?? ''); ?>"
                                 data-media-type="<?php echo htmlspecialchars($msg['message_type'] ?? ''); ?>"
                                 onclick="openMessageDetail(this)">
                                <div class="message-content">
                                    <?php if ($msg['direction'] === 'outgoing' && $msg['sender_name']): ?>
                                    <div class="message-sender"><?php echo htmlspecialchars($msg['sender_name']); ?></div>
                                    <?php endif; ?>
                                    
                                    <?php if ($msg['message_type'] === 'image' && $msg['media_url']): ?>
                                    <?php 
                                    $captionJs = $msg['media_caption'] ? json_encode($msg['media_caption'], JSON_HEX_APOS | JSON_HEX_QUOT) : "''";
                                    ?>
                                    <div class="message-media">
                                        <img src="<?php echo htmlspecialchars($msg['media_url']); ?>" alt="Image" 
                                             onclick="openGallery(this.src, <?php echo $captionJs; ?>)">
                                    </div>
                                    <?php if ($msg['media_caption']): ?>
                                    <div class="message-text"><?php echo nl2br(htmlspecialchars($msg['media_caption'])); ?></div>
                                    <?php endif; ?>
                                    <?php elseif ($msg['message_type'] === 'video' && $msg['media_url']): ?>
                                    <div class="message-media">
                                        <video controls src="<?php echo htmlspecialchars($msg['media_url']); ?>"></video>
                                    </div>
                                    <?php if ($msg['media_caption']): ?>
                                    <div class="message-text"><?php echo nl2br(htmlspecialchars($msg['media_caption'])); ?></div>
                                    <?php endif; ?>
                                    <?php elseif ($msg['message_type'] === 'document' && $msg['media_url']): ?>
                                    <div class="message-media document">
                                        <i class="fas fa-file-alt fa-2x text-secondary"></i>
                                        <div>
                                            <div><?php echo htmlspecialchars($msg['media_filename'] ?: 'Document'); ?></div>
                                            <a href="<?php echo htmlspecialchars($msg['media_url']); ?>" target="_blank" class="small">Download</a>
                                        </div>
                                    </div>
                                    <?php elseif ($msg['message_type'] === 'voice' || $msg['message_type'] === 'audio'): ?>
                                    <div class="message-media">
                                        <audio controls src="<?php echo htmlspecialchars($msg['media_url']); ?>" style="width: 250px;"></audio>
                                    </div>
                                    <?php elseif ($msg['message_type'] === 'location'): ?>
                                    <div class="message-media">
                                        <a href="https://maps.google.com/?q=<?php echo $msg['latitude']; ?>,<?php echo $msg['longitude']; ?>" target="_blank">
                                            <i class="fas fa-map-marker-alt fa-2x text-danger"></i>
                                            📍 <?php echo htmlspecialchars($msg['location_name'] ?: 'Location'); ?>
                                        </a>
                                    </div>
                                    <?php else: ?>
                                    <div class="message-text"><?php echo nl2br(htmlspecialchars($msg['body'] ?? '')); ?></div>
                                    <?php endif; ?>
                                    
                                    <div class="message-meta">
                                        <span><?php echo date('g:i A', strtotime($msg['created_at'])); ?></span>
                                        <?php if ($msg['direction'] === 'outgoing'): ?>
                                        <span class="message-status">
                                            <?php if ($msg['status'] === 'read'): ?>
                                            <i class="fas fa-check-double"></i>
                                            <?php elseif ($msg['status'] === 'delivered'): ?>
                                            <i class="fas fa-check-double" style="color: #667781;"></i>
                                            <?php elseif ($msg['status'] === 'sent'): ?>
                                            <i class="fas fa-check" style="color: #667781;"></i>
                                            <?php elseif ($msg['status'] === 'failed'): ?>
                                            <i class="fas fa-exclamation-circle text-danger"></i>
                                            <?php else: ?>
                                            <i class="fas fa-clock" style="color: #667781;"></i>
                                            <?php endif; ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Input Area Wrapper -->
                    <div class="chat-input-wrapper" style="position: relative;">
                        <!-- Media Preview -->
                        <div class="media-preview" id="mediaPreview">
                            <div class="media-preview-inner">
                                <div class="media-preview-thumb" id="mediaThumb">
                                    <i class="fas fa-file"></i>
                                </div>
                                <div class="media-preview-info">
                                    <div class="media-preview-name" id="mediaName">filename.jpg</div>
                                    <div class="media-preview-size" id="mediaSize">2.5 MB</div>
                                    <input type="text" class="media-preview-caption" id="mediaCaption" placeholder="Add a caption...">
                                </div>
                                <button type="button" class="media-preview-close" onclick="clearMediaPreview()">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    
                        <form class="chat-input" id="sendForm" method="POST" action="api/send-message.php">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="conversation_id" value="<?php echo $selected_id; ?>">
                        <input type="hidden" name="phone" value="<?php echo htmlspecialchars($selected_conversation['phone_number']); ?>">
                        
                        <!-- Hidden file input -->
                        <input type="file" id="fileInput" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip" style="display: none;">
                        
                        <!-- Templates Menu -->
                        <div class="templates-menu" id="templatesMenu">
                            <div class="templates-header">
                                <h5><i class="fas fa-file-alt me-2"></i>Message Templates</h5>
                                <button type="button" class="templates-close" id="templatesCloseBtn">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="templates-content" id="templatesContent">
                                <div class="templates-loading">
                                    <i class="fas fa-spinner fa-spin"></i> Loading templates...
                                </div>
                            </div>
                        </div>
                        
                        <!-- Attachment Menu -->
                        <div class="attachment-menu" id="attachmentMenu">
                            <button type="button" class="attachment-option" onclick="selectFileType('image')">
                                <i class="fas fa-image photo-icon"></i>
                                <span>Photos</span>
                            </button>
                            <button type="button" class="attachment-option" onclick="selectFileType('document')">
                                <i class="fas fa-file-alt document-icon"></i>
                                <span>Document</span>
                            </button>
                            <button type="button" class="attachment-option" onclick="selectFileType('camera')">
                                <i class="fas fa-camera camera-icon"></i>
                                <span>Camera</span>
                            </button>
                        </div>
                        
                        <!-- Templates Button -->
                        <button type="button" class="chat-input-btn templates" id="templatesBtn">
                            <i class="fas fa-plus"></i>
                        </button>
                        
                        <!-- Attachment Button -->
                        <button type="button" class="chat-input-btn attachment" id="attachBtn" onclick="toggleAttachmentMenu()">
                            <i class="fas fa-paperclip"></i>
                        </button>
                        
                        <!-- Text Input (Auto-expanding) -->
                        <textarea name="message" class="chat-input-field" placeholder="Type a message" 
                               autocomplete="off" id="messageInput" rows="1"></textarea>
                        
                        <!-- Send Button -->
                        <button type="submit" class="chat-input-btn" id="sendBtn">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                        
                        <!-- Voice Button -->
                        <button type="button" class="chat-input-btn voice" id="voiceBtn" onclick="toggleVoiceRecording()">
                            <i class="fas fa-microphone"></i>
                        </button>
                        
                        <!-- Voice Recording Indicator -->
                        <div class="voice-recording-indicator" id="voiceRecordingIndicator" style="display: none;">
                            <button type="button" class="recording-cancel" onclick="cancelVoiceRecording()" title="Cancel">
                                <i class="fas fa-trash"></i>
                            </button>
                            <div class="recording-dot"></div>
                            <span class="recording-time" id="recordingTime">0:00</span>
                            <button type="button" class="recording-send" onclick="stopVoiceRecording()" title="Send">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                        
                        </form>
                    </div><!-- End chat-input-wrapper -->
                    
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fab fa-whatsapp"></i>
                        <h3>Select a Conversation</h3>
                        <p>Choose a conversation from the list to start chatting</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php endif; ?>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/admin.js"></script>
<script>
// Scroll to bottom of messages
const chatMessages = document.getElementById('chatMessages');
if (chatMessages) {
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

// Handle form submission
const sendForm = document.getElementById('sendForm');
const messageInput = document.getElementById('messageInput');
const sendBtn = document.getElementById('sendBtn');

// Auto-expand textarea as user types
if (messageInput) {
    function autoResize() {
        // Reset height to calculate new height
        messageInput.style.height = 'auto';
        
        // Get content height
        const newHeight = Math.min(messageInput.scrollHeight, 120);
        messageInput.style.height = newHeight + 'px';
        
        // Show scrollbar if at max height
        messageInput.style.overflowY = messageInput.scrollHeight > 120 ? 'auto' : 'hidden';
    }
    
    // Auto-resize on input
    messageInput.addEventListener('input', autoResize);
    
    // Enter to send, Shift+Enter for new line
    messageInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (sendForm && (this.value.trim() || selectedFile)) {
                sendForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            }
        }
    });
    
    // Initialize
    autoResize();
}

if (sendForm) {
    sendForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // Check if there's a selected file for media
        if (selectedFile) {
            await sendMediaMessage();
            return;
        }
        
        const message = messageInput.value.trim();
        if (!message) return;
        
        sendBtn.disabled = true;
        
        try {
            const formData = new FormData(sendForm);
            const response = await fetch('api/send-message.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Track this message ID to prevent duplicate from polling
                if (result.message_id) {
                    locallyAddedMessages.add(result.message_id);
                    // Update lastMessageId so polling skips this message
                    lastMessageId = Math.max(lastMessageId, result.message_id);
                }
                
                // Add message to UI
                const msgDiv = document.createElement('div');
                msgDiv.className = 'message outgoing';
                if (result.message_id) {
                    msgDiv.dataset.messageId = result.message_id;
                }
                msgDiv.innerHTML = `
                    <div class="message-content">
                        <div class="message-sender"><?php echo htmlspecialchars($current_user['name'] ?? 'You'); ?></div>
                        <div class="message-text">${escapeHtml(message)}</div>
                        <div class="message-meta">
                            <span>${new Date().toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}</span>
                            <span class="message-status"><i class="fas fa-check" style="color: #667781;"></i></span>
                        </div>
                    </div>
                `;
                chatMessages.appendChild(msgDiv);
                chatMessages.scrollTop = chatMessages.scrollHeight;
                
                messageInput.value = '';
                
            } else {
                alert('Failed to send: ' + (result.error || 'Unknown error'));
            }
        } catch (err) {
            alert('Error sending message: ' + err.message);
        } finally {
            sendBtn.disabled = false;
            messageInput.focus();
        }
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Mobile navigation
function goBackToList() {
    const container = document.querySelector('.inbox-container');
    if (container) {
        container.classList.remove('has-selection');
    }
    // Update URL without the id parameter
    const url = new URL(window.location);
    url.searchParams.delete('id');
    window.history.pushState({}, '', url);
}

// Handle mobile view - add has-selection class if conversation is selected
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($selected_id): ?>
    const container = document.querySelector('.inbox-container');
    if (container && window.innerWidth <= 768) {
        container.classList.add('has-selection');
    }
    <?php endif; ?>
    
    // Handle window resize
    window.addEventListener('resize', function() {
        const container = document.querySelector('.inbox-container');
        if (container) {
            if (window.innerWidth > 768) {
                container.classList.remove('has-selection');
            } else if (<?php echo $selected_id ? 'true' : 'false'; ?>) {
                container.classList.add('has-selection');
            }
        }
    });
});

// ============================================
// REAL-TIME MESSAGING - Auto-poll for updates
// ============================================

<?php if ($selected_id): ?>
// Track the last message ID we've seen
let lastMessageId = <?php 
    $lastMsg = end($messages);
    echo $lastMsg ? (int)$lastMsg['id'] : 0;
?>;
let lastDate = '<?php echo $lastMsg ? date('Y-m-d', strtotime($lastMsg['created_at'])) : ''; ?>';
let isPolling = true;
let pollInterval = 3000; // 3 seconds
let locallyAddedMessages = new Set(); // Track messages added locally to avoid duplicates

// Poll for new messages
async function pollNewMessages() {
    if (!isPolling) return;
    
    try {
        const response = await fetch(`api/get-new-messages.php?conversation_id=<?php echo $selected_id; ?>&last_message_id=${lastMessageId}`);
        const data = await response.json();
        
        if (data.success && data.messages && data.messages.length > 0) {
            // Add new messages to the chat
            data.messages.forEach(msg => {
                addMessageToChat(msg);
            });
            
            // Update last message ID
            lastMessageId = data.last_message_id;
            
            // Play notification sound for incoming messages
            const hasIncoming = data.messages.some(m => m.direction === 'incoming');
            if (hasIncoming) {
                playNotificationSound();
            }
        }
    } catch (err) {
        console.error('Poll error:', err);
    }
    
    // Schedule next poll
    setTimeout(pollNewMessages, pollInterval);
}

// Add a message to the chat UI
function addMessageToChat(msg) {
    const chatMessages = document.getElementById('chatMessages');
    if (!chatMessages) return;
    
    // Skip if message already exists in DOM or was locally added
    if (document.querySelector(`[data-message-id="${msg.id}"]`)) return;
    if (locallyAddedMessages.has(msg.id)) return;
    
    // Check if we need a date divider
    const msgDate = msg.date;
    if (msgDate !== lastDate) {
        lastDate = msgDate;
        const divider = document.createElement('div');
        divider.className = 'date-divider';
        divider.innerHTML = `<span>${escapeHtml(msgDate)}</span>`;
        chatMessages.appendChild(divider);
    }
    
    // Create message element
    const msgDiv = document.createElement('div');
    msgDiv.className = `message ${msg.direction === 'incoming' ? 'incoming' : 'outgoing'}`;
    msgDiv.dataset.messageId = msg.id;
    
    let content = '<div class="message-content">';
    
    // Sender name for outgoing messages
    if (msg.direction === 'outgoing' && msg.sender_name) {
        content += `<div class="message-sender">${escapeHtml(msg.sender_name)}</div>`;
    }
    
    // Message body based on type
    if (msg.type === 'image' && msg.media_url) {
        const captionJs = msg.media_caption ? JSON.stringify(msg.media_caption) : "''";
        content += `<div class="message-media"><img src="${escapeHtml(msg.media_url)}" alt="Image" onclick="openGallery(this.src, ${captionJs})"></div>`;
        if (msg.media_caption) {
            content += `<div class="message-text">${escapeHtml(msg.media_caption)}</div>`;
        }
    } else if (msg.type === 'video' && msg.media_url) {
        content += `<div class="message-media"><video controls src="${escapeHtml(msg.media_url)}"></video></div>`;
        if (msg.media_caption) {
            content += `<div class="message-text">${escapeHtml(msg.media_caption)}</div>`;
        }
    } else if (msg.type === 'document' && msg.media_url) {
        content += `<div class="message-media document">
            <i class="fas fa-file-alt fa-2x text-secondary"></i>
            <div>
                <div>${escapeHtml(msg.media_filename || 'Document')}</div>
                <a href="${escapeHtml(msg.media_url)}" target="_blank" class="small">Download</a>
            </div>
        </div>`;
    } else if ((msg.type === 'voice' || msg.type === 'audio') && msg.media_url) {
        content += `<div class="message-media"><audio controls src="${escapeHtml(msg.media_url)}" style="width: 250px;"></audio></div>`;
    } else if (msg.type === 'location' && msg.latitude && msg.longitude) {
        content += `<div class="message-media">
            <a href="https://maps.google.com/?q=${msg.latitude},${msg.longitude}" target="_blank">
                <i class="fas fa-map-marker-alt fa-2x text-danger"></i>
                📍 ${escapeHtml(msg.location_name || 'Location')}
            </a>
        </div>`;
    } else {
        content += `<div class="message-text">${escapeHtml(msg.body || '').replace(/\n/g, '<br>')}</div>`;
    }
    
    // Meta info (time and status)
    content += `<div class="message-meta">
        <span>${msg.time}</span>
        ${msg.direction === 'outgoing' ? getStatusIcon(msg.status) : ''}
    </div>`;
    
    content += '</div>';
    msgDiv.innerHTML = content;
    
    chatMessages.appendChild(msgDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

function getStatusIcon(status) {
    switch(status) {
        case 'read':
            return '<span class="message-status"><i class="fas fa-check-double" style="color: #53bdeb;"></i></span>';
        case 'delivered':
            return '<span class="message-status"><i class="fas fa-check-double" style="color: #667781;"></i></span>';
        case 'sent':
            return '<span class="message-status"><i class="fas fa-check" style="color: #667781;"></i></span>';
        case 'failed':
            return '<span class="message-status"><i class="fas fa-exclamation-circle text-danger"></i></span>';
        default:
            return '<span class="message-status"><i class="fas fa-clock" style="color: #667781;"></i></span>';
    }
}

// Notification sound - using actual audio file
const notificationSound = new Audio('../../call-center/assets/mixkit-software-interface-start-2574.wav');
notificationSound.volume = 0.5;

function playNotificationSound() {
    try {
        // Reset and play
        notificationSound.currentTime = 0;
        notificationSound.play().catch(e => {
            // Ignore autoplay errors - browser requires user interaction first
        });
    } catch (e) {
        // Ignore audio errors
    }
}

// Start polling
setTimeout(pollNewMessages, pollInterval);
<?php endif; ?>

// ============================================
// SIDEBAR REAL-TIME UPDATES
// ============================================

let sidebarPollInterval = 5000; // 5 seconds for sidebar

async function pollConversations() {
    try {
        const filter = '<?php echo htmlspecialchars($filter); ?>';
        const search = '<?php echo htmlspecialchars($search); ?>';
        
        const response = await fetch(`api/get-conversations.php?filter=${filter}&search=${encodeURIComponent(search)}`);
        const data = await response.json();
        
        if (data.success) {
            // Update stats badges
            updateStatsBadges(data.stats);
            
            // Update conversation list
            updateConversationList(data.conversations);
        }
    } catch (err) {
        console.error('Sidebar poll error:', err);
    }
    
    setTimeout(pollConversations, sidebarPollInterval);
}

function updateStatsBadges(stats) {
    // Update filter badges
    document.querySelectorAll('.inbox-filter').forEach(filter => {
        const href = filter.getAttribute('href') || '';
        if (href.includes('filter=all')) {
            const badge = filter.querySelector('.badge');
            if (badge) badge.textContent = stats.total;
        } else if (href.includes('filter=unread')) {
            const badge = filter.querySelector('.badge');
            if (badge) badge.textContent = stats.unread;
        } else if (href.includes('filter=unknown')) {
            const badge = filter.querySelector('.badge');
            if (badge) badge.textContent = stats.unknown;
        }
    });
}

function updateConversationList(conversations) {
    const list = document.querySelector('.conversation-list');
    if (!list) return;
    
    const selectedId = <?php echo $selected_id ?: 'null'; ?>;
    const filter = '<?php echo htmlspecialchars($filter); ?>';
    
    // Only update if we have conversations
    if (conversations.length === 0) return;
    
    // Update existing items or add new ones
    conversations.forEach((conv, index) => {
        let item = list.querySelector(`a[href*="id=${conv.id}"]`);
        
        if (item) {
            // Update existing conversation
            const unreadBadge = item.querySelector('.conv-unread-badge');
            const previewEl = item.querySelector('.conv-preview');
            const timeEl = item.querySelector('.conv-time');
            
            // Update unread badge
            if (conv.unread_count > 0) {
                item.classList.add('unread');
                if (unreadBadge) {
                    unreadBadge.textContent = conv.unread_count;
                    unreadBadge.style.display = 'flex';
                } else {
                    // Add badge if missing
                    const preview = item.querySelector('.conv-preview');
                    if (preview) {
                        const badge = document.createElement('span');
                        badge.className = 'conv-unread-badge';
                        badge.textContent = conv.unread_count;
                        preview.appendChild(badge);
                    }
                }
            } else {
                item.classList.remove('unread');
                if (unreadBadge) unreadBadge.style.display = 'none';
            }
            
            // Update preview
            if (previewEl) {
                let previewHtml = '';
                if (conv.last_message_direction === 'outgoing') {
                    previewHtml += '<i class="fas fa-check-double text-primary" style="font-size: 0.7rem;"></i> ';
                }
                previewHtml += escapeHtml(conv.last_message_preview);
                // Keep the badge if exists
                const existingBadge = previewEl.querySelector('.conv-unread-badge');
                previewEl.innerHTML = previewHtml;
                if (existingBadge && conv.unread_count > 0) {
                    previewEl.appendChild(existingBadge);
                }
            }
            
            // Update time
            if (timeEl) {
                timeEl.textContent = conv.last_message_at;
                timeEl.classList.toggle('unread', conv.unread_count > 0);
            }
        }
    });
    
    // Reorder if needed (move items with new messages to top)
    // This is optional - might cause UI jumping
}

// Start sidebar polling
setTimeout(pollConversations, sidebarPollInterval);

// Pause polling when tab is not visible
document.addEventListener('visibilitychange', function() {
    if (typeof isPolling !== 'undefined') {
        isPolling = !document.hidden;
    }
    if (!document.hidden) {
        // Resume polling immediately when tab becomes visible
        if (typeof pollNewMessages === 'function') pollNewMessages();
        pollConversations();
    }
});

// ============================================
// MEDIA ATTACHMENT HANDLING
// ============================================

let selectedFile = null;

// Input listener (for future use if needed)
// Voice recording disabled - use attachment button to upload audio files

// ============================================
// DONOR DATA FOR TEMPLATE VARIABLES
// ============================================

const donorTemplateData = <?php echo $donor_template_data_json; ?>;

// ============================================
// TEMPLATES MENU
// ============================================

let templatesLoaded = false;
let templatesData = [];

// Toggle templates menu
function toggleTemplatesMenu() {
    const menu = document.getElementById('templatesMenu');
    if (!menu) return;
    
    const isActive = menu.classList.contains('active');
    
    if (isActive) {
        menu.classList.remove('active');
    } else {
        menu.classList.add('active');
        // Close attachment menu if open
        const attachMenu = document.getElementById('attachmentMenu');
        if (attachMenu) attachMenu.classList.remove('active');
        
        // Load templates if not loaded yet
        if (!templatesLoaded) {
            loadTemplates();
        }
    }
}

// Ensure inline handlers and dynamic bindings can always find this function
window.toggleTemplatesMenu = toggleTemplatesMenu;
if (typeof window.toggleTemplatesMenu !== 'function') {
    window.toggleTemplatesMenu = function () {
        return toggleTemplatesMenu();
    };
}

function bindTemplateToggleControls() {
    const templatesBtn = document.getElementById('templatesBtn');
    const templatesCloseBtn = document.getElementById('templatesCloseBtn');
    const templatesCloseHandler = (event) => {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        window.toggleTemplatesMenu();
    };
    
    if (templatesBtn) {
        templatesBtn.addEventListener('click', templatesCloseHandler);
    }
    
    if (templatesCloseBtn) {
        templatesCloseBtn.addEventListener('click', templatesCloseHandler);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindTemplateToggleControls);
} else {
    bindTemplateToggleControls();
}

// Close templates menu when clicking outside
document.addEventListener('click', function(e) {
    const menu = document.getElementById('templatesMenu');
    const templatesBtn = document.getElementById('templatesBtn');
    if (menu && templatesBtn && !menu.contains(e.target) && e.target !== templatesBtn && !templatesBtn.contains(e.target)) {
        menu.classList.remove('active');
    }
});

// Load templates from API
async function loadTemplates() {
    const content = document.getElementById('templatesContent');
    if (!content) return;
    
    try {
        const response = await fetch('api/get-templates.php');
        const result = await response.json();
        
        if (result.success && result.templates) {
            templatesData = result.templates;
            templatesLoaded = true;
            displayTemplates(result.grouped || {});
        } else {
            content.innerHTML = `
                <div class="templates-empty">
                    <i class="fas fa-file-alt"></i>
                    <p>No templates available</p>
                    <small><a href="templates.php" target="_blank">Create WhatsApp Templates</a></small>
                </div>
            `;
        }
    } catch (err) {
        console.error('Failed to load templates:', err);
        content.innerHTML = `
            <div class="templates-empty">
                <i class="fas fa-exclamation-circle"></i>
                <p>Failed to load templates</p>
            </div>
        `;
    }
}

// Display templates grouped by category
function displayTemplates(grouped) {
    const content = document.getElementById('templatesContent');
    if (!content) return;
    
    if (Object.keys(grouped).length === 0) {
        content.innerHTML = `
            <div class="templates-empty">
                <i class="fas fa-file-alt"></i>
                <p>No templates available</p>
                <small><a href="templates.php" target="_blank">Create WhatsApp Templates</a></small>
            </div>
        `;
        return;
    }
    
    let html = '';
    
    const flatTemplates = [];
    for (const templates of Object.values(grouped)) {
        templates.forEach(template => flatTemplates.push(template));
    }
    
    flatTemplates.forEach(template => {
        html += `
            <div class="template-item" data-template-id="${template.id}">
                <div class="template-name">${escapeHtml(template.name)}</div>
            </div>
        `;
    });
    
    content.innerHTML = html;
    
    // Add click handlers after rendering
    content.querySelectorAll('.template-item').forEach(item => {
        item.addEventListener('click', function() {
            const templateId = parseInt(this.dataset.templateId, 10);
            selectTemplate(templateId);
        });
    });
}

// Process template and replace variables with donor data
function processTemplateVariables(content) {
    if (!donorTemplateData || Object.keys(donorTemplateData).length === 0) {
        return content;
    }
    
    let processed = content;
    
    // Replace all variables in format {variable_name}
    for (const [key, value] of Object.entries(donorTemplateData)) {
        const regex = new RegExp(`\\{${key}\\}`, 'gi');
        processed = processed.replace(regex, value || '');
    }
    
    return processed;
}

// Select template and fill message input
function selectTemplate(templateId) {
    const messageInput = document.getElementById('messageInput');
    const templatesMenu = document.getElementById('templatesMenu');
    
    // Find template content from stored data
    const template = templatesData.find(t => t.id === templateId);
    if (!template) {
        console.error('Template not found:', templateId);
        return;
    }
    
    if (messageInput) {
        // Process template and replace variables with donor data
        const processedContent = processTemplateVariables(template.content);
        
        // Fill the input with processed content
        messageInput.value = processedContent;
        messageInput.focus();
        
        // Move cursor to end
        messageInput.setSelectionRange(messageInput.value.length, messageInput.value.length);
    }
    
    // Close templates menu
    if (templatesMenu) {
        templatesMenu.classList.remove('active');
    }
}

// Toggle attachment menu
function toggleAttachmentMenu() {
    const menu = document.getElementById('attachmentMenu');
    if (menu) {
        const isActive = menu.classList.contains('active');
        menu.classList.toggle('active');
        
        // Close templates menu if opening attachment menu
        if (!isActive) {
            const templatesMenu = document.getElementById('templatesMenu');
            if (templatesMenu) templatesMenu.classList.remove('active');
        }
    }
}

// Close attachment menu when clicking outside
document.addEventListener('click', function(e) {
    const menu = document.getElementById('attachmentMenu');
    const attachBtn = document.getElementById('attachBtn');
    if (menu && attachBtn && !menu.contains(e.target) && e.target !== attachBtn && !attachBtn.contains(e.target)) {
        menu.classList.remove('active');
    }
});

// Select file type and trigger file input
function selectFileType(type) {
    const fileInput = document.getElementById('fileInput');
    if (!fileInput) return;
    
    // Close attachment menu first
    const menu = document.getElementById('attachmentMenu');
    if (menu) menu.classList.remove('active');
    
    // Set accept attribute based on type
    switch(type) {
        case 'image':
            fileInput.accept = 'image/*';
            fileInput.removeAttribute('capture');
            break;
        case 'document':
            fileInput.accept = '.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip';
            fileInput.removeAttribute('capture');
            break;
        case 'camera':
            fileInput.accept = 'image/*';
            fileInput.capture = 'environment';
            break;
    }
    
    // Small delay to ensure menu is closed before file dialog opens
    setTimeout(() => {
        fileInput.click();
    }, 100);
}

// Handle file selection
const fileInput = document.getElementById('fileInput');
if (fileInput) {
    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        // Validate file size (16MB max)
        if (file.size > 16 * 1024 * 1024) {
            alert('File too large. Maximum size is 16MB.');
            this.value = '';
            return;
        }
        
        selectedFile = file;
        showMediaPreview(file);
    });
}

// Show media preview
function showMediaPreview(file) {
    const preview = document.getElementById('mediaPreview');
    const thumb = document.getElementById('mediaThumb');
    const name = document.getElementById('mediaName');
    const size = document.getElementById('mediaSize');
    const captionInput = document.getElementById('mediaCaption');
    
    if (!preview) return;
    
    // Set file info
    name.textContent = file.name;
    size.textContent = formatFileSize(file.size);
    captionInput.value = '';
    
    // Set thumbnail
    thumb.innerHTML = '';
    if (file.type.startsWith('image/')) {
        const img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        img.onload = () => URL.revokeObjectURL(img.src);
        thumb.appendChild(img);
    } else if (file.type.startsWith('video/')) {
        thumb.innerHTML = '<i class="fas fa-video"></i>';
    } else if (file.type.startsWith('audio/')) {
        thumb.innerHTML = '<i class="fas fa-music"></i>';
    } else if (file.type === 'application/pdf') {
        thumb.innerHTML = '<i class="fas fa-file-pdf text-danger"></i>';
    } else if (file.type.includes('word')) {
        thumb.innerHTML = '<i class="fas fa-file-word text-primary"></i>';
    } else if (file.type.includes('excel') || file.type.includes('spreadsheet')) {
        thumb.innerHTML = '<i class="fas fa-file-excel text-success"></i>';
    } else {
        thumb.innerHTML = '<i class="fas fa-file"></i>';
    }
    
    preview.classList.add('active');
}

// Clear media preview
function clearMediaPreview() {
    const preview = document.getElementById('mediaPreview');
    const fileInput = document.getElementById('fileInput');
    
    selectedFile = null;
    if (preview) preview.classList.remove('active');
    if (fileInput) fileInput.value = '';
}

// Format file size
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Send media message
async function sendMediaMessage() {
    if (!selectedFile) return;
    
    const sendBtn = document.getElementById('sendBtn');
    const captionInput = document.getElementById('mediaCaption');
    const caption = captionInput ? captionInput.value.trim() : '';
    
    if (sendBtn) sendBtn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('media', selectedFile);
        formData.append('conversation_id', document.querySelector('input[name="conversation_id"]').value);
        formData.append('phone', document.querySelector('input[name="phone"]').value);
        formData.append('caption', caption);
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
        
        // Determine media type
        // Always use 'audio' for audio files (UltraMsg voice endpoint is more restrictive)
        let mediaType = 'document';
        if (selectedFile.type.startsWith('image/')) mediaType = 'image';
        else if (selectedFile.type.startsWith('video/')) mediaType = 'video';
        else if (selectedFile.type.startsWith('audio/')) mediaType = 'audio';
        formData.append('media_type', mediaType);
        
        const response = await fetch('api/send-media.php', {
            method: 'POST',
            body: formData
        });
        
        // Check if response is ok and has content
        const responseText = await response.text();
        if (!responseText) {
            throw new Error('Server returned empty response. Please try again.');
        }
        
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseErr) {
            console.error('Invalid JSON response:', responseText);
            throw new Error('Server error: ' + responseText.substring(0, 100));
        }
        
        if (result.success) {
            // Track message ID
            if (result.message_id) {
                locallyAddedMessages.add(result.message_id);
                lastMessageId = Math.max(lastMessageId, result.message_id);
            }
            
            // Add to chat UI
            addMediaMessageToChat(result, caption, selectedFile);
            
            // Clear preview
            clearMediaPreview();
        } else {
            alert('Failed to send media: ' + (result.error || 'Unknown error'));
        }
    } catch (err) {
        alert('Error sending media: ' + err.message);
    } finally {
        if (sendBtn) sendBtn.disabled = false;
    }
}

// Add media message to chat
function addMediaMessageToChat(result, caption, file) {
    const chatMessages = document.getElementById('chatMessages');
    if (!chatMessages) return;
    
    const msgDiv = document.createElement('div');
    msgDiv.className = 'message outgoing';
    if (result.message_id) {
        msgDiv.dataset.messageId = result.message_id;
    }
    
    let mediaHtml = '';
    if (file.type.startsWith('image/')) {
        const captionJs = caption ? JSON.stringify(caption) : "''";
        mediaHtml = `<div class="message-media"><img src="${escapeHtml(result.media_url)}" alt="Image" onclick="openGallery(this.src, ${captionJs})"></div>`;
    } else if (file.type.startsWith('video/')) {
        mediaHtml = `<div class="message-media"><video controls src="${escapeHtml(result.media_url)}" style="max-width: 300px;"></video></div>`;
    } else if (file.type.startsWith('audio/')) {
        mediaHtml = `<div class="message-media"><audio controls src="${escapeHtml(result.media_url)}" style="width: 250px;"></audio></div>`;
    } else {
        mediaHtml = `<div class="message-media document">
            <i class="fas fa-file-alt fa-2x text-secondary"></i>
            <div>
                <div>${escapeHtml(result.filename)}</div>
                <a href="${escapeHtml(result.media_url)}" target="_blank" class="small">Download</a>
            </div>
        </div>`;
    }
    
    msgDiv.innerHTML = `
        <div class="message-content">
            <div class="message-sender"><?php echo htmlspecialchars($current_user['name'] ?? 'You'); ?></div>
            ${mediaHtml}
            ${caption ? `<div class="message-text">${escapeHtml(caption)}</div>` : ''}
            <div class="message-meta">
                <span>${new Date().toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}</span>
                <span class="message-status"><i class="fas fa-check" style="color: #667781;"></i></span>
            </div>
        </div>
    `;
    
    chatMessages.appendChild(msgDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

// ============================================
// VOICE RECORDING WITH MP3 CONVERSION
// ============================================

let mediaRecorder = null;
let audioChunks = [];
let recordingStartTime = null;
let recordingTimer = null;
let audioContext = null;

// Check if voice recording is supported
function isVoiceRecordingSupported() {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.lamejs);
}

// Toggle voice recording
async function toggleVoiceRecording() {
    if (!isVoiceRecordingSupported()) {
        alert('Voice recording is not supported in your browser.');
        return;
    }
    
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        // Stop recording
        stopVoiceRecording();
    } else {
        // Start recording
        await startVoiceRecording();
    }
}

// Start voice recording
async function startVoiceRecording() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ 
            audio: {
                channelCount: 1,
                sampleRate: 44100
            } 
        });
        
        audioChunks = [];
        audioContext = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: 44100 });
        
        mediaRecorder = new MediaRecorder(stream, {
            mimeType: MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : 'audio/ogg'
        });
        
        mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                audioChunks.push(event.data);
            }
        };
        
        mediaRecorder.onstop = async () => {
            // Stop all tracks
            stream.getTracks().forEach(track => track.stop());
            
            // Convert to MP3 and send
            await convertAndSendVoice();
        };
        
        mediaRecorder.start(100); // Collect data every 100ms
        recordingStartTime = Date.now();
        
        // Update UI
        document.getElementById('voiceBtn').classList.add('recording');
        document.getElementById('voiceRecordingIndicator').style.display = 'flex';
        
        // Hide other input elements
        document.getElementById('messageInput').style.display = 'none';
        document.getElementById('templatesBtn').style.display = 'none';
        document.getElementById('attachBtn').style.display = 'none';
        document.getElementById('sendBtn').style.display = 'none';
        
        // Start timer
        updateRecordingTime();
        recordingTimer = setInterval(updateRecordingTime, 1000);
        
    } catch (err) {
        console.error('Failed to start recording:', err);
        alert('Could not access microphone. Please check permissions.');
    }
}

// Stop voice recording
function stopVoiceRecording() {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
    }
    
    // Clear timer
    if (recordingTimer) {
        clearInterval(recordingTimer);
        recordingTimer = null;
    }
    
    // Reset UI
    resetRecordingUI();
}

// Cancel voice recording
function cancelVoiceRecording() {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        // Remove the onstop handler to prevent sending
        mediaRecorder.onstop = () => {
            mediaRecorder.stream.getTracks().forEach(track => track.stop());
        };
        mediaRecorder.stop();
    }
    
    audioChunks = [];
    
    // Clear timer
    if (recordingTimer) {
        clearInterval(recordingTimer);
        recordingTimer = null;
    }
    
    // Reset UI
    resetRecordingUI();
}

// Reset recording UI
function resetRecordingUI() {
    document.getElementById('voiceBtn').classList.remove('recording');
    document.getElementById('voiceRecordingIndicator').style.display = 'none';
    document.getElementById('messageInput').style.display = '';
    document.getElementById('templatesBtn').style.display = '';
    document.getElementById('attachBtn').style.display = '';
    document.getElementById('sendBtn').style.display = '';
    document.getElementById('recordingTime').textContent = '0:00';
}

// Update recording time display
function updateRecordingTime() {
    if (!recordingStartTime) return;
    
    const elapsed = Math.floor((Date.now() - recordingStartTime) / 1000);
    const minutes = Math.floor(elapsed / 60);
    const seconds = elapsed % 60;
    document.getElementById('recordingTime').textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
}

// Convert recorded audio to MP3 and send
async function convertAndSendVoice() {
    try {
        // Show loading state
        const voiceBtn = document.getElementById('voiceBtn');
        voiceBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        voiceBtn.disabled = true;
        
        // Create blob from chunks
        const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
        
        // Decode audio data
        const arrayBuffer = await audioBlob.arrayBuffer();
        const audioBuffer = await audioContext.decodeAudioData(arrayBuffer);
        
        // Convert to MP3
        const mp3Blob = await encodeMP3(audioBuffer);
        
        // Create file and send
        const fileName = `voice_${Date.now()}.mp3`;
        const mp3File = new File([mp3Blob], fileName, { type: 'audio/mp3' });
        
        // Send via sendMediaMessage
        await sendVoiceMessage(mp3File);
        
    } catch (err) {
        console.error('Failed to convert/send voice:', err);
        alert('Failed to send voice message: ' + err.message);
    } finally {
        // Reset button
        const voiceBtn = document.getElementById('voiceBtn');
        voiceBtn.innerHTML = '<i class="fas fa-microphone"></i>';
        voiceBtn.disabled = false;
        audioChunks = [];
    }
}

// Encode audio buffer to MP3 using lamejs
function encodeMP3(audioBuffer) {
    return new Promise((resolve, reject) => {
        try {
            const channels = audioBuffer.numberOfChannels;
            const sampleRate = audioBuffer.sampleRate;
            const samples = audioBuffer.getChannelData(0);
            
            // Convert float32 samples to int16
            const sampleCount = samples.length;
            const int16Samples = new Int16Array(sampleCount);
            
            for (let i = 0; i < sampleCount; i++) {
                const s = Math.max(-1, Math.min(1, samples[i]));
                int16Samples[i] = s < 0 ? s * 0x8000 : s * 0x7FFF;
            }
            
            // Create MP3 encoder
            const mp3encoder = new lamejs.Mp3Encoder(1, sampleRate, 128);
            const mp3Data = [];
            
            // Encode in chunks
            const blockSize = 1152;
            for (let i = 0; i < int16Samples.length; i += blockSize) {
                const chunk = int16Samples.subarray(i, i + blockSize);
                const mp3buf = mp3encoder.encodeBuffer(chunk);
                if (mp3buf.length > 0) {
                    mp3Data.push(mp3buf);
                }
            }
            
            // Flush encoder
            const mp3buf = mp3encoder.flush();
            if (mp3buf.length > 0) {
                mp3Data.push(mp3buf);
            }
            
            // Create blob
            const blob = new Blob(mp3Data, { type: 'audio/mp3' });
            resolve(blob);
            
        } catch (err) {
            reject(err);
        }
    });
}

// Send voice message
async function sendVoiceMessage(file) {
    const formData = new FormData();
    formData.append('media', file);
    formData.append('conversation_id', <?php echo $selected_id ?: 0; ?>);
    formData.append('phone', '<?php echo htmlspecialchars($selected_conversation['phone_number'] ?? ''); ?>');
    formData.append('media_type', 'audio');
    formData.append('csrf_token', '<?php echo csrf_token(); ?>');
    
    const response = await fetch('api/send-media.php', {
        method: 'POST',
        body: formData
    });
    
    const text = await response.text();
    let result;
    try {
        result = JSON.parse(text);
    } catch (e) {
        throw new Error('Server error: ' + text.substring(0, 200));
    }
    
    if (result.success) {
        // Add message to chat
        const chatMessages = document.querySelector('.chat-messages');
        if (chatMessages) {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
            
            const messageHtml = `
                <div class="message outgoing" data-message-id="${result.message_id || 'temp_' + Date.now()}">
                    <div class="message-sender"><?php echo htmlspecialchars($current_user['name'] ?? 'You'); ?></div>
                    <div class="message-content">
                        <div class="message-media audio">
                            <audio controls src="${result.media_url || URL.createObjectURL(file)}">
                                Your browser does not support audio.
                            </audio>
                        </div>
                    </div>
                    <div class="message-footer">
                        <span class="message-time">${timeStr}</span>
                        <span class="message-status"><i class="fas fa-check"></i></span>
                    </div>
                </div>
            `;
            
            chatMessages.insertAdjacentHTML('beforeend', messageHtml);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    } else {
        throw new Error(result.error || 'Failed to send voice message');
    }
}

// ============================================
// IMAGE GALLERY
// ============================================

let galleryImages = [];
let currentGalleryIndex = 0;

// Collect all images from conversation
function collectGalleryImages() {
    galleryImages = [];
    const chatMessages = document.getElementById('chatMessages');
    if (!chatMessages) return;
    
    const images = chatMessages.querySelectorAll('.message-media img');
    images.forEach((img, index) => {
        const messageDiv = img.closest('.message');
        const captionEl = messageDiv?.querySelector('.message-text');
        const caption = captionEl ? captionEl.textContent.trim() : '';
        
        galleryImages.push({
            src: img.src,
            alt: img.alt || 'Image',
            caption: caption,
            index: index
        });
    });
}

// Open gallery with specific image
function openGallery(imageSrc, caption = '') {
    collectGalleryImages();
    
    // Find the index of the clicked image
    currentGalleryIndex = galleryImages.findIndex(img => img.src === imageSrc);
    if (currentGalleryIndex === -1) {
        // If not found, add it
        galleryImages = [{ src: imageSrc, alt: 'Image', caption: caption, index: 0 }];
        currentGalleryIndex = 0;
    }
    
    showGallery();
}

// Show gallery modal
function showGallery() {
    const modal = document.getElementById('imageGalleryModal');
    if (!modal || galleryImages.length === 0) return;
    
    modal.classList.add('active');
    updateGalleryImage();
    document.body.style.overflow = 'hidden'; // Prevent background scrolling
}

// Hide gallery modal
function closeGallery(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    const modal = document.getElementById('imageGalleryModal');
    if (modal) {
        modal.classList.remove('active');
    }
    document.body.style.overflow = ''; // Restore scrolling
    
    // Reset gallery state
    currentGalleryIndex = 0;
    galleryImages = [];
}

// Update gallery image display
function updateGalleryImage() {
    if (galleryImages.length === 0) return;
    
    const currentImage = galleryImages[currentGalleryIndex];
    const imgEl = document.getElementById('galleryImage');
    const captionEl = document.getElementById('galleryCaption');
    const counterEl = document.getElementById('galleryCounter');
    const prevBtn = document.getElementById('galleryPrev');
    const nextBtn = document.getElementById('galleryNext');
    const thumbnailsEl = document.getElementById('galleryThumbnails');
    
    if (imgEl) {
        imgEl.src = currentImage.src;
        imgEl.alt = currentImage.alt;
    }
    
    if (captionEl) {
        captionEl.textContent = currentImage.caption || '';
        captionEl.style.display = currentImage.caption ? 'block' : 'none';
    }
    
    if (counterEl && galleryImages.length > 1) {
        counterEl.textContent = `${currentGalleryIndex + 1} / ${galleryImages.length}`;
        counterEl.style.display = 'block';
    } else if (counterEl) {
        counterEl.style.display = 'none';
    }
    
    if (prevBtn) {
        prevBtn.disabled = currentGalleryIndex === 0;
        prevBtn.style.display = galleryImages.length > 1 ? 'flex' : 'none';
    }
    
    if (nextBtn) {
        nextBtn.disabled = currentGalleryIndex === galleryImages.length - 1;
        nextBtn.style.display = galleryImages.length > 1 ? 'flex' : 'none';
    }
    
    // Update thumbnails
    if (thumbnailsEl && galleryImages.length > 1) {
        thumbnailsEl.innerHTML = '';
        galleryImages.forEach((img, index) => {
            const thumb = document.createElement('img');
            thumb.src = img.src;
            thumb.className = 'gallery-thumbnail' + (index === currentGalleryIndex ? ' active' : '');
            thumb.onclick = () => {
                currentGalleryIndex = index;
                updateGalleryImage();
            };
            thumbnailsEl.appendChild(thumb);
        });
        thumbnailsEl.style.display = 'flex';
    } else if (thumbnailsEl) {
        thumbnailsEl.style.display = 'none';
    }
}

// Navigate gallery
function galleryPrev() {
    if (currentGalleryIndex > 0) {
        currentGalleryIndex--;
        updateGalleryImage();
    }
}

function galleryNext() {
    if (currentGalleryIndex < galleryImages.length - 1) {
        currentGalleryIndex++;
        updateGalleryImage();
    }
}

// Keyboard navigation
document.addEventListener('keydown', function(e) {
    const modal = document.getElementById('imageGalleryModal');
    if (!modal || !modal.classList.contains('active')) return;
    
    if (e.key === 'Escape') {
        closeGallery();
    } else if (e.key === 'ArrowLeft') {
        galleryPrev();
    } else if (e.key === 'ArrowRight') {
        galleryNext();
    }
});

// Close button event listener - multiple event types for reliability
document.addEventListener('DOMContentLoaded', function() {
    const closeBtn = document.getElementById('galleryCloseBtn');
    if (closeBtn) {
        const handleClose = function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeGallery(e);
            return false;
        };
        
        // Add multiple event listeners for maximum compatibility
        closeBtn.addEventListener('click', handleClose, true); // Use capture phase
        closeBtn.addEventListener('mousedown', handleClose, true);
        closeBtn.addEventListener('touchstart', handleClose, true);
    }
});

// Close on backdrop click (but not on content)
document.addEventListener('click', function(e) {
    const modal = document.getElementById('imageGalleryModal');
    const container = document.querySelector('.gallery-container');
    
    if (modal && modal.classList.contains('active')) {
        // Close if clicking directly on the modal backdrop (not on container or its children)
        if (e.target === modal) {
            e.preventDefault();
            e.stopPropagation();
            closeGallery(e);
        }
    }
});

// ============================================
// MESSAGE DETAIL MODAL
// ============================================

let currentMessageElement = null;
let currentMessageId = null;

function openMessageDetail(element) {
    currentMessageElement = element;
    currentMessageId = element.dataset.messageId;
    
    const modal = document.getElementById('messageDetailModal');
    const preview = document.getElementById('messageDetailPreview');
    const text = document.getElementById('messageDetailText');
    const media = document.getElementById('messageDetailMedia');
    const time = document.getElementById('messageDetailTime');
    const sender = document.getElementById('messageDetailSender');
    const senderRow = document.getElementById('messageDetailSenderRow');
    const status = document.getElementById('messageDetailStatus');
    const typeRow = document.getElementById('messageDetailTypeRow');
    const typeIcon = document.getElementById('messageDetailTypeIcon');
    const typeValue = document.getElementById('messageDetailType');
    
    // Set preview style
    preview.className = 'message-detail-preview';
    if (element.dataset.direction === 'outgoing') {
        preview.classList.add('outgoing');
    }
    
    // Set message content
    const body = element.dataset.body || '';
    const mediaUrl = element.dataset.mediaUrl || '';
    const mediaType = element.dataset.mediaType || 'text';
    
    // Handle media
    if (mediaUrl && mediaType !== 'text') {
        media.style.display = 'block';
        if (mediaType === 'image') {
            media.innerHTML = `<img src="${mediaUrl}" alt="Image">`;
            typeIcon.className = 'fas fa-image';
            typeValue.textContent = 'Image';
        } else if (mediaType === 'video') {
            media.innerHTML = `<video controls src="${mediaUrl}"></video>`;
            typeIcon.className = 'fas fa-video';
            typeValue.textContent = 'Video';
        } else if (mediaType === 'audio' || mediaType === 'voice') {
            media.innerHTML = `<audio controls src="${mediaUrl}" style="width:100%"></audio>`;
            typeIcon.className = 'fas fa-microphone';
            typeValue.textContent = 'Voice Message';
        } else if (mediaType === 'document') {
            media.innerHTML = `<a href="${mediaUrl}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-download me-1"></i>Download Document</a>`;
            typeIcon.className = 'fas fa-file-alt';
            typeValue.textContent = 'Document';
        }
        typeRow.style.display = 'flex';
    } else {
        media.style.display = 'none';
        media.innerHTML = '';
        typeRow.style.display = 'none';
    }
    
    // Set text
    text.textContent = body || (mediaType !== 'text' ? '' : 'No content');
    text.style.display = body ? 'block' : 'none';
    
    // Set time
    time.textContent = `${element.dataset.time} · ${element.dataset.date}`;
    
    // Set sender
    const senderName = element.dataset.sender;
    if (senderName && element.dataset.direction === 'outgoing') {
        sender.textContent = senderName;
        senderRow.style.display = 'flex';
    } else if (element.dataset.direction === 'incoming') {
        sender.textContent = 'Donor';
        senderRow.style.display = 'flex';
    } else {
        senderRow.style.display = 'none';
    }
    
    // Set status
    const msgStatus = element.dataset.status || 'sent';
    status.className = 'status-badge ' + msgStatus;
    
    const statusLabels = {
        'pending': '<i class="fas fa-clock"></i> Pending',
        'sent': '<i class="fas fa-check"></i> Sent',
        'delivered': '<i class="fas fa-check-double"></i> Delivered',
        'read': '<i class="fas fa-check-double"></i> Read',
        'failed': '<i class="fas fa-times"></i> Failed'
    };
    status.innerHTML = statusLabels[msgStatus] || statusLabels['sent'];
    
    // Show modal
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeMessageDetail() {
    const modal = document.getElementById('messageDetailModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
    currentMessageElement = null;
    currentMessageId = null;
}

function deleteCurrentMessage() {
    if (!currentMessageId) {
        alert('No message selected');
        return;
    }
    
    // Store references before closing modal
    const msgId = currentMessageId;
    let msgElement = null;
    
    if (currentMessageElement) {
        msgElement = currentMessageElement.closest('.message-wrapper');
        if (!msgElement) {
            msgElement = currentMessageElement;
        }
    }
    
    closeMessageDetail();
    
    // Open delete confirmation
    setTimeout(() => {
        openDeleteModal('message', msgId, msgElement);
    }, 200);
}

// ============================================
// DELETE FUNCTIONALITY
// ============================================

let deleteType = null; // 'message' or 'conversation'
let deleteId = null;
let deleteElement = null;

// Open delete modal
function openDeleteModal(type, id, element) {
    deleteType = type;
    deleteId = id;
    deleteElement = element;
    
    const modal = document.getElementById('deleteModal');
    const title = document.getElementById('deleteModalTitle');
    const text = document.getElementById('deleteModalText');
    const btnText = document.getElementById('deleteModalBtnText');
    const btnIcon = document.getElementById('deleteModalIcon');
    
    // Reset button state
    btnText.textContent = 'Delete';
    btnIcon.className = 'fas fa-trash-alt';
    document.getElementById('deleteModalConfirm').disabled = false;
    
    if (type === 'message') {
        title.textContent = 'Delete Message?';
        text.textContent = 'This message will be permanently deleted and cannot be recovered.';
    } else if (type === 'conversation') {
        title.textContent = 'Delete Conversation?';
        text.textContent = 'All messages, photos, and files in this conversation will be permanently deleted.';
    }
    
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

// Close delete modal
function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
    deleteType = null;
    deleteId = null;
    deleteElement = null;
}

// Confirm delete
async function confirmDelete() {
    if (!deleteType || !deleteId) return;
    
    const btn = document.getElementById('deleteModalConfirm');
    const btnText = document.getElementById('deleteModalBtnText');
    const btnIcon = document.getElementById('deleteModalIcon');
    
    btn.disabled = true;
    btnIcon.className = 'fas fa-spinner fa-spin';
    btnText.textContent = 'Deleting...';
    
    try {
        const formData = new FormData();
        formData.append('csrf_token', '<?php echo csrf_token(); ?>');
        
        let endpoint;
        if (deleteType === 'message') {
            endpoint = 'api/delete-message.php';
            formData.append('message_id', deleteId);
        } else {
            endpoint = 'api/delete-conversation.php';
            formData.append('conversation_id', deleteId);
        }
        
        const response = await fetch(endpoint, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Show success state briefly
            btnIcon.className = 'fas fa-check';
            btnText.textContent = 'Deleted!';
            btn.style.background = 'linear-gradient(135deg, #10b981 0%, #059669 100%)';
            
            if (deleteType === 'message') {
                if (deleteElement) {
                    // Remove message from DOM with smooth animation
                    setTimeout(() => {
                        deleteElement.style.transition = 'all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
                        deleteElement.style.opacity = '0';
                        deleteElement.style.transform = 'scale(0.8)';
                        deleteElement.style.maxHeight = '0';
                        deleteElement.style.marginBottom = '0';
                        deleteElement.style.padding = '0';
                        
                        setTimeout(() => {
                            if (deleteElement && deleteElement.parentNode) {
                                deleteElement.remove();
                            }
                        }, 400);
                        
                        closeDeleteModal();
                    }, 500);
                } else {
                    // No element reference, just reload the page
                    setTimeout(() => {
                        closeDeleteModal();
                        window.location.reload();
                    }, 500);
                }
            } else if (deleteType === 'conversation') {
                // Redirect to inbox after brief delay
                setTimeout(() => {
                    window.location.href = 'inbox.php';
                }, 500);
            }
        } else {
            // Show error state
            btnIcon.className = 'fas fa-exclamation-triangle';
            btnText.textContent = 'Failed';
            btn.style.background = 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)';
            
            setTimeout(() => {
                btn.disabled = false;
                btnIcon.className = 'fas fa-trash-alt';
                btnText.textContent = 'Try Again';
                btn.style.background = '';
            }, 2000);
        }
    } catch (err) {
        console.error('Delete error:', err);
        
        // Show error state
        btnIcon.className = 'fas fa-exclamation-triangle';
        btnText.textContent = 'Error';
        
        setTimeout(() => {
            btn.disabled = false;
            btnIcon.className = 'fas fa-trash-alt';
            btnText.textContent = 'Try Again';
        }, 2000);
    }
}

// Delete message by ID
function deleteMessage(messageId, element) {
    openDeleteModal('message', messageId, element.closest('.message-wrapper') || element.closest('.message'));
}

// Delete conversation
function deleteConversation(conversationId) {
    openDeleteModal('conversation', conversationId, null);
}

// ============================================
// CONVERSATION CONTEXT MENU (right-click / long-press)
// ============================================
let ctxConvId = null;
let ctxConvFilter = '<?php echo htmlspecialchars($filter); ?>';
let longPressTimer = null;

let ctxMenu = null;

function getCtxMenu() {
    if (!ctxMenu) {
        ctxMenu = document.getElementById('convCtxMenu');
    }
    return ctxMenu;
}

function showCtxMenu(x, y, convId) {
    const menu = getCtxMenu();
    if (!menu) return;
    ctxConvId = convId;
    menu.style.left = Math.min(x, window.innerWidth - 190) + 'px';
    menu.style.top = Math.min(y, window.innerHeight - 150) + 'px';
    menu.classList.add('show');
}

function hideCtxMenu() {
    const menu = getCtxMenu();
    if (menu) {
        menu.classList.remove('show');
    }
    ctxConvId = null;
}

function ctxOpenChat() {
    const selectedConvId = ctxConvId;
    hideCtxMenu();
    if (selectedConvId) window.location.href = '?id=' + selectedConvId + '&filter=' + ctxConvFilter;
}

function ctxMarkRead() {
    const selectedConvId = ctxConvId;
    hideCtxMenu();
    if (!selectedConvId) return;
    const fd = new FormData();
    fd.append('csrf_token', '<?php echo csrf_token(); ?>');
    fd.append('conversation_ids', JSON.stringify([selectedConvId]));
    fetch('api/bulk-mark-read.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) { const el = document.querySelector('.conversation-item[data-conversation-id="' + selectedConvId + '"]'); if (el) { el.classList.remove('unread'); const badge = el.querySelector('.conv-unread-badge'); if (badge) badge.remove(); } } });
}

function ctxDeleteConversation() {
    const selectedConvId = ctxConvId;
    hideCtxMenu();
    if (selectedConvId) deleteConversation(selectedConvId);
}

// Right-click on conversation items
document.addEventListener('contextmenu', function(e) {
    const item = e.target.closest('.conversation-item');
    if (item) {
        e.preventDefault();
        showCtxMenu(e.clientX, e.clientY, parseInt(item.dataset.conversationId));
    }
});

// Long-press on mobile
document.addEventListener('touchstart', function(e) {
    const item = e.target.closest('.conversation-item');
    if (!item) return;
    const convId = parseInt(item.dataset.conversationId);
    longPressTimer = setTimeout(function() {
        const touch = e.touches[0];
        showCtxMenu(touch.clientX, touch.clientY, convId);
        // prevent the link navigation
        item.dataset.longPressed = '1';
    }, 500);
}, { passive: true });

document.addEventListener('touchend', function(e) {
    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
    const item = e.target.closest('.conversation-item');
    if (item && item.dataset.longPressed === '1') {
        e.preventDefault();
        delete item.dataset.longPressed;
    }
});

document.addEventListener('touchmove', function() {
    if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
}, { passive: true });

// Close context menu on click anywhere else
document.addEventListener('click', function(e) {
    if (!e.target.closest('.conv-ctx-menu')) hideCtxMenu();
});

// Close context menu on scroll
document.addEventListener('scroll', hideCtxMenu, true);

// Keyboard shortcut to close modals
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeMessageDetail();
        closeDeleteModal();
        exitBulkMode();
    }
});

// ============================================
// BULK ACTIONS
// ============================================

let bulkModeActive = false;
let selectedConversations = new Set();

function toggleBulkMode() {
    bulkModeActive = !bulkModeActive;
    const sidebar = document.querySelector('.inbox-sidebar');
    const btn = document.getElementById('bulkModeBtn');
    
    if (bulkModeActive) {
        sidebar.classList.add('bulk-mode');
        btn.classList.add('active');
    } else {
        exitBulkMode();
    }
}

function exitBulkMode() {
    bulkModeActive = false;
    selectedConversations.clear();
    
    const sidebar = document.querySelector('.inbox-sidebar');
    const btn = document.getElementById('bulkModeBtn');
    const bar = document.getElementById('bulkActionBar');
    const selectAll = document.getElementById('selectAllCheckbox');
    
    if (sidebar) sidebar.classList.remove('bulk-mode');
    if (btn) btn.classList.remove('active');
    if (bar) bar.classList.remove('active');
    if (selectAll) selectAll.classList.remove('checked');
    
    // Uncheck all
    document.querySelectorAll('.conv-checkbox.checked').forEach(cb => {
        cb.classList.remove('checked');
    });
    
    updateBulkActionBar();
}

function handleConversationClick(event, element) {
    if (bulkModeActive) {
        event.preventDefault();
        const checkbox = element.querySelector('.conv-checkbox');
        if (checkbox) {
            toggleConversationSelect(checkbox);
        }
        return false;
    }
    
    // Normal click - navigate (mobile handling)
    if (window.innerWidth <= 768) {
        document.querySelector('.inbox-container')?.classList.add('has-selection');
    }
    return true;
}

function toggleConversationSelect(checkbox) {
    const id = checkbox.dataset.id;
    
    if (checkbox.classList.contains('checked')) {
        checkbox.classList.remove('checked');
        selectedConversations.delete(id);
    } else {
        checkbox.classList.add('checked');
        selectedConversations.add(id);
    }
    
    updateBulkActionBar();
    updateSelectAllState();
}

function toggleSelectAll() {
    const selectAllCb = document.getElementById('selectAllCheckbox');
    const allCheckboxes = document.querySelectorAll('.conversation-item .conv-checkbox');
    
    if (selectAllCb.classList.contains('checked')) {
        // Deselect all
        selectAllCb.classList.remove('checked');
        allCheckboxes.forEach(cb => {
            cb.classList.remove('checked');
            selectedConversations.delete(cb.dataset.id);
        });
    } else {
        // Select all
        selectAllCb.classList.add('checked');
        allCheckboxes.forEach(cb => {
            cb.classList.add('checked');
            selectedConversations.add(cb.dataset.id);
        });
    }
    
    updateBulkActionBar();
}

function updateSelectAllState() {
    const selectAllCb = document.getElementById('selectAllCheckbox');
    const allCheckboxes = document.querySelectorAll('.conversation-item .conv-checkbox');
    const checkedCount = document.querySelectorAll('.conversation-item .conv-checkbox.checked').length;
    
    if (checkedCount === allCheckboxes.length && allCheckboxes.length > 0) {
        selectAllCb.classList.add('checked');
    } else {
        selectAllCb.classList.remove('checked');
    }
}

function updateBulkActionBar() {
    const bar = document.getElementById('bulkActionBar');
    const count = document.getElementById('selectedCount');
    
    count.textContent = selectedConversations.size;
    
    if (selectedConversations.size > 0) {
        bar.classList.add('active');
    } else {
        bar.classList.remove('active');
    }
}

async function bulkMarkAsRead() {
    if (selectedConversations.size === 0) return;
    
    const btn = document.querySelector('.bulk-action-btn.read');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Marking...</span>';
    
    try {
        const formData = new FormData();
        formData.append('csrf_token', '<?php echo csrf_token(); ?>');
        formData.append('conversation_ids', JSON.stringify([...selectedConversations]));
        
        const response = await fetch('api/bulk-mark-read.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Update UI
            selectedConversations.forEach(id => {
                const item = document.querySelector(`.conversation-item[data-conversation-id="${id}"]`);
                if (item) {
                    item.classList.remove('unread');
                }
            });
            
            exitBulkMode();
        } else {
            alert('Failed: ' + (result.error || 'Unknown error'));
        }
    } catch (err) {
        console.error('Bulk mark read error:', err);
        alert('Failed to mark as read');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-double"></i> <span>Mark Read</span>';
    }
}

async function bulkDelete() {
    if (selectedConversations.size === 0) return;
    
    const count = selectedConversations.size;
    if (!confirm(`Delete ${count} conversation${count > 1 ? 's' : ''}? This cannot be undone.`)) {
        return;
    }
    
    const btn = document.querySelector('.bulk-action-btn.delete');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Deleting...</span>';
    
    try {
        const formData = new FormData();
        formData.append('csrf_token', '<?php echo csrf_token(); ?>');
        formData.append('conversation_ids', JSON.stringify([...selectedConversations]));
        
        const response = await fetch('api/bulk-delete.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Remove from DOM
            selectedConversations.forEach(id => {
                const item = document.querySelector(`.conversation-item[data-conversation-id="${id}"]`);
                if (item) {
                    item.style.transition = 'all 0.3s';
                    item.style.opacity = '0';
                    item.style.transform = 'translateX(-100%)';
                    setTimeout(() => item.remove(), 300);
                }
            });
            
            exitBulkMode();
            
            // Reload if current conversation was deleted
            const currentId = new URLSearchParams(window.location.search).get('id');
            if (currentId && selectedConversations.has(currentId)) {
                setTimeout(() => {
                    window.location.href = 'inbox.php';
                }, 400);
            }
        } else {
            alert('Failed: ' + (result.error || 'Unknown error'));
        }
    } catch (err) {
        console.error('Bulk delete error:', err);
        alert('Failed to delete');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash-alt"></i> <span>Delete</span>';
    }
}
</script>

<!-- Image Gallery Modal -->
<div class="image-gallery-modal" id="imageGalleryModal">
    <div class="gallery-container">
        <button class="gallery-close" id="galleryCloseBtn" aria-label="Close gallery" type="button">
            <i class="fas fa-times"></i>
        </button>
        
        <div class="gallery-image-wrapper">
            <img id="galleryImage" class="gallery-image" src="" alt="">
            
            <button class="gallery-nav prev" id="galleryPrev" onclick="galleryPrev()" aria-label="Previous image">
                <i class="fas fa-chevron-left"></i>
            </button>
            <button class="gallery-nav next" id="galleryNext" onclick="galleryNext()" aria-label="Next image">
                <i class="fas fa-chevron-right"></i>
            </button>
            
            <div class="gallery-counter" id="galleryCounter" style="display: none;"></div>
        </div>
        
        <div class="gallery-caption" id="galleryCaption" style="display: none;"></div>
        
        <div class="gallery-thumbnails" id="galleryThumbnails" style="display: none;"></div>
    </div>
</div>

<!-- Bulk Action Bar -->
<div class="bulk-action-bar" id="bulkActionBar">
    <div class="bulk-action-content">
        <div class="bulk-action-header">
            <span class="bulk-action-count"><span id="selectedCount">0</span> selected</span>
            <button type="button" class="bulk-action-close" onclick="exitBulkMode()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="bulk-action-buttons">
            <button type="button" class="bulk-action-btn read" onclick="bulkMarkAsRead()">
                <i class="fas fa-check-double"></i>
                <span>Mark Read</span>
            </button>
            <button type="button" class="bulk-action-btn delete" onclick="bulkDelete()">
                <i class="fas fa-trash-alt"></i>
                <span>Delete</span>
            </button>
        </div>
    </div>
</div>

<!-- Message Detail Modal -->
<div class="message-detail-modal" id="messageDetailModal" onclick="if(event.target === this) closeMessageDetail()">
    <div class="message-detail-content">
        <div class="message-detail-header">
            <h5>Message Details</h5>
            <button type="button" class="message-detail-close" onclick="closeMessageDetail()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="message-detail-body">
            <!-- Message Preview -->
            <div class="message-detail-preview" id="messageDetailPreview">
                <div class="message-detail-media" id="messageDetailMedia" style="display: none;"></div>
                <div class="message-detail-text" id="messageDetailText"></div>
            </div>
            
            <!-- Message Info -->
            <div class="message-detail-info">
                <div class="message-detail-row">
                    <div class="message-detail-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="message-detail-row-content">
                        <div class="message-detail-label">Sent</div>
                        <div class="message-detail-value" id="messageDetailTime"></div>
                    </div>
                </div>
                
                <div class="message-detail-row" id="messageDetailSenderRow" style="display: none;">
                    <div class="message-detail-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="message-detail-row-content">
                        <div class="message-detail-label">Sent by</div>
                        <div class="message-detail-value" id="messageDetailSender"></div>
                    </div>
                </div>
                
                <div class="message-detail-row">
                    <div class="message-detail-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="message-detail-row-content">
                        <div class="message-detail-label">Status</div>
                        <div class="message-detail-value">
                            <span class="status-badge" id="messageDetailStatus"></span>
                        </div>
                    </div>
                </div>
                
                <div class="message-detail-row" id="messageDetailTypeRow">
                    <div class="message-detail-icon">
                        <i class="fas fa-file" id="messageDetailTypeIcon"></i>
                    </div>
                    <div class="message-detail-row-content">
                        <div class="message-detail-label">Type</div>
                        <div class="message-detail-value" id="messageDetailType"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="message-detail-footer">
            <button type="button" class="message-detail-delete" id="messageDetailDelete" onclick="deleteCurrentMessage()">
                <i class="fas fa-trash-alt"></i>
                <span>Delete Message</span>
            </button>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<!-- Conversation Context Menu -->
<div class="conv-ctx-menu" id="convCtxMenu">
    <button class="conv-ctx-item" onclick="ctxOpenChat()"><i class="fas fa-comment"></i> Open Chat</button>
    <button class="conv-ctx-item" onclick="ctxMarkRead()"><i class="fas fa-check-double"></i> Mark as Read</button>
    <button class="conv-ctx-item danger" onclick="ctxDeleteConversation()"><i class="fas fa-trash-alt"></i> Delete Chat</button>
</div>

<div class="delete-modal" id="deleteModal" onclick="if(event.target === this) closeDeleteModal()">
    <div class="delete-modal-content">
        <div class="delete-modal-icon">
            <i class="fas fa-trash-alt"></i>
        </div>
        <div class="delete-modal-title" id="deleteModalTitle">Delete Message?</div>
        <div class="delete-modal-text" id="deleteModalText">This action cannot be undone.</div>
        <div class="delete-modal-actions">
            <button type="button" class="delete-modal-btn delete" id="deleteModalConfirm" onclick="confirmDelete()">
                <i class="fas fa-trash-alt" id="deleteModalIcon"></i>
                <span id="deleteModalBtnText">Delete</span>
            </button>
            <button type="button" class="delete-modal-btn cancel" onclick="closeDeleteModal()">Cancel</button>
        </div>
    </div>
</div>

</body>
</html>
