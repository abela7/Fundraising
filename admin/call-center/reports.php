<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Only admins and registrars (agents) can access, but only admins see all data
$user_role = $_SESSION['user']['role'] ?? 'registrar';
$current_user_id = (int)$_SESSION['user']['id'];
$is_admin = ($user_role === 'admin');

$page_title = 'Call Center Reports';

// Initialize Filters
$date_from = $_GET['date_from'] ?? date('Y-m-d'); // Default to today
$date_to = $_GET['date_to'] ?? date('Y-m-d');     // Default to today
$agent_id = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : ($is_admin ? 0 : $current_user_id); // 0 = All Agents (Admin only)

// Restrict non-admins to their own data
if (!$is_admin && $agent_id !== $current_user_id) {
    $agent_id = $current_user_id;
}

$stats = [
    'total_calls' => 0,
    'successful_calls' => 0,
    'busy_calls' => 0,
    'no_answer_calls' => 0,
    'callbacks_scheduled' => 0,
    'total_talk_time' => 0,
    'outstanding_amount' => 0,
    'outcomes' => []
];

try {
    $db = db();

    // 1. Get list of agents for filter (Admin only)
    $agents = [];
    if ($is_admin) {
        $agents_query = $db->query("SELECT id, name FROM users WHERE role IN ('admin', 'registrar') ORDER BY name");
        while ($row = $agents_query->fetch_assoc()) {
            $agents[] = $row;
        }
    }

    // Build Query Conditions
    $where_clauses = ["s.created_at BETWEEN ? AND ?"];
    $params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
    $types = "ss";

    if ($agent_id > 0) {
        $where_clauses[] = "s.agent_id = ?";
        $params[] = $agent_id;
        $types .= "i";
    }

    $where_sql = implode(" AND ", $where_clauses);

    // 2. Main Stats Query
    // Note: Adjust outcomes based on your actual ENUM values in DB
    $stats_query = "
        SELECT 
            COUNT(*) as total_calls,
            SUM(CASE 
                WHEN outcome NOT IN ('no_answer', 'busy_signal', 'invalid_number', 'wrong_number', 'number_not_in_service', 'network_error', 'voicemail', 'no_connection') 
                AND conversation_stage != 'no_connection'
                THEN 1 
                ELSE 0 
            END) as successful_calls,
            SUM(CASE WHEN outcome = 'busy_signal' THEN 1 ELSE 0 END) as busy_calls,
            SUM(CASE WHEN outcome IN ('no_answer', 'voicemail') THEN 1 ELSE 0 END) as no_answer_calls,
            SUM(CASE WHEN callback_scheduled_for IS NOT NULL THEN 1 ELSE 0 END) as callbacks_scheduled,
            SUM(COALESCE(duration_seconds, 0)) as total_talk_time
        FROM call_center_sessions s
        WHERE {$where_sql}
    ";

    $stmt = $db->prepare($stats_query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    if ($result) {
        $stats['total_calls'] = (int)$result['total_calls'];
        $stats['successful_calls'] = (int)$result['successful_calls'];
        $stats['busy_calls'] = (int)$result['busy_calls'];
        $stats['no_answer_calls'] = (int)$result['no_answer_calls'];
        $stats['callbacks_scheduled'] = (int)$result['callbacks_scheduled'];
        $stats['total_talk_time'] = (int)$result['total_talk_time'];
    }
    $stmt->close();

    // 3. Outcomes Breakdown
    $outcomes_query = "
        SELECT 
            outcome, 
            COUNT(*) as count,
            ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 1) as percentage
        FROM call_center_sessions s
        WHERE {$where_sql}
        GROUP BY outcome
        ORDER BY count DESC
    ";
    $stmt = $db->prepare($outcomes_query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $outcomes_result = $stmt->get_result();
    while ($row = $outcomes_result->fetch_assoc()) {
        $stats['outcomes'][] = $row;
    }
    $stmt->close();

    // 4. Outstanding Balance (Snapshot based on current assignments)
    // This is not strictly tied to the date range of calls, but shows current portfolio status
    $balance_where = "donor_type = 'pledge' AND balance > 0";
    $balance_params = [];
    $balance_types = "";

    if ($agent_id > 0) {
        $balance_where .= " AND agent_id = ?";
        $balance_params[] = $agent_id;
        $balance_types .= "i";
    }

    $balance_query = "SELECT SUM(balance) as total FROM donors WHERE {$balance_where}";
    $stmt = $db->prepare($balance_query);
    if (!empty($balance_params)) {
        $stmt->bind_param($balance_types, ...$balance_params);
    }
    $stmt->execute();
    $balance_result = $stmt->get_result()->fetch_assoc();
    $stats['outstanding_amount'] = (float)($balance_result['total'] ?? 0);
    $stmt->close();

} catch (Exception $e) {
    $error_message = $e->getMessage();
}

// Format Talk Time
$hours = floor($stats['total_talk_time'] / 3600);
$minutes = floor(($stats['total_talk_time'] % 3600) / 60);
$formatted_time = $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";

// Calculate Success Rate
$success_rate = $stats['total_calls'] > 0 
    ? round(($stats['successful_calls'] / $stats['total_calls']) * 100, 1) 
    : 0;
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
        .report-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
            height: 100%;
            border: 1px solid #e2e8f0;
            transition: transform 0.2s;
        }
        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.1);
        }
        .report-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        .report-value {
            font-size: 2rem;
            font-weight: 800;
            color: #1e293b;
            line-height: 1.2;
            margin-bottom: 0.5rem;
        }
        .report-label {
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .progress-custom {
            height: 10px;
            border-radius: 5px;
            background-color: #f1f5f9;
            margin-bottom: 0.5rem;
        }
        .filter-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 2rem;
        }
        
        /* Mobile Responsive Adjustments */
        @media (max-width: 768px) {
            .report-value {
                font-size: 1.75rem;
            }
            .report-card {
                padding: 1.25rem;
            }
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
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
                    <div>
                        <h1 class="h3 fw-bold text-primary mb-1">
                            <i class="fas fa-chart-pie me-2"></i>Call Center Reports
                        </h1>
                        <p class="text-muted mb-0">Performance metrics and analytics</p>
                    </div>
                    <div>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </a>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-card">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-bold text-secondary small">Date From</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-bold text-secondary small">Date To</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        
                        <?php if ($is_admin): ?>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-bold text-secondary small">Agent</label>
                            <select name="agent_id" class="form-select">
                                <option value="0">All Agents</option>
                                <?php foreach ($agents as $agent): ?>
                                    <option value="<?php echo $agent['id']; ?>" <?php echo $agent_id == $agent['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($agent['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-12 col-md-3">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-2"></i>Update Report
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Stats Grid -->
                <div class="row g-3 g-lg-4 mb-4">
                    <!-- Total Calls -->
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="report-card">
                            <div class="report-icon bg-primary bg-opacity-10 text-primary">
                                <i class="fas fa-phone"></i>
                            </div>
                            <div class="report-value"><?php echo number_format($stats['total_calls']); ?></div>
                            <div class="report-label">Total Calls</div>
                        </div>
                    </div>

                    <!-- Success Rate -->
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="report-card">
                            <div class="report-icon bg-success bg-opacity-10 text-success">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="report-value"><?php echo $success_rate; ?>%</div>
                            <div class="report-label">Success Rate</div>
                            <small class="text-muted"><?php echo number_format($stats['successful_calls']); ?> successful</small>
                        </div>
                    </div>

                    <!-- Outstanding Balance -->
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="report-card">
                            <div class="report-icon bg-danger bg-opacity-10 text-danger">
                                <i class="fas fa-coins"></i>
                            </div>
                            <div class="report-value">£<?php echo number_format($stats['outstanding_amount']); ?></div>
                            <div class="report-label">Outstanding Balance</div>
                            <small class="text-muted">Assigned donors</small>
                        </div>
                    </div>

                    <!-- Talk Time -->
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="report-card">
                            <div class="report-icon bg-info bg-opacity-10 text-info">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="report-value" style="font-size: 1.5rem;"><?php echo $formatted_time; ?></div>
                            <div class="report-label">Total Talk Time</div>
                            <small class="text-muted">Avg: <?php echo $stats['total_calls'] > 0 ? gmdate("i:s", (int)($stats['total_talk_time'] / $stats['total_calls'])) : '00:00'; ?> / call</small>
                        </div>
                    </div>
                </div>

                <div class="row g-3 g-lg-4">
                    <!-- Call Outcomes Breakdown -->
                    <div class="col-12 col-lg-6">
                        <div class="card shadow-sm h-100 border-0">
                            <div class="card-header bg-white py-3 border-bottom">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-chart-bar me-2 text-secondary"></i>Outcome Breakdown</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($stats['outcomes'])): ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="fas fa-chart-simple fa-3x mb-3 opacity-25"></i>
                                        <p>No data available for selected period</p>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex flex-column gap-3">
                                        <?php foreach ($stats['outcomes'] as $outcome): 
                                            $color = 'secondary';
                                            $icon = 'circle';
                                            $out_key = $outcome['outcome'] ?? 'unknown';
                                            
                                            if (in_array($out_key, ['connected', 'agreement_reached', 'payment_method_selected'])) {
                                                $color = 'success';
                                                $icon = 'check';
                                            } elseif (in_array($out_key, ['busy_signal', 'callback_scheduled'])) {
                                                $color = 'warning';
                                                $icon = 'clock';
                                            } elseif (in_array($out_key, ['no_answer', 'no_connection'])) {
                                                $color = 'secondary';
                                                $icon = 'phone-slash';
                                            } elseif (in_array($out_key, ['invalid_number', 'wrong_number', 'network_error'])) {
                                                $color = 'danger';
                                                $icon = 'exclamation-circle';
                                            }
                                        ?>
                                        <div>
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="fw-semibold text-<?php echo $color; ?>">
                                                    <i class="fas fa-<?php echo $icon; ?> me-2"></i>
                                                    <?php echo ucwords(str_replace('_', ' ', $out_key)); ?>
                                                </span>
                                                <span class="fw-bold"><?php echo number_format($outcome['count']); ?> <small class="text-muted fw-normal">(<?php echo $outcome['percentage']; ?>%)</small></span>
                                            </div>
                                            <div class="progress progress-custom">
                                                <div class="progress-bar bg-<?php echo $color; ?>" style="width: <?php echo $outcome['percentage']; ?>%"></div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Activity Summary -->
                    <div class="col-12 col-lg-6">
                        <div class="card shadow-sm h-100 border-0">
                            <div class="card-header bg-white py-3 border-bottom">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-list-ul me-2 text-secondary"></i>Activity Summary</h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3 text-center">
                                    <div class="col-6">
                                        <div class="p-3 border rounded-3 bg-light">
                                            <h3 class="fw-bold text-warning mb-1"><?php echo number_format($stats['busy_calls']); ?></h3>
                                            <p class="mb-0 text-muted small text-uppercase fw-bold">Busy Signals</p>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-3 border rounded-3 bg-light">
                                            <h3 class="fw-bold text-secondary mb-1"><?php echo number_format($stats['no_answer_calls']); ?></h3>
                                            <p class="mb-0 text-muted small text-uppercase fw-bold">No Answer</p>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-3 border rounded-3 bg-light">
                                            <h3 class="fw-bold text-info mb-1"><?php echo number_format($stats['callbacks_scheduled']); ?></h3>
                                            <p class="mb-0 text-muted small text-uppercase fw-bold">Callbacks Scheduled</p>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-3 border rounded-3 bg-light">
                                            <h3 class="fw-bold text-success mb-1"><?php echo number_format($stats['successful_calls']); ?></h3>
                                            <p class="mb-0 text-muted small text-uppercase fw-bold">Successful Contacts</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-4 text-center">
                                    <a href="call-history.php" class="btn btn-outline-primary btn-sm">
                                        View Full Call Logs <i class="fas fa-arrow-right ms-2"></i>
                                    </a>
                                </div>
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

