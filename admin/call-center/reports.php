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
$quick_filter = $_GET['quick'] ?? 'today'; // today, week, month, all
$agent_id = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : ($is_admin ? 0 : $current_user_id); // 0 = All Agents (Admin only)

// Calculate date range based on quick filter
switch ($quick_filter) {
    case 'week':
        $date_from = date('Y-m-d', strtotime('monday this week'));
        $date_to = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'month':
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-t');
        break;
    case 'all':
        $date_from = '2000-01-01'; // Far past date
        $date_to = date('Y-m-d');
        break;
    case 'today':
    default:
        $date_from = date('Y-m-d');
        $date_to = date('Y-m-d');
        break;
}

// Allow custom date override
if (isset($_GET['date_from']) && isset($_GET['date_to'])) {
    $date_from = $_GET['date_from'];
    $date_to = $_GET['date_to'];
    $quick_filter = 'custom';
}

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

$issues_data = [];

try {
    $db = db();

    // 1. Get list of agents for filter (Admin only)
    $agents = [];
    if ($is_admin) {
        $agents_query = $db->query("SELECT id, name FROM users WHERE role IN ('admin', 'registrar') AND active = 1 ORDER BY name");
        while ($row = $agents_query->fetch_assoc()) {
            $agents[] = $row;
        }
    }

    // Build Query Conditions
    // Filter by actual call time (call_started_at), not record creation time
    $where_clauses = ["s.call_started_at BETWEEN ? AND ?"];
    $params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
    $types = "ss";

    if ($agent_id > 0) {
        $where_clauses[] = "s.agent_id = ?";
        $params[] = $agent_id;
        $types .= "i";
    }

    $where_sql = implode(" AND ", $where_clauses);

    // 2. Main Stats Query
    // Calculate total talk time using multiple sources for accuracy
    $stats_query = "
        SELECT 
            COUNT(*) as total_calls,
            SUM(CASE 
                WHEN conversation_stage NOT IN ('pending', 'attempt_failed', 'invalid_data')
                THEN 1 
                ELSE 0 
            END) as successful_calls,
            SUM(CASE WHEN outcome = 'busy_signal' THEN 1 ELSE 0 END) as busy_calls,
            SUM(CASE WHEN outcome IN ('no_answer', 'voicemail') THEN 1 ELSE 0 END) as no_answer_calls,
            SUM(CASE WHEN callback_scheduled_for IS NOT NULL THEN 1 ELSE 0 END) as callbacks_scheduled,
            SUM(
                CASE 
                    WHEN s.duration_seconds IS NOT NULL AND s.duration_seconds > 0
                    THEN s.duration_seconds
                    WHEN s.call_ended_at IS NOT NULL AND s.call_started_at IS NOT NULL
                    THEN TIMESTAMPDIFF(SECOND, s.call_started_at, s.call_ended_at)
                    ELSE 0
                END
            ) as total_talk_time
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

    // 5. Fetch Issues (Refused/Invalid Cases)
    $issues_query = "
        SELECT 
            s.id, s.donor_id, s.call_started_at, s.outcome, s.conversation_stage, s.notes,
            d.name as donor_name, d.phone as donor_phone,
            u.name as agent_name
        FROM call_center_sessions s
        LEFT JOIN donors d ON s.donor_id = d.id
        LEFT JOIN users u ON s.agent_id = u.id
        WHERE {$where_sql}
        AND s.conversation_stage IN ('closed_refused', 'invalid_data')
        ORDER BY s.call_started_at DESC
        LIMIT 100
    ";
    
    $stmt = $db->prepare($issues_query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $issues_res = $stmt->get_result();
    while ($row = $issues_res->fetch_assoc()) {
        $issues_data[] = $row;
    }
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
            font-size: 1.125rem;
            margin-bottom: 1rem;
            border: 2px solid;
            background: transparent !important;
        }
        
        .report-icon.icon-primary {
            color: #0d6efd;
            border-color: #0d6efd;
        }
        
        .report-icon.icon-success {
            color: #198754;
            border-color: #198754;
        }
        
        .report-icon.icon-danger {
            color: #dc3545;
            border-color: #dc3545;
        }
        
        .report-icon.icon-info {
            color: #0dcaf0;
            border-color: #0dcaf0;
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
        
        .card-hover-effect {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .card-hover-effect:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            background-color: #fff !important;
            border-color: currentColor !important;
        }
        
        /* Quick Filter Buttons */
        .btn-group {
            flex-wrap: nowrap;
            overflow-x: auto;
        }
        
        .btn-group .btn {
            font-size: 0.875rem;
            padding: 0.5rem 0.75rem;
            white-space: nowrap;
        }
        
        /* Tabs Styling */
        .nav-tabs .nav-link {
            color: #64748b;
            font-weight: 600;
            border: none;
            border-bottom: 3px solid transparent;
            padding: 1rem 1.5rem;
        }
        
        .nav-tabs .nav-link.active {
            color: #0a6286;
            border-bottom-color: #0a6286;
            background: none;
        }
        
        .nav-tabs .nav-link:hover:not(.active) {
            color: #0a6286;
            background: #f8fafc;
            border-bottom-color: #cbd5e1;
        }
        
        @media (max-width: 576px) {
            .btn-group {
                flex-wrap: wrap;
            }
            
            .btn-group .btn {
                flex: 1 1 calc(50% - 0.25rem);
                margin-bottom: 0.5rem;
                font-size: 0.8125rem;
            }
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
                        <p class="text-muted mb-0">
                            <?php 
                            $period_label = match($quick_filter) {
                                'week' => 'This Week',
                                'month' => 'This Month',
                                'all' => 'All Time',
                                'custom' => date('M j', strtotime($date_from)) . ' - ' . date('M j, Y', strtotime($date_to)),
                                default => 'Today'
                            };
                            echo $period_label;
                            ?>
                            <?php if ($is_admin && $agent_id > 0): ?>
                                <?php 
                                $agent_name = 'Unknown Agent';
                                foreach ($agents as $agent) {
                                    if ($agent['id'] == $agent_id) {
                                        $agent_name = $agent['name'];
                                        break;
                                    }
                                }
                                ?>
                                · <?php echo htmlspecialchars($agent_name); ?>
                            <?php elseif ($is_admin): ?>
                                · All Agents
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary" onclick="toggleFilters()">
                            <i class="fas fa-filter me-2"></i>Filters
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </a>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-card" id="filterSection" style="display: none;">
                    <!-- Quick Filters -->
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary small mb-2">Quick Filters</label>
                        <div class="btn-group w-100" role="group">
                            <a href="?quick=today<?php echo $is_admin && $agent_id > 0 ? '&agent_id=' . $agent_id : ''; ?>" 
                               class="btn <?php echo $quick_filter === 'today' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                <i class="fas fa-calendar-day me-1"></i>Today
                            </a>
                            <a href="?quick=week<?php echo $is_admin && $agent_id > 0 ? '&agent_id=' . $agent_id : ''; ?>" 
                               class="btn <?php echo $quick_filter === 'week' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                <i class="fas fa-calendar-week me-1"></i>This Week
                            </a>
                            <a href="?quick=month<?php echo $is_admin && $agent_id > 0 ? '&agent_id=' . $agent_id : ''; ?>" 
                               class="btn <?php echo $quick_filter === 'month' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                <i class="fas fa-calendar-alt me-1"></i>This Month
                            </a>
                            <a href="?quick=all<?php echo $is_admin && $agent_id > 0 ? '&agent_id=' . $agent_id : ''; ?>" 
                               class="btn <?php echo $quick_filter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                <i class="fas fa-infinity me-1"></i>All Time
                            </a>
                        </div>
                    </div>

                    <!-- Custom Date Range -->
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-bold text-secondary small">Custom Date From</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-bold text-secondary small">Custom Date To</label>
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
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-search me-2"></i>Apply Custom Range
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Tabs Navigation -->
                <ul class="nav nav-tabs mb-4" id="reportTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab" aria-controls="overview" aria-selected="true">
                            <i class="fas fa-chart-pie me-2"></i>Performance Overview
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="issues-tab" data-bs-toggle="tab" data-bs-target="#issues" type="button" role="tab" aria-controls="issues" aria-selected="false">
                            <i class="fas fa-exclamation-circle me-2 text-danger"></i>Issues & Refusals 
                            <span class="badge bg-danger rounded-pill ms-1"><?php echo count($issues_data); ?></span>
                        </button>
                    </li>
                </ul>

                <!-- Tab Content -->
                <div class="tab-content" id="reportTabContent">
                    
                    <!-- Overview Tab -->
                    <div class="tab-pane fade show active" id="overview" role="tabpanel" aria-labelledby="overview-tab">
                        <!-- Stats Grid -->
                        <div class="row g-3 g-lg-4 mb-4">
                            <!-- Total Calls -->
                            <div class="col-12 col-sm-6 col-lg-3">
                                <div class="report-card">
                                    <div class="report-icon icon-primary">
                                        <i class="fas fa-phone-alt"></i>
                                    </div>
                                    <div class="report-value"><?php echo number_format($stats['total_calls']); ?></div>
                                    <div class="report-label">Total Calls</div>
                                </div>
                            </div>

                            <!-- Success Rate -->
                            <div class="col-12 col-sm-6 col-lg-3">
                                <div class="report-card">
                                    <div class="report-icon icon-success">
                                        <i class="far fa-check-circle"></i>
                                    </div>
                                    <div class="report-value"><?php echo $success_rate; ?>%</div>
                                    <div class="report-label">Success Rate</div>
                                    <small class="text-muted"><?php echo number_format($stats['successful_calls']); ?> successful</small>
                                </div>
                            </div>

                            <!-- Outstanding Balance -->
                            <div class="col-12 col-sm-6 col-lg-3">
                                <div class="report-card">
                                    <div class="report-icon icon-danger">
                                        <i class="far fa-money-bill-alt"></i>
                                    </div>
                                    <div class="report-value">£<?php echo number_format($stats['outstanding_amount']); ?></div>
                                    <div class="report-label">Outstanding Balance</div>
                                    <small class="text-muted">Assigned donors</small>
                                </div>
                            </div>

                            <!-- Talk Time -->
                            <div class="col-12 col-sm-6 col-lg-3">
                                <div class="report-card">
                                    <div class="report-icon icon-info">
                                        <i class="far fa-clock"></i>
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
                                                    
                                                    // Success outcomes - Green
                                                    if (in_array($out_key, ['connected', 'agreement_reached', 'payment_method_selected', 'payment_plan_created', 'agreed_to_pay_full'])) {
                                                        $color = 'success';
                                                        $icon = 'check-circle';
                                                    } 
                                                    // Positive/Callback outcomes - Info/Blue
                                                    elseif (in_array($out_key, ['callback_requested', 'interested_needs_time', 'callback_scheduled'])) {
                                                        $color = 'info';
                                                        $icon = 'calendar-check';
                                                    } 
                                                    // Busy/Warning outcomes - Warning/Orange
                                                    elseif (in_array($out_key, ['busy_signal', 'not_ready_to_pay'])) {
                                                        $color = 'warning';
                                                        $icon = 'hourglass-half';
                                                    } 
                                                    // No Contact outcomes - Secondary/Gray
                                                    elseif (in_array($out_key, ['no_answer', 'no_connection', 'voicemail_left'])) {
                                                        $color = 'secondary';
                                                        $icon = 'phone-slash';
                                                    } 
                                                    // Negative outcomes - Danger/Red
                                                    elseif (in_array($out_key, ['invalid_number', 'wrong_number', 'network_error', 'number_not_in_service', 'not_interested', 'financial_hardship', 'moved_abroad', 'donor_deceased', 'never_pledged_denies', 'already_paid_claims'])) {
                                                        $color = 'danger';
                                                        $icon = 'times-circle';
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
                                            <?php 
                                            $base_link = "call-history.php?" . ($agent_id > 0 ? "agent={$agent_id}&" : "") . "date_from={$date_from}&date_to={$date_to}";
                                            ?>
                                            <div class="col-6">
                                                <a href="<?php echo $base_link; ?>&outcome=busy_signal" class="text-decoration-none">
                                                    <div class="p-3 border rounded-3 bg-light h-100 card-hover-effect">
                                                        <h3 class="fw-bold text-warning mb-1"><?php echo number_format($stats['busy_calls']); ?></h3>
                                                        <p class="mb-0 text-muted small text-uppercase fw-bold">Busy Signals</p>
                                                    </div>
                                                </a>
                                            </div>
                                            <div class="col-6">
                                                <a href="<?php echo $base_link; ?>&outcome=no_answer" class="text-decoration-none">
                                                    <div class="p-3 border rounded-3 bg-light h-100 card-hover-effect">
                                                        <h3 class="fw-bold text-secondary mb-1"><?php echo number_format($stats['no_answer_calls']); ?></h3>
                                                        <p class="mb-0 text-muted small text-uppercase fw-bold">No Answer</p>
                                                    </div>
                                                </a>
                                            </div>
                                            <div class="col-6">
                                                <a href="<?php echo $base_link; ?>&outcome=callback_scheduled" class="text-decoration-none">
                                                    <div class="p-3 border rounded-3 bg-light h-100 card-hover-effect">
                                                        <h3 class="fw-bold text-info mb-1"><?php echo number_format($stats['callbacks_scheduled']); ?></h3>
                                                        <p class="mb-0 text-muted small text-uppercase fw-bold">Callbacks Scheduled</p>
                                                    </div>
                                                </a>
                                            </div>
                                            <div class="col-6">
                                                <a href="<?php echo $base_link; ?>&outcome=connected" class="text-decoration-none">
                                                    <div class="p-3 border rounded-3 bg-light h-100 card-hover-effect">
                                                        <h3 class="fw-bold text-success mb-1"><?php echo number_format($stats['successful_calls']); ?></h3>
                                                        <p class="mb-0 text-muted small text-uppercase fw-bold">Successful Contacts</p>
                                                    </div>
                                                </a>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-4 text-center">
                                            <a href="call-history.php<?php echo $agent_id > 0 ? '?agent=' . $agent_id : ''; ?>" class="btn btn-outline-primary btn-sm">
                                                View Full Call Logs <i class="fas fa-arrow-right ms-2"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Issues Tab -->
                    <div class="tab-pane fade" id="issues" role="tabpanel" aria-labelledby="issues-tab">
                        <div class="card shadow-sm border-0">
                            <div class="card-header bg-white py-3 border-bottom">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0 fw-bold text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Problematic Cases</h5>
                                    <span class="badge bg-danger-subtle text-danger">Refused or Invalid</span>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Donor</th>
                                            <th>Stage</th>
                                            <th>Outcome</th>
                                            <th>Agent</th>
                                            <th>Notes</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($issues_data)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-5 text-muted">
                                                    <i class="fas fa-check-circle fa-2x mb-3 text-success opacity-50"></i>
                                                    <p class="mb-0">No flagged issues found in this period.</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($issues_data as $issue): ?>
                                                <tr>
                                                    <td><?php echo date('M j, H:i', strtotime($issue['call_started_at'])); ?></td>
                                                    <td>
                                                        <div class="fw-bold"><?php echo htmlspecialchars($issue['donor_name'] ?? 'Unknown'); ?></div>
                                                        <div class="small text-muted"><?php echo htmlspecialchars($issue['donor_phone'] ?? ''); ?></div>
                                                    </td>
                                                    <td>
                                                        <?php if ($issue['conversation_stage'] === 'closed_refused'): ?>
                                                            <span class="badge bg-warning text-dark">Refused</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">Invalid Data</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo ucwords(str_replace('_', ' ', $issue['outcome'] ?? '')); ?></td>
                                                    <td><?php echo htmlspecialchars($issue['agent_name'] ?? 'Unknown'); ?></td>
                                                    <td>
                                                        <span class="d-inline-block text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($issue['notes'] ?? ''); ?>">
                                                            <?php echo htmlspecialchars($issue['notes'] ?? '-'); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-end">
                                                        <a href="../donor-management/view-donor.php?id=<?php echo $issue['donor_id']; ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-user"></i>
                                                        </a>
                                                        <a href="call-details.php?id=<?php echo $issue['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                                            <i class="fas fa-info-circle"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
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
<script>
function toggleFilters() {
    const filterSection = document.getElementById('filterSection');
    if (filterSection.style.display === 'none') {
        filterSection.style.display = 'block';
    } else {
        filterSection.style.display = 'none';
    }
}
</script>
</body>
</html>
