<?php
declare(strict_types=1);
error_reporting(0); // Suppress warnings in production
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

try {
    $db = db();
    $settings = $db->query('SELECT projector_names_mode, currency_code FROM settings WHERE id=1')->fetch_assoc();
    if (!$settings) {
        $settings = ['projector_names_mode' => 'full', 'currency_code' => 'GBP'];
    }
    $mode = $settings['projector_names_mode'] ?? 'full';
    $currency = $settings['currency_code'] ?? 'GBP';
    
    // Check if pledge_payments exists
    $has_pp = $db->query("SHOW TABLES LIKE 'pledge_payments'")->num_rows > 0;
    
    // Use the exact same SQL structure as admin/approved page
    $sql = "
    (SELECT 
        p.amount,
        'pledge' AS type,
        p.anonymous,
        p.donor_name,
        p.approved_at
      FROM pledges p
      WHERE p.status = 'approved')
    UNION ALL
    (SELECT 
        pay.amount,
        'paid' AS type,
        0 AS anonymous,
        pay.donor_name,
        pay.received_at AS approved_at
      FROM payments pay
      WHERE pay.status = 'approved')
    ";
    
    if ($has_pp) {
        $sql .= "
        UNION ALL
        (SELECT 
            pp.amount,
            'paid' AS type,
            0 AS anonymous,
            COALESCE(d.name, 'Unknown') as donor_name,
            pp.created_at AS approved_at
          FROM pledge_payments pp
          LEFT JOIN donors d ON pp.donor_id = d.id
          WHERE pp.status = 'confirmed')
        ";
    }
    
    $sql .= " ORDER BY approved_at DESC LIMIT 20";

    $res = $db->query($sql);
    if (!$res) {
        throw new Exception($db->error);
    }
    
    $items = [];
    while ($row = $res->fetch_assoc()) {
        // Always show as "Kind Donor" for complete anonymity and simplicity
        $name = 'Kind Donor';
        
        $verb = $row['type'] === 'paid' ? 'paid' : 'pledged';
        $text = $name . ' ' . $verb . ' ' . $currency . ' ' . number_format((float)$row['amount'], 0);
        
        $items[] = [
            'text' => $text,
            'approved_at' => $row['approved_at'],
            'is_anonymous' => true, // Flag to indicate this is anonymized
        ];
    }
    
    echo json_encode(['items' => $items]);
} catch (Exception $e) {
    error_log('Error in recent.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['items' => []]);
}
