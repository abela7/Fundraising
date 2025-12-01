<?php
/**
 * API: Get WhatsApp Message Templates
 * 
 * Returns active templates for WhatsApp (platform = 'whatsapp' or 'both')
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
    
    // Check if platform column exists
    $hasplatform = false;
    $columns = $db->query("SHOW COLUMNS FROM sms_templates LIKE 'platform'");
    if ($columns && $columns->num_rows > 0) {
        $hasplatform = true;
    }
    
    // Get WhatsApp templates (platform = 'whatsapp' or 'both')
    if ($hasplatform) {
        $result = $db->query("
            SELECT id, template_key, name, category, message_en, message_am, message_ti, description, platform
            FROM sms_templates 
            WHERE is_active = 1 AND platform IN ('whatsapp', 'both')
            ORDER BY category, name
        ");
    } else {
        // Fallback if platform column doesn't exist
        $result = $db->query("
            SELECT id, template_key, name, category, message_en, message_am, message_ti, description
            FROM sms_templates 
            WHERE is_active = 1 
            ORDER BY category, name
        ");
    }
    
    $templates = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $templates[] = [
                'id' => (int)$row['id'],
                'key' => $row['template_key'],
                'name' => $row['name'],
                'category' => $row['category'] ?: 'General',
                'content' => $row['message_en'],
                'description' => $row['description'] ?: '',
                'platform' => $row['platform'] ?? 'sms'
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
        'grouped' => $grouped,
        'count' => count($templates)
    ]);
    
} catch (Throwable $e) {
    error_log("Get templates error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load templates'
    ]);
}

