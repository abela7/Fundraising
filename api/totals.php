<?php
declare(strict_types=1);
error_reporting(0); // Suppress warnings in production
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

try {
    $db = db();
    
    $settings = $db->query('SELECT target_amount, projector_display_mode FROM settings WHERE id=1')->fetch_assoc();
    if (!$settings) {
        $settings = ['target_amount' => 100000, 'projector_display_mode' => 'amount'];
    }

    // 1. Instant Payments (Direct donations)
    $instRow = $db->query("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = 'approved'")->fetch_assoc();
    $instantTotal = (float)($instRow['total'] ?? 0);
    
    // 2. Pledge Payments (Installments) - Check if table exists
    $pledgePaidTotal = 0;
    $check = $db->query("SHOW TABLES LIKE 'pledge_payments'");
    if ($check->num_rows > 0) {
        $ppRow = $db->query("SELECT COALESCE(SUM(amount), 0) AS total FROM pledge_payments WHERE status = 'confirmed'")->fetch_assoc();
        $pledgePaidTotal = (float)($ppRow['total'] ?? 0);
    }
    
    // 3. Pledges (Promises)
    $pledgedRow = $db->query("SELECT COALESCE(SUM(amount), 0) AS total FROM pledges WHERE status = 'approved'")->fetch_assoc();
    $pledgedTotal = (float)($pledgedRow['total'] ?? 0);
    
    // Logic for Projector Display:
    // Total Paid = All cash received (Instant + Pledge Installments)
    // Total Pledged = Outstanding Pledges (Total Promises - Paid Installments)
    // Grand Total = Paid + Outstanding (Total Value)
    
    $paidTotalDisplay = $instantTotal + $pledgePaidTotal;
    $pledgedTotalDisplay = max(0, $pledgedTotal - $pledgePaidTotal);
    $grandTotalDisplay = $paidTotalDisplay + $pledgedTotalDisplay;
    
    $target = max(1.0, (float)$settings['target_amount']);
    $progress = min(100.0, round(($grandTotalDisplay / $target) * 100, 2));

    echo json_encode([
        'paid_total' => $paidTotalDisplay,
        'pledged_total' => $pledgedTotalDisplay,
        'grand_total' => $grandTotalDisplay,
        'progress_pct' => $progress,
        'projector_display_mode' => $settings['projector_display_mode'] ?? 'amount',
        'last_updated' => date('Y-m-d H:i:s'),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
