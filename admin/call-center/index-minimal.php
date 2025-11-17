<?php
declare(strict_types=1);

// VERSION 1: Absolute minimal - just auth and login check
try {
    require_once __DIR__ . '/../../shared/auth.php';
    require_once __DIR__ . '/../../config/db.php';
    require_login(); // This will redirect if not logged in
    
    // If we get here, user is logged in
    $user = $_SESSION['user'];
    $user_id = (int)$user['id'];
    $user_name = $user['name'] ?? 'Agent';
    
} catch (Throwable $e) {
    die("Init Error: " . $e->getMessage());
}

// VERSION 2: Try to get database
try {
    $db = db();
} catch (Throwable $e) {
    die("DB Error: " . $e->getMessage());
}

// VERSION 3: Initialize variables
$today_stats = (object)[
    'total_calls' => 0,
    'successful_contacts' => 0,
    'positive_outcomes' => 0,
    'callbacks_scheduled' => 0,
    'total_talk_time' => 0
];
$queue_result = null;
$conversion_rate = 0;

// VERSION 4: Check if tables exist
try {
    $tables_check = $db->query("SHOW TABLES LIKE 'call_center_sessions'");
    $tables_exist = $tables_check && $tables_check->num_rows > 0;
    
    if (!$tables_exist) {
        $setup_needed = true;
    } else {
        $setup_needed = false;
        
        // VERSION 5: Get today's stats
        $today_start = date('Y-m-d 00:00:00');
        $today_end = date('Y-m-d 23:59:59');
        
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_calls,
                SUM(CASE WHEN conversation_stage != 'no_connection' THEN 1 ELSE 0 END) as successful_contacts,
                SUM(CASE WHEN outcome IN ('payment_plan_created', 'agreed_to_pay_full', 'agreed_reduced_amount', 'agreed_cash_collection') THEN 1 ELSE 0 END) as positive_outcomes,
                SUM(CASE WHEN callback_scheduled_for IS NOT NULL THEN 1 ELSE 0 END) as callbacks_scheduled,
                SUM(duration_seconds) as total_talk_time
            FROM call_center_sessions
            WHERE agent_id = ? AND call_started_at BETWEEN ? AND ?
        ");
        
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
        
        // VERSION 6: Get queue
        $stmt = $db->prepare("
            SELECT 
                q.id as queue_id,
                q.donor_id,
                q.queue_type,
                q.priority,
                q.attempts_count,
                d.name,
                d.phone,
                d.balance,
                d.city
            FROM call_center_queues q
            JOIN donors d ON q.donor_id = d.id
            WHERE q.status = 'pending' 
                AND (q.assigned_to IS NULL OR q.assigned_to = ?)
                AND (q.next_attempt_after IS NULL OR q.next_attempt_after <= NOW())
            ORDER BY q.priority DESC
            LIMIT 50
        ");
        
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $queue_result = $stmt->get_result();
            $stmt->close();
        }
        
        // Calculate conversion
        $conversion_rate = $today_stats->total_calls > 0 
            ? round(($today_stats->positive_outcomes / $today_stats->total_calls) * 100, 1) 
            : 0;
    }
    
} catch (Throwable $e) {
    die("Query Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
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
    <?php 
    // VERSION 7: Include sidebar
    try {
        include __DIR__ . '/../includes/sidebar.php';
    } catch (Throwable $e) {
        echo "<div style='background:red;color:white;padding:10px;'>Sidebar Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    ?>
    
    <div class="admin-content">
        <?php 
        // VERSION 8: Include topbar
        try {
            include __DIR__ . '/../includes/topbar.php';
        } catch (Throwable $e) {
            echo "<div style='background:red;color:white;padding:10px;'>Topbar Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        ?>
        
        <main class="main-content">
            <div class="content-header">
                <div>
                    <h1 class="content-title">
                        <i class="fas fa-headset me-2"></i>
                        Call Center Dashboard (Minimal)
                    </h1>
                    <p class="content-subtitle">Minimal version with step-by-step error checking</p>
                </div>
            </div>

            <?php if (isset($setup_needed) && $setup_needed): ?>
            <div class="alert alert-warning">
                <h4><i class="fas fa-exclamation-triangle me-2"></i>Setup Required</h4>
                <p>Call center tables need to be created.</p>
            </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card stat-primary">
                        <div class="stat-icon"><i class="fas fa-phone"></i></div>
                        <div class="stat-details">
                            <div class="stat-value"><?php echo $today_stats->total_calls ?? 0; ?></div>
                            <div class="stat-label">Calls Today</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card stat-success">
                        <div class="stat-icon"><i class="fas fa-phone-volume"></i></div>
                        <div class="stat-details">
                            <div class="stat-value"><?php echo $today_stats->successful_contacts ?? 0; ?></div>
                            <div class="stat-label">Connected</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card stat-info">
                        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-details">
                            <div class="stat-value"><?php echo $conversion_rate; ?>%</div>
                            <div class="stat-label">Conversion</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card stat-warning">
                        <div class="stat-icon"><i class="fas fa-clock"></i></div>
                        <div class="stat-details">
                            <div class="stat-value"><?php echo gmdate("H:i", $today_stats->total_talk_time ?? 0); ?></div>
                            <div class="stat-label">Talk Time</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Queue -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-list-check me-2"></i>Call Queue</h5>
                </div>
                <div class="card-body">
                    <?php if ($queue_result && $queue_result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Priority</th>
                                        <th>Donor</th>
                                        <th>Phone</th>
                                        <th>Balance</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($donor = $queue_result->fetch_object()): ?>
                                        <tr>
                                            <td><span class="badge bg-warning"><?php echo $donor->priority; ?></span></td>
                                            <td><?php echo htmlspecialchars($donor->name); ?></td>
                                            <td><?php echo htmlspecialchars($donor->phone); ?></td>
                                            <td>£<?php echo number_format((float)$donor->balance, 2); ?></td>
                                            <td>
                                                <a href="make-call.php?donor_id=<?php echo $donor->donor_id; ?>" class="btn btn-sm btn-success">
                                                    <i class="fas fa-phone"></i> Call
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center py-4">No donors in queue</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="alert alert-info mt-4">
                <h5>Debug Info:</h5>
                <ul>
                    <li>User ID: <?php echo $user_id; ?></li>
                    <li>User Name: <?php echo htmlspecialchars($user_name); ?></li>
                    <li>Tables Exist: <?php echo $tables_exist ? 'Yes' : 'No'; ?></li>
                    <li>Stats Object: <?php echo isset($today_stats) ? 'Set' : 'Not Set'; ?></li>
                    <li>Queue Rows: <?php echo $queue_result ? $queue_result->num_rows : 'NULL'; ?></li>
                </ul>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/call-center.js"></script>
</body>
</html>

