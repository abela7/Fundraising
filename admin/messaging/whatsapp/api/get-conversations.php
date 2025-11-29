<?php
/**
 * API Endpoint: Get Conversations Update
 * 
 * Fetches updated conversation list with unread counts for real-time sidebar updates
 */

declare(strict_types=1);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../../shared/auth.php';
    require_once __DIR__ . '/../../../../config/db.php';
    require_login();
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = db();
$current_user = current_user();
$is_admin = ($current_user['role'] ?? '') === 'admin';

// Get parameters
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');

try {
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
    
    // Get stats
    $stats_query = $db->query("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(CASE WHEN unread_count > 0 THEN 1 ELSE 0 END), 0) as unread,
            COALESCE(SUM(CASE WHEN is_unknown = 1 THEN 1 ELSE 0 END), 0) as unknown
        FROM whatsapp_conversations
        WHERE status = 'active'
    ");
    
    $stats = ['total' => 0, 'unread' => 0, 'unknown' => 0];
    if ($stats_query && $row = $stats_query->fetch_assoc()) {
        $stats = [
            'total' => (int)($row['total'] ?? 0),
            'unread' => (int)($row['unread'] ?? 0),
            'unknown' => (int)($row['unknown'] ?? 0)
        ];
    }
    
    // Get conversations
    $query = "
        SELECT 
            wc.id,
            wc.phone_number,
            wc.contact_name,
            wc.donor_id,
            wc.is_unknown,
            wc.unread_count,
            wc.last_message_at,
            wc.last_message_preview,
            wc.last_message_direction,
            d.name as donor_name,
            d.balance as donor_balance
        FROM whatsapp_conversations wc
        LEFT JOIN donors d ON wc.donor_id = d.id
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
    
    $conversations = [];
    while ($row = $result->fetch_assoc()) {
        $name = $row['donor_name'] ?: $row['contact_name'] ?: 'Unknown';
        $conversations[] = [
            'id' => (int)$row['id'],
            'name' => $name,
            'initials' => strtoupper(substr($name, 0, 1)),
            'phone' => $row['phone_number'],
            'donor_id' => $row['donor_id'] ? (int)$row['donor_id'] : null,
            'is_unknown' => (int)$row['is_unknown'] === 1,
            'unread_count' => (int)$row['unread_count'],
            'last_message_at' => $row['last_message_at'] ? date('M j, g:i A', strtotime($row['last_message_at'])) : '',
            'last_message_preview' => $row['last_message_preview'] ?: 'No messages',
            'last_message_direction' => $row['last_message_direction'],
            'donor_balance' => $row['donor_balance'] ? number_format((float)$row['donor_balance'], 2) : '0.00'
        ];
    }
    
    echo json_encode([
        'success' => true,
        'conversations' => $conversations,
        'stats' => $stats
    ]);
    
} catch (Throwable $e) {
    error_log("Get conversations error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}

