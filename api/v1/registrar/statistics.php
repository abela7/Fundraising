<?php
declare(strict_types=1);

/**
 * API v1 - Registrar Statistics Endpoint
 * 
 * GET /api/v1/registrar/statistics - Get registrar's performance stats
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

// Require registrar or admin authentication
$auth = new ApiAuth();
$authData = $auth->requireRole(['registrar', 'admin']);

$db = db();
$userId = $authData['user_id'];

// Get pledge statistics
$pledgeStats = $db->prepare(
    "SELECT 
        COUNT(*) as total_pledges,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_pledges,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_pledges,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_pledges,
        COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) as total_amount
     FROM pledges 
     WHERE registered_by = ?"
);
$pledgeStats->bind_param('i', $userId);
$pledgeStats->execute();
$stats = $pledgeStats->get_result()->fetch_assoc();
$pledgeStats->close();

// Get payment statistics
$paymentStats = $db->prepare(
    "SELECT 
        COUNT(*) as total_payments,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_payments,
        COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) as total_collected
     FROM payments 
     WHERE registered_by = ?"
);
$paymentStats->bind_param('i', $userId);
$paymentStats->execute();
$payStats = $paymentStats->get_result()->fetch_assoc();
$paymentStats->close();

// Get unique donors count
$donorCount = $db->prepare(
    "SELECT COUNT(DISTINCT donor_phone) as unique_donors
     FROM pledges 
     WHERE registered_by = ?"
);
$donorCount->bind_param('i', $userId);
$donorCount->execute();
$donors = $donorCount->get_result()->fetch_assoc();
$donorCount->close();

// Get this month's stats
$monthStats = $db->prepare(
    "SELECT 
        COUNT(*) as pledges_this_month,
        COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) as amount_this_month
     FROM pledges 
     WHERE registered_by = ? AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())"
);
$monthStats->bind_param('i', $userId);
$monthStats->execute();
$monthly = $monthStats->get_result()->fetch_assoc();
$monthStats->close();

ApiResponse::success([
    'pledges' => [
        'total' => (int) $stats['total_pledges'],
        'approved' => (int) $stats['approved_pledges'],
        'pending' => (int) $stats['pending_pledges'],
        'rejected' => (int) $stats['rejected_pledges'],
        'total_amount' => (float) $stats['total_amount'],
    ],
    'payments' => [
        'total' => (int) $payStats['total_payments'],
        'approved' => (int) $payStats['approved_payments'],
        'total_collected' => (float) $payStats['total_collected'],
    ],
    'donors' => [
        'unique_count' => (int) $donors['unique_donors'],
    ],
    'this_month' => [
        'pledges' => (int) $monthly['pledges_this_month'],
        'amount' => (float) $monthly['amount_this_month'],
    ],
    'currency' => 'GBP',
]);

