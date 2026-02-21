<?php
/**
 * API: Bulk Mark Conversations as Read
 */

declare(strict_types=1);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../../shared/auth.php';
    require_once __DIR__ . '/../../../../shared/csrf.php';
    require_once __DIR__ . '/../../../../config/db.php';
    require_login();
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Auth failed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    verify_csrf();
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$db = db();
$current_user = current_user();
$is_admin = ($current_user['role'] ?? '') === 'admin';

// Get conversation IDs
$idsJson = $_POST['conversation_ids'] ?? '[]';
$ids = json_decode($idsJson, true);

if (!is_array($ids) || empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No conversations selected']);
    exit;
}

try {
    // Sanitize IDs
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, fn($id) => $id > 0);
    
    if (empty($ids)) {
        throw new Exception('No valid conversation IDs');
    }
    
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    // Restrict non-admin to their own conversations only
    if (!$is_admin) {
        $userId = (int)($current_user['id'] ?? 0);
        $accessSql = "
            SELECT wc.id
            FROM whatsapp_conversations wc
            LEFT JOIN donors d ON wc.donor_id = d.id
            WHERE wc.id IN ($placeholders)
              AND (wc.assigned_agent_id = ? OR d.agent_id = ?)
        ";
        $accessTypes = $types . 'ii';
        $accessParams = array_merge($ids, [$userId, $userId]);
        $accessStmt = $db->prepare($accessSql);
        $accessStmt->bind_param($accessTypes, ...$accessParams);
        $accessStmt->execute();
        $accessResult = $accessStmt->get_result();

        $allowedIds = [];
        while ($row = $accessResult->fetch_assoc()) {
            $allowedIds[] = (int)$row['id'];
        }
        $accessStmt->close();

        if (empty($allowedIds)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit;
        }

        $ids = $allowedIds;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
    }
    
    // Mark as read
    $stmt = $db->prepare("UPDATE whatsapp_conversations SET unread_count = 0 WHERE id IN ($placeholders)");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $updated = $stmt->affected_rows;
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'message' => "Marked $updated conversation(s) as read",
        'updated' => $updated
    ]);
    
} catch (Throwable $e) {
    error_log("Bulk mark read error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

