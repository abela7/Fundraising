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
    
    // Get filter parameters
    $search = trim($_GET['search'] ?? '');
    $city_filter = trim($_GET['city'] ?? '');
    $payment_status_filter = trim($_GET['payment_status'] ?? '');
    $donor_type_filter = trim($_GET['donor_type'] ?? '');
    $payment_method_filter = trim($_GET['payment_method'] ?? '');
    
    // Handle balance filters
    $balance_min = null;
    if (isset($_GET['balance_min']) && $_GET['balance_min'] !== '') {
        $val = (float)$_GET['balance_min'];
        if ($val > 0) {
            $balance_min = $val;
        }
    }
    
    $balance_max = null;
    if (isset($_GET['balance_max']) && $_GET['balance_max'] !== '') {
        $val = (float)$_GET['balance_max'];
        if ($val > 0) {
            $balance_max = $val;
        }
    }
    
    // Handle total pledged filters
    $pledged_min = null;
    if (isset($_GET['pledged_min']) && $_GET['pledged_min'] !== '') {
        $val = (float)$_GET['pledged_min'];
        if ($val > 0) {
            $pledged_min = $val;
        }
    }
    
    $pledged_max = null;
    if (isset($_GET['pledged_max']) && $_GET['pledged_max'] !== '') {
        $val = (float)$_GET['pledged_max'];
        if ($val > 0) {
            $pledged_max = $val;
        }
    }
    
    // Handle total paid filters
    $paid_min = null;
    if (isset($_GET['paid_min']) && $_GET['paid_min'] !== '') {
        $val = (float)$_GET['paid_min'];
        if ($val >= 0) {
            $paid_min = $val;
        }
    }
    
    $paid_max = null;
    if (isset($_GET['paid_max']) && $_GET['paid_max'] !== '') {
        $val = (float)$_GET['paid_max'];
        if ($val > 0) {
            $paid_max = $val;
        }
    }
    
    // Pagination
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 25;
    $offset = ($page - 1) * $per_page;
    
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
    $total_count = 0;
    $total_pages = 1;
    $queue_types_list = [];
    $cities_list = [];
    
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
        
        // SIMPLE APPROACH: Just show all donors with balance
        // Build simple query
        $where_parts = ["d.balance > 0", "d.donor_type = 'pledge'"];
        $params = [];
        $types = '';
        
        if (!empty($search)) {
            $where_parts[] = "(d.name LIKE ? OR d.phone LIKE ?)";
            $search_param = "%{$search}%";
            $params[] = $search_param;
            $params[] = $search_param;
            $types .= 'ss';
        }
        
        if (!empty($city_filter)) {
            $where_parts[] = "d.city = ?";
            $params[] = $city_filter;
            $types .= 's';
        }
        
        if (!empty($payment_status_filter)) {
            $where_parts[] = "d.payment_status = ?";
            $params[] = $payment_status_filter;
            $types .= 's';
        }
        
        if (!empty($donor_type_filter)) {
            $where_parts[] = "d.donor_type = ?";
            $params[] = $donor_type_filter;
            $types .= 's';
        }
        
        if (!empty($payment_method_filter)) {
            $where_parts[] = "d.preferred_payment_method = ?";
            $params[] = $payment_method_filter;
            $types .= 's';
        }
        
        if ($balance_min !== null && $balance_min > 0) {
            $where_parts[] = "d.balance >= ?";
            $params[] = $balance_min;
            $types .= 'd';
        }
        
        if ($balance_max !== null && $balance_max > 0) {
            $where_parts[] = "d.balance <= ?";
            $params[] = $balance_max;
            $types .= 'd';
        }
        
        if ($pledged_min !== null && $pledged_min > 0) {
            $where_parts[] = "d.total_pledged >= ?";
            $params[] = $pledged_min;
            $types .= 'd';
        }
        
        if ($pledged_max !== null && $pledged_max > 0) {
            $where_parts[] = "d.total_pledged <= ?";
            $params[] = $pledged_max;
            $types .= 'd';
        }
        
        if ($paid_min !== null && $paid_min >= 0) {
            $where_parts[] = "d.total_paid >= ?";
            $params[] = $paid_min;
            $types .= 'd';
        }
        
        if ($paid_max !== null && $paid_max > 0) {
            $where_parts[] = "d.total_paid <= ?";
            $params[] = $paid_max;
            $types .= 'd';
        }
        
        $where_clause = implode(' AND ', $where_parts);
        
        // Get total count
        $count_query = "SELECT COUNT(*) as total FROM donors d WHERE {$where_clause}";
        $count_stmt = $db->prepare($count_query);
        if ($count_stmt && !empty($params)) {
            $count_stmt->bind_param($types, ...$params);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            if ($count_result) {
                $row = $count_result->fetch_assoc();
                $total_count = (int)$row['total'];
                $total_pages = max(1, (int)ceil($total_count / $per_page));
            }
            $count_stmt->close();
        } elseif ($count_stmt && empty($params)) {
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            if ($count_result) {
                $row = $count_result->fetch_assoc();
                $total_count = (int)$row['total'];
                $total_pages = max(1, (int)ceil($total_count / $per_page));
            }
            $count_stmt->close();
        } else {
            $total_count = 0;
            $total_pages = 1;
        }
        
        // Get donors with queue info
        $params[] = $per_page;
        $params[] = $offset;
        $types .= 'ii';
        
        $queue_query = "
            SELECT 
                q.id as queue_id,
                d.id as donor_id,
                COALESCE(q.queue_type, 'not_in_queue') as queue_type,
                COALESCE(q.priority, 5) as priority,
                COALESCE(q.attempts_count, 0) as attempts_count,
                q.next_attempt_after,
                q.reason_for_queue,
                q.preferred_contact_time,
                d.name,
                d.phone,
                d.balance,
                d.city,
                d.last_contacted_at
            FROM donors d
            LEFT JOIN call_center_queues q ON q.donor_id = d.id AND q.status = 'pending'
            WHERE {$where_clause}
            ORDER BY d.balance DESC, d.created_at ASC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $db->prepare($queue_query);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $queue_result = $stmt->get_result();
            $stmt->close();
        }
        
        // Get filter options
        $queue_types_list = [];
        $cities_list = [];
        
        $types_query = $db->query("SELECT DISTINCT queue_type FROM call_center_queues WHERE queue_type IS NOT NULL ORDER BY queue_type");
        if ($types_query) {
            while ($row = $types_query->fetch_assoc()) {
                $queue_types_list[] = $row['queue_type'];
            }
        }
        
        $cities_query = $db->query("SELECT DISTINCT d.city FROM call_center_queues q JOIN donors d ON q.donor_id = d.id WHERE d.city IS NOT NULL AND d.city != '' AND q.status = 'pending' ORDER BY d.city");
        if ($cities_query) {
            while ($row = $cities_query->fetch_assoc()) {
                $cities_list[] = $row['city'];
            }
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
    $search = '';
    $queue_type_filter = '';
    $priority_filter = 0;
    $city_filter = '';
    $balance_min = null;
    $balance_max = null;
    $attempts_min = null;
    $attempts_max = null;
    $page = 1;
    $per_page = 25;
    $total_count = 0;
    $total_pages = 1;
    $queue_types_list = [];
    $cities_list = [];
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

            <!-- Today's Statistics - Compact -->
            <div class="row g-2 mb-3">
                <div class="col-6 col-md-3">
                    <div class="stat-card stat-primary">
                        <div class="stat-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div class="stat-details">
                            <div class="stat-value"><?php echo (int)($today_stats->total_calls ?? 0); ?></div>
                            <div class="stat-label">Calls</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
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
                <div class="col-6 col-md-3">
                    <div class="stat-card stat-info">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-details">
                            <div class="stat-value"><?php echo $conversion_rate; ?>%</div>
                            <div class="stat-label">Conversion</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card stat-warning">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-details">
                            <div class="stat-value"><?php echo gmdate("H:i", (int)($today_stats->total_talk_time ?? 0)); ?></div>
                            <div class="stat-label">Time</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters Panel -->
            <div class="card mb-4" id="filterPanel" style="display: none;">
                <div class="card-header bg-light">
                    <h6 class="mb-0">
                        <i class="fas fa-filter me-2"></i>Filter Queue
                    </h6>
                </div>
                <div class="card-body">
                    <form method="GET" action="index.php" class="row g-3">
                        <!-- Search -->
                        <div class="col-md-3">
                            <label class="form-label small">Search</label>
                            <input type="text" 
                                   name="search" 
                                   class="form-control form-control-sm" 
                                   placeholder="Name or phone..."
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <!-- Payment Status -->
                        <div class="col-md-2">
                            <label class="form-label small">Payment Status</label>
                            <select name="payment_status" class="form-select form-select-sm">
                                <option value="">All Statuses</option>
                                <option value="no_pledge" <?php echo $payment_status_filter === 'no_pledge' ? 'selected' : ''; ?>>No Pledge</option>
                                <option value="not_started" <?php echo $payment_status_filter === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                <option value="paying" <?php echo $payment_status_filter === 'paying' ? 'selected' : ''; ?>>Paying</option>
                                <option value="overdue" <?php echo $payment_status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                <option value="completed" <?php echo $payment_status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="defaulted" <?php echo $payment_status_filter === 'defaulted' ? 'selected' : ''; ?>>Defaulted</option>
                            </select>
                        </div>
                        
                        <!-- Donor Type -->
                        <div class="col-md-2">
                            <label class="form-label small">Donor Type</label>
                            <select name="donor_type" class="form-select form-select-sm">
                                <option value="">All Types</option>
                                <option value="pledge" <?php echo $donor_type_filter === 'pledge' ? 'selected' : ''; ?>>Pledge</option>
                                <option value="immediate_payment" <?php echo $donor_type_filter === 'immediate_payment' ? 'selected' : ''; ?>>Immediate Payment</option>
                            </select>
                        </div>
                        
                        <!-- Payment Method -->
                        <div class="col-md-2">
                            <label class="form-label small">Payment Method</label>
                            <select name="payment_method" class="form-select form-select-sm">
                                <option value="">All Methods</option>
                                <option value="cash" <?php echo $payment_method_filter === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="bank_transfer" <?php echo $payment_method_filter === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="card" <?php echo $payment_method_filter === 'card' ? 'selected' : ''; ?>>Card</option>
                            </select>
                        </div>
                        
                        <!-- City -->
                        <div class="col-md-2">
                            <label class="form-label small">City</label>
                            <select name="city" class="form-select form-select-sm">
                                <option value="">All Cities</option>
                                <?php foreach ($cities_list as $city): ?>
                                    <option value="<?php echo htmlspecialchars($city); ?>" 
                                            <?php echo $city_filter === $city ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($city); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Balance Range -->
                        <div class="col-md-3">
                            <label class="form-label small">Balance Range (£)</label>
                            <div class="input-group input-group-sm">
                                <input type="number" 
                                       name="balance_min" 
                                       class="form-control" 
                                       placeholder="Min"
                                       step="0.01"
                                       value="<?php echo $balance_min !== null ? htmlspecialchars($balance_min) : ''; ?>">
                                <span class="input-group-text">-</span>
                                <input type="number" 
                                       name="balance_max" 
                                       class="form-control" 
                                       placeholder="Max"
                                       step="0.01"
                                       value="<?php echo $balance_max !== null ? htmlspecialchars($balance_max) : ''; ?>">
                            </div>
                        </div>
                        
                        <!-- Total Pledged Range -->
                        <div class="col-md-3">
                            <label class="form-label small">Total Pledged Range (£)</label>
                            <div class="input-group input-group-sm">
                                <input type="number" 
                                       name="pledged_min" 
                                       class="form-control" 
                                       placeholder="Min"
                                       step="0.01"
                                       value="<?php echo $pledged_min !== null ? htmlspecialchars($pledged_min) : ''; ?>">
                                <span class="input-group-text">-</span>
                                <input type="number" 
                                       name="pledged_max" 
                                       class="form-control" 
                                       placeholder="Max"
                                       step="0.01"
                                       value="<?php echo $pledged_max !== null ? htmlspecialchars($pledged_max) : ''; ?>">
                            </div>
                        </div>
                        
                        <!-- Total Paid Range -->
                        <div class="col-md-3">
                            <label class="form-label small">Total Paid Range (£)</label>
                            <div class="input-group input-group-sm">
                                <input type="number" 
                                       name="paid_min" 
                                       class="form-control" 
                                       placeholder="Min"
                                       step="0.01"
                                       value="<?php echo $paid_min !== null ? htmlspecialchars($paid_min) : ''; ?>">
                                <span class="input-group-text">-</span>
                                <input type="number" 
                                       name="paid_max" 
                                       class="form-control" 
                                       placeholder="Max"
                                       step="0.01"
                                       value="<?php echo $paid_max !== null ? htmlspecialchars($paid_max) : ''; ?>">
                            </div>
                        </div>
                        
                        <!-- Filter Buttons -->
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-filter me-1"></i>Apply Filters
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-times me-1"></i>Clear All
                            </a>
                            <?php if ($search || $city_filter || $payment_status_filter || $donor_type_filter || $payment_method_filter || $balance_min !== null || $balance_max !== null || $pledged_min !== null || $pledged_max !== null || $paid_min !== null || $paid_max !== null): ?>
                                <span class="badge bg-info ms-2">
                                    <?php echo number_format($total_count); ?> result<?php echo $total_count != 1 ? 's' : ''; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="row g-4">
                <!-- Call Queue -->
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-list-check me-2"></i>Call Queue
                                <span class="badge bg-primary ms-2"><?php echo number_format($total_count); ?></span>
                            </h5>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-secondary" onclick="toggleFilters()">
                                    <i class="fas fa-filter me-1"></i>Filters
                                </button>
                                <button class="btn btn-sm btn-primary" onclick="location.reload()">
                                    <i class="fas fa-sync-alt me-1"></i>Refresh
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <?php if ($queue_result && $queue_result->num_rows > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Priority</th>
                                                <th>Donor</th>
                                                <th>Payment</th>
                                                <th>Type</th>
                                                <th>Attempts</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($donor = $queue_result->fetch_object()): ?>
                                                <tr>
                                                    <td data-label="Priority">
                                                        <span class="priority-badge priority-<?php echo (int)$donor->priority >= 8 ? 'urgent' : ((int)$donor->priority >= 5 ? 'high' : 'normal'); ?>">
                                                            <?php echo htmlspecialchars((string)$donor->priority); ?>
                                                        </span>
                                                    </td>
                                                    <td data-label="Donor">
                                                        <div class="donor-info">
                                                            <div class="donor-name"><?php echo htmlspecialchars($donor->name); ?></div>
                                                            <small class="text-muted d-block">
                                                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor->phone); ?>
                                                            </small>
                                                            <?php if (!empty($donor->city)): ?>
                                                                <small class="text-muted d-block">
                                                                    <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($donor->city); ?>
                                                                </small>
                                                            <?php endif; ?>
                                                            <?php if (!empty($donor->last_contacted_at)): ?>
                                                                <small class="text-muted d-block">
                                                                    <i class="fas fa-clock me-1"></i>Last: <?php echo date('M j', strtotime($donor->last_contacted_at)); ?>
                                                                </small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td data-label="Payment">
                                                        <span class="badge bg-danger">£<?php echo number_format((float)$donor->balance, 2); ?></span>
                                                    </td>
                                                    <td data-label="Type">
                                                        <span class="badge bg-secondary"><?php echo htmlspecialchars(str_replace('_', ' ', ucwords($donor->queue_type, '_'))); ?></span>
                                                    </td>
                                                    <td data-label="Attempts">
                                                        <span class="badge bg-info"><?php echo (int)$donor->attempts_count; ?> calls</span>
                                                    </td>
                                                    <td data-label="Action">
                                                        <?php if (!empty($donor->queue_id) && $donor->queue_id > 0): ?>
                                                            <a href="make-call.php?donor_id=<?php echo (int)$donor->donor_id; ?>&queue_id=<?php echo (int)$donor->queue_id; ?>" 
                                                               class="btn btn-primary btn-sm">
                                                                <i class="fas fa-phone-alt me-1"></i>Call
                                                            </a>
                                                        <?php else: ?>
                                                            <button type="button" 
                                                                    class="btn btn-success btn-sm" 
                                                                    onclick="showAddToQueueModal(<?php echo (int)$donor->donor_id; ?>, '<?php echo htmlspecialchars(addslashes($donor->name)); ?>', <?php echo (float)$donor->balance; ?>)">
                                                                <i class="fas fa-plus me-1"></i>Add to Queue
                                                            </button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                <div class="card-footer">
                                    <nav aria-label="Queue pagination">
                                        <ul class="pagination pagination-sm justify-content-center mb-0">
                                            <?php
                                            $query_params = $_GET;
                                            
                                            // Previous
                                            if ($page > 1):
                                                $query_params['page'] = $page - 1;
                                                $prev_url = '?' . http_build_query($query_params);
                                            ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="<?php echo htmlspecialchars($prev_url); ?>">
                                                        <i class="fas fa-chevron-left"></i> Previous
                                                    </a>
                                                </li>
                                            <?php else: ?>
                                                <li class="page-item disabled">
                                                    <span class="page-link">
                                                        <i class="fas fa-chevron-left"></i> Previous
                                                    </span>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php
                                            // Page numbers
                                            $start_page = max(1, $page - 2);
                                            $end_page = min($total_pages, $page + 2);
                                            
                                            if ($start_page > 1):
                                                $query_params['page'] = 1;
                                            ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="<?php echo htmlspecialchars('?' . http_build_query($query_params)); ?>">1</a>
                                                </li>
                                                <?php if ($start_page > 2): ?>
                                                    <li class="page-item disabled">
                                                        <span class="page-link">...</span>
                                                    </li>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                                <?php
                                                $query_params['page'] = $i;
                                                $page_url = '?' . http_build_query($query_params);
                                                ?>
                                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                                    <a class="page-link" href="<?php echo htmlspecialchars($page_url); ?>">
                                                        <?php echo $i; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>
                                            
                                            <?php
                                            if ($end_page < $total_pages):
                                                $query_params['page'] = $total_pages;
                                            ?>
                                                <?php if ($end_page < $total_pages - 1): ?>
                                                    <li class="page-item disabled">
                                                        <span class="page-link">...</span>
                                                    </li>
                                                <?php endif; ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="<?php echo htmlspecialchars('?' . http_build_query($query_params)); ?>">
                                                        <?php echo $total_pages; ?>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php
                                            // Next
                                            if ($page < $total_pages):
                                                $query_params['page'] = $page + 1;
                                                $next_url = '?' . http_build_query($query_params);
                                            ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="<?php echo htmlspecialchars($next_url); ?>">
                                                        Next <i class="fas fa-chevron-right"></i>
                                                    </a>
                                                </li>
                                            <?php else: ?>
                                                <li class="page-item disabled">
                                                    <span class="page-link">
                                                        Next <i class="fas fa-chevron-right"></i>
                                                    </span>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                    <div class="text-center text-muted small mt-2">
                                        Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format(min($offset + $per_page, $total_count)); ?> 
                                        of <?php echo number_format($total_count); ?> donors in queue
                                    </div>
                                </div>
                                <?php endif; ?>
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

/* View Button Styling */
.table .btn-primary {
    white-space: nowrap;
    min-width: 80px;
}

@media (max-width: 767px) {
    .table .btn-primary {
        font-size: 0.875rem;
        padding: 0.25rem 0.5rem;
        min-width: 70px;
    }
    
    .table .btn-primary .fas {
        font-size: 0.75rem;
    }
    
    /* Filter Panel Mobile */
    #filterPanel .card-body .row.g-3 > div {
        margin-bottom: 0.5rem;
    }
    
    #filterPanel .input-group-sm {
        flex-wrap: nowrap;
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

// Toggle Filters Panel
function toggleFilters() {
    const panel = document.getElementById('filterPanel');
    if (panel) {
        if (panel.style.display === 'none' || panel.style.display === '') {
            panel.style.display = 'block';
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            panel.style.display = 'none';
        }
    }
}

// Show filters if there are active filters
<?php if ($search || $city_filter || $payment_status_filter || $donor_type_filter || $payment_method_filter || $balance_min !== null || $balance_max !== null || $pledged_min !== null || $pledged_max !== null || $paid_min !== null || $paid_max !== null): ?>
document.addEventListener('DOMContentLoaded', function() {
    const panel = document.getElementById('filterPanel');
    if (panel) {
        panel.style.display = 'block';
    }
});
<?php endif; ?>

// Add to Queue Modal
function showAddToQueueModal(donorId, donorName, balance) {
    document.getElementById('addQueueDonorId').value = donorId;
    document.getElementById('addQueueDonorName').textContent = donorName;
    document.getElementById('addQueueBalance').textContent = '£' + parseFloat(balance).toFixed(2);
    
    const modal = new bootstrap.Modal(document.getElementById('addToQueueModal'));
    modal.show();
}

function addToQueue() {
    const donorId = document.getElementById('addQueueDonorId').value;
    const queueType = document.getElementById('addQueueType').value;
    const priority = document.getElementById('addQueuePriority').value;
    const reason = document.getElementById('addQueueReason').value;
    
    if (!queueType) {
        alert('Please select a queue type');
        return;
    }
    
    // Disable button
    const btn = document.getElementById('addQueueBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Adding...';
    
    // Send AJAX request
    fetch('add-to-queue.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            donor_id: donorId,
            queue_type: queueType,
            priority: priority,
            reason: reason
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('addToQueueModal'));
            modal.hide();
            
            // Show success message
            alert('Donor added to queue successfully!');
            
            // Reload page
            window.location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to add donor to queue'));
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-plus me-1"></i>Add to Queue';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error: Failed to add donor to queue');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plus me-1"></i>Add to Queue';
    });
}

// Prevent form submission issues
document.addEventListener('DOMContentLoaded', function() {
    const filterForm = document.querySelector('#filterPanel form');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            // Remove empty inputs to avoid sending empty values
            const inputs = filterForm.querySelectorAll('input[type="number"]');
            inputs.forEach(function(input) {
                if (input.value === '' || input.value === null) {
                    input.disabled = true;
                }
            });
        });
    }
});
</script>

<!-- Add to Queue Modal -->
<div class="modal fade" id="addToQueueModal" tabindex="-1" aria-labelledby="addToQueueModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addToQueueModalLabel">
                    <i class="fas fa-plus-circle me-2"></i>Add Donor to Queue
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="addQueueDonorId">
                
                <div class="mb-3">
                    <strong>Donor:</strong> <span id="addQueueDonorName"></span><br>
                    <strong>Balance:</strong> <span id="addQueueBalance" class="text-danger"></span>
                </div>
                
                <div class="mb-3">
                    <label for="addQueueType" class="form-label">Queue Type <span class="text-danger">*</span></label>
                    <select class="form-select" id="addQueueType" required>
                        <option value="">Select Queue Type</option>
                        <option value="new_pledge">New Pledge</option>
                        <option value="overdue_pledges">Overdue Pledges</option>
                        <option value="follow_up">Follow Up</option>
                        <option value="callback">Callback</option>
                        <option value="payment_discussion">Payment Discussion</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label for="addQueuePriority" class="form-label">Priority</label>
                    <select class="form-select" id="addQueuePriority">
                        <option value="5">Normal (5)</option>
                        <option value="6">Above Normal (6)</option>
                        <option value="7">High (7)</option>
                        <option value="8">Urgent (8)</option>
                        <option value="9">Very Urgent (9)</option>
                        <option value="10">Critical (10)</option>
                    </select>
                    <small class="text-muted">Higher number = higher priority</small>
                </div>
                
                <div class="mb-3">
                    <label for="addQueueReason" class="form-label">Reason for Queue</label>
                    <textarea class="form-control" id="addQueueReason" rows="3" placeholder="Optional: Add reason for adding to queue..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="addQueueBtn" onclick="addToQueue()">
                    <i class="fas fa-plus me-1"></i>Add to Queue
                </button>
            </div>
        </div>
    </div>
</div>

</body>
</html>
