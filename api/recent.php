<?php
declare(strict_types=1);
error_reporting(0); // Suppress warnings in production
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

try {
    $settings = db()->query('SELECT projector_names_mode, currency_code FROM settings WHERE id=1')->fetch_assoc();
    if (!$settings) {
        $settings = ['projector_names_mode' => 'full', 'currency_code' => 'GBP'];
    }
    $mode = $settings['projector_names_mode'] ?? 'full';
    $currency = $settings['currency_code'] ?? 'GBP';
    
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
    ORDER BY approved_at DESC 
    LIMIT 20
    ";

    $res = db()->query($sql);
    if (!$res) {
        throw new Exception(db()->error);
    }
    
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $name = $row['anonymous'] ? 'Anonymous' : ($row['donor_name'] ?? 'Supporter');
        if (!$row['anonymous'] && $mode === 'first_initial' && $name !== 'Anonymous' && $name !== 'Supporter') {
            $parts = preg_split('/\s+/', trim((string)$name));
            if ($parts && count($parts) > 0) {
                $first = $parts[0];
                $last = count($parts) > 1 ? end($parts) : '';
                $name = $first . ($last ? ' ' . strtoupper(substr($last, 0, 1)) . '.' : '');
            }
        }
        if ($mode === 'off') { 
            $name = 'Supporter'; 
        }
        
        $verb = $row['type'] === 'paid' ? 'paid' : 'pledged';
        $text = $name . ' ' . $verb . ' ' . $currency . ' ' . number_format((float)$row['amount'], 0);
        
        $items[] = [
            'text' => $text,
            'approved_at' => $row['approved_at'],
        ];
    }
    
    echo json_encode(['items' => $items]);
} catch (Exception $e) {
    error_log('Error in recent.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['items' => []]);
}
