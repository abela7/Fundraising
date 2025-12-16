<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../services/TwilioService.php';
require_once __DIR__ . '/../../../services/TwilioErrorCodes.php';
require_login();
require_admin();

$db = db();
$page_title = 'Call Dashboard';

// Get Twilio configuration status
$twilio = TwilioService::fromDatabase($db);
$twilio_configured = ($twilio !== null);

// Get statistics for current month
$stats = [
    'total_calls' => 0,
    'successful_calls' => 0,
    'failed_calls' => 0,
    'success_rate' => 0,
    'total_duration' => 0,
    'unique_donors' => 0,
    'error_types' => 0
];

$date_from = date('Y-m-01') . ' 00:00:00';
$date_to = date('Y-m-d') . ' 23:59:59';

// Get call statistics
// Successful = completed with duration > 0 (actual conversation happened)
// Failed = has error code OR completed with 0 duration
$stats_query = "
    SELECT 
        COUNT(*) as total_calls,
        COUNT(CASE WHEN twilio_status = 'completed' AND COALESCE(twilio_duration, 0) > 0 AND twilio_error_code IS NULL THEN 1 END) as successful_calls,
        COUNT(CASE WHEN twilio_error_code IS NOT NULL OR (twilio_status IN ('busy', 'no-answer', 'failed', 'canceled')) THEN 1 END) as failed_calls,
        COUNT(DISTINCT donor_id) as unique_donors,
        SUM(COALESCE(twilio_duration, 0)) as total_duration,
        COUNT(DISTINCT twilio_error_code) as error_types
    FROM call_center_sessions
    WHERE call_source = 'twilio'
        AND call_started_at BETWEEN ? AND ?
";

$stmt = $db->prepare($stats_query);
$stmt->bind_param('ss', $date_from, $date_to);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($result) {
    $stats = $result;
    $stats['success_rate'] = $stats['total_calls'] > 0 
        ? round(($stats['successful_calls'] / $stats['total_calls']) * 100, 1) 
        : 0;
}

// Get top 3 error types
$top_errors_query = "
    SELECT 
        twilio_error_code,
        COUNT(*) as count
    FROM call_center_sessions
    WHERE call_source = 'twilio'
        AND twilio_error_code IS NOT NULL
        AND call_started_at BETWEEN ? AND ?
    GROUP BY twilio_error_code
    ORDER BY count DESC
    LIMIT 3
";

$stmt = $db->prepare($top_errors_query);
$stmt->bind_param('ss', $date_from, $date_to);
$stmt->execute();
$top_errors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get Twilio settings
$settings_query = "SELECT * FROM twilio_settings WHERE is_active = 1 LIMIT 1";
$settings = $db->query($settings_query)->fetch_assoc();

// Format total duration
$hours = floor($stats['total_duration'] / 3600);
$minutes = floor(($stats['total_duration'] % 3600) / 60);
$formatted_duration = $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/admin.css">
    <style>
        .dashboard-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            height: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .dashboard-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        
        .status-active {
            background: #22c55e;
            animation: pulse 2s infinite;
        }
        
        .status-inactive {
            background: #ef4444;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .quick-action-card {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            text-decoration: none;
            color: inherit;
            display: block;
            transition: all 0.2s;
            background: white;
        }
        
        .quick-action-card:hover {
            border-color: #0a6286;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(10, 98, 134, 0.1);
            color: inherit;
            text-decoration: none;
        }
        
        .quick-action-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.75rem;
        }
        
        .icon-primary {
            background: #dbeafe;
            color: #0a6286;
        }
        
        .icon-success {
            background: #dcfce7;
            color: #22c55e;
        }
        
        .icon-danger {
            background: #fee2e2;
            color: #ef4444;
        }
        
        .icon-warning {
            background: #fef3c7;
            color: #f59e0b;
        }
        
        .error-mini {
            background: #fef2f2;
            border-left: 3px solid #ef4444;
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h4 mb-1">
                            <i class="fas fa-phone-volume text-primary me-2"></i>
                            Call Dashboard
                        </h1>
                        <p class="text-muted mb-0 small">Manage voice calling and view call analytics</p>
                    </div>
                    <div>
                        <a href="../index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back to Donor Management
                        </a>
                    </div>
                </div>
                
                <!-- Status Banner -->
                <div class="alert <?php echo $twilio_configured ? 'alert-success' : 'alert-warning'; ?> mb-4">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <span class="status-indicator <?php echo $twilio_configured ? 'status-active' : 'status-inactive'; ?>"></span>
                            <strong>Call Service Status:</strong>
                            <?php if ($twilio_configured): ?>
                                Active and Ready
                                <?php if ($settings): ?>
                                    <span class="ms-2 small">
                                        • Number: <?php echo htmlspecialchars($settings['phone_number']); ?>
                                        • Recording: <?php echo $settings['recording_enabled'] ? 'Enabled' : 'Disabled'; ?>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                Not Configured
                            <?php endif; ?>
                        </div>
                        <?php if (!$twilio_configured): ?>
                            <a href="settings.php" class="btn btn-sm btn-warning">
                                <i class="fas fa-cog me-1"></i>Configure Now
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Monthly Statistics -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value text-primary"><?php echo number_format($stats['total_calls']); ?></div>
                            <div class="stat-label">Total Calls This Month</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value text-success"><?php echo $stats['success_rate']; ?>%</div>
                            <div class="stat-label">Success Rate</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value text-info"><?php echo number_format($stats['unique_donors']); ?></div>
                            <div class="stat-label">Unique Donors Contacted</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value text-warning"><?php echo $formatted_duration; ?></div>
                            <div class="stat-label">Total Talk Time</div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <a href="settings.php" class="quick-action-card">
                            <div class="quick-action-icon icon-primary">
                                <i class="fas fa-cog"></i>
                            </div>
                            <h6 class="mb-1">Call Settings</h6>
                            <small class="text-muted">Configure voice calling</small>
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="../../call-center/twilio-error-report.php" class="quick-action-card">
                            <div class="quick-action-icon icon-danger">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <h6 class="mb-1">Error Report</h6>
                            <small class="text-muted">View failed calls & errors</small>
                            <?php if ($stats['failed_calls'] > 0): ?>
                                <div class="mt-2">
                                    <span class="badge bg-danger"><?php echo $stats['failed_calls']; ?> errors</span>
                                </div>
                            <?php endif; ?>
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="../../call-center/call-history.php?call_source=twilio" class="quick-action-card">
                            <div class="quick-action-icon icon-success">
                                <i class="fas fa-history"></i>
                            </div>
                            <h6 class="mb-1">Call History</h6>
                            <small class="text-muted">View all voice calls</small>
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="../../call-center/ivr-recordings.php" class="quick-action-card">
                            <div class="quick-action-icon icon-warning" style="background: linear-gradient(135deg, #8b5cf6, #6d28d9);">
                                <i class="fas fa-microphone-alt"></i>
                            </div>
                            <h6 class="mb-1">IVR Recordings</h6>
                            <small class="text-muted">Record voice messages</small>
                        </a>
                    </div>
                </div>
                
                <!-- Additional Quick Actions -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <a href="../../call-center/inbound-callbacks.php" class="quick-action-card">
                            <div class="quick-action-icon" style="background: #fef3c7; color: #f59e0b;">
                                <i class="fas fa-phone-volume"></i>
                            </div>
                            <h6 class="mb-1">Inbound Callbacks</h6>
                            <small class="text-muted">Donors who called back</small>
                        </a>
                    </div>
                </div>
                
                <div class="row g-3">
                    <!-- Recent Error Summary -->
                    <div class="col-md-6">
                        <div class="dashboard-card">
                            <h5 class="mb-3">
                                <i class="fas fa-exclamation-circle text-danger me-2"></i>
                                Top Errors This Month
                            </h5>
                            <?php if (empty($top_errors)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                    <p class="text-muted mb-0">No errors this month! 🎉</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($top_errors as $error): ?>
                                    <?php 
                                        $errorInfo = TwilioErrorCodes::getErrorInfo($error['twilio_error_code']);
                                    ?>
                                    <div class="error-mini">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?php echo htmlspecialchars($errorInfo['category']); ?></strong>
                                                <div class="small text-muted">
                                                    Code: <?php echo htmlspecialchars($error['twilio_error_code']); ?>
                                                </div>
                                            </div>
                                            <span class="badge bg-danger"><?php echo $error['count']; ?> calls</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <div class="mt-3">
                                    <a href="../../call-center/twilio-error-report.php" class="btn btn-sm btn-outline-danger w-100">
                                        View Full Error Report <i class="fas fa-arrow-right ms-1"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- System Information -->
                    <div class="col-md-6">
                        <div class="dashboard-card">
                            <h5 class="mb-3">
                                <i class="fas fa-info-circle text-primary me-2"></i>
                                System Information
                            </h5>
                            <table class="table table-sm">
                                <tbody>
                                    <tr>
                                        <td><i class="fas fa-check-circle text-success me-2"></i>Call Service</td>
                                        <td class="text-end">
                                            <span class="badge <?php echo $twilio_configured ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $twilio_configured ? 'Configured' : 'Not Set Up'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php if ($settings): ?>
                                    <tr>
                                        <td><i class="fas fa-microphone text-primary me-2"></i>Call Recording</td>
                                        <td class="text-end">
                                            <span class="badge <?php echo $settings['recording_enabled'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $settings['recording_enabled'] ? 'Enabled' : 'Disabled'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><i class="fas fa-file-alt text-info me-2"></i>Transcription</td>
                                        <td class="text-end">
                                            <span class="badge <?php echo $settings['transcription_enabled'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $settings['transcription_enabled'] ? 'Enabled' : 'Disabled'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><i class="fas fa-clock text-warning me-2"></i>Last Test</td>
                                        <td class="text-end small text-muted">
                                            <?php echo $settings['last_test_at'] ? date('M j, Y g:i A', strtotime($settings['last_test_at'])) : 'Never'; ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <div class="mt-3">
                                <a href="settings.php" class="btn btn-sm btn-outline-primary w-100">
                                    <i class="fas fa-cog me-1"></i>Manage Settings
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Documentation -->
                <div class="card mt-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">
                            <i class="fas fa-book text-primary me-2"></i>
                            Documentation & Resources
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <h6><i class="fas fa-question-circle text-info me-2"></i>How It Works</h6>
                                <p class="small text-muted">
                                    Click-to-call enables voice calling directly from the system. When you call a donor, 
                                    your phone rings first, then you're automatically connected to the donor.
                                </p>
                            </div>
                            <div class="col-md-4">
                                <h6><i class="fas fa-shield-alt text-success me-2"></i>Privacy & Security</h6>
                                <p class="small text-muted">
                                    All calls go through your Liverpool phone number. Your personal number 
                                    stays private. Call recordings are encrypted and stored securely.
                                </p>
                            </div>
                            <div class="col-md-4">
                                <h6><i class="fas fa-chart-line text-warning me-2"></i>Analytics</h6>
                                <p class="small text-muted">
                                    Track call success rates, error patterns, and donor response times. 
                                    Use error reports to improve call quality and donor outreach.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/admin.js"></script>
</body>
</html>

