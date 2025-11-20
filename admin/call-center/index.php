<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$db = db();
$user_id = (int)$_SESSION['user']['id'];
$user_name = $_SESSION['user']['name'] ?? 'Agent';

// Get today's stats
$today_start = date('Y-m-d 00:00:00');
$today_end = date('Y-m-d 23:59:59');

$today_stats = [
    'total_calls' => 0,
    'successful_contacts' => 0,
    'payment_plans_created' => 0,
    'total_talk_time' => 0
];

// Check if call_center_sessions table exists
$tables_check = $db->query("SHOW TABLES LIKE 'call_center_sessions'");
if ($tables_check && $tables_check->num_rows > 0) {
    $stats_query = "
        SELECT 
            COUNT(*) as total_calls,
            SUM(CASE WHEN conversation_stage != 'no_connection' THEN 1 ELSE 0 END) as successful_contacts,
            SUM(CASE WHEN outcome = 'payment_plan_created' THEN 1 ELSE 0 END) as payment_plans_created,
            SUM(duration_seconds) as total_talk_time
        FROM call_center_sessions
        WHERE agent_id = ? AND call_started_at BETWEEN ? AND ?
    ";
    
    $stmt = $db->prepare($stats_query);
    if ($stmt) {
        $stmt->bind_param('iss', $user_id, $today_start, $today_end);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $row = $result->fetch_assoc()) {
            $today_stats = $row;
        }
        $stmt->close();
    }
}

// Get donors with balance count
$donors_with_balance = 0;
$donors_query = $db->query("SELECT COUNT(*) as cnt FROM donors WHERE balance > 0 AND donor_type = 'pledge'");
if ($donors_query && $row = $donors_query->fetch_assoc()) {
    $donors_with_balance = (int)$row['cnt'];
}

// Format talk time
$hours = floor($today_stats['total_talk_time'] / 3600);
$minutes = floor(($today_stats['total_talk_time'] % 3600) / 60);
$talk_time_display = $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";

$page_title = 'Call Center Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid #dee2e6;
            transition: transform 0.2s;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        .stat-card.primary { border-left-color: #0a6286; }
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.info { border-left-color: #17a2b8; }
        .stat-card.warning { border-left-color: #ffc107; }
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .action-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            text-align: center;
            transition: transform 0.2s;
        }
        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        .action-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #0a6286;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-4">
                
                <div class="mb-4">
                    <h2><i class="fas fa-phone-alt me-2"></i>Call Center Dashboard</h2>
                    <p class="text-muted">Welcome back, <?php echo htmlspecialchars($user_name); ?>!</p>
                </div>

                <!-- Today's Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="stat-card primary">
                            <div class="stat-value text-primary"><?php echo number_format($today_stats['total_calls']); ?></div>
                            <div class="stat-label">Calls Today</div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="stat-card success">
                            <div class="stat-value text-success"><?php echo number_format($today_stats['successful_contacts']); ?></div>
                            <div class="stat-label">Successful Contacts</div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="stat-card info">
                            <div class="stat-value text-info"><?php echo number_format($today_stats['payment_plans_created']); ?></div>
                            <div class="stat-label">Plans Created</div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="stat-card warning">
                            <div class="stat-value text-warning"><?php echo $talk_time_display; ?></div>
                            <div class="stat-label">Talk Time</div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row g-3 mb-4">
                    <div class="col-lg-4 col-md-6">
                        <div class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <h4 class="mb-3">Start a Call</h4>
                            <p class="text-muted mb-3">Browse donors and start calling directly</p>
                            <a href="donors-list.php" class="btn btn-primary btn-lg w-100">
                                <i class="fas fa-phone me-2"></i>Select Donor to Call
                            </a>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-history"></i>
                            </div>
                            <h4 class="mb-3">Call History</h4>
                            <p class="text-muted mb-3">View all your past calls and conversations</p>
                            <a href="call-history.php" class="btn btn-outline-primary btn-lg w-100">
                                <i class="fas fa-clock me-2"></i>View History
                            </a>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <h4 class="mb-3">My Schedule</h4>
                            <p class="text-muted mb-3">View scheduled callbacks and appointments</p>
                            <a href="my-schedule.php" class="btn btn-outline-primary btn-lg w-100">
                                <i class="fas fa-calendar me-2"></i>View Schedule
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Quick Info -->
                <div class="row">
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Quick Info</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span><i class="fas fa-users me-2 text-primary"></i>Donors with Balance</span>
                                    <strong class="text-danger"><?php echo number_format($donors_with_balance); ?></strong>
                                </div>
                                <hr>
                                <p class="mb-0 small text-muted">
                                    <i class="fas fa-lightbulb me-2"></i>
                                    <strong>Tip:</strong> Use filters on the donor list to find specific donors quickly. 
                                    You can filter by name, phone, city, or balance amount.
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>How to Make a Call</h5>
                            </div>
                            <div class="card-body">
                                <ol class="mb-0">
                                    <li>Click <strong>"Select Donor to Call"</strong> button above</li>
                                    <li>Browse or filter the donor list</li>
                                    <li>Click <strong>"Call"</strong> button next to any donor</li>
                                    <li>Review donor information</li>
                                    <li>Click <strong>"Start Call"</strong> to begin</li>
                                </ol>
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
