<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();

// Check if columns still exist (to verify migration ran)
$check_cols = $db->query("SHOW COLUMNS FROM donors LIKE 'plan_monthly_amount'");
$migration_ran = ($check_cols->num_rows == 0);

// Count donors with active plans
$stats = $db->query("
    SELECT 
        COUNT(*) as total_donors,
        SUM(CASE WHEN has_active_plan = 1 THEN 1 ELSE 0 END) as with_active_plan,
        SUM(CASE WHEN active_payment_plan_id IS NOT NULL THEN 1 ELSE 0 END) as with_plan_id
    FROM donors
")->fetch_assoc();

$page_title = 'Migration Complete - Next Steps';
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
        .hero-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 3rem;
            margin-bottom: 2rem;
            text-align: center;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #667eea;
        }
        .stat-label {
            color: #64748b;
            font-size: 0.9rem;
        }
        .check-item {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border-left: 4px solid #28a745;
        }
        .test-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
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
                
                <!-- Hero Section -->
                <div class="hero-card">
                    <h1 class="mb-3"><i class="fas fa-check-circle me-2"></i>Migration Complete!</h1>
                    <p class="lead mb-0">Your payment plan system now uses Single Source of Truth architecture</p>
                </div>

                <?php if (!$migration_ran): ?>
                <div class="alert alert-danger">
                    <h5><i class="fas fa-exclamation-triangle me-2"></i>Warning: Migration Not Detected</h5>
                    <p class="mb-0">The cache columns still exist in the database. Did you run the SQL migration?</p>
                </div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo number_format($stats['total_donors']); ?></div>
                            <div class="stat-label">Total Donors</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo number_format($stats['with_active_plan']); ?></div>
                            <div class="stat-label">With Active Plans</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo number_format($stats['with_plan_id']); ?></div>
                            <div class="stat-label">Plan IDs Set</div>
                        </div>
                    </div>
                </div>

                <!-- What We Changed -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-wrench me-2"></i>What We Changed</h5>
                    </div>
                    <div class="card-body">
                        <div class="check-item">
                            <h6><i class="fas fa-check me-2 text-success"></i>Database Structure</h6>
                            <p class="mb-0">Removed 4 redundant cache columns from <code>donors</code> table</p>
                        </div>
                        
                        <div class="check-item">
                            <h6><i class="fas fa-check me-2 text-success"></i>process-conversation.php</h6>
                            <p class="mb-0">Now only sets flags (<code>has_active_plan</code>, <code>active_payment_plan_id</code>)</p>
                        </div>
                        
                        <div class="check-item">
                            <h6><i class="fas fa-check me-2 text-success"></i>donors.php</h6>
                            <p class="mb-0">Reads plan details from master table via LEFT JOIN</p>
                        </div>
                        
                        <div class="check-item">
                            <h6><i class="fas fa-check me-2 text-success"></i>conversation.php</h6>
                            <p class="mb-0">Already perfect - no changes needed</p>
                        </div>
                    </div>
                </div>

                <!-- Testing Guide -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-vial me-2"></i>Testing Checklist</h5>
                    </div>
                    <div class="card-body">
                        
                        <div class="test-card">
                            <h6 class="text-primary mb-3">1️⃣ Test Creating New Plan</h6>
                            <ol class="mb-0">
                                <li>Go to <a href="../call-center/index.php" target="_blank">Call Center</a></li>
                                <li>Start a call for a donor with balance > 0</li>
                                <li>Complete all steps and select "12 Months Plan"</li>
                                <li>Choose payment method (Bank Transfer/Card/Cash)</li>
                                <li>Click "Complete & Finish Call"</li>
                                <li><strong>Expected:</strong> Success message, no errors</li>
                            </ol>
                        </div>

                        <div class="test-card">
                            <h6 class="text-primary mb-3">2️⃣ Test Viewing Donor List</h6>
                            <ol class="mb-0">
                                <li>Go to <a href="donors.php" target="_blank">Donors List</a></li>
                                <li>Find the donor you just created a plan for</li>
                                <li>Click "View Details" button (eye icon)</li>
                                <li><strong>Expected:</strong> Plan details show correctly:
                                    <ul>
                                        <li>Monthly Amount: £33.33 (NOT £0.00)</li>
                                        <li>Duration: 12 months</li>
                                        <li>Start date is set</li>
                                        <li>Status: Active</li>
                                    </ul>
                                </li>
                            </ol>
                        </div>

                        <div class="test-card">
                            <h6 class="text-primary mb-3">3️⃣ Test Viewing Donor Profile</h6>
                            <ol class="mb-0">
                                <li>From donors list, click the donor's name</li>
                                <li>Or go directly to <code>view-donor.php?id=X</code></li>
                                <li>Scroll to "Payment Plans" accordion</li>
                                <li>Click to expand it</li>
                                <li><strong>Expected:</strong> Plan table shows:
                                    <ul>
                                        <li>Plan ID</li>
                                        <li>Start Date</li>
                                        <li>Total Amount</li>
                                        <li>Monthly Amount (correct value)</li>
                                        <li>Progress: 0/12 payments</li>
                                        <li>Status badge</li>
                                    </ul>
                                </li>
                            </ol>
                        </div>

                        <div class="test-card">
                            <h6 class="text-primary mb-3">4️⃣ Test Viewing Payment Plan Details</h6>
                            <ol class="mb-0">
                                <li>From Payment Plans section, click "View" button</li>
                                <li>Or go to <code>view-payment-plan.php?id=X</code></li>
                                <li><strong>Expected:</strong> Full plan details display:
                                    <ul>
                                        <li>Monthly Amount: £33.33</li>
                                        <li>Duration: 12 months</li>
                                        <li>Start/End dates</li>
                                        <li>Payment schedule</li>
                                        <li>Progress bar</li>
                                        <li>Payment history</li>
                                    </ul>
                                </li>
                            </ol>
                        </div>

                    </div>
                </div>

                <!-- Quick Links -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-link me-2"></i>Quick Links for Testing</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="mb-3">Call Center</h6>
                                <ul>
                                    <li><a href="../call-center/index.php" target="_blank">Call Queue</a></li>
                                    <li><a href="../call-center/call-history.php" target="_blank">Call History</a></li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="mb-3">Donor Management</h6>
                                <ul>
                                    <li><a href="donors.php" target="_blank">Donors List</a></li>
                                    <li><a href="payment-plans.php" target="_blank">Payment Plan Templates</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Analysis Links -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="fas fa-book me-2"></i>Analysis & Documentation</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="mb-3">Technical Analysis</h6>
                                <ul>
                                    <li><a href="table-usage-analysis-report.php" target="_blank">Table Usage Report</a> - How each page uses the tables</li>
                                    <li><a href="../call-center/conversation-analysis.php" target="_blank">Conversation.php Analysis</a> - Deep dive into the call flow</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="mb-3">Migration Files</h6>
                                <ul>
                                    <li><a href="remove-cache-columns-migration.sql" download>Download SQL Migration</a></li>
                                    <li><a href="MIGRATION_PLAN.md" download>Download Migration Plan</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- What If Things Break -->
                <div class="card shadow-sm border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-life-ring me-2"></i>If Something Breaks</h5>
                    </div>
                    <div class="card-body">
                        <h6 class="mb-3">Rollback Procedure (Emergency Only)</h6>
                        <p>If you need to revert the changes, run this SQL:</p>
                        <pre class="bg-light p-3 rounded"><code>-- Add columns back
ALTER TABLE donors 
ADD COLUMN plan_monthly_amount DECIMAL(10,2) NULL AFTER active_payment_plan_id,
ADD COLUMN plan_duration_months INT NULL AFTER plan_monthly_amount,
ADD COLUMN plan_start_date DATE NULL AFTER plan_duration_months,
ADD COLUMN plan_next_due_date DATE NULL AFTER plan_start_date;

-- Sync data
UPDATE donors d
INNER JOIN donor_payment_plans p ON d.active_payment_plan_id = p.id
SET d.plan_monthly_amount = p.monthly_amount,
    d.plan_duration_months = p.total_months,
    d.plan_start_date = p.start_date,
    d.plan_next_due_date = p.next_payment_due
WHERE p.status = 'active';</code></pre>
                        <p class="mb-0 text-danger"><strong>⚠️ Only do this if absolutely necessary!</strong> The new architecture is cleaner and better.</p>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

