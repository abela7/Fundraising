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
    
    // Generate secure anonymous names that can't be reverse-engineered
    function generateSecureAnonymousName($originalName, $timestamp) {
        if (empty($originalName) || $originalName === 'Anonymous' || $originalName === 'Supporter') {
            return 'Anonymous Supporter';
        }
        
        // Create a hash-based anonymous identifier that's consistent but secure
        $hash = hash('sha256', $originalName . $timestamp . 'projector_salt_2024');
        $shortHash = substr($hash, 0, 6);
        
        // Generate a friendly anonymous name based on the hash
        $prefixes = ['Kind', 'Generous', 'Blessed', 'Caring', 'Noble', 'Faithful', 'Devoted', 'Loving'];
        $suffixes = ['Supporter', 'Friend', 'Donor', 'Helper', 'Giver', 'Benefactor', 'Patron', 'Contributor'];
        
        $prefixIndex = hexdec(substr($shortHash, 0, 1)) % count($prefixes);
        $suffixIndex = hexdec(substr($shortHash, 1, 1)) % count($suffixes);
        
        return $prefixes[$prefixIndex] . ' ' . $suffixes[$suffixIndex];
    }
    
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $originalName = $row['donor_name'] ?? 'Supporter';
        
        // Always anonymize for public projector - no real names ever sent to frontend
        if ($row['anonymous']) {
            $name = 'Anonymous Supporter';
        } else {
            // Generate secure anonymous name based on original name and timestamp
            $name = generateSecureAnonymousName($originalName, $row['approved_at']);
        }
        
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
