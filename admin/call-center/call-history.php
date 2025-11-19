<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$db = db();
$user_id = (int)($_SESSION['user']['id'] ?? 0);
$user_role = $_SESSION['user']['role'] ?? 'registrar';
$is_admin = ($user_role === 'admin');

// Filter parameters (needed for redirects)
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : null;
$outcome_filter = $_GET['outcome'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Agent filter logic
// If 'agent' is set in GET, use it (empty means all). 
// If NOT set, default to current user.
if (isset($_GET['agent'])) {
    $agent_filter = $_GET['agent'];
} else {
    $agent_filter = (string)$user_id;
}

// Handle success messages from redirects
$message = '';
$message_type = '';
if (isset($_GET['msg'])) {
    $message = urldecode($_GET['msg']);
    $message_type = 'success';
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    verify_csrf();
    
    $bulk_action = $_POST['bulk_action'] ?? '';
    $selected_ids = $_POST['selected_ids'] ?? [];
    
    if (!is_array($selected_ids) || count($selected_ids) === 0) {
        $message = 'No records selected.';
        $message_type = 'warning';
    } else {
        $selected_ids = array_values(array_unique(array_map('intval', $selected_ids)));
        $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
        $types = str_repeat('i', count($selected_ids));
        
        $db->begin_transaction();
        try {
            if ($bulk_action === 'delete') {
                // Delete selected call records
                $delete_query = "DELETE FROM call_center_sessions WHERE id IN ($placeholders)";
                $stmt = $db->prepare($delete_query);
                $stmt->bind_param($types, ...$selected_ids);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                
                // Audit log
                $summary = json_encode(['action' => 'bulk_delete', 'count' => $affected, 'ids' => $selected_ids]);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'call_session', 0, 'bulk_delete', NULL, ?, 'admin')");
                $log->bind_param('is', $user_id, $summary);
                $log->execute();
                
                $db->commit();
                // Redirect to prevent resubmission - preserve current filters
                $redirect_params = array_filter([
                    'donor_id' => $donor_id ?? null,
                    'outcome' => $outcome_filter ?? null,
                    'date_from' => $date_from ?? null,
                    'date_to' => $date_to ?? null,
                    'agent' => $agent_filter ?? null,
                    'msg' => "Successfully deleted $affected call record(s)."
                ]);
                header('Location: call-history.php?' . http_build_query($redirect_params));
                exit;
                
            } elseif ($bulk_action === 'export') {
                // Export to CSV
                $db->commit(); // Close transaction before export
                
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="call_history_' . date('Y-m-d_His') . '.csv"');
                
                $output = fopen('php://output', 'w');
                
                // CSV headers
                fputcsv($output, [
                    'ID', 'Date & Time', 'Donor Name', 'Phone', 'Outcome', 
                    'Stage', 'Duration (seconds)', 'Agent', 'Notes'
                ]);
                
                // Fetch selected records
                $export_query = "
                    SELECT 
                        s.id,
                        s.call_started_at,
                        COALESCE(d.name, 'Unknown Donor') as donor_name,
                        d.phone as donor_phone,
                        s.outcome,
                        s.conversation_stage,
                        s.duration_seconds,
                        COALESCE(u.name, 'Unknown Agent') as agent_name,
                        s.notes
                    FROM call_center_sessions s
                    LEFT JOIN donors d ON s.donor_id = d.id
                    LEFT JOIN users u ON s.agent_id = u.id
                    WHERE s.id IN ($placeholders)
                    ORDER BY s.call_started_at DESC
                ";
                
                $stmt = $db->prepare($export_query);
                $stmt->bind_param($types, ...$selected_ids);
                $stmt->execute();
                $export_result = $stmt->get_result();
                
                while ($row = $export_result->fetch_assoc()) {
                    $call_date = $row['call_started_at'] ? date('Y-m-d H:i:s', strtotime($row['call_started_at'])) : '';
                    fputcsv($output, [
                        $row['id'],
                        $call_date,
                        $row['donor_name'],
                        $row['donor_phone'] ?? '',
                        ucwords(str_replace('_', ' ', $row['outcome'] ?? '')),
                        ucwords(str_replace('_', ' ', $row['conversation_stage'] ?? '')),
                        $row['duration_seconds'] ?? 0,
                        $row['agent_name'],
                        $row['notes'] ?? ''
                    ]);
                }
                
                fclose($output);
                $stmt->close();
                exit;
                
            } elseif ($bulk_action === 'mark_reviewed' && $is_admin) {
                // Mark as reviewed (if requires_supervisor_review field exists)
                // Note: This assumes the field exists. If not, we'll skip it gracefully.
                $update_query = "UPDATE call_center_sessions SET requires_supervisor_review = 0 WHERE id IN ($placeholders)";
                $stmt = $db->prepare($update_query);
                $stmt->bind_param($types, ...$selected_ids);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                
                // Audit log
                $summary = json_encode(['action' => 'bulk_mark_reviewed', 'count' => $affected]);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'call_session', 0, 'bulk_mark_reviewed', NULL, ?, 'admin')");
                $log->bind_param('is', $user_id, $summary);
                $log->execute();
                
                $db->commit();
                // Redirect to prevent resubmission - preserve current filters
                $redirect_params = array_filter([
                    'donor_id' => $donor_id ?? null,
                    'outcome' => $outcome_filter ?? null,
                    'date_from' => $date_from ?? null,
                    'date_to' => $date_to ?? null,
                    'agent' => $agent_filter ?? null,
                    'msg' => "Marked $affected call record(s) as reviewed."
                ]);
                header('Location: call-history.php?' . http_build_query($redirect_params));
                exit;
            } else {
                $db->rollback();
                $message = 'Invalid bulk action.';
                $message_type = 'danger';
            }
        } catch (Throwable $e) {
            $db->rollback();
            error_log("Bulk action error: " . $e->getMessage());
            $message = 'An error occurred: ' . htmlspecialchars($e->getMessage());
            $message_type = 'danger';
        }
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
                        <div class="col-md-3">
                            <label class="form-label">Agent</label>
                            <select name="agent" class="form-select">
                                <option value="">All Agents</option>
                                <?php while ($agent = $agents_result->fetch_object()): ?>
                                    <option value="<?php echo $agent->id; ?>" <?php echo $agent_filter === (string)$agent->id ? 'selected' : ''; ?>>
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

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Call History List -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="card-title mb-0">
                        Call Records (<?php echo $history_result->num_rows; ?>)
                    </h5>
                    <?php if ($history_result->num_rows > 0): ?>
                    <form method="POST" id="bulkActionForm" class="d-flex align-items-center gap-2">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="bulk_action" id="bulkActionInput" value="">
                        <select class="form-select form-select-sm" id="bulkActionSelect" style="min-width: 180px;">
                            <option value="">Bulk Actions</option>
                            <option value="export">Export Selected to CSV</option>
                            <?php if ($is_admin): ?>
                            <option value="mark_reviewed">Mark as Reviewed</option>
                            <option value="delete">Delete Selected</option>
                            <?php endif; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary" id="bulkActionBtn" disabled>
                            <i class="fas fa-check me-1"></i>Apply
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if ($history_result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th width="40">
                                            <input type="checkbox" id="selectAll" title="Select All">
                                        </th>
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
                                    <?php 
                                    // Reset result pointer
                                    $history_result->data_seek(0);
                                    while ($call = $history_result->fetch_object()): ?>
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
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="selected_ids[]" value="<?php echo $call->id; ?>" class="row-checkbox">
                                            </td>
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
                                                <small><?php echo ucwords(str_replace('_', ' ', $call->conversation_stage ?? '-')); ?></small>
                                            </td>
                                            <td>
                                                <?php echo $formatted_duration; ?>
                                            </td>
                                            <td>
                                                <small><?php echo $agent_name; ?></small>
                                            </td>
                                            <td>
                                                <a href="call-details.php?id=<?php echo $call->id; ?>" class="btn btn-sm btn-outline-primary">
                                                    View
                                                </a>
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
<script>
// Bulk Actions JavaScript
(function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const rowCheckboxes = document.querySelectorAll('.row-checkbox');
    const bulkActionSelect = document.getElementById('bulkActionSelect');
    const bulkActionBtn = document.getElementById('bulkActionBtn');
    const bulkActionForm = document.getElementById('bulkActionForm');
    const bulkActionInput = document.getElementById('bulkActionInput');
    
    // Select All functionality
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            rowCheckboxes.forEach(cb => {
                cb.checked = this.checked;
            });
            updateBulkActionButton();
        });
    }
    
    // Individual checkbox change
    rowCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateSelectAllState();
            updateBulkActionButton();
        });
    });
    
    // Update select all checkbox state
    function updateSelectAllState() {
        if (!selectAllCheckbox) return;
        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
        selectAllCheckbox.checked = checkedCount === rowCheckboxes.length;
        selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < rowCheckboxes.length;
    }
    
    // Update bulk action button state
    function updateBulkActionButton() {
        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
        const hasSelection = checkedCount > 0;
        const hasAction = bulkActionSelect.value !== '';
        
        bulkActionBtn.disabled = !hasSelection || !hasAction;
        
        if (hasSelection) {
            bulkActionBtn.innerHTML = `<i class="fas fa-check me-1"></i>Apply (${checkedCount})`;
        } else {
            bulkActionBtn.innerHTML = '<i class="fas fa-check me-1"></i>Apply';
        }
    }
    
    // Bulk action select change
    if (bulkActionSelect) {
        bulkActionSelect.addEventListener('change', function() {
            updateBulkActionButton();
        });
    }
    
    // Form submission
    if (bulkActionForm) {
        bulkActionForm.addEventListener('submit', function(e) {
            const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
            const action = bulkActionSelect.value;
            
            if (checkedCount === 0) {
                e.preventDefault();
                alert('Please select at least one record.');
                return false;
            }
            
            if (!action) {
                e.preventDefault();
                alert('Please select a bulk action.');
                return false;
            }
            
            // Set the action value
            bulkActionInput.value = action;
            
            // Confirmation for destructive actions
            if (action === 'delete') {
                if (!confirm(`Are you sure you want to delete ${checkedCount} call record(s)? This action cannot be undone.`)) {
                    e.preventDefault();
                    return false;
                }
            } else if (action === 'mark_reviewed') {
                if (!confirm(`Mark ${checkedCount} call record(s) as reviewed?`)) {
                    e.preventDefault();
                    return false;
                }
            }
            
            // For export, we don't need confirmation
            return true;
        });
    }
})();
</script>
<style>
.row-checkbox {
    cursor: pointer;
}

#selectAll {
    cursor: pointer;
}

#bulkActionBtn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.card-header {
    flex-wrap: wrap;
}

@media (max-width: 767px) {
    .card-header {
        flex-direction: column;
        align-items: flex-start !important;
    }
    
    #bulkActionForm {
        width: 100%;
        margin-top: 0.5rem;
    }
    
    #bulkActionSelect {
        flex: 1;
        min-width: auto;
    }
}
</style>
</body>
</html>
