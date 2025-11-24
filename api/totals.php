<?php
declare(strict_types=1);
error_reporting(0); // Suppress warnings in production
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../shared/FinancialCalculator.php';

header('Content-Type: application/json');

try {
    $db = db();
    
    $settings = $db->query('SELECT target_amount, projector_display_mode FROM settings WHERE id=1')->fetch_assoc();
    if (!$settings) {
        $settings = ['target_amount' => 100000, 'projector_display_mode' => 'amount'];
    }

    // Use centralized calculator
    $calculator = new FinancialCalculator();
    $totals = $calculator->getTotals();
    
    $target = max(1.0, (float)$settings['target_amount']);
    $progress = min(100.0, round(($totals['grand_total'] / $target) * 100, 2));

    echo json_encode([
        'paid_total' => $totals['total_paid'],
        'pledged_total' => $totals['outstanding_pledged'], // Display outstanding as "Pledged"
        'grand_total' => $totals['grand_total'],
        'progress_pct' => $progress,
        'projector_display_mode' => $settings['projector_display_mode'] ?? 'amount',
        'last_updated' => date('Y-m-d H:i:s'),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
