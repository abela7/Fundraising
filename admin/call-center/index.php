<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$page_title = 'Call Center Dashboard';
$user_name = $_SESSION['user']['name'] ?? 'Agent';
$user_id = (int)$_SESSION['user']['id'];

// Initialize stats
$stats = [
    'today_calls' => 0,
    'today_positive' => 0,
    'today_talk_time' => 0,
    'today_callbacks' => 0,
    'donors_with_balance' => 0,
    'total_outstanding' => 0,
    'active_plans' => 0,
    'pending_callbacks' => 0
];
$conversion_rate = 0;
$recent_calls = [];
$error_message = null;

try {
    $db = db();
    
    // Check if tables exist
    $tables_check = $db->query("SHOW TABLES LIKE 'call_center_sessions'");
    $tables_exist = ($tables_check && $tables_check->num_rows > 0);

    if ($tables_exist) {
        // Get today's call stats
        $today_start = date('Y-m-d 00:00:00');
        $today_end = date('Y-m-d 23:59:59');

        $call_stats = $db->prepare("
            SELECT 
                COUNT(*) as total_calls,
                SUM(CASE WHEN conversation_stage NOT IN ('no_connection', 'disconnected') THEN 1 ELSE 0 END) as positive_outcomes,
                SUM(CASE WHEN callback_scheduled_for IS NOT NULL THEN 1 ELSE 0 END) as callbacks_scheduled,
                SUM(COALESCE(duration_seconds, 0)) as total_talk_time
            FROM call_center_sessions
            WHERE agent_id = ? AND created_at BETWEEN ? AND ?
        ");
        $call_stats->bind_param('iss', $user_id, $today_start, $today_end);
        $call_stats->execute();
        $result = $call_stats->get_result()->fetch_assoc();
        
        $stats['today_calls'] = (int)$result['total_calls'];
        $stats['today_positive'] = (int)$result['positive_outcomes'];
        $stats['today_talk_time'] = (int)$result['total_talk_time'];
        $stats['today_callbacks'] = (int)$result['callbacks_scheduled'];
        
        if ($stats['today_calls'] > 0) {
            $conversion_rate = round(($stats['today_positive'] / $stats['today_calls']) * 100, 1);
        }

        // Get pending callbacks
        $pending_cb = $db->prepare("
            SELECT COUNT(*) as cnt
            FROM call_center_appointments
            WHERE agent_id = ? AND status = 'scheduled' AND appointment_date >= CURDATE()
        ");
        $pending_cb->bind_param('i', $user_id);
        $pending_cb->execute();
        $stats['pending_callbacks'] = (int)$pending_cb->get_result()->fetch_assoc()['cnt'];
        
        // Get today's scheduled calls (appointments for today)
        $today_date = date('Y-m-d');
        $scheduled_today_query = $db->prepare("
            SELECT 
                a.id, a.appointment_date, a.appointment_time, a.notes,
                d.id as donor_id, d.name, d.phone, d.balance
            FROM call_center_appointments a
            JOIN donors d ON a.donor_id = d.id
            WHERE a.agent_id = ? AND a.status = 'scheduled' 
                AND a.appointment_date = ?
            ORDER BY a.appointment_time ASC
            LIMIT 5
        ");
        $scheduled_today_query->bind_param('is', $user_id, $today_date);
        $scheduled_today_query->execute();
        $result = $scheduled_today_query->get_result();
        $scheduled_today = [];
        while ($row = $result->fetch_assoc()) {
            $scheduled_today[] = $row;
        }

        // Get recent calls (last 8)
        $recent_query = $db->prepare("
            SELECT 
                s.id, s.donor_id, s.created_at, s.conversation_stage,
                s.duration_seconds, d.name, d.phone, d.balance
            FROM call_center_sessions s
            JOIN donors d ON s.donor_id = d.id
            WHERE s.agent_id = ?
            ORDER BY s.created_at DESC
            LIMIT 8
        ");
        $recent_query->bind_param('i', $user_id);
        $recent_query->execute();
        $result = $recent_query->get_result();
        while ($row = $result->fetch_assoc()) {
            $recent_calls[] = $row;
        }
    }

    // Get donor stats (always show these)
    $donor_stats = $db->query("
        SELECT 
            COUNT(CASE WHEN balance > 0 THEN 1 END) as with_balance,
            SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) as total_outstanding,
            COUNT(CASE WHEN has_active_plan = 1 THEN 1 END) as active_plans
        FROM donors
        WHERE donor_type = 'pledge'
    ")->fetch_assoc();
    
    $stats['donors_with_balance'] = (int)$donor_stats['with_balance'];
    $stats['total_outstanding'] = (float)$donor_stats['total_outstanding'];
    $stats['active_plans'] = (int)$donor_stats['active_plans'];

} catch (Exception $e) {
    $error_message = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Use JSDelivr for better availability and specific version -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        /* Compact, Mobile-First Dashboard Styles */
        .dashboard-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .dashboard-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        
        .stat-card {
            padding: 1rem;
            border-radius: 10px;
            background: white;
        }
        
        /* Explicit Icon Styling to fix visibility issues */
        .icon-box {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 1.1rem;
            margin-right: 10px;
            flex-shrink: 0;
        }

        /* Custom colors to ensure contrast (avoiding bg-opacity issues) */
        .icon-box-primary {
            background-color: rgba(13, 110, 253, 0.1) !important;
            color: #0d6efd !important;
        }
        
        .icon-box-success {
            background-color: rgba(25, 135, 84, 0.1) !important;
            color: #198754 !important;
        }
        
        .icon-box-danger {
            background-color: rgba(220, 53, 69, 0.1) !important;
            color: #dc3545 !important;
        }

        .icon-box-warning {
            background-color: rgba(255, 193, 7, 0.1) !important;
            color: #ffc107 !important;
        }

        .icon-box-info {
            background-color: rgba(13, 202, 240, 0.1) !important;
            color: #0dcaf0 !important;
        }
        
        .icon-box-secondary {
            background-color: rgba(108, 117, 125, 0.1) !important;
            color: #6c757d !important;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1;
            margin: 0;
        }
        
        .stat-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            color: #6c757d;
            margin-bottom: 0.25rem;
        }
        
        .action-btn {
            padding: 0.875rem 1rem;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            background: white;
            transition: all 0.2s;
            text-decoration: none;
            display: block;
        }
        
        .action-btn:hover {
            border-color: #0a6286;
            background: #f8f9fa;
            transform: translateX(4px);
        }
        
        .recent-call-item {
            padding: 0.75rem;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }
        
        .recent-call-item:hover {
            background: #f8f9fa;
        }
        
        .recent-call-item:last-child {
            border-bottom: none;
        }
        
        @media (max-width: 768px) {
            .stat-value {
                font-size: 1.25rem;
            }
            
            .icon-box {
                width: 36px;
                height: 36px;
                font-size: 1rem;
            }
            
            .action-btn {
                padding: 0.75rem;
            }
        }
        
        .badge-sm {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                        <h1 class="h4 mb-1 text-primary fw-bold">
                            <i class="fa-solid fa-headset me-2"></i>Call Center
                    </h1>
                        <p class="text-muted small mb-0">Welcome back, <?php echo htmlspecialchars($user_name); ?></p>
                </div>
            </div>

                <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

                <!-- Start Calling Button - Top Priority -->
                <div class="mb-4">
                    <a href="../donor-management/donors.php?balance=has_balance" 
                       class="btn btn-success btn-lg w-100 shadow-sm py-3">
                        <i class="fa-solid fa-phone-alt me-2"></i>
                        <span class="fw-bold">Start Calling Donors</span>
                        <span class="badge bg-white text-success ms-2"><?php echo $stats['donors_with_balance']; ?> with balance</span>
                        <i class="fa-solid fa-arrow-right ms-2"></i>
                    </a>
                </div>

                <!-- Key Stats -->
                <div class="mb-4">
                    <h6 class="text-uppercase fw-bold text-secondary mb-3" style="font-size: 0.75rem; letter-spacing: 1px;">
                        <i class="fa-solid fa-chart-line me-1"></i>Performance Overview
                    </h6>
                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <div class="dashboard-card stat-card">
                                <div class="d-flex align-items-start justify-content-between mb-2">
                                    <div class="icon-box icon-box-primary">
                                        <i class="fa-solid fa-phone-alt"></i>
                        </div>
                                    <span class="badge bg-primary badge-sm"><?php echo $conversion_rate; ?>%</span>
                        </div>
                                <div class="stat-label">Today's Calls</div>
                                <h3 class="stat-value text-primary"><?php echo $stats['today_calls']; ?></h3>
                </div>
                        </div>
                        
                        <div class="col-6 col-md-4">
                            <div class="dashboard-card stat-card">
                                <div class="d-flex align-items-start justify-content-between mb-2">
                                    <div class="icon-box icon-box-success">
                                        <i class="fa-solid fa-check-circle"></i>
                        </div>
                    </div>
                                <div class="stat-label">Successful</div>
                                <h3 class="stat-value text-success"><?php echo $stats['today_positive']; ?></h3>
                </div>
                        </div>
                        
                        <div class="col-12 col-md-4">
                            <div class="dashboard-card stat-card">
                                <div class="icon-box icon-box-danger mb-2">
                                    <i class="fa-solid fa-exclamation-circle"></i>
                        </div>
                                <div class="stat-label">Donors Outstanding</div>
                                <h3 class="stat-value text-danger"><?php echo $stats['donors_with_balance']; ?></h3>
                                <small class="text-muted">£<?php echo number_format($stats['total_outstanding'], 0); ?> total</small>
                        </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 g-lg-4">
                    <!-- Quick Actions -->
                    <div class="col-12 col-lg-5">
                        <div class="dashboard-card p-3 p-md-4">
                            <h6 class="fw-bold mb-3">
                                <i class="fa-solid fa-bolt me-2 text-warning"></i>Quick Actions
                            </h6>
                            <div class="d-flex flex-column gap-2">
                                <a href="../donor-management/donors.php" class="action-btn">
                                    <div class="d-flex align-items-center">
                                        <div class="icon-box icon-box-primary" style="width: 32px; height: 32px; font-size: 0.9rem;">
                                            <i class="fa-solid fa-list"></i>
            </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-semibold small">Donor List</div>
                                            <div class="text-muted" style="font-size: 0.7rem;">Browse all donors</div>
                        </div>
                                        <i class="fa-solid fa-chevron-right text-muted small"></i>
                                                        </div>
                                </a>
                                
                                <a href="my-schedule.php" class="action-btn">
                                    <div class="d-flex align-items-center">
                                        <div class="icon-box icon-box-info" style="width: 32px; height: 32px; font-size: 0.9rem;">
                                            <i class="fa-solid fa-calendar-alt"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-semibold small">My Schedule</div>
                                            <div class="text-muted" style="font-size: 0.7rem;">View calendar & appointments</div>
                                </div>
                                        <?php if ($stats['pending_callbacks'] > 0): ?>
                                        <span class="badge bg-warning text-dark"><?php echo $stats['pending_callbacks']; ?></span>
                                        <?php endif; ?>
                                        <i class="fa-solid fa-chevron-right text-muted small ms-2"></i>
                                    </div>
                                </a>
                                
                                <a href="call-history.php" class="action-btn">
                                    <div class="d-flex align-items-center">
                                        <div class="icon-box icon-box-secondary" style="width: 32px; height: 32px; font-size: 0.9rem;">
                                            <i class="fa-solid fa-history"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-semibold small">Call History</div>
                                            <div class="text-muted" style="font-size: 0.7rem;">View all calls</div>
                                        </div>
                                        <i class="fa-solid fa-chevron-right text-muted small"></i>
                                    </div>
                                </a>
                                </div>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="col-12 col-lg-7">
                        <div class="dashboard-card">
                            <div class="p-3 p-md-4 pb-0 d-flex justify-content-between align-items-center">
                                <h6 class="fw-bold mb-0">
                                    <i class="fa-solid fa-history me-2 text-secondary"></i>Recent Calls
                                </h6>
                                <?php if (!empty($recent_calls)): ?>
                                <a href="call-history.php" class="btn btn-sm btn-outline-secondary">
                                    View All
                                </a>
                                <?php endif; ?>
                            </div>
                            
                            <div class="p-3 p-md-4 pt-3">
                                <?php if (empty($recent_calls)): ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="fa-solid fa-phone-slash fa-2x mb-3 opacity-25"></i>
                                        <p class="small mb-2">No calls made yet</p>
                                        <a href="../donor-management/donors.php" class="btn btn-sm btn-primary">
                                            <i class="fa-solid fa-phone-alt me-1"></i>Make First Call
                                        </a>
                        </div>
                                <?php else: ?>
                                                <div>
                                        <?php foreach ($recent_calls as $call): ?>
                                        <div class="recent-call-item">
                                            <div class="d-flex align-items-center gap-2 gap-md-3">
                                                <div class="text-muted small" style="min-width: 45px;">
                                                    <?php echo date('H:i', strtotime($call['created_at'])); ?>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="fw-semibold small mb-1"><?php echo htmlspecialchars($call['name']); ?></div>
                                                    <div class="d-flex flex-wrap gap-2 align-items-center">
                                                        <span class="badge bg-light text-dark border badge-sm">
                                                            <?php echo gmdate("i:s", (int)$call['duration_seconds']); ?>
                                                        </span>
                                                        <span class="badge bg-secondary badge-sm">
                                                            <?php echo ucfirst(str_replace('_', ' ', $call['conversation_stage'] ?? 'N/A')); ?>
                                                        </span>
                                                        <?php if ($call['balance'] > 0): ?>
                                                        <span class="text-danger small fw-semibold">
                                                            £<?php echo number_format((float)$call['balance'], 2); ?>
                                                        </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <a href="../donor-management/view-donor.php?id=<?php echo $call['donor_id']; ?>" 
                                                   class="btn btn-sm btn-light">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Today's Scheduled Calls -->
                    <div class="col-12 col-lg-7">
                        <div class="dashboard-card">
                            <div class="p-3 p-md-4 pb-0 d-flex justify-content-between align-items-center">
                                <h6 class="fw-bold mb-0">
                                    <i class="fa-solid fa-calendar-check me-2 text-info"></i>Today's Schedule
                                </h6>
                                <?php if (!empty($scheduled_today)): ?>
                                <a href="my-schedule.php" class="btn btn-sm btn-outline-info">
                                    View All
                                </a>
                                <?php endif; ?>
                            </div>
                            
                            <div class="p-3 p-md-4 pt-3">
                                <?php if (empty($scheduled_today)): ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="fa-solid fa-calendar-xmark fa-2x mb-3 opacity-25"></i>
                                        <p class="small mb-2">No scheduled calls for today</p>
                                        <a href="my-schedule.php" class="btn btn-sm btn-info">
                                            <i class="fa-solid fa-calendar-plus me-1"></i>View Schedule
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div>
                                        <?php foreach ($scheduled_today as $appt): ?>
                                        <div class="recent-call-item">
                                            <div class="d-flex align-items-center gap-2 gap-md-3">
                                                <div class="text-muted small fw-bold" style="min-width: 45px;">
                                                    <?php echo date('H:i', strtotime($appt['appointment_time'])); ?>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="fw-semibold small mb-1"><?php echo htmlspecialchars($appt['name']); ?></div>
                                                    <div class="d-flex flex-wrap gap-2 align-items-center">
                                                        <span class="badge bg-light text-dark border badge-sm">
                                                            <i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($appt['phone']); ?>
                                                        </span>
                                                        <?php if (!empty($appt['notes'])): ?>
                                                        <span class="badge bg-info badge-sm" title="<?php echo htmlspecialchars($appt['notes']); ?>">
                                                            <i class="fa-solid fa-sticky-note"></i> Note
                                                        </span>
                                                        <?php endif; ?>
                                                        <?php if ($appt['balance'] > 0): ?>
                                                        <span class="text-danger small fw-semibold">
                                                            £<?php echo number_format((float)$appt['balance'], 2); ?>
                                                        </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <a href="make-call.php?donor_id=<?php echo $appt['donor_id']; ?>" 
                                                   class="btn btn-sm btn-success">
                                                    <i class="fa-solid fa-phone-alt"></i> Call
                                                </a>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>
