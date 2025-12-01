<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/TwilioErrorCodes.php';
require_login();

$db = db();
$user_role = $_SESSION['user']['role'] ?? 'registrar';
$is_admin = ($user_role === 'admin');

// Date filter
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today

// Initialize variables
$error_stats = [];
$failed_calls = [];
$total_calls = 0;
$failed_calls_count = 0;
$successful_calls_count = 0;
$success_rate = 0;
$failure_rate = 0;

// Get error statistics
// Include both error codes AND failed statuses (busy, no-answer, etc.)
$error_stats_query = "
    SELECT 
        COALESCE(twilio_error_code, twilio_status) as error_key,
        twilio_error_code,
        twilio_error_message,
        twilio_status,
        COUNT(*) as error_count,
        COUNT(DISTINCT donor_id) as unique_donors,
        MIN(call_started_at) as first_occurrence,
        MAX(call_started_at) as last_occurrence
    FROM call_center_sessions
    WHERE call_source = 'twilio'
        AND (twilio_error_code IS NOT NULL OR twilio_status IN ('busy', 'no-answer', 'failed', 'canceled'))
        AND call_started_at BETWEEN ? AND ?
    GROUP BY error_key, twilio_error_code, twilio_error_message, twilio_status
    ORDER BY error_count DESC
";

$date_from_full = $date_from . ' 00:00:00';
$date_to_full = $date_to . ' 23:59:59';

$stmt = $db->prepare($error_stats_query);
if (!$stmt) {
    die("Error preparing query: " . $db->error);
}
$stmt->bind_param('ss', $date_from_full, $date_to_full);
$stmt->execute();
$result = $stmt->get_result();
$error_stats = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Get failed calls with details
// Include both error codes AND failed statuses
$failed_calls_query = "
    SELECT 
        s.id,
        s.call_started_at,
        s.twilio_error_code,
        s.twilio_error_message,
        s.twilio_status,
        s.twilio_duration,
        d.name as donor_name,
        d.phone as donor_phone,
        d.id as donor_id,
        u.name as agent_name
    FROM call_center_sessions s
    LEFT JOIN donors d ON s.donor_id = d.id
    LEFT JOIN users u ON s.agent_id = u.id
    WHERE s.call_source = 'twilio'
        AND (s.twilio_error_code IS NOT NULL OR s.twilio_status IN ('busy', 'no-answer', 'failed', 'canceled'))
        AND s.call_started_at BETWEEN ? AND ?
    ORDER BY s.call_started_at DESC
    LIMIT 100
";

$stmt = $db->prepare($failed_calls_query);
if (!$stmt) {
    die("Error preparing query: " . $db->error);
}
$stmt->bind_param('ss', $date_from_full, $date_to_full);
$stmt->execute();
$result = $stmt->get_result();
$failed_calls = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Calculate summary stats
// Successful = completed with duration > 0 and no error code
// Failed = has error code OR status is busy/no-answer/failed/canceled
$total_twilio_calls_query = "
    SELECT 
        COUNT(*) as total_calls,
        COUNT(CASE WHEN twilio_error_code IS NOT NULL OR twilio_status IN ('busy', 'no-answer', 'failed', 'canceled') THEN 1 END) as failed_calls,
        COUNT(CASE WHEN twilio_status = 'completed' AND COALESCE(twilio_duration, 0) > 0 AND twilio_error_code IS NULL THEN 1 END) as successful_calls
    FROM call_center_sessions
    WHERE call_source = 'twilio'
        AND call_started_at BETWEEN ? AND ?
";

$stmt = $db->prepare($total_twilio_calls_query);
if (!$stmt) {
    die("Error preparing query: " . $db->error);
}
$stmt->bind_param('ss', $date_from_full, $date_to_full);
$stmt->execute();
$result = $stmt->get_result();
$summary = $result ? $result->fetch_assoc() : [];
$stmt->close();

$total_calls = (int)($summary['total_calls'] ?? 0);
$failed_calls_count = (int)($summary['failed_calls'] ?? 0);
$successful_calls_count = (int)($summary['successful_calls'] ?? 0);
$success_rate = $total_calls > 0 ? round(($successful_calls_count / $total_calls) * 100, 1) : 0;
$failure_rate = $total_calls > 0 ? round(($failed_calls_count / $total_calls) * 100, 1) : 0;

$page_title = 'Twilio Error Report';
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
    <style>
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            height: 100%;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .error-card {
            background: white;
            border-radius: 8px;
            border-left: 4px solid #ef4444;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .error-card.warning {
            border-left-color: #f59e0b;
        }
        
        .error-card.info {
            border-left-color: #3b82f6;
        }
        
        .error-header {
            display: flex;
            justify-content: between;
            align-items: start;
            margin-bottom: 0.75rem;
        }
        
        .error-code {
            font-family: 'Courier New', monospace;
            background: #fee2e2;
            color: #991b1b;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .error-count {
            font-size: 1.5rem;
            font-weight: 700;
            color: #ef4444;
        }
        
        .action-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .action-retry {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .action-update {
            background: #fef3c7;
            color: #92400e;
        }
        
        .action-skip {
            background: #f3f4f6;
            color: #374151;
        }
        
        .action-escalate {
            background: #fee2e2;
            color: #991b1b;
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h4 mb-1">
                            <i class="fas fa-exclamation-triangle text-danger me-2"></i>
                            Twilio Error Report
                        </h1>
                        <p class="text-muted mb-0 small">Analyze failed calls and error patterns</p>
                    </div>
                    <div>
                        <a href="reports.php" class="btn btn-outline-secondary">
                            <i class="fas fa-chart-bar me-1"></i>All Reports
                        </a>
                    </div>
                </div>
                
                <!-- Date Filter -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">From Date</label>
                                <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">To Date</label>
                                <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-1"></i>Apply Filter
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Summary Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value text-primary"><?php echo number_format($total_calls); ?></div>
                            <div class="stat-label">Total Twilio Calls</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value text-success"><?php echo number_format($successful_calls_count); ?></div>
                            <div class="stat-label">Successful Calls (<?php echo $success_rate; ?>%)</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value text-danger"><?php echo number_format($failed_calls_count); ?></div>
                            <div class="stat-label">Failed Calls (<?php echo $failure_rate; ?>%)</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value text-warning"><?php echo count($error_stats); ?></div>
                            <div class="stat-label">Unique Error Types</div>
                        </div>
                    </div>
                </div>
                
                <!-- Error Breakdown -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-pie me-2 text-danger"></i>
                            Error Breakdown
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($error_stats)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <h5>No Errors Found</h5>
                                <p class="text-muted">All Twilio calls completed successfully in this period!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($error_stats as $error): ?>
                                <?php 
                                    // Use error code if available, otherwise use the status
                                    $errorCode = $error['twilio_error_code'] ?? $error['twilio_status'];
                                    $errorInfo = TwilioErrorCodes::getErrorInfo($errorCode);
                                    $action = TwilioErrorCodes::getRecommendedAction($errorCode);
                                    $isRetryable = TwilioErrorCodes::isRetryable($errorCode);
                                    $isBadNumber = TwilioErrorCodes::isBadNumber($errorCode);
                                    
                                    $cardClass = 'error-card';
                                    if ($isRetryable) {
                                        $cardClass .= ' info';
                                    } elseif ($isBadNumber) {
                                        $cardClass .= ' warning';
                                    }
                                    
                                    $actionClass = 'action-' . $action;
                                    $actionLabel = ucfirst(str_replace('_', ' ', $action));
                                    
                                    // Display code
                                    $displayCode = $error['twilio_error_code'] ?: strtoupper($error['twilio_status'] ?? 'UNKNOWN');
                                ?>
                                <div class="<?php echo $cardClass; ?>">
                                    <div class="row align-items-center">
                                        <div class="col-md-1 text-center">
                                            <div class="error-count"><?php echo $error['error_count']; ?></div>
                                            <small class="text-muted">calls</small>
                                        </div>
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <span class="error-code"><?php echo htmlspecialchars($displayCode); ?></span>
                                                <strong class="text-dark"><?php echo htmlspecialchars($errorInfo['category']); ?></strong>
                                            </div>
                                            <div class="mb-2">
                                                <i class="fas fa-info-circle text-muted me-1"></i>
                                                <?php echo htmlspecialchars($errorInfo['message']); ?>
                                            </div>
                                            <div class="small text-muted">
                                                <i class="fas fa-lightbulb me-1"></i>
                                                <strong>Recommended Action:</strong> <?php echo htmlspecialchars($errorInfo['action']); ?>
                                            </div>
                                        </div>
                                        <div class="col-md-3 text-end">
                                            <div class="mb-2">
                                                <span class="action-badge <?php echo $actionClass; ?>">
                                                    <?php echo $actionLabel; ?>
                                                </span>
                                            </div>
                                            <div class="small text-muted">
                                                <?php echo $error['unique_donors']; ?> unique donors affected
                                            </div>
                                            <div class="small text-muted">
                                                Last: <?php echo date('M j, g:i A', strtotime($error['last_occurrence'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Recent Failed Calls -->
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2 text-danger"></i>
                            Recent Failed Calls (Last 100)
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($failed_calls)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <p class="text-muted">No failed calls in this period</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date & Time</th>
                                            <th>Donor</th>
                                            <th>Agent</th>
                                            <th>Error</th>
                                            <th>Recommended Action</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($failed_calls as $call): ?>
                                            <?php 
                                                // Use error code if available, otherwise use status
                                                $errorKey = $call['twilio_error_code'] ?? $call['twilio_status'];
                                                $errorInfo = TwilioErrorCodes::getErrorInfo($errorKey);
                                                $action = TwilioErrorCodes::getRecommendedAction($errorKey);
                                                $actionClass = 'action-' . $action;
                                                $displayCode = $call['twilio_error_code'] ?: strtoupper($call['twilio_status'] ?? 'UNKNOWN');
                                            ?>
                                            <tr>
                                                <td class="small">
                                                    <?php echo date('M j, Y', strtotime($call['call_started_at'])); ?><br>
                                                    <span class="text-muted"><?php echo date('g:i A', strtotime($call['call_started_at'])); ?></span>
                                                </td>
                                                <td>
                                                    <div><?php echo htmlspecialchars($call['donor_name'] ?? 'Unknown'); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($call['donor_phone'] ?? ''); ?></small>
                                                </td>
                                                <td class="small"><?php echo htmlspecialchars($call['agent_name'] ?? 'Unknown'); ?></td>
                                                <td>
                                                    <div class="small">
                                                        <span class="error-code"><?php echo htmlspecialchars($displayCode); ?></span>
                                                    </div>
                                                    <div class="small text-muted mt-1">
                                                        <?php echo htmlspecialchars($errorInfo['category']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="action-badge <?php echo $actionClass; ?> small">
                                                        <?php echo ucfirst(str_replace('_', ' ', $action)); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="make-call.php?donor_id=<?php echo $call['donor_id']; ?>" 
                                                       class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-phone me-1"></i>Retry
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
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

