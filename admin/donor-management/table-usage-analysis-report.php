<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();

// Analyze which columns exist in both tables
$donor_plan_cols = [];
$plan_table_cols = [];

$donors_check = $db->query("SHOW COLUMNS FROM donors");
while ($col = $donors_check->fetch_assoc()) {
    $donors_check_col[] = $col['Field'];
    if (in_array($col['Field'], ['has_active_plan', 'active_payment_plan_id', 'plan_monthly_amount', 'plan_duration_months', 'plan_start_date', 'plan_next_due_date'])) {
        $donor_plan_cols[] = $col;
    }
}

$plan_check = $db->query("SHOW COLUMNS FROM donor_payment_plans");
while ($col = $plan_check->fetch_assoc()) {
    $plan_table_cols[] = $col;
}

// Get sample data for analysis
$sample_plan = null;
$sample_donor = null;

$plan_query = $db->query("SELECT * FROM donor_payment_plans WHERE id = 11 LIMIT 1");
if ($plan_query && $plan_query->num_rows > 0) {
    $sample_plan = $plan_query->fetch_assoc();
    
    if ($sample_plan) {
        $donor_query = $db->prepare("SELECT * FROM donors WHERE id = ?");
        $donor_query->bind_param('i', $sample_plan['donor_id']);
        $donor_query->execute();
        $donor_result = $donor_query->get_result();
        $sample_donor = $donor_result->fetch_assoc();
        $donor_query->close();
    }
}

$page_title = 'Table Usage Analysis Report';
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
        .page-card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .read-op {
            background: #d4edda;
            padding: 0.5rem;
            border-left: 4px solid #28a745;
            margin: 0.5rem 0;
            border-radius: 4px;
        }
        .write-op {
            background: #fff3cd;
            padding: 0.5rem;
            border-left: 4px solid #ffc107;
            margin: 0.5rem 0;
            border-radius: 4px;
        }
        .mixed-op {
            background: #f8d7da;
            padding: 0.5rem;
            border-left: 4px solid #dc3545;
            margin: 0.5rem 0;
            border-radius: 4px;
        }
        .code-block {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 5px;
            font-family: monospace;
            font-size: 0.9em;
            overflow-x: auto;
        }
        .table-name {
            background: #007bff;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-weight: bold;
            font-size: 0.85em;
        }
        .column-name {
            background: #6c757d;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-family: monospace;
            font-size: 0.85em;
        }
        .issue-badge {
            background: #dc3545;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-size: 0.8em;
        }
        .comparison-row {
            border-bottom: 1px solid #dee2e6;
            padding: 0.75rem 0;
        }
        .comparison-row:last-child {
            border-bottom: none;
        }
        .value-correct {
            color: #28a745;
            font-weight: bold;
        }
        .value-incorrect {
            color: #dc3545;
            font-weight: bold;
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
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-microscope me-2"></i>Table Usage Analysis Report</h2>
                    <div>
                        <a href="database-analysis-report.php?id=11" class="btn btn-outline-primary">
                            <i class="fas fa-database me-1"></i>Database Structure
                        </a>
                    </div>
                </div>

                <div class="alert alert-info">
                    <h5><i class="fas fa-info-circle me-2"></i>Report Purpose</h5>
                    <p class="mb-0">This report analyzes how each page in the system reads and writes to the <span class="table-name">donor_payment_plans</span> table and <span class="table-name">donors</span> table (plan-related columns). It identifies potential inconsistencies and synchronization issues.</p>
                </div>

                <!-- Sample Data Comparison -->
                <div class="page-card">
                    <h4 class="mb-3"><i class="fas fa-balance-scale me-2"></i>Live Data Comparison (Plan #11 / Donor #180)</h4>
                    
                    <?php if ($sample_plan && $sample_donor): ?>
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="fw-bold">Master Table: <span class="table-name">donor_payment_plans</span></h6>
                            <div class="code-block">
                                <div>id: <strong><?php echo $sample_plan['id']; ?></strong></div>
                                <div>monthly_amount: <strong class="value-correct">£<?php echo number_format((float)$sample_plan['monthly_amount'], 2); ?></strong></div>
                                <div>total_months: <strong><?php echo $sample_plan['total_months']; ?></strong></div>
                                <div>start_date: <strong><?php echo $sample_plan['start_date']; ?></strong></div>
                                <div>next_payment_due: <strong><?php echo $sample_plan['next_payment_due'] ?? 'NULL'; ?></strong></div>
                                <div>status: <strong><?php echo $sample_plan['status']; ?></strong></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold">Cache Table: <span class="table-name">donors</span> (ID: <?php echo $sample_donor['id']; ?>)</h6>
                            <div class="code-block">
                                <div>has_active_plan: <strong class="<?php echo $sample_donor['has_active_plan'] == 1 ? 'value-correct' : 'value-incorrect'; ?>"><?php echo $sample_donor['has_active_plan']; ?></strong> <?php if ($sample_donor['has_active_plan'] != 1): ?><span class="issue-badge">SHOULD BE 1</span><?php endif; ?></div>
                                <div>active_payment_plan_id: <strong class="<?php echo $sample_donor['active_payment_plan_id'] == $sample_plan['id'] ? 'value-correct' : 'value-incorrect'; ?>"><?php echo $sample_donor['active_payment_plan_id'] ?? 'NULL'; ?></strong> <?php if ($sample_donor['active_payment_plan_id'] != $sample_plan['id']): ?><span class="issue-badge">SHOULD BE <?php echo $sample_plan['id']; ?></span><?php endif; ?></div>
                                <div>plan_monthly_amount: <strong class="<?php echo abs((float)$sample_donor['plan_monthly_amount'] - (float)$sample_plan['monthly_amount']) < 0.01 ? 'value-correct' : 'value-incorrect'; ?>">£<?php echo $sample_donor['plan_monthly_amount'] ? number_format((float)$sample_donor['plan_monthly_amount'], 2) : 'NULL'; ?></strong> <?php if (abs((float)$sample_donor['plan_monthly_amount'] - (float)$sample_plan['monthly_amount']) >= 0.01): ?><span class="issue-badge">SHOULD BE £<?php echo number_format((float)$sample_plan['monthly_amount'], 2); ?></span><?php endif; ?></div>
                                <div>plan_duration_months: <strong class="<?php echo $sample_donor['plan_duration_months'] == $sample_plan['total_months'] ? 'value-correct' : 'value-incorrect'; ?>"><?php echo $sample_donor['plan_duration_months'] ?? 'NULL'; ?></strong> <?php if ($sample_donor['plan_duration_months'] != $sample_plan['total_months']): ?><span class="issue-badge">SHOULD BE <?php echo $sample_plan['total_months']; ?></span><?php endif; ?></div>
                                <div>plan_start_date: <strong class="<?php echo $sample_donor['plan_start_date'] == $sample_plan['start_date'] ? 'value-correct' : 'value-incorrect'; ?>"><?php echo $sample_donor['plan_start_date'] ?? 'NULL'; ?></strong> <?php if ($sample_donor['plan_start_date'] != $sample_plan['start_date']): ?><span class="issue-badge">SHOULD BE <?php echo $sample_plan['start_date']; ?></span><?php endif; ?></div>
                                <div>plan_next_due_date: <strong class="<?php echo $sample_donor['plan_next_due_date'] == $sample_plan['next_payment_due'] ? 'value-correct' : 'value-incorrect'; ?>"><?php echo $sample_donor['plan_next_due_date'] ?? 'NULL'; ?></strong> <?php if ($sample_donor['plan_next_due_date'] != $sample_plan['next_payment_due']): ?><span class="issue-badge">SHOULD BE <?php echo $sample_plan['next_payment_due'] ?? 'NULL'; ?></span><?php endif; ?></div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning">Sample plan #11 not found in database.</div>
                    <?php endif; ?>
                </div>

                <!-- Page Analysis -->
                <h4 class="mb-3"><i class="fas fa-file-code me-2"></i>Page-by-Page Analysis</h4>

                <!-- Page 1: payment-plans.php -->
                <div class="page-card">
                    <h5 class="mb-3">1. admin/donor-management/payment-plans.php</h5>
                    <p class="text-muted">Payment Plan Templates page (not individual plans)</p>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="fw-bold text-success"><i class="fas fa-eye me-2"></i>READ Operations</h6>
                            <div class="read-op">
                                <strong>Table:</strong> <span class="table-name">payment_plan_templates</span><br>
                                <strong>Purpose:</strong> List available templates<br>
                                <strong>Query:</strong> <code>SELECT * FROM payment_plan_templates</code>
                            </div>
                            <div class="alert alert-info mt-2 mb-0">
                                <small><i class="fas fa-check me-1"></i>This page does NOT directly read from donor_payment_plans or donors plan columns.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold text-warning"><i class="fas fa-edit me-2"></i>WRITE Operations</h6>
                            <div class="write-op">
                                <strong>Table:</strong> <span class="table-name">payment_plan_templates</span><br>
                                <strong>Purpose:</strong> Create/Update/Delete templates<br>
                                <strong>Operations:</strong> INSERT, UPDATE, DELETE on templates
                            </div>
                            <div class="alert alert-info mt-2 mb-0">
                                <small><i class="fas fa-check me-1"></i>No writes to donor_payment_plans or donors.</small>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-3">
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-check-circle me-2"></i><strong>Status:</strong> No sync issues - this page only manages templates
                    </div>
                </div>

                <!-- Page 2: call-details.php -->
                <div class="page-card">
                    <h5 class="mb-3">2. admin/call-center/call-details.php?id=57</h5>
                    <p class="text-muted">View call session details</p>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <h6 class="fw-bold text-success"><i class="fas fa-eye me-2"></i>READ Operations</h6>
                            
                            <div class="read-op">
                                <strong>Line 33-38:</strong> Join query<br>
                                <strong>Tables:</strong> <span class="table-name">call_center_sessions</span> LEFT JOIN <span class="table-name">donor_payment_plans</span><br>
                                <strong>Columns Read from Plans:</strong>
                                <ul class="mb-0 mt-2">
                                    <li><span class="column-name">pp.total_amount</span> as plan_amount</li>
                                    <li><span class="column-name">pp.status</span> as plan_status</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-warning mt-3 mb-0">
                                <i class="fas fa-exclamation-triangle me-2"></i><strong>Issue:</strong> Only reads from <span class="table-name">donor_payment_plans</span> table, NOT from <span class="table-name">donors</span> cache columns. If cache is used elsewhere for searching, this could cause inconsistency.
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <h6 class="fw-bold text-warning"><i class="fas fa-edit me-2"></i>WRITE Operations</h6>
                        <div class="alert alert-info mb-0">
                            <small><i class="fas fa-check me-1"></i>No write operations on payment plan tables.</small>
                        </div>
                    </div>
                    
                    <hr class="my-3">
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-check-circle me-2"></i><strong>Status:</strong> Correctly reads from master table (donor_payment_plans)
                    </div>
                </div>

                <!-- Page 3: view-payment-plan.php -->
                <div class="page-card">
                    <h5 class="mb-3">3. admin/donor-management/view-payment-plan.php?id=11</h5>
                    <p class="text-muted">View detailed payment plan information</p>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <h6 class="fw-bold text-success"><i class="fas fa-eye me-2"></i>READ Operations</h6>
                            
                            <div class="read-op">
                                <strong>Line 19-24:</strong> Primary plan query<br>
                                <strong>Table:</strong> <span class="table-name">donor_payment_plans</span> LEFT JOIN <span class="table-name">payment_plan_templates</span><br>
                                <strong>Columns Read:</strong>
                                <ul class="mb-0 mt-2">
                                    <li><span class="column-name">pp.*</span> (all columns including <strong>monthly_amount</strong>)</li>
                                    <li><span class="column-name">pp.total_months</span></li>
                                    <li><span class="column-name">pp.start_date</span></li>
                                    <li><span class="column-name">pp.next_payment_due</span></li>
                                    <li><span class="column-name">pp.total_amount</span></li>
                                    <li><span class="column-name">pp.amount_paid</span></li>
                                </ul>
                            </div>
                            
                            <div class="read-op">
                                <strong>Line 50-56:</strong> Donor query<br>
                                <strong>Table:</strong> <span class="table-name">donors</span><br>
                                <strong>Columns Read:</strong>
                                <ul class="mb-0 mt-2">
                                    <li><span class="column-name">id, name, phone, email, balance, total_pledged, total_paid</span></li>
                                    <li><strong>NOTE:</strong> Does NOT read plan_monthly_amount, plan_duration_months, etc.</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-success mt-3 mb-0">
                                <i class="fas fa-check-circle me-2"></i><strong>Good:</strong> Reads directly from <span class="table-name">donor_payment_plans</span> (master), does NOT rely on <span class="table-name">donors</span> cache
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <h6 class="fw-bold text-warning"><i class="fas fa-edit me-2"></i>WRITE Operations</h6>
                        <div class="alert alert-info mb-0">
                            <small><i class="fas fa-info-circle me-1"></i>Writes happen via separate endpoints (update-payment-plan.php, update-payment-plan-status.php)</small>
                        </div>
                    </div>
                    
                    <hr class="my-3">
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-check-circle me-2"></i><strong>Status:</strong> Correctly reads from master table. If showing £0.00, it's a database data issue, not a query issue.
                    </div>
                </div>

                <!-- Page 4: view-donor.php -->
                <div class="page-card">
                    <h5 class="mb-3">4. admin/donor-management/view-donor.php?id=180</h5>
                    <p class="text-muted">View complete donor profile</p>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <h6 class="fw-bold text-success"><i class="fas fa-eye me-2"></i>READ Operations</h6>
                            
                            <div class="read-op">
                                <strong>Line 28-41:</strong> Main donor query<br>
                                <strong>Table:</strong> <span class="table-name">donors</span> LEFT JOIN <span class="table-name">users</span> and <span class="table-name">churches</span><br>
                                <strong>Columns Read:</strong>
                                <ul class="mb-0 mt-2">
                                    <li><span class="column-name">d.*</span> (ALL donor columns including cache)</li>
                                    <li><span class="column-name">has_active_plan</span> ✅</li>
                                    <li><span class="column-name">active_payment_plan_id</span> ✅</li>
                                    <li><span class="column-name">plan_monthly_amount</span> ✅</li>
                                    <li><span class="column-name">plan_duration_months</span> ✅</li>
                                    <li><span class="column-name">plan_start_date</span> ✅</li>
                                    <li><span class="column-name">plan_next_due_date</span> ✅</li>
                                </ul>
                            </div>
                            
                            <div class="read-op">
                                <strong>Line 121-138:</strong> Payment Plans list query<br>
                                <strong>Table:</strong> <span class="table-name">donor_payment_plans</span> LEFT JOIN <span class="table-name">payment_plan_templates</span><br>
                                <strong>Columns Read:</strong>
                                <ul class="mb-0 mt-2">
                                    <li><span class="column-name">pp.*</span> (all plan columns)</li>
                                    <li>This includes <span class="column-name">monthly_amount</span>, <span class="column-name">total_months</span>, <span class="column-name">start_date</span>, etc.</li>
                                </ul>
                            </div>
                            
                            <div class="mixed-op mt-3">
                                <i class="fas fa-exclamation-circle me-2"></i><strong>CRITICAL ISSUE:</strong><br>
                                This page reads BOTH from <span class="table-name">donors</span> cache columns AND from <span class="table-name">donor_payment_plans</span> table.<br>
                                <strong>Problem:</strong> If cache is out of sync, different sections of the page will show different data!
                                <ul class="mb-0 mt-2">
                                    <li>Quick stats at top use <span class="table-name">donors</span> cache → Shows £0.00</li>
                                    <li>Payment Plans accordion uses <span class="table-name">donor_payment_plans</span> → Shows correct £33.33</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <h6 class="fw-bold text-warning"><i class="fas fa-edit me-2"></i>WRITE Operations</h6>
                        <div class="alert alert-info mb-0">
                            <small><i class="fas fa-info-circle me-1"></i>No direct writes from this page (view only)</small>
                        </div>
                    </div>
                    
                    <hr class="my-3">
                    <div class="alert alert-danger mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i><strong>Status:</strong> INCONSISTENT - Uses both master and cache, causing display discrepancies
                    </div>
                </div>

                <!-- Page 5: process-conversation.php -->
                <div class="page-card">
                    <h5 class="mb-3">5. admin/call-center/process-conversation.php</h5>
                    <p class="text-muted">Creates payment plans when call center agent completes call</p>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <h6 class="fw-bold text-warning"><i class="fas fa-edit me-2"></i>WRITE Operations</h6>
                            
                            <div class="write-op">
                                <strong>Line 239-318:</strong> Create payment plan<br>
                                <strong>Table:</strong> <span class="table-name">donor_payment_plans</span><br>
                                <strong>Columns Written:</strong>
                                <ul class="mb-0 mt-2">
                                    <li><span class="column-name">donor_id, pledge_id, template_id</span></li>
                                    <li><span class="column-name">total_amount, monthly_amount</span> ✅ (calculated)</li>
                                    <li><span class="column-name">total_months, total_payments</span></li>
                                    <li><span class="column-name">start_date, payment_day</span></li>
                                    <li><span class="column-name">plan_frequency_unit, plan_frequency_number</span></li>
                                    <li><span class="column-name">payment_method, next_payment_due</span></li>
                                    <li><span class="column-name">status = 'active'</span></li>
                                </ul>
                            </div>
                            
                            <div class="write-op">
                                <strong>Line 320-324:</strong> Update donor<br>
                                <strong>Table:</strong> <span class="table-name">donors</span><br>
                                <strong>Columns Written:</strong>
                                <ul class="mb-0 mt-2">
                                    <li><span class="column-name">active_payment_plan_id</span> = new plan_id ✅</li>
                                    <li><span class="column-name">payment_status = 'paying'</span> ✅</li>
                                </ul>
                            </div>
                            
                            <div class="mixed-op mt-3">
                                <i class="fas fa-exclamation-circle me-2"></i><strong>CRITICAL ISSUE:</strong><br>
                                Only updates 2 columns in <span class="table-name">donors</span> table, but DOES NOT sync:
                                <ul class="mb-0 mt-2">
                                    <li><span class="column-name">has_active_plan</span> → Should be set to 1</li>
                                    <li><span class="column-name">plan_monthly_amount</span> → Should be set to monthly_amount</li>
                                    <li><span class="column-name">plan_duration_months</span> → Should be set to total_months</li>
                                    <li><span class="column-name">plan_start_date</span> → Should be set to start_date</li>
                                    <li><span class="column-name">plan_next_due_date</span> → Should be set to next_payment_due</li>
                                </ul>
                                <strong>This is the root cause of the sync issue!</strong>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-3">
                    <div class="alert alert-danger mb-0">
                        <i class="fas fa-times-circle me-2"></i><strong>Status:</strong> INCOMPLETE SYNC - Only sets 2 of 6 cache columns
                    </div>
                </div>

                <!-- Solution Recommendation -->
                <div class="page-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h4 class="mb-3"><i class="fas fa-lightbulb me-2"></i>Recommended Solutions</h4>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h5 class="mb-3">Option 1: Code-Based Sync (Quick Fix)</h5>
                            <div class="bg-white text-dark p-3 rounded">
                                <p><strong>Update process-conversation.php (Line 320-324)</strong></p>
                                <p>Change from:</p>
                                <pre class="code-block">UPDATE donors 
SET active_payment_plan_id = ?, 
    payment_status = 'paying' 
WHERE id = ?</pre>
                                <p>To:</p>
                                <pre class="code-block">UPDATE donors 
SET active_payment_plan_id = ?,
    has_active_plan = 1,
    plan_monthly_amount = ?,
    plan_duration_months = ?,
    plan_start_date = ?,
    plan_next_due_date = ?,
    payment_status = 'paying'
WHERE id = ?</pre>
                                <p class="mb-0 text-success"><i class="fas fa-check me-1"></i>Also update other files: update-payment-plan.php, update-payment-plan-status.php</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h5 class="mb-3">Option 2: Database Triggers (Automatic)</h5>
                            <div class="bg-white text-dark p-3 rounded">
                                <p><strong>Create MySQL triggers</strong></p>
                                <p>Triggers automatically sync donors table when donor_payment_plans is modified:</p>
                                <ul>
                                    <li>✅ No code changes needed</li>
                                    <li>✅ Works for all operations</li>
                                    <li>✅ Can't be bypassed</li>
                                    <li>✅ Database-level consistency</li>
                                </ul>
                                <p class="mb-0">
                                    <a href="create-payment-plan-triggers.sql" class="btn btn-primary" download>
                                        <i class="fas fa-download me-1"></i>Download Trigger SQL
                                    </a>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-3" style="border-color: rgba(255,255,255,0.3);">
                    
                    <h5 class="mb-3">Option 3: Remove Cache Columns (Clean Architecture)</h5>
                    <div class="bg-white text-dark p-3 rounded">
                        <p><strong>Eliminate duplication entirely</strong></p>
                        <p>Remove plan_monthly_amount, plan_duration_months, plan_start_date, plan_next_due_date from donors table and always read from donor_payment_plans:</p>
                        <ul>
                            <li>✅ Single source of truth</li>
                            <li>✅ No sync issues possible</li>
                            <li>❌ Requires JOIN for every query</li>
                            <li>❌ May impact search performance</li>
                            <li>❌ Requires updating many files</li>
                        </ul>
                        <p class="mb-0 text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Most disruptive option, but cleanest long-term</p>
                    </div>
                </div>

                <!-- Summary -->
                <div class="page-card">
                    <h4 class="mb-3"><i class="fas fa-clipboard-check me-2"></i>Summary</h4>
                    
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Page</th>
                                <th>Reads From</th>
                                <th>Writes To</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>payment-plans.php</td>
                                <td><span class="table-name">templates</span> only</td>
                                <td><span class="table-name">templates</span> only</td>
                                <td><span class="badge bg-success">OK</span></td>
                            </tr>
                            <tr>
                                <td>call-details.php</td>
                                <td><span class="table-name">donor_payment_plans</span> (master)</td>
                                <td>-</td>
                                <td><span class="badge bg-success">OK</span></td>
                            </tr>
                            <tr>
                                <td>view-payment-plan.php</td>
                                <td><span class="table-name">donor_payment_plans</span> (master)</td>
                                <td>via separate endpoints</td>
                                <td><span class="badge bg-success">OK</span></td>
                            </tr>
                            <tr>
                                <td>view-donor.php</td>
                                <td><span class="table-name">donors</span> cache + <span class="table-name">donor_payment_plans</span></td>
                                <td>-</td>
                                <td><span class="badge bg-danger">INCONSISTENT</span></td>
                            </tr>
                            <tr>
                                <td>process-conversation.php</td>
                                <td>-</td>
                                <td><span class="table-name">donor_payment_plans</span> (full) + <span class="table-name">donors</span> (partial)</td>
                                <td><span class="badge bg-danger">INCOMPLETE SYNC</span></td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div class="alert alert-danger mb-0">
                        <h5 class="mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Root Cause</h5>
                        <p class="mb-0"><strong>process-conversation.php</strong> creates plans but only updates 2 of 6 cache columns in the donors table. This leaves the cache out of sync, causing view-donor.php to show incorrect data.</p>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

