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
            width: 44px;
            height: 44px;
            min-width: 44px;
            min-height: 44px;
            max-width: 44px;
            max-height: 44px;
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
            aspect-ratio: 1;
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
        
        .chat-input-btn.attachment {
            background: transparent;
            color: var(--wa-text-secondary);
            transform: none;
        }
        
        .chat-input-btn.attachment:hover {
            color: var(--wa-teal);
            background: transparent;
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
            
            .chat-input {
                padding: 0.5rem 0.75rem;
                gap: 0.5rem;
                overflow: visible;
            }
            
            .chat-input-field {
                padding: 0.4rem 0.75rem;
                font-size: 0.875rem;
                min-height: 38px;
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
            
            .chat-input-btn.attachment {
                width: 40px;
                height: 40px;
                min-width: 40px;
                min-height: 40px;
                max-width: 40px;
                max-height: 40px;
            }
            
            /* Back button for mobile */
            .mobile-back-btn {
                display: flex !important;
            }
            
            /* Empty state mobile */
            .empty-state i {
                font-size: 3.5rem;
            }
            
            .empty-state h3 {
                font-size: 1.25rem;
            }
            
            .empty-state p {
                font-size: 0.875rem;
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
                padding: 0.5rem 0.625rem;
                gap: 0.375rem;
            }
            
            .chat-input-field {
                padding: 0.375rem 0.625rem;
                font-size: 0.8125rem;
                min-height: 36px;
            }
            
            .chat-input-btn {
                width: 38px;
                height: 38px;
                min-width: 38px;
                min-height: 38px;
                max-width: 38px;
                max-height: 38px;
                font-size: 0.9375rem;
            }
            
            .chat-input-btn.attachment {
                width: 38px;
                height: 38px;
                min-width: 38px;
                min-height: 38px;
                max-width: 38px;
                max-height: 38px;
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
                               class="conversation-item <?php echo $isActive ? 'active' : ''; ?> <?php echo $isUnread ? 'unread' : ''; ?>"
                               onclick="if(window.innerWidth <= 768) document.querySelector('.inbox-container').classList.add('has-selection')">
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
                            <div class="message-content">
                                <?php if ($msg['direction'] === 'outgoing' && $msg['sender_name']): ?>
                                <div class="message-sender"><?php echo htmlspecialchars($msg['sender_name']); ?></div>
                                <?php endif; ?>
                                
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
        content += `<div class="message-media"><img src="${escapeHtml(msg.media_url)}" alt="Image" onclick="window.open(this.src, '_blank')"></div>`;
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

// Play notification sound
function playNotificationSound() {
    try {
        // Create a simple beep using Web Audio API
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        
        oscillator.frequency.value = 800;
        oscillator.type = 'sine';
        gainNode.gain.value = 0.1;
        
        oscillator.start(audioContext.currentTime);
        oscillator.stop(audioContext.currentTime + 0.15);
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
</script>
</body>
</html>

