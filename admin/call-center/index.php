<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

try {
    // Get database connection
    $db = db();
    
    // Get user data from session
    $user_id = (int)$_SESSION['user']['id'];
    $user_name = $_SESSION['user']['name'] ?? 'Agent';
    
    // Initialize default values
    $today_stats = (object)[
        'total_calls' => 0,
        'successful_contacts' => 0,
        'positive_outcomes' => 0,
        'callbacks_scheduled' => 0,
        'total_talk_time' => 0
    ];
    $queue_result = null;
    $callbacks_result = null;
    $recent_result = null;
    $setup_needed = false;

    // Check if call center tables exist
    $tables_check = $db->query("SHOW TABLES LIKE 'call_center_sessions'");
    $tables_exist = $tables_check && $tables_check->num_rows > 0;

    if (!$tables_exist) {
        $setup_needed = true;
    } else {
        // Get today's stats for this agent
        $today_start = date('Y-m-d 00:00:00');
        $today_end = date('Y-m-d 23:59:59');

        $stats_query = "
            SELECT 
                COUNT(*) as total_calls,
                SUM(CASE WHEN conversation_stage != 'no_connection' THEN 1 ELSE 0 END) as successful_contacts,
                SUM(CASE WHEN outcome IN ('payment_plan_created', 'agreed_to_pay_full', 'agreed_reduced_amount', 'agreed_cash_collection') THEN 1 ELSE 0 END) as positive_outcomes,
                SUM(CASE WHEN callback_scheduled_for IS NOT NULL THEN 1 ELSE 0 END) as callbacks_scheduled,
                SUM(duration_seconds) as total_talk_time
            FROM call_center_sessions
            WHERE agent_id = ? AND call_started_at BETWEEN ? AND ?
        ";
        
        $stmt = $db->prepare($stats_query);
        if ($stmt) {
            $stmt->bind_param('iss', $user_id, $today_start, $today_end);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                $row = $result->fetch_object();
                if ($row) {
                    $today_stats = $row;
                }
            }
            $stmt->close();
        }
        
        // Get call queue - donors who need to be called
        $queue_query = "
            SELECT 
                q.id as queue_id,
                q.donor_id,
                q.queue_type,
                q.priority,
                q.attempts_count,
                q.next_attempt_after,
                q.reason_for_queue,
                q.preferred_contact_time,
                d.name,
                d.phone,
                d.balance,
                d.city,
                d.last_contacted_at,
                (SELECT outcome FROM call_center_sessions WHERE donor_id = d.id ORDER BY call_started_at DESC LIMIT 1) as last_outcome
            FROM call_center_queues q
            JOIN donors d ON q.donor_id = d.id
            WHERE q.status = 'pending' 
                AND (q.assigned_to IS NULL OR q.assigned_to = ?)
                AND (q.next_attempt_after IS NULL OR q.next_attempt_after <= NOW())
            ORDER BY q.priority DESC, q.next_attempt_after ASC, q.created_at ASC
            LIMIT 50
        ";
        
        $stmt = $db->prepare($queue_query);
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $queue_result = $stmt->get_result();
            $stmt->close();
        }

        // Get upcoming callbacks (scheduled for future)
        $callbacks_query = "
            SELECT 
                s.id as session_id,
                s.donor_id,
                s.callback_scheduled_for,
                s.callback_reason,
                s.preferred_callback_time,
                d.name,
                d.phone,
                d.balance
            FROM call_center_sessions s
            JOIN donors d ON s.donor_id = d.id
            WHERE s.agent_id = ? 
                AND s.callback_scheduled_for > NOW()
                AND s.callback_scheduled_for <= DATE_ADD(NOW(), INTERVAL 7 DAY)
            ORDER BY s.callback_scheduled_for ASC
            LIMIT 10
        ";
        
        $stmt = $db->prepare($callbacks_query);
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $callbacks_result = $stmt->get_result();
            $stmt->close();
        }

        // Get recent activity (last 10 calls)
        $recent_query = "
            SELECT 
                s.id,
                s.donor_id,
                s.call_started_at,
                s.outcome,
                s.conversation_stage,
                s.duration_seconds,
                d.name,
                d.phone
            FROM call_center_sessions s
            JOIN donors d ON s.donor_id = d.id
            WHERE s.agent_id = ?
            ORDER BY s.call_started_at DESC
            LIMIT 10
        ";
        
        $stmt = $db->prepare($recent_query);
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $recent_result = $stmt->get_result();
            $stmt->close();
        }
    }

    // Ensure today_stats is always set
    if (!isset($today_stats) || !is_object($today_stats)) {
        $today_stats = (object)[
            'total_calls' => 0,
            'successful_contacts' => 0,
            'positive_outcomes' => 0,
            'callbacks_scheduled' => 0,
            'total_talk_time' => 0
        ];
    }

    // Set conversion rate with proper type casting
    $conversion_rate = isset($today_stats->total_calls) && (int)$today_stats->total_calls > 0 
        ? round(((int)$today_stats->positive_outcomes / (int)$today_stats->total_calls) * 100, 1) 
        : 0;

} catch (mysqli_sql_exception $e) {
    error_log("Call Center MySQLi Error: " . $e->getMessage() . " | Code: " . $e->getCode());
    $today_stats = (object)[
        'total_calls' => 0,
        'successful_contacts' => 0,
        'positive_outcomes' => 0,
        'callbacks_scheduled' => 0,
        'total_talk_time' => 0
    ];
    $queue_result = null;
    $callbacks_result = null;
    $recent_result = null;
    $conversion_rate = 0;
    $setup_needed = false;
    $error_message = "Database error: " . htmlspecialchars($e->getMessage()) . ". Please check <a href='debug.php'>debug page</a> for details.";
} catch (Exception $e) {
    error_log("Call Center Error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    $today_stats = (object)[
        'total_calls' => 0,
        'successful_contacts' => 0,
        'positive_outcomes' => 0,
        'callbacks_scheduled' => 0,
        'total_talk_time' => 0
    ];
    $queue_result = null;
    $callbacks_result = null;
    $recent_result = null;
    $conversion_rate = 0;
    $setup_needed = false;
    $error_message = "An error occurred: " . htmlspecialchars($e->getMessage()) . ". Please check <a href='debug.php'>debug page</a> or contact administrator.";
}

$page_title = 'Call Center Dashboard';
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
    <link rel="stylesheet" href="assets/call-center.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="content-header">
                <div>
                    <h1 class="content-title">
                        <i class="fas fa-headset me-2"></i>
                        Call Center Dashboard
                    </h1>
                    <p class="content-subtitle">
                        Welcome back, <?php echo htmlspecialchars($user_name); ?>! 
                        <span class="text-primary">•</span> 
                        <?php echo date('l, F j, Y'); ?>
                    </p>
                </div>
                <div class="header-actions">
                    <a href="make-call.php" class="btn btn-success">
                        <i class="fas fa-phone-alt me-2"></i>New Call
                    </a>
                    <a href="call-history.php" class="btn btn-outline-primary">
                        <i class="fas fa-history me-2"></i>History
                    </a>
                    <button class="btn btn-outline-secondary d-lg-none" onclick="toggleFilters()">
                        <i class="fas fa-filter"></i>
                    </button>
                </div>
            </div>

            <?php 
            // Calculate daily progress
            $daily_target = 50; // Calls target
            $progress_percentage = min(100, round(((int)($today_stats->total_calls ?? 0) / $daily_target) * 100));
            ?>
            <div class="progress-section mb-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold">Daily Progress</span>
                    <span class="text-muted"><?php echo (int)($today_stats->total_calls ?? 0); ?> / <?php echo $daily_target; ?> calls</span>
                </div>
                <div class="progress" style="height: 8px;">
                    <div class="progress-bar bg-primary" role="progressbar" 
                         style="width: <?php echo $progress_percentage; ?>%" 
                         aria-valuenow="<?php echo $progress_percentage; ?>" 
                         aria-valuemin="0" 
                         aria-valuemax="100">
                    </div>
                </div>
            </div>

            <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <h4 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Error!</h4>
                <p class="mb-2"><?php echo $error_message; ?></p>
                <div class="d-flex gap-2 mt-3">
                    <a href="debug.php" class="btn btn-danger btn-sm">
                        <i class="fas fa-bug me-2"></i>Run Diagnostics
                    </a>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (isset($setup_needed) && $setup_needed): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <h4 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Setup Required!</h4>
                <p class="mb-3">The Call Center database tables haven't been created yet. Please follow these steps to get started:</p>
                <ol class="mb-3">
                    <li><strong>Check database status:</strong> <a href="check-database.php" class="alert-link fw-bold">Click here to see what's missing</a></li>
                    <li><strong>Open phpMyAdmin</strong> and select your fundraising database</li>
                    <li><strong>Run the SQL script</strong> that creates all call center tables</li>
                    <li><strong>Refresh this page</strong> or check database status again</li>
                </ol>
                <div class="d-flex gap-2 mt-3">
                    <a href="check-database.php" class="btn btn-warning btn-sm">
                        <i class="fas fa-database me-2"></i>Check Database Status
                    </a>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Today's Statistics -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-primary">
                        <div class="stat-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div class="stat-details">
                            <div class="stat-value"><?php echo (int)($today_stats->total_calls ?? 0); ?></div>
                            <div class="stat-label">Calls Today</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card stat-success">
                        <div class="stat-icon">
                            <i class="fas fa-phone-volume"></i>
                        </div>
                        <div class="stat-details">
                            <div class="stat-value"><?php echo (int)($today_stats->successful_contacts ?? 0); ?></div>
                            <div class="stat-label">Connected</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card stat-info">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-details">
                            <div class="stat-value"><?php echo $conversion_rate; ?>%</div>
                            <div class="stat-label">Conversion Rate</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card stat-warning">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-details">
                            <div class="stat-value"><?php echo gmdate("H:i", (int)($today_stats->total_talk_time ?? 0)); ?></div>
                            <div class="stat-label">Talk Time</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <!-- Call Queue -->
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-list-check me-2"></i>Call Queue
                            </h5>
                            <button class="btn btn-sm btn-primary" onclick="location.reload()">
                                <i class="fas fa-sync-alt me-1"></i>Refresh
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <?php if ($queue_result && $queue_result->num_rows > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Priority</th>
                                                <th>Donor</th>
                                                <th>Phone</th>
                                                <th>Balance</th>
                                                <th>Type</th>
                                                <th>Attempts</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($donor = $queue_result->fetch_object()): ?>
                                                <tr data-donor-id="<?php echo (int)$donor->donor_id; ?>" 
                                                    data-priority="<?php echo (int)$donor->priority; ?>">
                                                    <td data-label="Priority">
                                                        <span class="priority-badge priority-<?php echo (int)$donor->priority >= 8 ? 'urgent' : ((int)$donor->priority >= 5 ? 'high' : 'normal'); ?>">
                                                            <?php echo htmlspecialchars((string)$donor->priority); ?>
                                                        </span>
                                                    </td>
                                                    <td data-label="Donor">
                                                        <div class="donor-info">
                                                            <div class="donor-name"><?php echo htmlspecialchars($donor->name); ?></div>
                                                            <?php if (!empty($donor->city)): ?>
                                                                <small class="text-muted">
                                                                    <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($donor->city); ?>
                                                                </small>
                                                            <?php endif; ?>
                                                            <?php if (!empty($donor->last_contacted_at)): ?>
                                                                <small class="text-muted d-block">
                                                                    <i class="fas fa-clock"></i> Last: <?php echo date('M j', strtotime($donor->last_contacted_at)); ?>
                                                                </small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td data-label="Phone">
                                                        <a href="tel:<?php echo htmlspecialchars($donor->phone); ?>" class="phone-link">
                                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor->phone); ?>
                                                        </a>
                                                    </td>
                                                    <td data-label="Balance">
                                                        <span class="badge bg-danger">£<?php echo number_format((float)$donor->balance, 2); ?></span>
                                                    </td>
                                                    <td data-label="Type">
                                                        <span class="badge bg-secondary"><?php echo htmlspecialchars(str_replace('_', ' ', ucwords($donor->queue_type, '_'))); ?></span>
                                                    </td>
                                                    <td data-label="Attempts">
                                                        <span class="badge bg-info"><?php echo (int)$donor->attempts_count; ?> calls</span>
                                                    </td>
                                                    <td data-label="Action">
                                                        <a href="make-call.php?donor_id=<?php echo (int)$donor->donor_id; ?>&queue_id=<?php echo (int)$donor->queue_id; ?>" 
                                                           class="btn btn-sm btn-success w-100">
                                                            <i class="fas fa-phone-alt me-1"></i>Start Call
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="empty-state py-5">
                                    <div class="empty-state-icon mb-3">
                                        <i class="fas fa-check-circle fa-4x text-success"></i>
                                    </div>
                                    <h5 class="mb-2">All Caught Up!</h5>
                                    <p class="text-muted mb-4">No donors in queue. Great job!</p>
                                    <div class="d-flex gap-2 justify-content-center">
                                        <a href="../donor-management/" class="btn btn-outline-primary">
                                            <i class="fas fa-users me-2"></i>View All Donors
                                        </a>
                                        <button class="btn btn-primary" onclick="refreshQueue()">
                                            <i class="fas fa-sync-alt me-2"></i>Refresh Queue
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-history me-2"></i>Recent Activity
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <?php if ($recent_result && $recent_result->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($call = $recent_result->fetch_object()): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($call->name); ?></h6>
                                                    <p class="mb-1 small">
                                                        <span class="outcome-badge outcome-<?php echo htmlspecialchars(str_replace('_', '-', $call->outcome ?? 'unknown')); ?>">
                                                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $call->outcome ?? 'unknown'))); ?>
                                                        </span>
                                                        <span class="text-muted ms-2">
                                                            Stage: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $call->conversation_stage ?? 'unknown'))); ?>
                                                        </span>
                                                    </p>
                                                    <small class="text-muted">
                                                        <i class="fas fa-clock me-1"></i>
                                                        <?php echo date('M j, g:i A', strtotime($call->call_started_at)); ?>
                                                        <?php if (!empty($call->duration_seconds)): ?>
                                                            (<?php echo gmdate("i:s", (int)$call->duration_seconds); ?>)
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                                <a href="call-history.php?donor_id=<?php echo (int)$call->donor_id; ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state py-4">
                                    <p class="text-muted mb-0">No recent activity</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="col-lg-4">
                    <!-- Upcoming Callbacks -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-calendar-check me-2"></i>Upcoming Callbacks
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <?php if ($callbacks_result && $callbacks_result->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($callback = $callbacks_result->fetch_object()): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="mb-0"><?php echo htmlspecialchars($callback->name); ?></h6>
                                                <span class="badge bg-warning text-dark">
                                                    <?php echo date('M j', strtotime($callback->callback_scheduled_for)); ?>
                                                </span>
                                            </div>
                                            <p class="mb-1 small">
                                                <i class="fas fa-clock me-1"></i>
                                                <?php echo date('g:i A', strtotime($callback->callback_scheduled_for)); ?>
                                                <?php if (!empty($callback->preferred_callback_time)): ?>
                                                    (<?php echo htmlspecialchars(ucfirst($callback->preferred_callback_time)); ?>)
                                                <?php endif; ?>
                                            </p>
                                            <?php if (!empty($callback->callback_reason)): ?>
                                                <p class="mb-2 small text-muted">
                                                    <?php echo htmlspecialchars($callback->callback_reason); ?>
                                                </p>
                                            <?php endif; ?>
                                            <a href="make-call.php?donor_id=<?php echo (int)$callback->donor_id; ?>" 
                                               class="btn btn-sm btn-outline-success w-100">
                                                <i class="fas fa-phone me-1"></i>Call Now
                                            </a>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state py-4">
                                    <i class="fas fa-calendar-check fa-2x text-muted mb-2"></i>
                                    <p class="text-muted mb-0 small">No callbacks scheduled</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Tips -->
                    <div class="card mt-4">
                        <div class="card-header bg-info text-white">
                            <h6 class="card-title mb-0">
                                <i class="fas fa-lightbulb me-2"></i>Quick Tips
                            </h6>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0 small">
                                <li class="mb-2">
                                    <i class="fas fa-check text-success me-2"></i>
                                    Best calling times: 6-8 PM
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check text-success me-2"></i>
                                    Always verify identity first
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check text-success me-2"></i>
                                    Be respectful and patient
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check text-success me-2"></i>
                                    Record detailed notes
                                </li>
                                <li class="mb-0">
                                    <i class="fas fa-check text-success me-2"></i>
                                    Follow up on callbacks
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Floating Action Button (Mobile) -->
<div class="fab-container d-lg-none">
    <button class="fab fab-main" onclick="toggleFabMenu()">
        <i class="fas fa-phone"></i>
    </button>
    <div class="fab-menu" id="fabMenu">
        <a href="make-call.php" class="fab fab-secondary" data-tooltip="New Call">
            <i class="fas fa-plus"></i>
        </a>
        <button class="fab fab-secondary" onclick="refreshQueue()" data-tooltip="Refresh">
            <i class="fas fa-sync-alt"></i>
        </button>
        <a href="call-history.php" class="fab fab-secondary" data-tooltip="History">
            <i class="fas fa-history"></i>
        </a>
    </div>
</div>

<!-- Add custom styles for FAB -->
<style>
.fab-container {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 1000;
}

.fab {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    border: none;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    cursor: pointer;
    transition: all 0.3s ease;
}

.fab-main {
    background: #2563eb;
    font-size: 1.5rem;
}

.fab-main:hover {
    background: #1d4ed8;
    transform: scale(1.1);
}

.fab-menu {
    position: absolute;
    bottom: 70px;
    right: 0;
    display: none;
    flex-direction: column-reverse;
    gap: 12px;
}

.fab-menu.show {
    display: flex;
}

.fab-secondary {
    width: 48px;
    height: 48px;
    background: #10b981;
    font-size: 1.25rem;
    opacity: 0;
    animation: fadeIn 0.3s ease forwards;
}

.fab-secondary:nth-child(1) { animation-delay: 0.1s; }
.fab-secondary:nth-child(2) { animation-delay: 0.2s; }
.fab-secondary:nth-child(3) { animation-delay: 0.3s; }

.fab-secondary:hover {
    background: #059669;
}

.fab-secondary[data-tooltip]::before {
    content: attr(data-tooltip);
    position: absolute;
    right: 60px;
    top: 50%;
    transform: translateY(-50%);
    background: var(--cc-dark);
    color: white;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 0.875rem;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s;
}

.fab-secondary[data-tooltip]:hover::before {
    opacity: 1;
}

@keyframes fadeIn {
    from { opacity: 0; transform: scale(0.8); }
    to { opacity: 1; transform: scale(1); }
}

/* Quick Stats Widget */
.quick-stats {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
}

.quick-stats h5 {
    font-size: 0.875rem;
    opacity: 0.9;
    margin-bottom: 1rem;
}

.quick-stats .stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.quick-stats .stat-item {
    text-align: center;
}

.quick-stats .stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1;
}

.quick-stats .stat-label {
    font-size: 0.75rem;
    opacity: 0.8;
    margin-top: 0.25rem;
}

@media (min-width: 768px) {
    .quick-stats .stats-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/call-center.js"></script>
<script>
// FAB Menu Toggle
function toggleFabMenu() {
    const menu = document.getElementById('fabMenu');
    menu.classList.toggle('show');
    
    // Rotate main button
    const mainBtn = document.querySelector('.fab-main');
    if (menu.classList.contains('show')) {
        mainBtn.style.transform = 'rotate(45deg)';
    } else {
        mainBtn.style.transform = 'rotate(0deg)';
    }
}

// Close FAB menu when clicking outside
document.addEventListener('click', (e) => {
    if (!e.target.closest('.fab-container')) {
        const menu = document.getElementById('fabMenu');
        if (menu.classList.contains('show')) {
            toggleFabMenu();
        }
    }
});

// Add notification sound for new queue items
let lastQueueCount = <?php echo $queue_result ? $queue_result->num_rows : 0; ?>;

function checkForNewItems() {
    // This would normally fetch via AJAX
    // For now, it's just a placeholder
    console.log('Checking for new queue items...');
}

// Check every 30 seconds
setInterval(checkForNewItems, 30000);

// Show toast notification
function showNotification(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type} border-0`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    
    const container = document.querySelector('.toast-container') || createToastContainer();
    container.appendChild(toast);
    
    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();
}

function createToastContainer() {
    const container = document.createElement('div');
    container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
    document.body.appendChild(container);
    return container;
}

// Keyboard navigation
document.addEventListener('keydown', (e) => {
    if (e.ctrlKey || e.metaKey) {
        switch(e.key) {
            case 'n':
                e.preventDefault();
                window.location.href = 'make-call.php';
                break;
            case 'r':
                e.preventDefault();
                refreshQueue();
                break;
            case 'h':
                e.preventDefault();
                window.location.href = 'call-history.php';
                break;
        }
    }
});

// Add swipe gestures for mobile
let touchStartX = 0;
let touchEndX = 0;

document.addEventListener('touchstart', e => {
    touchStartX = e.changedTouches[0].screenX;
}, { passive: true });

document.addEventListener('touchend', e => {
    touchEndX = e.changedTouches[0].screenX;
    handleSwipe();
}, { passive: true });

function handleSwipe() {
    if (touchEndX < touchStartX - 50) {
        // Swiped left - could open filters
        console.log('Swiped left');
    }
    if (touchEndX > touchStartX + 50) {
        // Swiped right - could go back
        console.log('Swiped right');
    }
}
</script>
</body>
</html>
