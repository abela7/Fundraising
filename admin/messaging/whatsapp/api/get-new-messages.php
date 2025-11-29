<?php
/**
 * API Endpoint: Get New Messages
 * 
 * Fetches new messages since a given timestamp for real-time updates
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

// Get parameters
$conversation_id = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;
$last_message_id = isset($_GET['last_message_id']) ? (int)$_GET['last_message_id'] : 0;
$last_check = isset($_GET['last_check']) ? $_GET['last_check'] : null;

if (!$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'Missing conversation_id']);
    exit;
}

try {
    // Get new messages since last_message_id
    $query = "
        SELECT 
            wm.id,
            wm.conversation_id,
            wm.ultramsg_id,
            wm.direction,
            wm.message_type,
            wm.body,
            wm.media_url,
            wm.media_mime_type,
            wm.media_filename,
            wm.media_caption,
            wm.media_local_path,
            wm.latitude,
            wm.longitude,
            wm.location_name,
            wm.status,
            wm.created_at,
            u.name as sender_name
        FROM whatsapp_messages wm
        LEFT JOIN users u ON wm.sender_id = u.id
        WHERE wm.conversation_id = ?
        AND wm.id > ?
        ORDER BY wm.created_at ASC
        LIMIT 50
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param('ii', $conversation_id, $last_message_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    $max_id = $last_message_id;
    
    while ($row = $result->fetch_assoc()) {
        $max_id = max($max_id, (int)$row['id']);
        
        $messages[] = [
            'id' => (int)$row['id'],
            'direction' => $row['direction'],
            'type' => $row['message_type'],
            'body' => $row['body'],
            'media_url' => $row['media_url'],
            'media_filename' => $row['media_filename'],
            'media_caption' => $row['media_caption'],
            'latitude' => $row['latitude'],
            'longitude' => $row['longitude'],
            'location_name' => $row['location_name'],
            'status' => $row['status'],
            'sender_name' => $row['sender_name'],
            'time' => date('g:i A', strtotime($row['created_at'])),
            'date' => date('F j, Y', strtotime($row['created_at'])),
            'timestamp' => strtotime($row['created_at'])
        ];
    }
    
    // Mark conversation as read if we fetched incoming messages
    if (!empty($messages)) {
        $db->query("UPDATE whatsapp_conversations SET unread_count = 0 WHERE id = " . (int)$conversation_id);
    }
    
    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'last_message_id' => $max_id,
        'count' => count($messages)
    ]);
    
} catch (Throwable $e) {
    error_log("Get new messages error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}

