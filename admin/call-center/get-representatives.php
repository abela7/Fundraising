<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

header('Content-Type: application/json');

try {
    $db = db();
    $church_id = isset($_GET['church_id']) ? (int)$_GET['church_id'] : 0;
    
    if (!$church_id) {
        throw new Exception('Church ID is required');
    }
    
    // Get representatives for this church
    $query = "
        SELECT id, name, phone, role, is_primary 
        FROM church_representatives 
        WHERE church_id = ? AND is_active = 1 
        ORDER BY is_primary DESC, name ASC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $church_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $representatives = [];
    while ($row = $result->fetch_assoc()) {
        $representatives[] = $row;
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'representatives' => $representatives
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

