<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$page_title = 'Call Center Dashboard';
$user_name = $_SESSION['user']['name'] ?? 'Agent';
$user_id = (int)$_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'] ?? 'registrar';

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
$scheduled_today = [];
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

        // Successful calls = Contact made (excludes no answer, busy, invalid, etc.)
        $call_stats = $db->prepare("
            SELECT 
                COUNT(*) as total_calls,
                SUM(CASE 
                    WHEN conversation_stage NOT IN ('pending', 'attempt_failed', 'invalid_data')
                    THEN 1 
                    ELSE 0 
                END) as positive_outcomes,
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
        
        // Get today's scheduled calls
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
        while ($row = $result->fetch_assoc()) {
            $scheduled_today[] = $row;
        }

        // Get recent calls
        $recent_query = $db->prepare("
            SELECT 
                s.id, s.donor_id, s.created_at, s.conversation_stage,
                s.outcome, s.disposition, s.duration_seconds, 
                d.name, d.phone, d.balance
            FROM call_center_sessions s
            JOIN donors d ON s.donor_id = d.id
            WHERE s.agent_id = ? AND s.call_ended_at IS NOT NULL
            ORDER BY s.created_at DESC
            LIMIT 6
        ");
        $recent_query->bind_param('i', $user_id);
        $recent_query->execute();
        $result = $recent_query->get_result();
        while ($row = $result->fetch_assoc()) {
            $recent_calls[] = $row;
        }
    }

    // Get donor stats (assigned to agent only)
    $donor_stats_query = $db->prepare("
        SELECT 
            COUNT(CASE WHEN balance > 0 THEN 1 END) as with_balance,
            SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) as total_outstanding,
            COUNT(CASE WHEN has_active_plan = 1 THEN 1 END) as active_plans
        FROM donors
        WHERE agent_id = ? AND donor_type = 'pledge'
    ");
    $donor_stats_query->bind_param('i', $user_id);
    $donor_stats_query->execute();
    $donor_stats = $donor_stats_query->get_result()->fetch_assoc();
    
    $stats['donors_with_balance'] = (int)($donor_stats['with_balance'] ?? 0);
    $stats['total_outstanding'] = (float)($donor_stats['total_outstanding'] ?? 0);
    $stats['active_plans'] = (int)($donor_stats['active_plans'] ?? 0);

} catch (Exception $e) {
    $error_message = $e->getMessage();
}

// Format talk time
$hours = floor($stats['today_talk_time'] / 3600);
$minutes = floor(($stats['today_talk_time'] % 3600) / 60);
$talk_time_formatted = $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        /* Modern, Mobile-First Call Center Dashboard */
        :root {
            --cc-primary: #0a6286;
            --cc-gradient: linear-gradient(135deg, #0a6286 0%, #0d6efd 100%);
        }
        
        .dashboard-header {
            background: var(--cc-gradient);
            color: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(10, 98, 134, 0.15);
        }
        
        .welcome-text {
            font-size: 0.875rem;
            opacity: 0.95;
            margin: 0;
        }
        
        .dashboard-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0.25rem 0 0 0;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            transition: all 0.3s;
            border: 1px solid #f0f0f0;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
            margin: 0;
        }
        
        .stat-label {
            font-size: 0.8125rem;
            color: #64748b;
            font-weight: 600;
            margin-top: 0.5rem;
        }
        
        .stat-badge {
            display: inline-block;
            font-size: 0.75rem;
            padding: 0.25rem 0.625rem;
            border-radius: 6px;
            font-weight: 600;
            margin-top: 0.5rem;
        }

        .action-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            border: 1px solid #f0f0f0;
        }
        
        .action-header {
            padding: 1.25rem;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .action-title {
            font-size: 1rem;
            font-weight: 700;
            margin: 0;
            color: #1e293b;
        }
        
        .action-list {
            padding: 0.5rem;
        }
        
        .action-item {
            display: flex;
            align-items: center;
            gap: 0.875rem;
            padding: 0.875rem;
            border-radius: 8px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        
        .action-item:hover {
            background: #f8fafc;
            border-color: var(--cc-primary);
            transform: translateX(4px);
        }
        
        .action-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1rem;
        }
        
        .action-content {
            flex: 1;
        }
        
        .action-name {
            font-weight: 600;
            font-size: 0.9375rem;
            margin: 0;
            color: #1e293b;
        }
        
        .action-desc {
            font-size: 0.75rem;
            color: #64748b;
            margin: 0;
        }
        
        .start-calling-btn {
            background: #10b981;
            border: none;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            font-size: 1.125rem;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
            transition: all 0.3s;
        }
        
        .start-calling-btn:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.35);
        }
        
        .call-item {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }
        
        .call-item:hover {
            background: #f8fafc;
        }
        
        .call-item:last-child {
            border-bottom: none;
        }
        
        .call-time {
            font-size: 0.8125rem;
            color: #64748b;
            font-weight: 600;
            min-width: 50px;
        }
        
        .call-name {
            font-weight: 600;
            font-size: 0.9375rem;
            margin-bottom: 0.25rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 2.5rem 1rem;
        }
        
        .empty-icon {
            font-size: 3rem;
            color: #e2e8f0;
            margin-bottom: 1rem;
        }
        
        /* Mobile Optimization */
        @media (max-width: 576px) {
            .dashboard-header {
                padding: 1.25rem;
            }
            
            .dashboard-title {
                font-size: 1.25rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
            
            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }
            
            .start-calling-btn {
                padding: 1rem;
                font-size: 1rem;
            }
            
            .action-item {
                padding: 0.75rem;
                gap: 0.75rem;
        }
        
            .action-icon {
                width: 36px;
                height: 36px;
                font-size: 0.875rem;
            }
        }
        
        /* Color Utilities */
        .bg-primary-light { background: #eff6ff; color: #0d6efd; }
        .bg-success-light { background: #d1fae5; color: #059669; }
        .bg-danger-light { background: #fee2e2; color: #dc2626; }
        .bg-warning-light { background: #fef3c7; color: #f59e0b; }
        .bg-info-light { background: #dbeafe; color: #0284c7; }
        .bg-secondary-light { background: #f1f5f9; color: #64748b; }
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
                <div class="dashboard-header">
                    <p class="welcome-text">
                        <i class="fas fa-headset me-1"></i>Welcome back
                    </p>
                    <h1 class="dashboard-title"><?php echo htmlspecialchars($user_name); ?></h1>
            </div>

                <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

                <!-- Start Calling CTA -->
                <div class="mb-4">
                    <a href="../donor-management/donors.php?balance=has_balance" 
                       class="btn btn-success w-100 start-calling-btn">
                        <i class="fas fa-phone-alt me-2"></i>
                        Start Calling Donors
                        <span class="badge bg-white text-success ms-2"><?php echo $stats['donors_with_balance']; ?></span>
                    </a>
                </div>

                <!-- Key Stats Grid -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon bg-primary-light">
                                <i class="fas fa-phone"></i>
                        </div>
                                <h3 class="stat-value text-primary"><?php echo $stats['today_calls']; ?></h3>
                            <p class="stat-label">Calls Today</p>
                            <?php if ($stats['today_calls'] > 0): ?>
                            <span class="stat-badge bg-primary text-white">
                                <?php echo $conversion_rate; ?>% success
                            </span>
                            <?php endif; ?>
                </div>
                        </div>
                        
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon bg-success-light">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <h3 class="stat-value text-success"><?php echo $stats['today_positive']; ?></h3>
                            <p class="stat-label">Successful</p>
                            <?php if ($stats['today_talk_time'] > 0): ?>
                            <span class="stat-badge bg-success-light text-success">
                                <i class="fas fa-clock me-1"></i><?php echo $talk_time_formatted; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon bg-warning-light">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <h3 class="stat-value text-warning"><?php echo $stats['pending_callbacks']; ?></h3>
                            <p class="stat-label">Callbacks Due</p>
                </div>
                        </div>
                        
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="stat-icon bg-danger-light">
                                <i class="fas fa-exclamation-circle"></i>
                        </div>
                                <h3 class="stat-value text-danger"><?php echo $stats['donors_with_balance']; ?></h3>
                            <p class="stat-label">Outstanding</p>
                            <span class="stat-badge bg-danger-light text-danger">
                                £<?php echo number_format($stats['total_outstanding'], 0); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="row g-3 g-lg-4">
                    <!-- Quick Actions -->
                    <div class="col-12 col-lg-4">
                        <div class="action-card">
                            <div class="action-header">
                                <h2 class="action-title">
                                    <i class="fas fa-bolt text-warning me-2"></i>Quick Actions
                                </h2>
            </div>
                            <div class="action-list">
                                <a href="../donor-management/donors.php" class="action-item">
                                    <div class="action-icon bg-primary-light">
                                        <i class="fas fa-users"></i>
                        </div>
                                    <div class="action-content">
                                        <p class="action-name">Donor List</p>
                                        <p class="action-desc">Browse all donors</p>
                                                        </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                </a>
                                
                                <a href="my-schedule.php" class="action-item">
                                    <div class="action-icon bg-info-light">
                                        <i class="fas fa-calendar"></i>
                                        </div>
                                    <div class="action-content">
                                        <p class="action-name">My Schedule</p>
                                        <p class="action-desc">View appointments</p>
                                </div>
                                        <?php if ($stats['pending_callbacks'] > 0): ?>
                                        <span class="badge bg-warning text-dark"><?php echo $stats['pending_callbacks']; ?></span>
                                        <?php endif; ?>
                                    <i class="fas fa-chevron-right text-muted ms-2"></i>
                                </a>
                                
                                <a href="call-history.php" class="action-item">
                                    <div class="action-icon bg-secondary-light">
                                        <i class="fas fa-history"></i>
                                        </div>
                                    <div class="action-content">
                                        <p class="action-name">Call History</p>
                                        <p class="action-desc">View all calls</p>
                                    </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                </a>
                                
                                <?php if ($user_role === 'admin'): ?>
                                <a href="assign-donors.php" class="action-item">
                                    <div class="action-icon bg-success-light">
                                        <i class="fas fa-users-cog"></i>
                                        </div>
                                    <div class="action-content">
                                        <p class="action-name">Assign Donors</p>
                                        <p class="action-desc">Manage assignments</p>
                                    </div>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                </a>
                                <?php endif; ?>
                                </div>
                        </div>
                    </div>

                    <!-- Today's Schedule -->
                    <div class="col-12 col-lg-4">
                        <div class="action-card">
                            <div class="action-header d-flex justify-content-between align-items-center">
                                <h2 class="action-title">
                                    <i class="fas fa-calendar-check text-info me-2"></i>Today's Schedule
                                </h2>
                                <?php if (!empty($scheduled_today)): ?>
                                <a href="my-schedule.php" class="btn btn-sm btn-outline-info">View All</a>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (empty($scheduled_today)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-calendar-xmark empty-icon"></i>
                                    <p class="text-muted mb-3">No calls scheduled for today</p>
                                    <a href="my-schedule.php" class="btn btn-sm btn-info">
                                        <i class="fas fa-calendar-plus me-1"></i>View Schedule
                                        </a>
                        </div>
                                <?php else: ?>
                                <?php foreach ($scheduled_today as $appt): ?>
                                <div class="call-item">
                                            <div class="d-flex align-items-center gap-2 gap-md-3">
                                        <div class="call-time">
                                            <?php echo date('H:i', strtotime($appt['appointment_time'])); ?>
                                                </div>
                                                <div class="flex-grow-1">
                                            <div class="call-name"><?php echo htmlspecialchars($appt['name']); ?></div>
                                            <div class="d-flex flex-wrap gap-1 align-items-center">
                                                <span class="badge bg-light text-dark" style="font-size: 0.7rem;">
                                                    <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($appt['phone']); ?>
                                                        </span>
                                                <?php if ($appt['balance'] > 0): ?>
                                                <span class="badge bg-danger" style="font-size: 0.7rem;">
                                                    £<?php echo number_format((float)$appt['balance'], 2); ?>
                                                        </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                        <a href="make-call.php?donor_id=<?php echo $appt['donor_id']; ?>" 
                                           class="btn btn-sm btn-success">
                                            <i class="fas fa-phone"></i>
                                                </a>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Recent Activity -->
                    <div class="col-12 col-lg-4">
                        <div class="action-card">
                            <div class="action-header d-flex justify-content-between align-items-center">
                                <h2 class="action-title">
                                    <i class="fas fa-clock-rotate-left text-secondary me-2"></i>Recent Calls
                                </h2>
                                <?php if (!empty($recent_calls)): ?>
                                <a href="call-history.php" class="btn btn-sm btn-outline-secondary">View All</a>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (empty($recent_calls)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-phone-slash empty-icon"></i>
                                    <p class="text-muted mb-3">No calls made yet</p>
                                    <a href="../donor-management/donors.php" class="btn btn-sm btn-primary">
                                        <i class="fas fa-phone me-1"></i>Make First Call
                                        </a>
                                    </div>
                                <?php else: ?>
                                <?php foreach ($recent_calls as $call): 
                                    $stage = $call['conversation_stage'] ?? 'unknown';
                                    $status_map = [
                                        'contact_made' => ['label' => 'Contact Made', 'class' => 'info'],
                                        'success_pledged' => ['label' => 'Success', 'class' => 'success'],
                                        'callback_scheduled' => ['label' => 'Callback', 'class' => 'warning'],
                                        'interested_follow_up' => ['label' => 'Interested', 'class' => 'info'],
                                        'closed_refused' => ['label' => 'Refused', 'class' => 'danger'],
                                        'attempt_failed' => ['label' => 'No Contact', 'class' => 'secondary'],
                                        'invalid_data' => ['label' => 'Invalid', 'class' => 'danger'],
                                        'pending' => ['label' => 'Pending', 'class' => 'secondary']
                                    ];
                                    $status_info = $status_map[$stage] ?? ['label' => 'Other', 'class' => 'secondary'];
                                ?>
                                <div class="call-item">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="call-time">
                                            <?php echo date('H:i', strtotime($call['created_at'])); ?>
                                                </div>
                                                <div class="flex-grow-1">
                                            <div class="call-name"><?php echo htmlspecialchars($call['name']); ?></div>
                                            <div class="d-flex flex-wrap gap-1 align-items-center">
                                                <span class="badge bg-<?php echo $status_info['class']; ?>" style="font-size: 0.7rem;">
                                                    <?php echo $status_info['label']; ?>
                                                        </span>
                                                <?php if ($call['duration_seconds'] > 0): ?>
                                                <span class="badge bg-light text-dark" style="font-size: 0.7rem;">
                                                    <i class="fas fa-clock me-1"></i><?php echo gmdate("i:s", (int)$call['duration_seconds']); ?>
                                                        </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                        <a href="call-details.php?id=<?php echo $call['id']; ?>" 
                                           class="btn btn-sm btn-light" title="View Details">
                                            <i class="fas fa-eye"></i>
                                                </a>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                <?php endif; ?>
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
