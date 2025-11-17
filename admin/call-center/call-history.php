<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$db = db();
// Get user ID from session (auth system uses $_SESSION['user'] array)
$user_id = (int)($_SESSION['user']['id'] ?? 0);

// Filter parameters
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : null;
$outcome_filter = $_GET['outcome'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$agent_filter = $_GET['agent'] ?? '';

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
    $where_conditions[] = "s.outcome = ?";
    $params[] = $outcome_filter;
    $param_types .= 's';
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

if ($agent_filter) {
    $where_conditions[] = "s.agent_id = ?";
    $params[] = (int)$agent_filter;
    $param_types .= 'i';
} else {
    // Default: only show this agent's calls
    $where_conditions[] = "s.agent_id = ?";
    $params[] = $user_id;
    $param_types .= 'i';
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$history_query = "
    SELECT 
        s.*,
        d.name as donor_name,
        d.phone as donor_phone,
        d.balance as donor_balance,
        u.name as agent_name
    FROM call_center_sessions s
    JOIN donors d ON s.donor_id = d.id
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
    'moved_abroad' => 'Moved Abroad'
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
                        <div class="col-md-3">
                            <label class="form-label">Agent</label>
                            <select name="agent" class="form-select">
                                <option value="">All Agents</option>
                                <?php while ($agent = $agents_result->fetch_object()): ?>
                                    <option value="<?php echo $agent->id; ?>" <?php echo $agent_filter == $agent->id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($agent->name); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
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
                                        <th>Stage</th>
                                        <th>Duration</th>
                                        <th>Agent</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($call = $history_result->fetch_object()): ?>
                                        <tr>
                                            <td>
                                                <div><?php echo date('M j, Y', strtotime($call->call_started_at)); ?></div>
                                                <small class="text-muted"><?php echo date('g:i A', strtotime($call->call_started_at)); ?></small>
                                            </td>
                                            <td>
                                                <div class="donor-info">
                                                    <div class="donor-name"><?php echo htmlspecialchars($call->donor_name); ?></div>
                                                    <small class="text-muted"><?php echo $call->donor_phone; ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="outcome-badge outcome-<?php echo str_replace('_', '-', $call->outcome); ?>">
                                                    <?php echo ucwords(str_replace('_', ' ', $call->outcome)); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small><?php echo ucwords(str_replace('_', ' ', $call->conversation_stage)); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($call->duration_seconds): ?>
                                                    <?php echo gmdate("i:s", $call->duration_seconds); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($call->agent_name); ?></small>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#callDetailModal<?php echo $call->id; ?>">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </td>
                                        </tr>

                                        <!-- Call Detail Modal -->
                                        <div class="modal fade" id="callDetailModal<?php echo $call->id; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Call Details - <?php echo htmlspecialchars($call->donor_name); ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="row g-3">
                                                            <div class="col-md-6">
                                                                <label class="fw-bold">Date & Time:</label>
                                                                <p><?php echo date('F j, Y g:i A', strtotime($call->call_started_at)); ?></p>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="fw-bold">Duration:</label>
                                                                <p><?php echo $call->duration_seconds ? gmdate("H:i:s", $call->duration_seconds) : 'N/A'; ?></p>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="fw-bold">Outcome:</label>
                                                                <p><span class="outcome-badge outcome-<?php echo str_replace('_', '-', $call->outcome); ?>">
                                                                    <?php echo ucwords(str_replace('_', ' ', $call->outcome)); ?>
                                                                </span></p>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="fw-bold">Conversation Stage:</label>
                                                                <p><?php echo ucwords(str_replace('_', ' ', $call->conversation_stage)); ?></p>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="fw-bold">Donor Response:</label>
                                                                <p><?php echo ucwords($call->donor_response_type); ?></p>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="fw-bold">Call Quality:</label>
                                                                <p><?php echo $call->call_quality ? ucwords($call->call_quality) : 'N/A'; ?></p>
                                                            </div>
                                                            
                                                            <?php if ($call->payment_discussed): ?>
                                                            <div class="col-12">
                                                                <div class="alert alert-info">
                                                                    <i class="fas fa-pound-sign me-2"></i>
                                                                    Payment was discussed
                                                                    <?php if ($call->payment_amount_discussed): ?>
                                                                        (Amount: £<?php echo number_format($call->payment_amount_discussed, 2); ?>)
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                            <?php endif; ?>

                                                            <?php if ($call->callback_scheduled_for): ?>
                                                            <div class="col-12">
                                                                <div class="alert alert-warning">
                                                                    <i class="fas fa-calendar-check me-2"></i>
                                                                    Callback Scheduled: <?php echo date('F j, Y g:i A', strtotime($call->callback_scheduled_for)); ?>
                                                                    <?php if ($call->callback_reason): ?>
                                                                        <br><small>Reason: <?php echo htmlspecialchars($call->callback_reason); ?></small>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                            <?php endif; ?>

                                                            <?php if ($call->objections_raised): ?>
                                                            <div class="col-12">
                                                                <label class="fw-bold">Objections Raised:</label>
                                                                <p class="text-muted"><?php echo nl2br(htmlspecialchars($call->objections_raised)); ?></p>
                                                            </div>
                                                            <?php endif; ?>

                                                            <?php if ($call->promises_made): ?>
                                                            <div class="col-12">
                                                                <label class="fw-bold">Promises Made:</label>
                                                                <p class="text-muted"><?php echo nl2br(htmlspecialchars($call->promises_made)); ?></p>
                                                            </div>
                                                            <?php endif; ?>

                                                            <div class="col-12">
                                                                <label class="fw-bold">Call Notes:</label>
                                                                <p><?php echo nl2br(htmlspecialchars($call->notes)); ?></p>
                                                            </div>

                                                            <!-- Special Flags -->
                                                            <?php if ($call->donor_requested_supervisor || $call->donor_threatened_legal || $call->donor_claimed_already_paid || $call->donor_claimed_never_pledged || $call->language_barrier_encountered): ?>
                                                            <div class="col-12">
                                                                <label class="fw-bold">Special Circumstances:</label>
                                                                <div class="mt-2">
                                                                    <?php if ($call->donor_requested_supervisor): ?>
                                                                        <span class="badge bg-warning text-dark me-2">Requested Supervisor</span>
                                                                    <?php endif; ?>
                                                                    <?php if ($call->donor_threatened_legal): ?>
                                                                        <span class="badge bg-danger me-2">Legal Threat</span>
                                                                    <?php endif; ?>
                                                                    <?php if ($call->donor_claimed_already_paid): ?>
                                                                        <span class="badge bg-info me-2">Claims Paid</span>
                                                                    <?php endif; ?>
                                                                    <?php if ($call->donor_claimed_never_pledged): ?>
                                                                        <span class="badge bg-info me-2">Claims Never Pledged</span>
                                                                    <?php endif; ?>
                                                                    <?php if ($call->language_barrier_encountered): ?>
                                                                        <span class="badge bg-secondary me-2">Language Barrier</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                            <?php endif; ?>

                                                            <div class="col-12 mt-3">
                                                                <small class="text-muted">
                                                                    <i class="fas fa-user me-1"></i>Agent: <?php echo htmlspecialchars($call->agent_name); ?>
                                                                </small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <a href="make-call.php?donor_id=<?php echo $call->donor_id; ?>" class="btn btn-success">
                                                            <i class="fas fa-phone me-2"></i>Call Again
                                                        </a>
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
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
</body>
</html>

