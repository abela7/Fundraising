<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$db = db();

// Define all required tables and their columns
$required_tables = [
    'call_center_sessions' => [
        'id', 'donor_id', 'agent_id', 'campaign_id', 'call_started_at', 'call_ended_at', 
        'duration_seconds', 'outcome', 'conversation_stage', 'donor_response_type', 
        'disposition', 'callback_scheduled_for', 'callback_reason', 'preferred_callback_time',
        'payment_discussed', 'payment_amount_discussed', 'payment_plan_id', 'objections_raised',
        'questions_asked', 'promises_made', 'call_quality', 'technical_issues', 'notes',
        'agent_notes_private', 'recording_url', 'quality_score', 'requires_supervisor_review',
        'donor_requested_supervisor', 'donor_threatened_legal', 'donor_claimed_already_paid',
        'donor_claimed_never_pledged', 'language_barrier_encountered', 'created_at', 'updated_at'
    ],
    'call_center_queues' => [
        'id', 'donor_id', 'queue_type', 'priority', 'assigned_to', 'campaign_id', 'status',
        'attempts_count', 'last_attempt_at', 'last_attempt_outcome', 'next_attempt_after',
        'max_attempts_reached', 'reason_for_queue', 'special_instructions', 
        'preferred_contact_time', 'completed_at', 'completion_notes', 'created_at', 'updated_at'
    ],
    'call_center_campaigns' => [
        'id', 'name', 'description', 'campaign_type', 'target_amount', 'collected_amount',
        'start_date', 'end_date', 'status', 'filters', 'total_donors', 'contacted_donors',
        'successful_contacts', 'created_by', 'created_at', 'updated_at'
    ],
    'call_center_conversation_steps' => [
        'id', 'session_id', 'step_order', 'step_type', 'step_completed', 'donor_response',
        'agent_notes', 'timestamp'
    ],
    'call_center_responses' => [
        'id', 'session_id', 'question_key', 'response', 'response_type', 'created_at'
    ],
    'call_center_objections' => [
        'id', 'objection_category', 'objection_text', 'suggested_response_en',
        'suggested_response_am', 'suggested_response_ti', 'escalate_to_supervisor',
        'success_rate', 'times_used', 'times_successful', 'created_at'
    ],
    'call_center_attempt_log' => [
        'id', 'donor_id', 'session_id', 'attempt_number', 'attempted_at', 'phone_number_used',
        'result', 'will_retry', 'retry_after', 'reason_for_failure'
    ],
    'call_center_special_circumstances' => [
        'id', 'donor_id', 'circumstance_type', 'severity', 'details', 'action_required',
        'handled_by', 'status', 'resolution_notes', 'reported_at', 'resolved_at'
    ],
    'call_center_contact_verification' => [
        'id', 'donor_id', 'session_id', 'verification_type', 'old_value', 'new_value',
        'verified_status', 'verified_by', 'verified_at'
    ],
    'call_center_sms_templates' => [
        'id', 'template_key', 'template_name', 'message_en', 'message_am', 'message_ti',
        'variables', 'category', 'is_active', 'created_by', 'created_at', 'updated_at'
    ],
    'call_center_sms_log' => [
        'id', 'donor_id', 'session_id', 'template_id', 'sms_type', 'phone_number',
        'message_content', 'status', 'sent_at', 'delivered_at', 'failed_reason',
        'cost_pence', 'provider_message_id', 'created_by', 'created_at'
    ],
    'call_center_disposition_rules' => [
        'id', 'outcome', 'disposition', 'auto_action', 'sms_template_id', 'callback_delay_hours',
        'max_attempts', 'priority_adjustment', 'is_active', 'created_at'
    ],
    'call_center_workflow_rules' => [
        'id', 'rule_name', 'rule_description', 'trigger_outcome', 'trigger_attempts_count',
        'trigger_conversation_stage', 'action_type', 'action_params', 'execute_delay_minutes',
        'max_executions_per_donor', 'is_active', 'priority', 'created_by', 'created_at', 'updated_at'
    ],
    'call_center_workflow_executions' => [
        'id', 'rule_id', 'session_id', 'donor_id', 'executed_at', 'action_taken',
        'success', 'error_message', 'result_data'
    ],
    'call_center_agent_stats' => [
        'id', 'agent_id', 'stat_date', 'total_calls', 'successful_calls', 'total_talk_time_seconds',
        'pledges_collected', 'payment_plans_created', 'sms_sent', 'callbacks_scheduled',
        'avg_call_duration_seconds', 'conversion_rate', 'quality_score_avg', 'created_at', 'updated_at'
    ],
    'churches' => [
        'id', 'name', 'city', 'address', 'representative_id', 'phone', 'is_active', 'created_at', 'updated_at'
    ]
];

// Check each table
$results = [];
$all_tables_exist = true;
$all_columns_exist = true;

foreach ($required_tables as $table_name => $required_columns) {
    $table_check = $db->query("SHOW TABLES LIKE '$table_name'");
    $table_exists = $table_check && $table_check->num_rows > 0;
    
    if (!$table_exists) {
        $all_tables_exist = false;
        $results[$table_name] = [
            'exists' => false,
            'columns' => [],
            'missing_columns' => $required_columns,
            'existing_columns' => []
        ];
    } else {
        // Get existing columns
        $columns_result = $db->query("SHOW COLUMNS FROM `$table_name`");
        $existing_columns = [];
        while ($col = $columns_result->fetch_assoc()) {
            $existing_columns[] = $col['Field'];
        }
        
        // Find missing columns
        $missing_columns = array_diff($required_columns, $existing_columns);
        
        if (!empty($missing_columns)) {
            $all_columns_exist = false;
        }
        
        $results[$table_name] = [
            'exists' => true,
            'columns' => $existing_columns,
            'missing_columns' => array_values($missing_columns),
            'existing_columns' => $existing_columns
        ];
    }
}

// Check if donors table has required columns for call center
$donors_columns_check = [];
$donors_table_check = $db->query("SHOW TABLES LIKE 'donors'");
if ($donors_table_check && $donors_table_check->num_rows > 0) {
    $donors_cols = $db->query("SHOW COLUMNS FROM `donors`");
    $donors_existing = [];
    while ($col = $donors_cols->fetch_assoc()) {
        $donors_existing[] = $col['Field'];
    }
    
    $donors_required = ['baptism_name', 'city', 'church_id', 'portal_profile_completed', 'portal_profile_completed_at'];
    $donors_missing = array_diff($donors_required, $donors_existing);
    
    $donors_columns_check = [
        'exists' => true,
        'missing' => array_values($donors_missing),
        'existing' => $donors_existing
    ];
} else {
    $donors_columns_check = [
        'exists' => false,
        'missing' => ['baptism_name', 'city', 'church_id', 'portal_profile_completed', 'portal_profile_completed_at'],
        'existing' => []
    ];
}

$page_title = 'Database Readiness Check';
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
                        <i class="fas fa-database me-2"></i>
                        Call Center Database Readiness Check
                    </h1>
                    <p class="content-subtitle">Verify all required tables and columns exist</p>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                    </a>
                    <button class="btn btn-primary" onclick="location.reload()">
                        <i class="fas fa-sync-alt me-2"></i>Refresh Check
                    </button>
                </div>
            </div>

            <!-- Overall Status -->
            <div class="alert alert-<?php echo ($all_tables_exist && $all_columns_exist) ? 'success' : 'warning'; ?> mb-4">
                <h4 class="alert-heading">
                    <i class="fas fa-<?php echo ($all_tables_exist && $all_columns_exist) ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                    <?php echo ($all_tables_exist && $all_columns_exist) ? 'Database is Ready!' : 'Database Setup Required'; ?>
                </h4>
                <?php if ($all_tables_exist && $all_columns_exist): ?>
                    <p class="mb-0">✅ All required tables and columns are present. Call Center is ready to use!</p>
                <?php else: ?>
                    <p class="mb-0">
                        ⚠️ Some tables or columns are missing. See details below and run the SQL scripts to fix.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Donors Table Check -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-users me-2"></i>Donors Table
                    </h5>
                    <span class="badge bg-<?php echo ($donors_columns_check['exists'] && empty($donors_columns_check['missing'])) ? 'success' : 'warning'; ?>">
                        <?php echo ($donors_columns_check['exists'] && empty($donors_columns_check['missing'])) ? 'Ready' : 'Needs Update'; ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php if (!$donors_columns_check['exists']): ?>
                        <div class="alert alert-danger">
                            <strong>❌ Donors table does not exist!</strong> This is a critical issue.
                        </div>
                    <?php elseif (!empty($donors_columns_check['missing'])): ?>
                        <div class="alert alert-warning">
                            <strong>⚠️ Missing Columns:</strong>
                            <ul class="mb-0 mt-2">
                                <?php foreach ($donors_columns_check['missing'] as $col): ?>
                                    <li><code><?php echo $col; ?></code></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="mt-3">
                            <strong>SQL to Add Missing Columns:</strong>
                            <pre class="bg-light p-3 mt-2"><code><?php
$sql = "-- Add missing columns to donors table\n";
foreach ($donors_columns_check['missing'] as $col) {
    switch($col) {
        case 'baptism_name':
            $sql .= "ALTER TABLE `donors` ADD COLUMN `baptism_name` varchar(255) DEFAULT NULL COMMENT 'Orthodox church baptismal name' AFTER `name`;\n";
            break;
        case 'city':
            $sql .= "ALTER TABLE `donors` ADD COLUMN `city` varchar(100) DEFAULT NULL COMMENT 'UK city where donor resides' AFTER `email`;\n";
            break;
        case 'church_id':
            $sql .= "ALTER TABLE `donors` ADD COLUMN `church_id` int(11) DEFAULT NULL COMMENT 'ID of church they attend' AFTER `city`;\n";
            $sql .= "ALTER TABLE `donors` ADD INDEX `idx_church_id` (`church_id`);\n";
            break;
        case 'portal_profile_completed':
            $sql .= "ALTER TABLE `donors` ADD COLUMN `portal_profile_completed` tinyint(1) DEFAULT 0 COMMENT 'Has donor completed welcome form?' AFTER `login_count`;\n";
            break;
        case 'portal_profile_completed_at':
            $sql .= "ALTER TABLE `donors` ADD COLUMN `portal_profile_completed_at` datetime DEFAULT NULL COMMENT 'When donor completed their profile' AFTER `portal_profile_completed`;\n";
            break;
    }
}
echo htmlspecialchars($sql);
?></code></pre>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success">
                            <strong>✅ All required columns exist!</strong> Donors table is ready for Call Center.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Call Center Tables Check -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-table me-2"></i>Call Center Tables (<?php echo count($required_tables); ?> tables)
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 30%">Table Name</th>
                                    <th style="width: 10%">Status</th>
                                    <th style="width: 10%">Columns</th>
                                    <th style="width: 50%">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $table_name => $table_info): ?>
                                    <tr>
                                        <td>
                                            <code><?php echo $table_name; ?></code>
                                        </td>
                                        <td>
                                            <?php if (!$table_info['exists']): ?>
                                                <span class="badge bg-danger">Missing</span>
                                            <?php elseif (!empty($table_info['missing_columns'])): ?>
                                                <span class="badge bg-warning">Incomplete</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Ready</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info">
                                                <?php echo count($table_info['existing_columns']); ?> / <?php echo count($required_tables[$table_name]); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!$table_info['exists']): ?>
                                                <span class="text-danger">
                                                    <i class="fas fa-times-circle me-1"></i>Table does not exist
                                                </span>
                                            <?php elseif (!empty($table_info['missing_columns'])): ?>
                                                <div>
                                                    <strong class="text-warning">Missing columns:</strong>
                                                    <ul class="mb-0 mt-1 small">
                                                        <?php foreach ($table_info['missing_columns'] as $col): ?>
                                                            <li><code><?php echo $col; ?></code></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-success">
                                                    <i class="fas fa-check-circle me-1"></i>All columns present
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Summary Statistics -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="text-primary mb-0"><?php echo count($required_tables); ?></h3>
                            <small class="text-muted">Total Tables Required</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="text-success mb-0">
                                <?php echo count(array_filter($results, fn($r) => $r['exists'] && empty($r['missing_columns']))); ?>
                            </h3>
                            <small class="text-muted">Tables Ready</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="text-warning mb-0">
                                <?php echo count(array_filter($results, fn($r) => $r['exists'] && !empty($r['missing_columns']))); ?>
                            </h3>
                            <small class="text-muted">Tables Incomplete</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="text-danger mb-0">
                                <?php echo count(array_filter($results, fn($r) => !$r['exists'])); ?>
                            </h3>
                            <small class="text-muted">Tables Missing</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Required -->
            <?php if (!$all_tables_exist || !$all_columns_exist): ?>
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-tools me-2"></i>Action Required
                    </h5>
                </div>
                <div class="card-body">
                    <p><strong>To fix the database, you need to:</strong></p>
                    <ol>
                        <li>Open <strong>phpMyAdmin</strong></li>
                        <li>Select your <strong>fundraising database</strong></li>
                        <li>Run the <strong>complete Call Center SQL script</strong> that creates all tables</li>
                        <li>If donors table is missing columns, run the SQL shown above</li>
                        <li>Refresh this page to verify everything is ready</li>
                    </ol>
                    <div class="alert alert-info mt-3">
                        <strong>💡 Tip:</strong> The SQL script should be in the same folder as this file, or you should have received it separately. 
                        It contains all the CREATE TABLE statements for the Call Center system.
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Links -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-link me-2"></i>Quick Links
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="index.php" class="btn btn-outline-primary">
                            <i class="fas fa-headset me-2"></i>Call Center Dashboard
                        </a>
                        <a href="SETUP_GUIDE.md" target="_blank" class="btn btn-outline-info">
                            <i class="fas fa-book me-2"></i>Setup Guide
                        </a>
                        <a href="README.md" target="_blank" class="btn btn-outline-info">
                            <i class="fas fa-question-circle me-2"></i>User Guide
                        </a>
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

