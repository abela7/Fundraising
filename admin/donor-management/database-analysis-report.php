<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$plan_id = isset($_GET['id']) ? (int)$_GET['id'] : 11; // Default to 11

// Get actual table structures
$donors_structure = [];
$plan_structure = [];

$donors_cols = $db->query("SHOW COLUMNS FROM donors");
while ($col = $donors_cols->fetch_assoc()) {
    $donors_structure[] = $col;
}

$plan_cols = $db->query("SHOW COLUMNS FROM donor_payment_plans");
while ($col = $plan_cols->fetch_assoc()) {
    $plan_structure[] = $col;
}

// Get actual data
$plan_data = null;
$donor_data = null;

$plan_query = $db->prepare("SELECT * FROM donor_payment_plans WHERE id = ?");
$plan_query->bind_param('i', $plan_id);
$plan_query->execute();
$plan_result = $plan_query->get_result();
if ($plan_result->num_rows > 0) {
    $plan_data = $plan_result->fetch_assoc();
    
    if ($plan_data) {
        $donor_query = $db->prepare("SELECT * FROM donors WHERE id = ?");
        $donor_query->bind_param('i', $plan_data['donor_id']);
        $donor_query->execute();
        $donor_result = $donor_query->get_result();
        $donor_data = $donor_result->fetch_assoc();
        $donor_query->close();
    }
}
$plan_query->close();

$page_title = 'Database Analysis Report';
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
        .analysis-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .column-match {
            background: #d4edda;
            padding: 0.5rem;
            border-radius: 5px;
            margin: 0.25rem 0;
        }
        .column-mismatch {
            background: #f8d7da;
            padding: 0.5rem;
            border-radius: 5px;
            margin: 0.25rem 0;
        }
        .column-unused {
            background: #fff3cd;
            padding: 0.5rem;
            border-radius: 5px;
            margin: 0.25rem 0;
        }
        .solution-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin: 2rem 0;
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
                
                <h2 class="mb-4"><i class="fas fa-microscope me-2"></i>Database Deep Analysis</h2>

                <!-- Table Structures -->
                <div class="row">
                    <div class="col-lg-6">
                        <div class="analysis-card">
                            <h4 class="mb-3"><i class="fas fa-table me-2"></i>donor_payment_plans Table</h4>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Column</th>
                                            <th>Type</th>
                                            <th>Null</th>
                                            <th>Default</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($plan_structure as $col): ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($col['Field']); ?></code></td>
                                            <td><?php echo htmlspecialchars($col['Type']); ?></td>
                                            <td><?php echo $col['Null'] === 'YES' ? '<span class="badge bg-warning">YES</span>' : '<span class="badge bg-success">NO</span>'; ?></td>
                                            <td><?php echo htmlspecialchars($col['Default'] ?? 'NULL'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="analysis-card">
                            <h4 class="mb-3"><i class="fas fa-table me-2"></i>donors Table (Plan-Related Columns)</h4>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Column</th>
                                            <th>Type</th>
                                            <th>Null</th>
                                            <th>Default</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $plan_related_cols = ['has_active_plan', 'active_payment_plan_id', 'plan_monthly_amount', 'plan_duration_months', 'plan_start_date', 'plan_next_due_date'];
                                        foreach ($donors_structure as $col): 
                                            if (in_array($col['Field'], $plan_related_cols)):
                                        ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($col['Field']); ?></code></td>
                                            <td><?php echo htmlspecialchars($col['Type']); ?></td>
                                            <td><?php echo $col['Null'] === 'YES' ? '<span class="badge bg-warning">YES</span>' : '<span class="badge bg-success">NO</span>'; ?></td>
                                            <td><?php echo htmlspecialchars($col['Default'] ?? 'NULL'); ?></td>
                                        </tr>
                                        <?php 
                                            endif;
                                        endforeach; 
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Column Mapping Analysis -->
                <div class="analysis-card">
                    <h4 class="mb-3"><i class="fas fa-project-diagram me-2"></i>Column Mapping Analysis</h4>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h5>Master Table (donor_payment_plans)</h5>
                            <div class="column-match">
                                <strong>monthly_amount</strong> → Should sync to → <strong>donors.plan_monthly_amount</strong>
                            </div>
                            <div class="column-match">
                                <strong>total_months</strong> → Should sync to → <strong>donors.plan_duration_months</strong>
                            </div>
                            <div class="column-match">
                                <strong>start_date</strong> → Should sync to → <strong>donors.plan_start_date</strong>
                            </div>
                            <div class="column-match">
                                <strong>next_payment_due</strong> → Should sync to → <strong>donors.plan_next_due_date</strong>
                            </div>
                            <div class="column-match">
                                <strong>id</strong> → Should sync to → <strong>donors.active_payment_plan_id</strong>
                            </div>
                            <div class="column-match">
                                <strong>status = 'active'</strong> → Should set → <strong>donors.has_active_plan = 1</strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h5>Current Status (Plan #<?php echo $plan_id; ?>)</h5>
                            <?php if ($plan_data && $donor_data): ?>
                                <div class="<?php echo ($donor_data['active_payment_plan_id'] == $plan_data['id']) ? 'column-match' : 'column-mismatch'; ?>">
                                    <strong>active_payment_plan_id:</strong> <?php echo $donor_data['active_payment_plan_id'] ?? 'NULL'; ?> 
                                    (Expected: <?php echo $plan_data['id']; ?>)
                                </div>
                                <div class="<?php echo ($donor_data['plan_monthly_amount'] == $plan_data['monthly_amount']) ? 'column-match' : 'column-mismatch'; ?>">
                                    <strong>plan_monthly_amount:</strong> <?php echo $donor_data['plan_monthly_amount'] ?? 'NULL'; ?> 
                                    (Expected: <?php echo $plan_data['monthly_amount']; ?>)
                                </div>
                                <div class="<?php echo ($donor_data['plan_duration_months'] == $plan_data['total_months']) ? 'column-match' : 'column-mismatch'; ?>">
                                    <strong>plan_duration_months:</strong> <?php echo $donor_data['plan_duration_months'] ?? 'NULL'; ?> 
                                    (Expected: <?php echo $plan_data['total_months']; ?>)
                                </div>
                                <div class="<?php echo ($donor_data['plan_start_date'] == $plan_data['start_date']) ? 'column-match' : 'column-mismatch'; ?>">
                                    <strong>plan_start_date:</strong> <?php echo $donor_data['plan_start_date'] ?? 'NULL'; ?> 
                                    (Expected: <?php echo $plan_data['start_date']; ?>)
                                </div>
                                <div class="<?php echo ($donor_data['plan_next_due_date'] == $plan_data['next_payment_due']) ? 'column-match' : 'column-mismatch'; ?>">
                                    <strong>plan_next_due_date:</strong> <?php echo $donor_data['plan_next_due_date'] ?? 'NULL'; ?> 
                                    (Expected: <?php echo $plan_data['next_payment_due']; ?>)
                                </div>
                                <div class="<?php echo ($donor_data['has_active_plan'] == 1 && $plan_data['status'] == 'active') ? 'column-match' : 'column-mismatch'; ?>">
                                    <strong>has_active_plan:</strong> <?php echo $donor_data['has_active_plan']; ?> 
                                    (Expected: 1)
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">Plan #<?php echo $plan_id; ?> not found!</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Smart Solution -->
                <div class="solution-box">
                    <h3 class="mb-3"><i class="fas fa-lightbulb me-2"></i>Recommended Solution: MySQL TRIGGERS</h3>
                    <p class="mb-3">Use database triggers to automatically sync the `donors` table whenever `donor_payment_plans` is created or updated. This ensures data consistency at the database level.</p>
                    
                    <div class="bg-white text-dark p-3 rounded mt-3">
                        <h5>Benefits:</h5>
                        <ul>
                            <li>✅ Automatic sync - no manual code needed</li>
                            <li>✅ Database-level consistency</li>
                            <li>✅ Works even if code is bypassed</li>
                            <li>✅ No performance impact on reads</li>
                            <li>✅ Handles all INSERT/UPDATE operations</li>
                        </ul>
                    </div>
                </div>

                <!-- SQL Scripts -->
                <div class="analysis-card">
                    <h4 class="mb-3"><i class="fas fa-code me-2"></i>SQL Scripts to Implement</h4>
                    
                    <div class="accordion" id="sqlAccordion">
                        <!-- Trigger Script -->
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#triggerScript">
                                    <i class="fas fa-bolt me-2"></i>1. Create Auto-Sync Triggers
                                </button>
                            </h2>
                            <div id="triggerScript" class="accordion-collapse collapse show" data-bs-parent="#sqlAccordion">
                                <div class="accordion-body">
                                    <p>This creates triggers that automatically sync the donors table when payment plans are created or updated.</p>
                                    <pre class="bg-light p-3 rounded"><code>-- Drop existing triggers if they exist
DROP TRIGGER IF EXISTS sync_donor_on_plan_insert;
DROP TRIGGER IF EXISTS sync_donor_on_plan_update;

-- Trigger 1: After INSERT on donor_payment_plans
DELIMITER $$
CREATE TRIGGER sync_donor_on_plan_insert
AFTER INSERT ON donor_payment_plans
FOR EACH ROW
BEGIN
    IF NEW.status = 'active' THEN
        UPDATE donors 
        SET 
            has_active_plan = 1,
            active_payment_plan_id = NEW.id,
            plan_monthly_amount = NEW.monthly_amount,
            plan_duration_months = NEW.total_months,
            plan_start_date = NEW.start_date,
            plan_next_due_date = NEW.next_payment_due,
            payment_status = 'paying'
        WHERE id = NEW.donor_id;
    END IF;
END$$
DELIMITER ;

-- Trigger 2: After UPDATE on donor_payment_plans
DELIMITER $$
CREATE TRIGGER sync_donor_on_plan_update
AFTER UPDATE ON donor_payment_plans
FOR EACH ROW
BEGIN
    -- If plan becomes active, sync to donor
    IF NEW.status = 'active' THEN
        UPDATE donors 
        SET 
            has_active_plan = 1,
            active_payment_plan_id = NEW.id,
            plan_monthly_amount = NEW.monthly_amount,
            plan_duration_months = NEW.total_months,
            plan_start_date = NEW.start_date,
            plan_next_due_date = NEW.next_payment_due,
            payment_status = 'paying'
        WHERE id = NEW.donor_id;
    -- If plan becomes inactive, clear donor fields
    ELSEIF NEW.status IN ('completed', 'cancelled', 'defaulted') THEN
        UPDATE donors 
        SET 
            has_active_plan = 0,
            active_payment_plan_id = NULL,
            plan_monthly_amount = NULL,
            plan_duration_months = NULL,
            plan_start_date = NULL,
            plan_next_due_date = NULL,
            payment_status = CASE 
                WHEN NEW.status = 'completed' THEN 'completed'
                WHEN NEW.status = 'defaulted' THEN 'defaulted'
                ELSE 'not_started'
            END
        WHERE id = NEW.donor_id AND active_payment_plan_id = NEW.id;
    -- If plan is paused, keep link but update status
    ELSEIF NEW.status = 'paused' THEN
        UPDATE donors 
        SET 
            plan_next_due_date = NEW.next_payment_due,
            payment_status = 'not_started'
        WHERE id = NEW.donor_id AND active_payment_plan_id = NEW.id;
    END IF;
END$$
DELIMITER ;</code></pre>
                                </div>
                            </div>
                        </div>

                        <!-- One-Time Sync Script -->
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#syncScript">
                                    <i class="fas fa-sync me-2"></i>2. One-Time Sync (Fix Existing Data)
                                </button>
                            </h2>
                            <div id="syncScript" class="accordion-collapse collapse" data-bs-parent="#sqlAccordion">
                                <div class="accordion-body">
                                    <p>Run this once to sync all existing active plans to their donors.</p>
                                    <pre class="bg-light p-3 rounded"><code>-- Sync all active plans to donors table
UPDATE donors d
INNER JOIN donor_payment_plans p ON d.id = p.donor_id
SET 
    d.has_active_plan = 1,
    d.active_payment_plan_id = p.id,
    d.plan_monthly_amount = p.monthly_amount,
    d.plan_duration_months = p.total_months,
    d.plan_start_date = p.start_date,
    d.plan_next_due_date = p.next_payment_due,
    d.payment_status = 'paying'
WHERE p.status = 'active';

-- Clear donors that don't have active plans
UPDATE donors d
LEFT JOIN donor_payment_plans p ON d.active_payment_plan_id = p.id AND p.status = 'active'
SET 
    d.has_active_plan = 0,
    d.active_payment_plan_id = NULL,
    d.plan_monthly_amount = NULL,
    d.plan_duration_months = NULL,
    d.plan_start_date = NULL,
    d.plan_next_due_date = NULL,
    d.payment_status = CASE 
        WHEN d.total_paid >= d.total_pledged AND d.total_pledged > 0 THEN 'completed'
        WHEN d.total_pledged > 0 THEN 'not_started'
        ELSE 'no_pledge'
    END
WHERE p.id IS NULL AND d.has_active_plan = 1;</code></pre>
                                </div>
                            </div>
                        </div>

                        <!-- Fix Monthly Amount Script -->
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#fixAmountScript">
                                    <i class="fas fa-calculator me-2"></i>3. Fix Zero Monthly Amounts
                                </button>
                            </h2>
                            <div id="fixAmountScript" class="accordion-collapse collapse" data-bs-parent="#sqlAccordion">
                                <div class="accordion-body">
                                    <p>Fix any plans where monthly_amount is 0 but total_amount > 0.</p>
                                    <pre class="bg-light p-3 rounded"><code>-- Fix monthly_amount where it's 0 but total > 0
UPDATE donor_payment_plans
SET monthly_amount = ROUND(total_amount / NULLIF(total_payments, 0), 2)
WHERE monthly_amount = 0 
  AND total_amount > 0 
  AND total_payments > 0;</code></pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Implementation Steps -->
                <div class="analysis-card">
                    <h4 class="mb-3"><i class="fas fa-list-ol me-2"></i>Implementation Steps</h4>
                    <ol>
                        <li><strong>Run Script #3</strong> - Fix any zero monthly amounts first</li>
                        <li><strong>Run Script #2</strong> - Sync all existing data</li>
                        <li><strong>Run Script #1</strong> - Create triggers for future auto-sync</li>
                        <li><strong>Test</strong> - Create/update a payment plan and verify donor table updates automatically</li>
                    </ol>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

