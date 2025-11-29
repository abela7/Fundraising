<?php
/**
 * API: Get WhatsApp Message Templates
 * 
 * Returns all active SMS templates that can be used for WhatsApp messages
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

try {
    // Check if table exists
    $check = $db->query("SHOW TABLES LIKE 'sms_templates'");
    if (!$check || $check->num_rows === 0) {
        echo json_encode([
            'success' => true,
            'templates' => [],
            'grouped' => []
        ]);
        exit;
    }
    
    // Get all active templates
    $result = $db->query("
        SELECT id, template_key, name, category, message_en, message_am, message_ti, description
        FROM sms_templates 
        WHERE is_active = 1 
        ORDER BY category, name
    ");
    
    $templates = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $templates[] = [
                'id' => (int)$row['id'],
                'key' => $row['template_key'],
                'name' => $row['name'],
                'category' => $row['category'] ?: 'General',
                'content' => $row['message_en'], // Use English message
                'description' => $row['description'] ?: ''
            ];
        }
    }
    
    // Group by category
    $grouped = [];
    foreach ($templates as $template) {
        $category = $template['category'];
        if (!isset($grouped[$category])) {
            $grouped[$category] = [];
        }
        $grouped[$category][] = $template;
    }
    
    echo json_encode([
        'success' => true,
        'templates' => $templates,
        'grouped' => $grouped
    ]);
    
} catch (Throwable $e) {
    error_log("Get templates error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load templates'
    ]);
}

