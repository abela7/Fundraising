<?php
declare(strict_types=1);

// Safe version - catches ALL errors and doesn't redirect
ini_set('display_errors', '0'); // Don't show PHP errors to user
error_reporting(E_ALL);

// Custom error handler to catch everything
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Call Center Error: [$errno] $errstr in $errfile:$errline");
    return false; // Let default handler also run
});

// Catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("Call Center Fatal Error: {$error['message']} in {$error['file']}:{$error['line']}");
        
        // Show user-friendly error
        http_response_code(500);
        echo "<!DOCTYPE html><html><head><title>Error</title></head><body>";
        echo "<h1>System Error</h1>";
        echo "<p>A fatal error occurred. Details have been logged.</p>";
        echo "<p>Error: " . htmlspecialchars($error['message']) . "</p>";
        echo "<p>File: " . htmlspecialchars($error['file']) . "</p>";
        echo "<p>Line: " . $error['line'] . "</p>";
        echo "</body></html>";
    }
});

try {
    // Try to load auth
    require_once __DIR__ . '/../../shared/auth.php';
    
    // Check if logged in - DON'T redirect, just show message
    if (!is_logged_in()) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Login Required</title>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
        </head>
        <body>
            <div class="container mt-5">
                <div class="row justify-content-center">
                    <div class="col-md-6">
                        <div class="card shadow">
                            <div class="card-body text-center p-5">
                                <i class="fas fa-lock fa-3x text-warning mb-3"></i>
                                <h2>Login Required</h2>
                                <p class="text-muted">You must be logged in to access the Call Center.</p>
                                <a href="../login.php" class="btn btn-primary btn-lg mt-3">
                                    <i class="fas fa-sign-in-alt me-2"></i>Login Now
                                </a>
                                <hr class="my-4">
                                <small class="text-muted">
                                    Session Status: <?php echo session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Inactive'; ?><br>
                                    Logged In: <?php echo is_logged_in() ? 'Yes' : 'No'; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        </body>
        </html>
        <?php
        exit;
    }
    
    // User is logged in - load database
    require_once __DIR__ . '/../../config/db.php';
    $db = db();
    
    $user_id = $_SESSION['user']['id'];
    $user_name = $_SESSION['user']['name'] ?? 'Agent';
    
    // Initialize defaults
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
    $error_message = null;
    $setup_needed = false;

    // Check if tables exist
    $tables_check = $db->query("SHOW TABLES LIKE 'call_center_sessions'");
    $tables_exist = $tables_check && $tables_check->num_rows > 0;

    if (!$tables_exist) {
        $setup_needed = true;
    } else {
        // Load actual data
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
            $row = $result->fetch_object();
            if ($row) {
                $today_stats = $row;
            }
            $stmt->close();
        }

        // Get queue
        $queue_query = "
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
        ";
        
        $stmt = $db->prepare($queue_query);
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $queue_result = $stmt->get_result();
            $stmt->close();
        }

        // Calculate conversion rate
        $conversion_rate = $today_stats->total_calls > 0 
            ? round(($today_stats->positive_outcomes / $today_stats->total_calls) * 100, 1) 
            : 0;
    }

} catch (Throwable $e) {
    error_log("Call Center Exception: " . $e->getMessage());
    $error_message = "Error: " . $e->getMessage();
    $today_stats = (object)['total_calls' => 0, 'successful_contacts' => 0, 'positive_outcomes' => 0, 'callbacks_scheduled' => 0, 'total_talk_time' => 0];
    $conversion_rate = 0;
    $queue_result = null;
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
                        Call Center Dashboard (Safe Mode)
                    </h1>
                    <p class="content-subtitle">This is a safe-mode version for debugging</p>
                </div>
            </div>

            <?php if ($error_message): ?>
            <div class="alert alert-danger">
                <h4><i class="fas fa-exclamation-triangle me-2"></i>Error</h4>
                <p><?php echo htmlspecialchars($error_message); ?></p>
            </div>
            <?php endif; ?>

            <?php if ($setup_needed): ?>
            <div class="alert alert-warning">
                <h4><i class="fas fa-exclamation-triangle me-2"></i>Setup Required</h4>
                <p>Call center tables need to be created.</p>
                <a href="check-database.php" class="btn btn-warning">Check Database</a>
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
                            <div class="stat-label">Conversion Rate</div>
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
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Donor</th>
                                        <th>Phone</th>
                                        <th>Balance</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($donor = $queue_result->fetch_object()): ?>
                                        <tr>
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
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

