<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$page_title = 'Call Center Dashboard';
$user_name = $_SESSION['user']['name'] ?? 'Agent';
$user_id = (int)$_SESSION['user']['id'];

// Initialize stats
$today_stats = (object)[
    'total_calls' => 0,
    'successful_contacts' => 0,
    'positive_outcomes' => 0,
    'callbacks_scheduled' => 0,
    'total_talk_time' => 0
];
$conversion_rate = 0;
$recent_calls = [];
$error_message = null;

try {
    $db = db();
    
    // Check tables exist
    $tables_check = $db->query("SHOW TABLES LIKE 'call_center_sessions'");
    if (!$tables_check || $tables_check->num_rows == 0) {
        // Don't throw exception immediately, UI will handle setup prompt if needed
        $tables_exist = false;
    } else {
        $tables_exist = true;
    }

    if ($tables_exist) {
        // 1. Get Today's Stats
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
        $stmt->bind_param('iss', $user_id, $today_start, $today_end);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_object()) {
            $today_stats = $row;
        }
        $stmt->close();

        // Calculate conversion rate
        if ($today_stats->total_calls > 0) {
            $conversion_rate = round(($today_stats->positive_outcomes / $today_stats->total_calls) * 100, 1);
        }

        // 2. Get Recent Activity
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
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_object()) {
            $recent_calls[] = $row;
        }
        $stmt->close();
    }

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
            <div class="container-fluid p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1 text-primary">
                            <i class="fas fa-headset me-2"></i>Call Center Dashboard
                        </h1>
                        <p class="text-muted mb-0">Welcome back, <?php echo htmlspecialchars($user_name); ?></p>
                    </div>
                    <div>
                        <a href="../donor-management/donors.php?balance=has_balance" class="btn btn-success btn-lg shadow-sm">
                            <i class="fas fa-phone-alt me-2"></i>Start Calling Donors
                        </a>
                    </div>
                </div>

                <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-primary bg-opacity-10 p-3 rounded-circle me-3">
                                        <i class="fas fa-phone text-primary fa-lg"></i>
                                    </div>
                                    <div>
                                        <div class="text-muted small text-uppercase fw-bold">Total Calls</div>
                                        <h2 class="mb-0 fw-bold"><?php echo (int)$today_stats->total_calls; ?></h2>
                                    </div>
                                </div>
                                <div class="progress" style="height: 4px;">
                                    <div class="progress-bar" style="width: 100%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-success bg-opacity-10 p-3 rounded-circle me-3">
                                        <i class="fas fa-check text-success fa-lg"></i>
                                    </div>
                                    <div>
                                        <div class="text-muted small text-uppercase fw-bold">Positive</div>
                                        <h2 class="mb-0 fw-bold"><?php echo (int)$today_stats->positive_outcomes; ?></h2>
                                    </div>
                                </div>
                                <div class="progress" style="height: 4px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $conversion_rate; ?>%"></div>
                                </div>
                                <small class="text-muted mt-2 d-block"><?php echo $conversion_rate; ?>% Conversion Rate</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-warning bg-opacity-10 p-3 rounded-circle me-3">
                                        <i class="fas fa-clock text-warning fa-lg"></i>
                                    </div>
                                    <div>
                                        <div class="text-muted small text-uppercase fw-bold">Talk Time</div>
                                        <h2 class="mb-0 fw-bold h4"><?php echo gmdate("H:i:s", (int)$today_stats->total_talk_time); ?></h2>
                                    </div>
                                </div>
                                <div class="progress" style="height: 4px;">
                                    <div class="progress-bar bg-warning" style="width: 100%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-info bg-opacity-10 p-3 rounded-circle me-3">
                                        <i class="fas fa-calendar-alt text-info fa-lg"></i>
                                    </div>
                                    <div>
                                        <div class="text-muted small text-uppercase fw-bold">Callbacks</div>
                                        <h2 class="mb-0 fw-bold"><?php echo (int)$today_stats->callbacks_scheduled; ?></h2>
                                    </div>
                                </div>
                                <div class="progress" style="height: 4px;">
                                    <div class="progress-bar bg-info" style="width: 100%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Left Column: Quick Actions -->
                    <div class="col-lg-4 mb-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white py-3">
                                <h5 class="mb-0"><i class="fas fa-bolt me-2 text-warning"></i>Quick Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-3">
                                    <a href="../donor-management/donors.php?balance=has_balance" class="btn btn-outline-primary p-3 text-start">
                                        <div class="d-flex align-items-center">
                                            <div class="bg-primary text-white rounded-circle p-2 me-3">
                                                <i class="fas fa-list"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold">Donor List</div>
                                                <div class="small text-muted">Filter and call donors</div>
                                            </div>
                                            <i class="fas fa-arrow-right ms-auto"></i>
                                        </div>
                                    </a>
                                    <a href="../donor-management/add-donor.php" class="btn btn-outline-success p-3 text-start">
                                        <div class="d-flex align-items-center">
                                            <div class="bg-success text-white rounded-circle p-2 me-3">
                                                <i class="fas fa-user-plus"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold">Add New Donor</div>
                                                <div class="small text-muted">Create record manually</div>
                                            </div>
                                            <i class="fas fa-arrow-right ms-auto"></i>
                                        </div>
                                    </a>
                                    <a href="call-history.php" class="btn btn-outline-secondary p-3 text-start">
                                        <div class="d-flex align-items-center">
                                            <div class="bg-secondary text-white rounded-circle p-2 me-3">
                                                <i class="fas fa-history"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold">Call History</div>
                                                <div class="small text-muted">View past calls</div>
                                            </div>
                                            <i class="fas fa-arrow-right ms-auto"></i>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Recent Activity -->
                    <div class="col-lg-8 mb-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-history me-2 text-secondary"></i>Recent Activity</h5>
                                <a href="call-history.php" class="btn btn-sm btn-outline-secondary">View All</a>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($recent_calls)): ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="fas fa-phone-slash fa-3x mb-3 opacity-25"></i>
                                        <p>No calls made yet today.</p>
                                        <a href="../donor-management/donors.php" class="btn btn-sm btn-primary mt-2">Make a Call</a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th>Time</th>
                                                    <th>Donor</th>
                                                    <th>Duration</th>
                                                    <th>Status</th>
                                                    <th>Outcome</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_calls as $call): ?>
                                                <tr>
                                                    <td><?php echo date('H:i', strtotime($call->call_started_at)); ?></td>
                                                    <td>
                                                        <div class="fw-bold"><?php echo htmlspecialchars($call->name); ?></div>
                                                        <div class="small text-muted"><?php echo htmlspecialchars($call->phone); ?></div>
                                                    </td>
                                                    <td>
                                                        <?php echo gmdate("i:s", (int)$call->duration_seconds); ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light text-dark border">
                                                            <?php echo ucfirst(str_replace('_', ' ', $call->conversation_stage ?? 'unknown')); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $outcomeClass = match($call->outcome) {
                                                            'payment_plan_created' => 'success',
                                                            'agreed_to_pay_full' => 'success',
                                                            'no_answer' => 'danger',
                                                            'busy' => 'warning',
                                                            default => 'secondary'
                                                        };
                                                        ?>
                                                        <span class="badge bg-<?php echo $outcomeClass; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $call->outcome ?? '-')); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="call-history.php?donor_id=<?php echo $call->donor_id; ?>" class="btn btn-sm btn-light text-primary">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
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