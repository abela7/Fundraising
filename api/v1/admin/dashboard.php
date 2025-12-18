<?php
declare(strict_types=1);

/**
 * API v1 - Admin Dashboard Endpoint
 * 
 * GET /api/v1/admin/dashboard - Get admin dashboard summary
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

// Require admin authentication
$auth = new ApiAuth();
$authData = $auth->requireRole('admin');

$db = db();

// Get overall statistics
$overallStats = $db->query(
    "SELECT 
        (SELECT COUNT(*) FROM donors) as total_donors,
        (SELECT COUNT(*) FROM pledges WHERE status = 'approved') as approved_pledges,
        (SELECT COUNT(*) FROM pledges WHERE status = 'pending') as pending_pledges,
        (SELECT COUNT(*) FROM payments WHERE status = 'approved') as approved_payments,
        (SELECT COUNT(*) FROM payments WHERE status = 'pending') as pending_payments,
        (SELECT COALESCE(SUM(amount), 0) FROM pledges WHERE status = 'approved') as total_pledged,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'approved') as total_collected"
)->fetch_assoc();

// Get today's activity
$todayStats = $db->query(
    "SELECT 
        (SELECT COUNT(*) FROM pledges WHERE DATE(created_at) = CURDATE()) as pledges_today,
        (SELECT COUNT(*) FROM payments WHERE DATE(created_at) = CURDATE()) as payments_today,
        (SELECT COALESCE(SUM(amount), 0) FROM pledges WHERE DATE(created_at) = CURDATE() AND status = 'approved') as pledged_today,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(created_at) = CURDATE() AND status = 'approved') as collected_today"
)->fetch_assoc();

// Get this month's stats
$monthStats = $db->query(
    "SELECT 
        (SELECT COALESCE(SUM(amount), 0) FROM pledges WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()) AND status = 'approved') as pledged_this_month,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()) AND status = 'approved') as collected_this_month"
)->fetch_assoc();

// Get pending approvals count
$pendingApprovals = $db->query(
    "SELECT 
        (SELECT COUNT(*) FROM pledges WHERE status = 'pending') as pending_pledges,
        (SELECT COUNT(*) FROM payments WHERE status = 'pending') as pending_payments"
)->fetch_assoc();

// Get recent registrations (last 10)
$recentPledges = $db->query(
    "SELECT p.id, p.donor_name, p.amount, p.status, p.created_at, u.name as registered_by
     FROM pledges p
     LEFT JOIN users u ON p.registered_by = u.id
     ORDER BY p.created_at DESC
     LIMIT 10"
);

$recent = [];
while ($row = $recentPledges->fetch_assoc()) {
    $recent[] = [
        'id' => (int) $row['id'],
        'donor_name' => $row['donor_name'],
        'amount' => (float) $row['amount'],
        'status' => $row['status'],
        'registered_by' => $row['registered_by'],
        'created_at' => $row['created_at'],
    ];
}

// Get PWA installation stats
$pwaStats = ['total' => 0, 'by_type' => []];
$pwaTableExists = $db->query("SHOW TABLES LIKE 'pwa_installations'")->num_rows > 0;

if ($pwaTableExists) {
    $pwaTotal = $db->query("SELECT COUNT(*) as total FROM pwa_installations WHERE is_active = 1");
    $pwaStats['total'] = (int) $pwaTotal->fetch_assoc()['total'];
    
    $pwaByType = $db->query(
        "SELECT user_type, device_type, COUNT(*) as count 
         FROM pwa_installations 
         WHERE is_active = 1 
         GROUP BY user_type, device_type"
    );
    while ($row = $pwaByType->fetch_assoc()) {
        $key = $row['user_type'] . '_' . ($row['device_type'] ?? 'unknown');
        $pwaStats['by_type'][$key] = (int) $row['count'];
    }
}

ApiResponse::success([
    'overview' => [
        'total_donors' => (int) $overallStats['total_donors'],
        'total_pledged' => (float) $overallStats['total_pledged'],
        'total_collected' => (float) $overallStats['total_collected'],
        'collection_rate' => $overallStats['total_pledged'] > 0 
            ? round(($overallStats['total_collected'] / $overallStats['total_pledged']) * 100, 1)
            : 0,
    ],
    'pending_approvals' => [
        'pledges' => (int) $pendingApprovals['pending_pledges'],
        'payments' => (int) $pendingApprovals['pending_payments'],
        'total' => (int) $pendingApprovals['pending_pledges'] + (int) $pendingApprovals['pending_payments'],
    ],
    'today' => [
        'pledges' => (int) $todayStats['pledges_today'],
        'payments' => (int) $todayStats['payments_today'],
        'pledged' => (float) $todayStats['pledged_today'],
        'collected' => (float) $todayStats['collected_today'],
    ],
    'this_month' => [
        'pledged' => (float) $monthStats['pledged_this_month'],
        'collected' => (float) $monthStats['collected_this_month'],
    ],
    'pwa_installations' => $pwaStats,
    'recent_registrations' => $recent,
    'currency' => 'GBP',
]);

