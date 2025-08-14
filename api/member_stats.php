<?php
declare(strict_types=1);
header('Content-Type: application/json');

// Suppress PHP warnings/notices that could break JSON output
error_reporting(0);
ini_set('display_errors', '0');

try {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../shared/auth.php';
    
    // Require admin authentication
    require_admin();
    
    $db = db();
    $user_id = (int)($_GET['user_id'] ?? 0);
    
    if ($user_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
        exit;
    }
    
    // Get user info
    $userQuery = "SELECT id, name, phone, email, role, active, created_at FROM users WHERE id = ?";
    $stmt = $db->prepare($userQuery);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    // Get statistics for pledges created by the user (any type)
    $pledgeStatsAllQuery = "
        SELECT 
            COUNT(*) as total_all,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_all,
            COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_all,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_all,
            SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END) as approved_amount_all,
            MAX(created_at) as last_any_date
        FROM pledges 
        WHERE created_by_user_id = ?
    ";
    $stmt = $db->prepare($pledgeStatsAllQuery);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $pledgeStatsAll = $stmt->get_result()->fetch_assoc() ?: [];

    // Get pledge-only statistics (type='pledge')
    $pledgeStatsQuery = "
        SELECT 
            COUNT(*) as total_pledges,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_pledges,
            COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_pledges,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_pledges,
            SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END) as approved_amount,
            MAX(created_at) as last_pledge_date
        FROM pledges 
        WHERE created_by_user_id = ? AND type = 'pledge'
    ";
    $stmt = $db->prepare($pledgeStatsQuery);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $pledgeStats = $stmt->get_result()->fetch_assoc() ?: [];

    // Get paid-now statistics from pledges table (type='paid')
    $paidFromPledgesQuery = "
        SELECT 
            COUNT(*) as total_paid_pledges,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_paid_pledges,
            COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_paid_pledges,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_paid_pledges,
            SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END) as approved_paid_pledges_amount,
            MAX(created_at) as last_paid_pledges_date
        FROM pledges 
        WHERE created_by_user_id = ? AND type = 'paid'
    ";
    $stmt = $db->prepare($paidFromPledgesQuery);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $paidFromPledges = $stmt->get_result()->fetch_assoc() ?: [];

    // Get payment statistics from payments table (received_by_user_id)
    $paymentStatsQuery = "
        SELECT 
            COUNT(*) as total_payments,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_payments,
            COUNT(CASE WHEN status = 'voided' THEN 1 END) as rejected_payments,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_payments,
            SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END) as approved_payment_amount,
            MAX(created_at) as last_payment_date
        FROM payments 
        WHERE received_by_user_id = ?
    ";
    $stmt = $db->prepare($paymentStatsQuery);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $paymentStats = $stmt->get_result()->fetch_assoc() ?: [];

    // Combine paid-now stats from both sources
    $combinedPaymentStats = [
        'total' => (int)($paidFromPledges['total_paid_pledges'] ?? 0) + (int)($paymentStats['total_payments'] ?? 0),
        'approved' => (int)($paidFromPledges['approved_paid_pledges'] ?? 0) + (int)($paymentStats['approved_payments'] ?? 0),
        'rejected' => (int)($paidFromPledges['rejected_paid_pledges'] ?? 0) + (int)($paymentStats['rejected_payments'] ?? 0),
        'pending' => (int)($paidFromPledges['pending_paid_pledges'] ?? 0) + (int)($paymentStats['pending_payments'] ?? 0),
        'approved_amount' => (float)($paidFromPledges['approved_paid_pledges_amount'] ?? 0) + (float)($paymentStats['approved_payment_amount'] ?? 0)
    ];
    
    // Get recent activity from both sources
    $recentActivityQuery = "
        (SELECT type, amount, status, created_at, donor_name 
         FROM pledges 
         WHERE created_by_user_id = ? 
         ORDER BY created_at DESC LIMIT 8)
        UNION ALL
        (SELECT 'payment' as type, amount, status, created_at, donor_name 
         FROM payments 
         WHERE received_by_user_id = ? 
         ORDER BY created_at DESC LIMIT 8)
        ORDER BY created_at DESC LIMIT 10
    ";
    $stmt = $db->prepare($recentActivityQuery);
    $stmt->bind_param('ii', $user_id, $user_id);
    $stmt->execute();
    $recentActivity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Calculate totals including both pledges and payments
    $totalRegistrations = (int)($pledgeStatsAll['total_all'] ?? 0) + (int)($paymentStats['total_payments'] ?? 0);
    $totalApproved = (int)($pledgeStatsAll['approved_all'] ?? 0) + (int)($paymentStats['approved_payments'] ?? 0);
    $totalRejected = (int)($pledgeStatsAll['rejected_all'] ?? 0) + (int)($paymentStats['rejected_payments'] ?? 0);
    $totalPending = (int)($pledgeStatsAll['pending_all'] ?? 0) + (int)($paymentStats['pending_payments'] ?? 0);
    $totalApprovedAmount = (float)($pledgeStatsAll['approved_amount_all'] ?? 0.0) + (float)($paymentStats['approved_payment_amount'] ?? 0);
    
    // Find last registration date from both sources
    $pledgeLastDate = $pledgeStatsAll['last_any_date'] ?? null;
    $paymentLastDate = $paymentStats['last_payment_date'] ?? null;
    
    $lastRegistrationDate = null;
    if ($pledgeLastDate && $paymentLastDate) {
        $lastRegistrationDate = max($pledgeLastDate, $paymentLastDate);
    } elseif ($pledgeLastDate) {
        $lastRegistrationDate = $pledgeLastDate;
    } elseif ($paymentLastDate) {
        $lastRegistrationDate = $paymentLastDate;
    }
    
    // Calculate performance metrics
    $approvalRate = $totalRegistrations > 0 ? round(($totalApproved / $totalRegistrations) * 100, 1) : 0;
    $rejectionRate = $totalRegistrations > 0 ? round(($totalRejected / $totalRegistrations) * 100, 1) : 0;
    
    // Days since last registration
    $daysSinceLastRegistration = null;
    if ($lastRegistrationDate) {
        $daysSinceLastRegistration = floor((time() - strtotime($lastRegistrationDate)) / (60 * 60 * 24));
    }
    
    echo json_encode([
        'success' => true,
        'user' => $user,
        'statistics' => [
            'total_registrations' => $totalRegistrations,
            'total_approved' => $totalApproved,
            'total_rejected' => $totalRejected,
            'total_pending' => $totalPending,
            'total_approved_amount' => $totalApprovedAmount,
            'approval_rate' => $approvalRate,
            'rejection_rate' => $rejectionRate,
            'last_registration_date' => $lastRegistrationDate,
            'days_since_last_registration' => $daysSinceLastRegistration
        ],
        'pledge_stats' => [
            'total' => (int)($pledgeStats['total_pledges'] ?? 0),
            'approved' => (int)($pledgeStats['approved_pledges'] ?? 0),
            'rejected' => (int)($pledgeStats['rejected_pledges'] ?? 0),
            'pending' => (int)($pledgeStats['pending_pledges'] ?? 0),
            'approved_amount' => (float)($pledgeStats['approved_amount'] ?? 0)
        ],
        'payment_stats' => $combinedPaymentStats,
        'recent_activity' => $recentActivity
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
}
?>
