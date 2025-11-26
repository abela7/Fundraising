<?php
// admin/reports/registrar-dashboard-data.php
// API endpoint for real-time dashboard data updates
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

require_login();
$current_user = current_user();
if (!in_array($current_user['role'] ?? '', ['admin', 'registrar'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = db();
$response = [];

try {
    // 1. Overview Statistics
    $stats = [];
    
    // Total Pledges & Amount
    $result = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM pledges WHERE status = 'approved'");
    $pledgeData = $result->fetch_assoc();
    $stats['total_pledges'] = $pledgeData['count'];
    $stats['total_pledged'] = $pledgeData['total'];
    
    // Total Collected
    $result = $db->query("SELECT COALESCE(SUM(amount), 0) as total FROM pledge_payments WHERE status = 'confirmed'");
    $stats['total_collected'] = $result->fetch_assoc()['total'];
    
    // Collection Rate
    $stats['collection_rate'] = $stats['total_pledged'] > 0 
        ? round(($stats['total_collected'] / $stats['total_pledged']) * 100, 1) 
        : 0;
    
    // Pending Payments
    $result = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM pledge_payments WHERE status = 'pending'");
    $pendingData = $result->fetch_assoc();
    $stats['pending_count'] = $pendingData['count'];
    $stats['pending_amount'] = $pendingData['total'];
    
    // Active Donors
    $result = $db->query("SELECT COUNT(DISTINCT donor_id) as count FROM pledges WHERE status = 'approved'");
    $stats['active_donors'] = $result->fetch_assoc()['count'];
    
    // Active Payment Plans
    $result = $db->query("SELECT COUNT(*) as count FROM donor_payment_plans WHERE status = 'active'");
    $stats['active_plans'] = $result->fetch_assoc()['count'];
    
    // Today's Stats
    $result = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM pledge_payments WHERE status = 'confirmed' AND DATE(approved_at) = CURDATE()");
    $todayData = $result->fetch_assoc();
    $stats['today_payments'] = $todayData['count'];
    $stats['today_amount'] = $todayData['total'];
    
    $response['stats'] = $stats;
    
    // 2. Recent Payments (Last 20)
    $recentPayments = [];
    $result = $db->query("
        SELECT pp.id, pp.amount, pp.payment_method, pp.status, pp.created_at, pp.approved_at,
               d.id as donor_id, d.name as donor_name, d.phone as donor_phone
        FROM pledge_payments pp
        LEFT JOIN donors d ON pp.donor_id = d.id
        ORDER BY pp.created_at DESC
        LIMIT 20
    ");
    while ($row = $result->fetch_assoc()) {
        $recentPayments[] = $row;
    }
    $response['recentPayments'] = $recentPayments;
    
    // 3. Recent Pledges (Last 20)
    $recentPledges = [];
    $result = $db->query("
        SELECT p.id, p.amount, p.status, p.created_at,
               d.id as donor_id, d.name as donor_name, d.phone as donor_phone
        FROM pledges p
        LEFT JOIN donors d ON p.donor_id = d.id
        ORDER BY p.created_at DESC
        LIMIT 20
    ");
    while ($row = $result->fetch_assoc()) {
        $recentPledges[] = $row;
    }
    $response['recentPledges'] = $recentPledges;
    
    // 4. Last update timestamps for change detection
    $result = $db->query("SELECT MAX(created_at) as last_payment FROM pledge_payments");
    $response['lastPaymentTime'] = $result->fetch_assoc()['last_payment'] ?? '';
    
    $result = $db->query("SELECT MAX(created_at) as last_pledge FROM pledges");
    $response['lastPledgeTime'] = $result->fetch_assoc()['last_pledge'] ?? '';
    
    $response['success'] = true;
    $response['timestamp'] = date('Y-m-d H:i:s');
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = 'Database error';
}

echo json_encode($response);

