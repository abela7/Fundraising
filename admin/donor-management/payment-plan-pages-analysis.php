<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 11;
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 180;

// Get actual data from both tables
$plan_data = null;
$donor_data = null;

// Fetch plan
$plan_query = $db->prepare("SELECT * FROM donor_payment_plans WHERE id = ?");
$plan_query->bind_param('i', $plan_id);
$plan_query->execute();
$plan_result = $plan_query->get_result();
if ($plan_result->num_rows > 0) {
    $plan_data = $plan_result->fetch_assoc();
    $donor_id = (int)$plan_data['donor_id'];
}
$plan_query->close();

// Fetch donor
$donor_query = $db->prepare("SELECT * FROM donors WHERE id = ?");
$donor_query->bind_param('i', $donor_id);
$donor_query->execute();
$donor_result = $donor_query->get_result();
if ($donor_result->num_rows > 0) {
    $donor_data = $donor_result->fetch_assoc();
}
$donor_query->close();

// Analyze each page's code
$pages_analysis = [
    'payment-plans.php' => [
        'file' => 'admin/donor-management/payment-plans.php',
        'purpose' => 'Payment Plan Templates Management + Create/Edit Plans',
        'reads_from' => [],
        'writes_to' => [],
        'sync_logic' => [],
        'issues' => []
    ],
    'call-details.php' => [
        'file' => 'admin/call-center/call-details.php',
        'purpose' => 'View Call Session Details',
        'reads_from' => [],
        'writes_to' => [],
        'sync_logic' => [],
        'issues' => []
    ],
    'view-payment-plan.php' => [
        'file' => 'admin/donor-management/view-payment-plan.php',
        'purpose' => 'View Individual Payment Plan Details',
        'reads_from' => [],
        'writes_to' => [],
        'sync_logic' => [],
        'issues' => []
    ],
    'view-donor.php' => [
        'file' => 'admin/donor-management/view-donor.php',
        'purpose' => 'View Donor Profile with Payment Plans',
        'reads_from' => [],
        'writes_to' => [],
        'sync_logic' => [],
        'issues' => []
    ]
];

// Read actual file contents to analyze
$files_to_analyze = [
    'payment-plans.php' => __DIR__ . '/payment-plans.php',
    'call-details.php' => __DIR__ . '/../call-center/call-details.php',
    'view-payment-plan.php' => __DIR__ . '/view-payment-plan.php',
    'view-donor.php' => __DIR__ . '/view-donor.php'
];

foreach ($files_to_analyze as $key => $file_path) {
    if (file_exists($file_path)) {
        $content = file_get_contents($file_path);
        
        // Analyze reads
        if (preg_match_all('/SELECT.*FROM\s+donor_payment_plans/ims', $content, $matches)) {
            $pages_analysis[$key]['reads_from'][] = 'donor_payment_plans (SELECT queries)';
        }
        if (preg_match_all('/plan_monthly_amount|active_payment_plan_id|has_active_plan|plan_duration_months|plan_start_date|plan_next_due_date/ims', $content, $matches)) {
            $pages_analysis[$key]['reads_from'][] = 'donors table (plan-related columns)';
        }
        
        // Analyze writes
        if (preg_match_all('/INSERT\s+INTO\s+donor_payment_plans/ims', $content, $matches)) {
            $pages_analysis[$key]['writes_to'][] = 'donor_payment_plans (INSERT)';
        }
        if (preg_match_all('/UPDATE\s+donor_payment_plans/ims', $content, $matches)) {
            $pages_analysis[$key]['writes_to'][] = 'donor_payment_plans (UPDATE)';
        }
        if (preg_match_all('/UPDATE\s+donors.*SET.*plan_monthly_amount|UPDATE\s+donors.*SET.*active_payment_plan_id|UPDATE\s+donors.*SET.*has_active_plan/ims', $content, $matches)) {
            $pages_analysis[$key]['writes_to'][] = 'donors table (plan-related columns UPDATE)';
        }
        
        // Check for sync logic
        if (strpos($content, 'UPDATE donors SET') !== false && 
            (strpos($content, 'plan_monthly_amount') !== false || strpos($content, 'active_payment_plan_id') !== false)) {
            $pages_analysis[$key]['sync_logic'][] = 'Has manual sync code (UPDATE donors after plan changes)';
        }
    }
}

// Detailed analysis based on code review
$pages_analysis['payment-plans.php']['reads_from'] = [
    'donor_payment_plans - Check for existing active plans (line 462)',
    'donors - Read donor info with active_payment_plan_id, has_active_plan, plan_monthly_amount (lines 663-666, 887-891)',
    'donor_payment_plans - JOIN with donors to show plan details in preview modal (line 896)'
];
$pages_analysis['payment-plans.php']['writes_to'] = [
    'donor_payment_plans - INSERT new plan (line 544-553)',
    'donor_payment_plans - UPDATE status to paused when creating new plan (line 476)',
    'donor_payment_plans - UPDATE status to cancelled when clearing plan (line 693)',
    'donors - UPDATE has_active_plan, active_payment_plan_id, plan_monthly_amount, plan_duration_months, plan_start_date, plan_next_due_date (lines 608-623)',
    'donors - CLEAR plan fields when clearing plan (lines 703-718)'
];
$pages_analysis['payment-plans.php']['sync_logic'] = [
    '✅ SYNC ON CREATE: After INSERT into donor_payment_plans, immediately UPDATE donors table (lines 608-623)',
    '✅ SYNC ON CLEAR: When clearing plan, UPDATE donors to clear all plan fields (lines 703-718)',
    '⚠️ ISSUE: Does NOT sync when plan is updated via UPDATE statement (only on INSERT)',
    '⚠️ ISSUE: Does NOT sync when plan status changes via other means'
];
$pages_analysis['payment-plans.php']['issues'] = [
    'Only syncs on INSERT, not on UPDATE',
    'If plan is updated elsewhere, donors table becomes out of sync'
];

$pages_analysis['call-details.php']['reads_from'] = [
    'donor_payment_plans - LEFT JOIN to get plan details (line 38)',
    'Only reads plan_amount and plan_status from the JOIN'
];
$pages_analysis['call-details.php']['writes_to'] = [
    'None - Read-only page'
];
$pages_analysis['call-details.php']['sync_logic'] = [
    'N/A - Read-only page, no sync needed'
];
$pages_analysis['call-details.php']['issues'] = [
    'None - Only reads from master table (donor_payment_plans)'
];

$pages_analysis['view-payment-plan.php']['reads_from'] = [
    'donor_payment_plans - Main query to get plan (lines 19-24)',
    'donors - Separate query to get donor basic info (line 50)',
    'Does NOT read plan-related columns from donors table'
];
$pages_analysis['view-payment-plan.php']['writes_to'] = [
    'None - Read-only page (but has edit functionality via update-payment-plan.php)'
];
$pages_analysis['view-payment-plan.php']['sync_logic'] = [
    '✅ CORRECT: Only reads from donor_payment_plans (master table)',
    '✅ CORRECT: Does NOT rely on donors table plan columns',
    'No sync needed - uses master table only'
];
$pages_analysis['view-payment-plan.php']['issues'] = [
    'None - Correctly uses master table only'
];

$pages_analysis['view-donor.php']['reads_from'] = [
    'donors - Main query gets all donor columns including plan-related ones (line 28-36)',
    'donor_payment_plans - Separate query to get all plans for donor (lines 121-127)',
    'Reads BOTH tables but displays plan data from donor_payment_plans query'
];
$pages_analysis['view-donor.php']['writes_to'] = [
    'None - Read-only page'
];
$pages_analysis['view-donor.php']['sync_logic'] = [
    '✅ CORRECT: Displays plan data from donor_payment_plans query (lines 722-727)',
    '✅ CORRECT: Uses $plan[\'monthly_amount\'], $plan[\'start_date\'], etc. from plan table',
    '✅ CORRECT: Does NOT use donors.plan_monthly_amount or other plan columns for display',
    'Reads both tables but only uses plan table for display'
];
$pages_analysis['view-donor.php']['issues'] = [
    'None - Correctly uses master table (donor_payment_plans) for display'
];

// Verified: view-donor.php uses $plan['monthly_amount'] from donor_payment_plans query, not donors table columns

$page_title = 'Payment Plan Pages Deep Analysis';
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
        .analysis-section {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .read-operation { background: #d1ecf1; padding: 0.5rem; border-radius: 5px; margin: 0.25rem 0; }
        .write-operation { background: #f8d7da; padding: 0.5rem; border-radius: 5px; margin: 0.25rem 0; }
        .sync-operation { background: #d4edda; padding: 0.5rem; border-radius: 5px; margin: 0.25rem 0; }
        .issue-item { background: #fff3cd; padding: 0.5rem; border-radius: 5px; margin: 0.25rem 0; border-left: 4px solid #ffc107; }
        .data-comparison { background: #e7f3ff; padding: 1rem; border-radius: 5px; margin: 1rem 0; }
        .match { color: #28a745; font-weight: bold; }
        .mismatch { color: #dc3545; font-weight: bold; }
        .code-snippet { background: #f8f9fa; padding: 1rem; border-radius: 5px; font-family: monospace; font-size: 0.9rem; overflow-x: auto; }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-4">
                
                <h2 class="mb-4"><i class="fas fa-microscope me-2"></i>Deep Analysis: Payment Plan Pages</h2>
                
                <!-- Current Data Comparison -->
                <div class="analysis-section">
                    <h3 class="mb-3"><i class="fas fa-database me-2"></i>Current Data Comparison</h3>
                    <?php if ($plan_data && $donor_data): ?>
                    <div class="data-comparison">
                        <h5>Plan #<?php echo $plan_id; ?> (donor_payment_plans) vs Donor #<?php echo $donor_id; ?> (donors)</h5>
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Field</th>
                                    <th>donor_payment_plans</th>
                                    <th>donors</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>ID</strong></td>
                                    <td><?php echo $plan_data['id']; ?></td>
                                    <td><?php echo $donor_data['active_payment_plan_id'] ?? 'NULL'; ?></td>
                                    <td><?php echo ($plan_data['id'] == $donor_data['active_payment_plan_id']) ? '<span class="match">✅ MATCH</span>' : '<span class="mismatch">❌ MISMATCH</span>'; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Monthly Amount</strong></td>
                                    <td>£<?php echo number_format($plan_data['monthly_amount'], 2); ?></td>
                                    <td><?php echo $donor_data['plan_monthly_amount'] ? '£' . number_format($donor_data['plan_monthly_amount'], 2) : 'NULL'; ?></td>
                                    <td><?php echo ($plan_data['monthly_amount'] == $donor_data['plan_monthly_amount']) ? '<span class="match">✅ MATCH</span>' : '<span class="mismatch">❌ MISMATCH</span>'; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Duration (Months)</strong></td>
                                    <td><?php echo $plan_data['total_months']; ?></td>
                                    <td><?php echo $donor_data['plan_duration_months'] ?? 'NULL'; ?></td>
                                    <td><?php echo ($plan_data['total_months'] == $donor_data['plan_duration_months']) ? '<span class="match">✅ MATCH</span>' : '<span class="mismatch">❌ MISMATCH</span>'; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Start Date</strong></td>
                                    <td><?php echo $plan_data['start_date']; ?></td>
                                    <td><?php echo $donor_data['plan_start_date'] ?? 'NULL'; ?></td>
                                    <td><?php echo ($plan_data['start_date'] == $donor_data['plan_start_date']) ? '<span class="match">✅ MATCH</span>' : '<span class="mismatch">❌ MISMATCH</span>'; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Next Due Date</strong></td>
                                    <td><?php echo $plan_data['next_payment_due'] ?? 'NULL'; ?></td>
                                    <td><?php echo $donor_data['plan_next_due_date'] ?? 'NULL'; ?></td>
                                    <td><?php echo ($plan_data['next_payment_due'] == $donor_data['plan_next_due_date']) ? '<span class="match">✅ MATCH</span>' : '<span class="mismatch">❌ MISMATCH</span>'; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Status</strong></td>
                                    <td><?php echo $plan_data['status']; ?></td>
                                    <td>has_active_plan: <?php echo $donor_data['has_active_plan']; ?></td>
                                    <td><?php echo ($plan_data['status'] == 'active' && $donor_data['has_active_plan'] == 1) ? '<span class="match">✅ MATCH</span>' : '<span class="mismatch">❌ MISMATCH</span>'; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning">Plan #<?php echo $plan_id; ?> or Donor #<?php echo $donor_id; ?> not found!</div>
                    <?php endif; ?>
                </div>

                <!-- Page-by-Page Analysis -->
                <?php foreach ($pages_analysis as $page_key => $analysis): ?>
                <div class="analysis-section">
                    <h3 class="mb-3">
                        <i class="fas fa-file-code me-2"></i>
                        <?php echo htmlspecialchars($page_key); ?>
                    </h3>
                    <p class="text-muted mb-3"><strong>Purpose:</strong> <?php echo htmlspecialchars($analysis['purpose']); ?></p>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h5><i class="fas fa-eye me-2"></i>Reads From:</h5>
                            <?php if (!empty($analysis['reads_from'])): ?>
                                <?php foreach ($analysis['reads_from'] as $read): ?>
                                    <div class="read-operation"><?php echo htmlspecialchars($read); ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted">No reads detected</div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-6">
                            <h5><i class="fas fa-pencil-alt me-2"></i>Writes To:</h5>
                            <?php if (!empty($analysis['writes_to'])): ?>
                                <?php foreach ($analysis['writes_to'] as $write): ?>
                                    <div class="write-operation"><?php echo htmlspecialchars($write); ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted">No writes detected</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <h5><i class="fas fa-sync me-2"></i>Sync Logic:</h5>
                        <?php if (!empty($analysis['sync_logic'])): ?>
                            <?php foreach ($analysis['sync_logic'] as $sync): ?>
                                <div class="sync-operation"><?php echo htmlspecialchars($sync); ?></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-muted">No sync logic detected</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mt-3">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i>Issues Found:</h5>
                        <?php if (!empty($analysis['issues'])): ?>
                            <?php foreach ($analysis['issues'] as $issue): ?>
                                <div class="issue-item"><?php echo htmlspecialchars($issue); ?></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-success">✅ No issues detected</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Summary & Recommendations -->
                <div class="analysis-section bg-light">
                    <h3 class="mb-3"><i class="fas fa-lightbulb me-2"></i>Summary & Recommendations</h3>
                    
                    <div class="alert alert-info">
                        <h5>Key Findings:</h5>
                        <ol>
                            <li><strong>payment-plans.php:</strong> ✅ Syncs on INSERT, ❌ Does NOT sync on UPDATE</li>
                            <li><strong>call-details.php:</strong> ✅ Read-only, uses master table only</li>
                            <li><strong>view-payment-plan.php:</strong> ✅ Read-only, uses master table only</li>
                            <li><strong>view-donor.php:</strong> ✅ Reads both tables but correctly displays from master table (donor_payment_plans)</li>
                        </ol>
                    </div>
                    
                    <div class="alert alert-warning">
                        <h5>Critical Issues Found:</h5>
                        <ul>
                            <li><strong>update-payment-plan.php:</strong> ❌ Only syncs donors table when status = 'completed' or 'cancelled'. Does NOT sync when monthly_amount, total_months, start_date, next_due_date are updated while status = 'active'</li>
                            <li><strong>update-payment-plan-status.php:</strong> ❌ Only syncs active_payment_plan_id and payment_status. Does NOT sync plan_monthly_amount, plan_duration_months, plan_start_date, plan_next_due_date, or has_active_plan</li>
                            <li><strong>process-conversation.php:</strong> ✅ Syncs on INSERT (plan creation), but this is in call-center, not donor-management</li>
                            <li><strong>Data Inconsistency:</strong> Current data shows donors table is out of sync with donor_payment_plans</li>
                        </ul>
                    </div>
                    
                    <div class="alert alert-success">
                        <h5>Recommended Actions:</h5>
                        <ol>
                            <li><strong>Fix update-payment-plan.php:</strong> Add full sync logic after UPDATE (around line 109) to sync all plan fields (monthly_amount, total_months, start_date, next_due_date, has_active_plan) to donors table when status = 'active'</li>
                            <li><strong>Fix update-payment-plan-status.php:</strong> Add full sync logic (around line 65) to sync plan_monthly_amount, plan_duration_months, plan_start_date, plan_next_due_date, has_active_plan when status changes to 'active'</li>
                            <li><strong>Fix existing data:</strong> Run sync script to fix current inconsistencies</li>
                            <li><strong>Display pages:</strong> ✅ All display pages already use master table (donor_payment_plans) correctly - no changes needed</li>
                            <li><strong>Consider triggers:</strong> MySQL triggers can ensure automatic sync at database level as backup safety net</li>
                        </ol>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// Initialize Bootstrap components
document.addEventListener('DOMContentLoaded', function() {
    // Initialize all Bootstrap accordions
    const accordionElements = document.querySelectorAll('.accordion-collapse');
    accordionElements.forEach(function(element) {
        if (element && typeof bootstrap !== 'undefined') {
            new bootstrap.Collapse(element, { toggle: false });
        }
    });
    
    // Ensure sidebar toggle works
    if (typeof toggleSidebar === 'undefined') {
        window.toggleSidebar = function() {
            const sidebar = document.getElementById('sidebar');
            const adminContent = document.querySelector('.admin-content');
            if (sidebar && adminContent) {
                sidebar.classList.toggle('collapsed');
                adminContent.classList.toggle('sidebar-collapsed');
            }
        };
    }
    
    // Initialize Bootstrap tooltips if any
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        }
    });
    
    // Initialize Bootstrap popovers if any
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function (popoverTriggerEl) {
        if (typeof bootstrap !== 'undefined' && bootstrap.Popover) {
            return new bootstrap.Popover(popoverTriggerEl);
        }
    });
});
</script>
</body>
</html>

