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

if ($selected_id && $tables_exist) {
    try {
        // Get conversation details
        $stmt = $db->prepare("
            SELECT wc.*, d.name as donor_name, d.phone as donor_phone, d.balance as donor_balance, d.id as donor_id
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
        }
    } catch (Throwable $e) {
        $error_message = "Error loading conversation: " . $e->getMessage();
        $debug_info[] = $error_message;
        error_log("WhatsApp Conversation Error: " . $e->getMessage());
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
            transition: background 0.15s;
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
        
        .chat-header-info {
            flex: 1;
        }
        
        .chat-header-name {
            font-weight: 400;
            font-size: 1rem;
            color: var(--wa-text);
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
            padding: 0.375rem 0.5rem 0.25rem 0.625rem;
            border-radius: 7.5px;
            position: relative;
            margin-bottom: 0.125rem;
            box-shadow: 0 1px 0.5px rgba(11, 20, 26, 0.13);
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
        
        .message-text {
            font-size: 0.875rem;
            line-height: 1.35;
            word-wrap: break-word;
            margin-right: 3rem;
        }
        
        .message-meta {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.25rem;
            margin-top: -0.875rem;
            font-size: 0.6875rem;
            color: var(--wa-text-secondary);
            float: right;
            position: relative;
            padding-left: 0.5rem;
        }
        
        .message.incoming .message-meta {
            color: var(--wa-text-secondary);
        }
        
        .message-status {
            color: #53bdeb;
            font-size: 1rem;
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
            margin-bottom: 0.25rem;
        }
        
        .message-media img {
            width: 100%;
            display: block;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        
        .message-media img:hover {
            opacity: 0.9;
        }
        
        .message-media.document {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: rgba(0,0,0,0.05);
            border-radius: 8px;
        }
        
        .message-media.document a {
            color: var(--wa-teal);
        }
        
        .message-media audio {
            width: 280px;
            height: 36px;
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
            padding: 0.625rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }
        
        .chat-input-field {
            flex: 1;
            padding: 0.5rem 1rem;
            border: 1px solid var(--wa-border);
            border-radius: 8px;
            font-size: 0.9375rem;
            outline: none;
            background: var(--wa-input-bg);
            color: var(--wa-text);
            min-height: 42px;
        }
        
        .chat-input-field:focus {
            border-color: var(--wa-teal);
        }
        
        .chat-input-field::placeholder {
            color: var(--wa-text-secondary);
        }
        
        .chat-input-btn {
            width: 42px;
            height: 42px;
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
        }
        
        .chat-input-btn:hover {
            transform: scale(1.05);
        }
        
        .chat-input-btn:disabled {
            background: #dfe5e7;
            cursor: not-allowed;
            color: var(--wa-text-secondary);
        }
        
        .chat-input-btn.attachment {
            background: transparent;
            color: var(--wa-text-secondary);
            transform: none;
        }
        
        .chat-input-btn.attachment:hover {
            color: var(--wa-teal);
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
            padding: 2rem;
            background: var(--wa-chat-bg);
        }
        
        .empty-state i {
            font-size: 5rem;
            color: var(--wa-teal);
            opacity: 0.3;
            margin-bottom: 1.5rem;
        }
        
        .empty-state h3 {
            font-size: 1.5rem;
            color: var(--wa-text);
            margin-bottom: 0.75rem;
            font-weight: 300;
        }
        
        .empty-state p {
            font-size: 0.9375rem;
            max-width: 400px;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .chat-messages {
                padding: 1rem 1.5rem;
            }
        }
        
        @media (max-width: 768px) {
            .inbox-sidebar {
                width: 100%;
            }
            
            .chat-area {
                display: none;
            }
            
            .inbox-container.has-selection .inbox-sidebar {
                display: none;
            }
            
            .inbox-container.has-selection .chat-area {
                display: flex;
            }
            
            .chat-messages {
                padding: 1rem;
            }
            
            .message {
                max-width: 85%;
            }
        }
        
        /* Setup Warning */
        .setup-warning {
            background: linear-gradient(135deg, #1a2e35, #0d1f26);
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
            background: var(--wa-input-bg);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            color: var(--wa-teal);
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
        
        /* Sender name for group feel */
        .message-sender {
            font-size: 0.8125rem;
            color: var(--wa-teal);
            font-weight: 500;
            margin-bottom: 0.125rem;
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
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php try { include '../../includes/sidebar.php'; } catch (Throwable $e) {} ?>
    
    <div class="admin-content">
        <?php try { include '../../includes/topbar.php'; } catch (Throwable $e) {} ?>
        
        <main class="main-content p-3">
            <!-- Debug Info (remove in production) -->
            <?php if (!empty($debug_info)): ?>
            <div style="background:#1e3a5f;border:1px solid #3b82f6;border-radius:8px;padding:1rem;margin-bottom:1rem;color:#93c5fd;font-size:0.8125rem;">
                <strong><i class="fas fa-bug me-1"></i> Debug Info:</strong>
                <ul class="mb-0 mt-2" style="padding-left:1.25rem;">
                    <?php foreach ($debug_info as $info): ?>
                    <li><?php echo htmlspecialchars($info); ?></li>
                    <?php endforeach; ?>
                </ul>
                <div class="mt-2">
                    <a href="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" class="btn btn-sm btn-primary me-2">
                        <i class="fas fa-sync-alt me-1"></i>Refresh
                    </a>
                    <a href="../../../webhooks/test.php" class="btn btn-sm btn-outline-light" target="_blank">
                        <i class="fas fa-vial me-1"></i>Test Webhook
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
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
                        <a href="../../donor-management/sms/whatsapp-settings.php" class="btn btn-sm btn-light">
                            <i class="fas fa-cog"></i>
                        </a>
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
                    </div>
                    
                    <div class="conversation-list">
                        <?php if (empty($conversations)): ?>
                        <div class="empty-state py-5">
                            <i class="fab fa-whatsapp"></i>
                            <h3>No Conversations Yet</h3>
                            <p>When donors message you on WhatsApp, they'll appear here.</p>
                            <a href="new-chat.php" class="btn btn-sm mt-3" style="background:var(--wa-teal);color:white;">
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
                               class="conversation-item <?php echo $isActive ? 'active' : ''; ?> <?php echo $isUnread ? 'unread' : ''; ?>">
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
                        <a href="?filter=<?php echo $filter; ?>" class="btn btn-sm btn-light me-2 d-md-none">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                        <div class="chat-header-avatar"><?php echo $chatInitials; ?></div>
                        <div class="chat-header-info">
                            <div class="chat-header-name"><?php echo htmlspecialchars($chatName); ?></div>
                            <div class="chat-header-status"><?php echo $chatStatus; ?></div>
                        </div>
                        <?php if ($selected_conversation['donor_id']): ?>
                        <a href="../../donor-management/view-donor.php?id=<?php echo $selected_conversation['donor_id']; ?>" 
                           class="btn btn-sm btn-outline-primary" target="_blank">
                            <i class="fas fa-user me-1"></i>View Donor
                        </a>
                        <?php endif; ?>
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
                        
                        <div class="message <?php echo $msg['direction'] === 'incoming' ? 'incoming' : 'outgoing'; ?>">
                            <?php if ($msg['message_type'] === 'image' && $msg['media_url']): ?>
                            <div class="message-media">
                                <img src="<?php echo htmlspecialchars($msg['media_url']); ?>" alt="Image" 
                                     onclick="window.open(this.src, '_blank')">
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
                                <?php if ($msg['direction'] === 'outgoing' && $msg['sender_name']): ?>
                                <span class="me-1"><?php echo htmlspecialchars($msg['sender_name']); ?> •</span>
                                <?php endif; ?>
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
                        <?php endforeach; ?>
                    </div>
                    
                    <form class="chat-input" id="sendForm" method="POST" action="api/send-message.php">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="conversation_id" value="<?php echo $selected_id; ?>">
                        <input type="hidden" name="phone" value="<?php echo htmlspecialchars($selected_conversation['phone_number']); ?>">
                        <button type="button" class="chat-input-btn attachment" onclick="alert('📎 Media upload coming soon!')">
                            <i class="fas fa-paperclip"></i>
                        </button>
                        <input type="text" name="message" class="chat-input-field" placeholder="Type a message" 
                               required autocomplete="off" id="messageInput">
                        <button type="submit" class="chat-input-btn" id="sendBtn">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                    
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

if (sendForm) {
    sendForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
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
                // Add message to UI
                const msgDiv = document.createElement('div');
                msgDiv.className = 'message outgoing';
                msgDiv.innerHTML = `
                    <div class="message-text">${escapeHtml(message)}</div>
                    <div class="message-meta">
                        <span>${new Date().toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}</span>
                        <span class="message-status"><i class="fas fa-check" style="color: #667781;"></i></span>
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

// Auto-refresh for new messages (every 10 seconds)
<?php if ($selected_id): ?>
setInterval(() => {
    // Could implement AJAX refresh here
}, 10000);
<?php endif; ?>
</script>
</body>
</html>

