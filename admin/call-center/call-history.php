<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$db = db();
$user_id = (int)($_SESSION['user']['id'] ?? 0);
$user_role = $_SESSION['user']['role'] ?? 'registrar';
$is_registrar = ($user_role === 'registrar');

// Filter parameters
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : null;
$outcome_filter = $_GET['outcome'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Agent filter logic
// Registrars can ONLY see their own calls
// Admins can see all calls or filter by agent
if ($is_registrar) {
    // Force registrars to only see their own calls
    $agent_filter = (string)$user_id;
} else {
    // Admins can filter by agent or see all
    if (isset($_GET['agent'])) {
        $agent_filter = $_GET['agent'];
    } else {
        // Default to all agents for admins
        $agent_filter = '';
    }
}

// Build query
$where_conditions = [];
$params = [];
$param_types = '';

if ($donor_id) {
    $where_conditions[] = "s.donor_id = ?";
    $params[] = $donor_id;
    $param_types .= 'i';
}

if ($outcome_filter) {
    if ($outcome_filter === 'callback_scheduled') {
        // Special handling for callback scheduled
        $where_conditions[] = "(s.outcome = 'callback_scheduled' OR s.callback_scheduled_for IS NOT NULL)";
    } elseif ($outcome_filter === 'connected') {
        // Special handling for successful contacts (Any contact made)
        // This must match the logic in reports.php for "Successful Contacts"
        // Includes all outcomes where a human was reached, even if they couldn't talk or were not interested
        $success_outcomes = "'connected', 'agreement_reached', 'payment_method_selected', 'payment_plan_created', 'agreed_to_pay_full', 'callback_requested', 'interested_needs_time', 'callback_scheduled', 'not_ready_to_pay', 'financial_hardship', 'moved_abroad', 'not_interested', 'driving_cannot_talk', 'at_work_cannot_talk', 'with_family_cannot_talk', 'busy_call_back_later'";
        $where_conditions[] = "s.outcome IN ($success_outcomes)";
    } else {
        $where_conditions[] = "s.outcome = ?";
        $params[] = $outcome_filter;
        $param_types .= 's';
    }
}

if ($date_from) {
    $where_conditions[] = "DATE(s.call_started_at) >= ?";
    $params[] = $date_from;
    $param_types .= 's';
}

if ($date_to) {
    $where_conditions[] = "DATE(s.call_started_at) <= ?";
    $params[] = $date_to;
    $param_types .= 's';
}

if ($agent_filter !== '') {
    $where_conditions[] = "s.agent_id = ?";
    $params[] = (int)$agent_filter;
    $param_types .= 'i';
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$history_query = "
    SELECT 
        s.*,
        COALESCE(d.name, 'Unknown Donor') as donor_name,
        d.phone as donor_phone,
        d.balance as donor_balance,
        COALESCE(u.name, 'Unknown Agent') as agent_name
    FROM call_center_sessions s
    LEFT JOIN donors d ON s.donor_id = d.id
    LEFT JOIN users u ON s.agent_id = u.id
    $where_clause
    ORDER BY s.call_started_at DESC
    LIMIT 100
";

$stmt = $db->prepare($history_query);
if ($params) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$history_result = $stmt->get_result();

// Get agents for filter (if admin)
$agents_result = $db->query("SELECT id, name FROM users WHERE role IN ('admin', 'registrar') ORDER BY name");

// Get unique outcomes for filter
$outcomes = [
    'no_answer' => 'No Answer',
    'busy_signal' => 'Busy',
    'voicemail_left' => 'Voicemail Left',
    'not_interested' => 'Not Interested',
    'interested_needs_time' => 'Interested - Needs Time',
    'payment_plan_created' => 'Payment Plan Created',
    'agreed_to_pay_full' => 'Agreed to Pay',
    'financial_hardship' => 'Financial Hardship',
    'moved_abroad' => 'Moved Abroad',
    'callback_requested' => 'Callback Requested',
    'not_ready_to_pay' => 'Not Ready to Pay'
];

$page_title = 'Call History';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Call Center</title>
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
                        <i class="fas fa-history me-2"></i>
                        Call History
                    </h1>
                    <p class="content-subtitle">View and search past call records</p>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Outcome</label>
                            <select name="outcome" class="form-select">
                                <option value="">All Outcomes</option>
                                <?php foreach ($outcomes as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $outcome_filter === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date From</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date To</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <?php if (!$is_registrar): ?>
                        <!-- Agent filter - only show for admins -->
                        <div class="col-md-3">
                            <label class="form-label">Agent</label>
                            <select name="agent" class="form-select">
                                <option value="">All Agents</option>
                                <?php 
                                // Reset the result pointer since we used it earlier
                                $agents_result->data_seek(0);
                                while ($agent = $agents_result->fetch_object()): ?>
                                    <option value="<?php echo $agent->id; ?>" <?php echo $agent_filter === (string)$agent->id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($agent->name); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-2"></i>Filter
                            </button>
                            <a href="call-history.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Clear Filters
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Call History List -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        Call Records (<?php echo $history_result->num_rows; ?>)
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if ($history_result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Donor</th>
                                        <th>Outcome</th>
                                        <th>Duration</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($call = $history_result->fetch_object()): ?>
                                        <?php
                                            // Safe data handling
                                            $call_date = $call->call_started_at ? date('M j, Y', strtotime($call->call_started_at)) : '-';
                                            $call_time = $call->call_started_at ? date('g:i A', strtotime($call->call_started_at)) : '-';
                                            $duration_sec = (int)($call->duration_seconds ?? 0);
                                            
                                            // Format duration
                                            if ($duration_sec > 60) {
                                                $formatted_duration = floor($duration_sec / 60) . 'm ' . ($duration_sec % 60) . 's';
                                            } elseif ($duration_sec > 0) {
                                                $formatted_duration = $duration_sec . 's';
                                            } else {
                                                $formatted_duration = '-';
                                            }
                                            
                                            $outcome_class = str_replace('_', '-', $call->outcome ?? 'unknown');
                                            $outcome_label = ucwords(str_replace('_', ' ', $call->outcome ?? 'Unknown'));
                                            
                                            $donor_name = htmlspecialchars($call->donor_name ?? 'Unknown');
                                            $donor_phone = htmlspecialchars($call->donor_phone ?? '');
                                            $agent_name = htmlspecialchars($call->agent_name ?? 'Unknown');
                                        ?>
                                        <tr onclick="window.location.href='call-details.php?id=<?php echo $call->id; ?>'" style="cursor: pointer;" class="call-history-row">
                                            <td>
                                                <div><?php echo $call_date; ?></div>
                                                <small class="text-muted"><?php echo $call_time; ?></small>
                                            </td>
                                            <td>
                                                <div class="donor-info">
                                                    <div class="donor-name"><?php echo $donor_name; ?></div>
                                                    <small class="text-muted"><?php echo $donor_phone; ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="outcome-badge outcome-<?php echo $outcome_class; ?>">
                                                    <?php echo $outcome_label; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo $formatted_duration; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state py-5">
                            <i class="fas fa-search fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No call records found matching your filters</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<style>
.call-history-row {
    transition: background-color 0.2s ease;
}

.call-history-row:hover {
    background-color: #f8fafc !important;
}

.call-history-row:active {
    background-color: #e2e8f0 !important;
}

.table tbody tr.call-history-row {
    cursor: pointer;
}
</style>
</body>
</html>
