<?php
// admin/donations/get-donor-history.php
// Fetches donor's complete payment and pledge history
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();

header('Content-Type: application/json');

$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;

if (!$donor_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid donor ID']);
    exit;
}

try {
    $db = db();
    
    // Get donor basic info with totals
    $donor_stmt = $db->prepare("
        SELECT id, name, phone, email, total_pledged, total_paid, balance, payment_status, created_at
        FROM donors WHERE id = ?
    ");
    $donor_stmt->bind_param('i', $donor_id);
    $donor_stmt->execute();
    $donor = $donor_stmt->get_result()->fetch_assoc();
    
    if (!$donor) {
        echo json_encode(['success' => false, 'error' => 'Donor not found']);
        exit;
    }
    
    // Get pledges with their payment progress
    $pledges_stmt = $db->prepare("
        SELECT 
            p.id,
            p.amount,
            p.status,
            p.notes,
            p.type,
            p.created_at,
            COALESCE((SELECT SUM(pp.amount) FROM pledge_payments pp WHERE pp.pledge_id = p.id AND pp.status = 'confirmed'), 0) as paid_amount
        FROM pledges p
        WHERE p.donor_id = ?
        ORDER BY p.created_at DESC
        LIMIT 10
    ");
    $pledges_stmt->bind_param('i', $donor_id);
    $pledges_stmt->execute();
    $pledges_result = $pledges_stmt->get_result();
    $pledges = [];
    while ($row = $pledges_result->fetch_assoc()) {
        $row['remaining'] = max(0, (float)$row['amount'] - (float)$row['paid_amount']);
        $row['progress_percent'] = $row['amount'] > 0 ? round(($row['paid_amount'] / $row['amount']) * 100) : 0;
        $pledges[] = $row;
    }
    
    // Get pledge payments (installments)
    $pledge_payments_stmt = $db->prepare("
        SELECT 
            pp.id,
            pp.amount,
            pp.payment_method,
            pp.payment_date,
            pp.status,
            pp.reference_number,
            pp.notes,
            pp.created_at,
            pl.notes as pledge_reference,
            'pledge_payment' as payment_type
        FROM pledge_payments pp
        LEFT JOIN pledges pl ON pp.pledge_id = pl.id
        WHERE pp.donor_id = ?
        ORDER BY pp.created_at DESC
        LIMIT 20
    ");
    $pledge_payments_stmt->bind_param('i', $donor_id);
    $pledge_payments_stmt->execute();
    $pledge_payments_result = $pledge_payments_stmt->get_result();
    $pledge_payments = [];
    while ($row = $pledge_payments_result->fetch_assoc()) {
        $pledge_payments[] = $row;
    }
    
    // Get immediate payments (from payments table)
    $payments_stmt = $db->prepare("
        SELECT 
            p.id,
            p.amount,
            p.method as payment_method,
            COALESCE(p.received_at, p.created_at) as payment_date,
            p.status,
            p.reference,
            'immediate_payment' as payment_type
        FROM payments p
        WHERE p.donor_id = ?
        ORDER BY p.created_at DESC
        LIMIT 20
    ");
    $payments_stmt->bind_param('i', $donor_id);
    $payments_stmt->execute();
    $payments_result = $payments_stmt->get_result();
    $immediate_payments = [];
    while ($row = $payments_result->fetch_assoc()) {
        $immediate_payments[] = $row;
    }
    
    // Calculate summary stats
    $stats = [
        'total_pledged' => (float)$donor['total_pledged'],
        'total_paid' => (float)$donor['total_paid'],
        'balance' => (float)$donor['balance'],
        'payment_status' => $donor['payment_status'],
        'pledge_count' => count($pledges),
        'payment_count' => count($pledge_payments) + count($immediate_payments),
        'pending_payments' => 0,
        'confirmed_payments' => 0,
        'voided_payments' => 0
    ];
    
    // Count payment statuses
    foreach ($pledge_payments as $pp) {
        if ($pp['status'] === 'pending') $stats['pending_payments']++;
        elseif ($pp['status'] === 'confirmed') $stats['confirmed_payments']++;
        elseif ($pp['status'] === 'voided') $stats['voided_payments']++;
    }
    foreach ($immediate_payments as $ip) {
        if ($ip['status'] === 'pending') $stats['pending_payments']++;
        elseif ($ip['status'] === 'approved') $stats['confirmed_payments']++;
        elseif (in_array($ip['status'], ['voided', 'rejected'])) $stats['voided_payments']++;
    }
    
    echo json_encode([
        'success' => true,
        'donor' => $donor,
        'pledges' => $pledges,
        'pledge_payments' => $pledge_payments,
        'immediate_payments' => $immediate_payments,
        'stats' => $stats
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

