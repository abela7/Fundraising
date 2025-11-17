<?php
declare(strict_types=1);

// Turn on ALL error reporting for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Log the exact point we're at
error_log("[CALL CENTER DEBUG] Starting index-debug.php");

try {
    error_log("[CALL CENTER DEBUG] About to require auth.php");
    require_once __DIR__ . '/../../shared/auth.php';
    error_log("[CALL CENTER DEBUG] auth.php loaded successfully");
    
    error_log("[CALL CENTER DEBUG] About to require db.php");
    require_once __DIR__ . '/../../config/db.php';
    error_log("[CALL CENTER DEBUG] db.php loaded successfully");
    
    error_log("[CALL CENTER DEBUG] About to call require_login()");
    require_login();
    error_log("[CALL CENTER DEBUG] require_login() passed");
    
    // Copy the rest of index.php exactly...
    // Try to get database connection
    try {
        $db = db();
    } catch (Exception $db_exception) {
        throw new Exception('Database connection failed: ' . $db_exception->getMessage(), 0, $db_exception);
    }
    
    // Get user data from session (auth system uses $_SESSION['user'] array)
    // require_login() already checked if user is logged in, so we can safely access
    if (!isset($_SESSION['user']) || !isset($_SESSION['user']['id'])) {
        // This shouldn't happen if require_login() worked, but just in case
        throw new Exception('User session data not found. Please login again.');
    }
    
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
        // Tables don't exist - show setup message
        $setup_needed = true;
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
    } else {
        $setup_needed = false;
        
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
        } else {
            $queue_result = null;
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
        } else {
            $callbacks_result = null;
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
        } else {
            $recent_result = null;
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

    // Set conversion rate (works for both setup and normal mode)
    $conversion_rate = isset($today_stats->total_calls) && (int)$today_stats->total_calls > 0 
        ? round(((int)$today_stats->positive_outcomes / (int)$today_stats->total_calls) * 100, 1) 
        : 0;

} catch (mysqli_sql_exception $e) {
    // Handle MySQLi specific exceptions
    error_log("Call Center MySQLi Error: " . $e->getMessage() . " | Code: " . $e->getCode());
    die("MySQLi Error: " . $e->getMessage() . " | Code: " . $e->getCode());
} catch (Exception $e) {
    // If anything else goes wrong, show error
    error_log("Call Center Error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    die("Error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
} catch (Throwable $e) {
    // Catch EVERYTHING including fatal errors
    error_log("Call Center Throwable: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    die("Throwable: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
}

error_log("[CALL CENTER DEBUG] All PHP logic completed successfully, about to output HTML");

$page_title = 'Call Center Dashboard';

// Continue with exact HTML from index.php but we'll stop here if there's an error
echo "<!DOCTYPE html>\n";
echo "<!-- DEBUG: Got to HTML output! -->\n";

// Include the rest of the HTML...
?>
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
<div class="alert alert-info m-3">
    <h4>Debug Mode Active</h4>
    <p>This is index-debug.php - if you see this, PHP execution completed successfully!</p>
    <ul>
        <li>User ID: <?php echo $user_id; ?></li>
        <li>User Name: <?php echo htmlspecialchars($user_name); ?></li>
        <li>Tables Exist: <?php echo $tables_exist ? 'Yes' : 'No'; ?></li>
        <li>Setup Needed: <?php echo $setup_needed ? 'Yes' : 'No'; ?></li>
        <li>Queue Results: <?php echo $queue_result ? $queue_result->num_rows . ' rows' : 'null'; ?></li>
    </ul>
</div>

<div class="admin-wrapper">
    <?php 
    error_log("[CALL CENTER DEBUG] About to include sidebar.php");
    include '../includes/sidebar.php'; 
    error_log("[CALL CENTER DEBUG] sidebar.php included successfully");
    ?>
    
    <div class="admin-content">
        <?php 
        error_log("[CALL CENTER DEBUG] About to include topbar.php");
        include '../includes/topbar.php'; 
        error_log("[CALL CENTER DEBUG] topbar.php included successfully");
        ?>
        
        <main class="main-content">
            <div class="content-header">
                <div>
                    <h1 class="content-title">
                        <i class="fas fa-headset me-2"></i>
                        Call Center Dashboard (DEBUG)
                    </h1>
                </div>
            </div>
            
            <p>If you see this page, the problem is somewhere in the HTML rendering, not in PHP logic!</p>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/call-center.js"></script>
</body>
</html>
