<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

header('Content-Type: application/json');

$church_id = (int)($_GET['church_id'] ?? 0);

if ($church_id <= 0) {
    echo json_encode(['error' => 'Invalid church ID', 'representatives' => []]);
    exit;
}

try {
    $db = db();
    $stmt = $db->prepare("
        SELECT id, name, role, is_primary 
        FROM church_representatives 
        WHERE church_id = ? AND is_active = 1 
        ORDER BY is_primary DESC, name ASC
    ");
    $stmt->bind_param("i", $church_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $representatives = [];
    while ($row = $result->fetch_assoc()) {
        $representatives[] = $row;
    }
    
    echo json_encode(['representatives' => $representatives]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'representatives' => []]);
}
?>

