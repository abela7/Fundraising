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

    // Calculate totals directly from source tables for real-time accuracy
    // This matches the comprehensive report calculations exactly
    $paidRow = $db->query("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = 'approved'")->fetch_assoc();
    $paidTotal = (float)($paidRow['total'] ?? 0);
    
    $pledgedRow = $db->query("SELECT COALESCE(SUM(amount), 0) AS total FROM pledges WHERE status = 'approved'")->fetch_assoc();
    $pledgedTotal = (float)($pledgedRow['total'] ?? 0);
    
    $grand = $paidTotal + $pledgedTotal;
    $target = max(1.0, (float)$settings['target_amount']);
    $progress = min(100.0, round(($grand / $target) * 100, 2));

    echo json_encode([
        'paid_total' => $paidTotal,
        'pledged_total' => $pledgedTotal,
        'grand_total' => $grand,
        'progress_pct' => $progress,
        'projector_display_mode' => $settings['projector_display_mode'] ?? 'amount',
        'last_updated' => date('Y-m-d H:i:s'),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
