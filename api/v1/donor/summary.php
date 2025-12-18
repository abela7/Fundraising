<?php
declare(strict_types=1);

/**
 * API v1 - Donor Summary Endpoint
 * 
 * GET /api/v1/donor/summary - Get donor dashboard summary
 */

require_once __DIR__ . '/../ApiResponse.php';
require_once __DIR__ . '/../ApiAuth.php';
require_once __DIR__ . '/../../../config/db.php';

// Handle CORS preflight
ApiResponse::handlePreflight();

// Only accept GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

// Require donor authentication
$auth = new ApiAuth();
$authData = $auth->requireRole('donor');

$db = db();
$donorId = $authData['user_id'];

// Get donor data
$donorStmt = $db->prepare(
    "SELECT id, name, phone, total_pledged, total_paid, balance,
            has_active_plan, active_payment_plan_id, payment_status
     FROM donors WHERE id = ? LIMIT 1"
);
$donorStmt->bind_param('i', $donorId);
$donorStmt->execute();
$donor = $donorStmt->get_result()->fetch_assoc();
$donorStmt->close();

if (!$donor) {
    ApiResponse::error('Donor not found', 404, 'DONOR_NOT_FOUND');
}

$phone = $donor['phone'];

// Get recent payments (last 5)
$paymentsStmt = $db->prepare(
    "SELECT id, amount, payment_date, status, payment_method
     FROM payments 
     WHERE donor_phone = ?
     ORDER BY payment_date DESC, created_at DESC
     LIMIT 5"
);
$paymentsStmt->bind_param('s', $phone);
$paymentsStmt->execute();
$paymentsResult = $paymentsStmt->get_result();

$recentPayments = [];
while ($row = $paymentsResult->fetch_assoc()) {
    $recentPayments[] = [
        'id' => (int) $row['id'],
        'amount' => (float) $row['amount'],
        'payment_date' => $row['payment_date'],
        'status' => $row['status'],
        'payment_method' => $row['payment_method'],
    ];
}
$paymentsStmt->close();

// Get payment plan info if active
$paymentPlan = null;
$nextPaymentDue = null;

if ($donor['active_payment_plan_id']) {
    $planStmt = $db->prepare(
        "SELECT id, frequency, installment_amount, next_payment_date, 
                total_installments, completed_installments, status
         FROM payment_plans WHERE id = ? LIMIT 1"
    );
    $planStmt->bind_param('i', $donor['active_payment_plan_id']);
    $planStmt->execute();
    $plan = $planStmt->get_result()->fetch_assoc();
    $planStmt->close();

    if ($plan) {
        $paymentPlan = [
            'frequency' => $plan['frequency'],
            'installment_amount' => (float) $plan['installment_amount'],
            'next_payment_date' => $plan['next_payment_date'],
            'progress' => [
                'completed' => (int) $plan['completed_installments'],
                'total' => (int) $plan['total_installments'],
                'percentage' => $plan['total_installments'] > 0 
                    ? round(($plan['completed_installments'] / $plan['total_installments']) * 100)
                    : 0,
            ],
        ];
        $nextPaymentDue = $plan['next_payment_date'];
    }
}

// Calculate payment progress
$pledged = (float) $donor['total_pledged'];
$paid = (float) $donor['total_paid'];
$progressPercentage = $pledged > 0 ? round(($paid / $pledged) * 100) : 0;

ApiResponse::success([
    'donor' => [
        'id' => (int) $donor['id'],
        'name' => $donor['name'],
        'phone' => $donor['phone'],
    ],
    'financials' => [
        'total_pledged' => $pledged,
        'total_paid' => $paid,
        'balance' => (float) $donor['balance'],
        'progress_percentage' => min(100, $progressPercentage),
        'currency' => 'GBP',
    ],
    'payment_status' => $donor['payment_status'],
    'has_active_plan' => (bool) $donor['has_active_plan'],
    'payment_plan' => $paymentPlan,
    'next_payment_due' => $nextPaymentDue,
    'recent_payments' => $recentPayments,
]);

