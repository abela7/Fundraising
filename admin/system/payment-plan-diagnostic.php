<?php
/**
 * Payment Plan System Diagnostic
 * This page checks all database requirements for the payment plan flow
 */

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();

$db = db();
$results = [];

// Helper function to check if column exists
function columnExists($db, $table, $column) {
    $result = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $result && $result->num_rows > 0;
}

// Helper function to check if table exists
function tableExists($db, $table) {
    $result = $db->query("SHOW TABLES LIKE '{$table}'");
    return $result && $result->num_rows > 0;
}

// ============================================
// 1. CHECK REQUIRED TABLES
// ============================================
$results['tables'] = [
    'title' => '1. Required Tables',
    'items' => []
];

$required_tables = [
    'donors' => 'Stores donor information',
    'pledges' => 'Stores pledge commitments',
    'pledge_payments' => 'Stores payments against pledges',
    'donor_payment_plans' => 'Stores payment plan details',
    'payment_plan_templates' => 'Stores payment plan templates (optional)',
    'audit_logs' => 'Stores audit trail'
];

foreach ($required_tables as $table => $description) {
    $exists = tableExists($db, $table);
    $results['tables']['items'][] = [
        'name' => $table,
        'description' => $description,
        'status' => $exists ? 'OK' : 'MISSING',
        'ok' => $exists
    ];
}

// ============================================
// 2. CHECK DONORS TABLE COLUMNS
// ============================================
$results['donors_columns'] = [
    'title' => '2. Donors Table - Required Columns',
    'items' => []
];

$donor_columns = [
    'id' => 'Primary key',
    'name' => 'Donor name',
    'phone' => 'Phone number',
    'balance' => 'Outstanding balance',
    'has_active_plan' => 'Flag for active payment plan (TINYINT)',
    'active_payment_plan_id' => 'FK to donor_payment_plans',
    'plan_next_due_date' => 'Next payment due date (optional)',
    'total_paid' => 'Total amount paid',
    'last_payment_date' => 'Last payment date'
];

if (tableExists($db, 'donors')) {
    foreach ($donor_columns as $column => $description) {
        $exists = columnExists($db, 'donors', $column);
        $results['donors_columns']['items'][] = [
            'name' => $column,
            'description' => $description,
            'status' => $exists ? 'OK' : 'MISSING',
            'ok' => $exists
        ];
    }
} else {
    $results['donors_columns']['items'][] = [
        'name' => 'donors table',
        'description' => 'Table does not exist',
        'status' => 'TABLE MISSING',
        'ok' => false
    ];
}

// ============================================
// 3. CHECK PLEDGE_PAYMENTS TABLE COLUMNS
// ============================================
$results['pledge_payments_columns'] = [
    'title' => '3. Pledge Payments Table - Required Columns',
    'items' => []
];

$pp_columns = [
    'id' => 'Primary key',
    'pledge_id' => 'FK to pledges',
    'donor_id' => 'FK to donors',
    'payment_plan_id' => 'FK to donor_payment_plans (CRITICAL for plan tracking)',
    'amount' => 'Payment amount',
    'payment_method' => 'cash, bank_transfer, card, other',
    'payment_date' => 'Date of payment',
    'status' => 'pending, confirmed, voided',
    'reference_number' => 'Payment reference',
    'payment_proof' => 'Uploaded proof file path',
    'approved_by_user_id' => 'Admin who approved',
    'approved_at' => 'Approval timestamp'
];

if (tableExists($db, 'pledge_payments')) {
    foreach ($pp_columns as $column => $description) {
        $exists = columnExists($db, 'pledge_payments', $column);
        $critical = ($column === 'payment_plan_id');
        $results['pledge_payments_columns']['items'][] = [
            'name' => $column,
            'description' => $description,
            'status' => $exists ? 'OK' : ($critical ? 'CRITICAL - MISSING' : 'MISSING'),
            'ok' => $exists,
            'critical' => $critical && !$exists
        ];
    }
} else {
    $results['pledge_payments_columns']['items'][] = [
        'name' => 'pledge_payments table',
        'description' => 'Table does not exist',
        'status' => 'TABLE MISSING',
        'ok' => false
    ];
}

// ============================================
// 4. CHECK DONOR_PAYMENT_PLANS TABLE COLUMNS
// ============================================
$results['dpp_columns'] = [
    'title' => '4. Donor Payment Plans Table - Required Columns',
    'items' => []
];

$dpp_columns = [
    'id' => 'Primary key',
    'donor_id' => 'FK to donors',
    'pledge_id' => 'FK to pledges (optional)',
    'template_id' => 'FK to payment_plan_templates (optional)',
    'total_amount' => 'Total pledge amount',
    'monthly_amount' => 'Amount per payment',
    'total_payments' => 'Total number of payments',
    'payments_made' => 'Number of payments completed (CRITICAL)',
    'amount_paid' => 'Total amount paid so far',
    'start_date' => 'Plan start date',
    'next_payment_due' => 'Next payment due date',
    'last_payment_date' => 'Last payment date',
    'status' => 'active, completed, cancelled',
    'plan_frequency_unit' => 'week, month, year',
    'plan_frequency_number' => 'Frequency multiplier',
    'payment_day' => 'Day of month for payment'
];

if (tableExists($db, 'donor_payment_plans')) {
    foreach ($dpp_columns as $column => $description) {
        $exists = columnExists($db, 'donor_payment_plans', $column);
        $results['dpp_columns']['items'][] = [
            'name' => $column,
            'description' => $description,
            'status' => $exists ? 'OK' : 'MISSING',
            'ok' => $exists
        ];
    }
} else {
    $results['dpp_columns']['items'][] = [
        'name' => 'donor_payment_plans table',
        'description' => 'Table does not exist',
        'status' => 'TABLE MISSING',
        'ok' => false
    ];
}

// ============================================
// 5. CHECK SAMPLE DATA - DONORS WITH ACTIVE PLANS
// ============================================
$results['donors_with_plans'] = [
    'title' => '5. Donors With Active Payment Plans',
    'items' => [],
    'data' => []
];

if (tableExists($db, 'donors') && columnExists($db, 'donors', 'has_active_plan')) {
    $query = "
        SELECT d.id, d.name, d.phone, d.balance, d.has_active_plan, d.active_payment_plan_id,
               dpp.id as plan_id, dpp.payments_made, dpp.total_payments, dpp.amount_paid, 
               dpp.monthly_amount, dpp.status as plan_status, dpp.next_payment_due
        FROM donors d
        LEFT JOIN donor_payment_plans dpp ON d.active_payment_plan_id = dpp.id
        WHERE d.has_active_plan = 1
        ORDER BY d.id DESC
        LIMIT 20
    ";
    
    try {
        $res = $db->query($query);
        while ($row = $res->fetch_assoc()) {
            $results['donors_with_plans']['data'][] = $row;
        }
        $results['donors_with_plans']['items'][] = [
            'name' => 'Query executed',
            'description' => 'Found ' . count($results['donors_with_plans']['data']) . ' donors with active plans',
            'status' => 'OK',
            'ok' => true
        ];
    } catch (Exception $e) {
        $results['donors_with_plans']['items'][] = [
            'name' => 'Query failed',
            'description' => $e->getMessage(),
            'status' => 'ERROR',
            'ok' => false
        ];
    }
} else {
    $results['donors_with_plans']['items'][] = [
        'name' => 'Cannot check',
        'description' => 'Required columns missing in donors table',
        'status' => 'SKIPPED',
        'ok' => false
    ];
}

// ============================================
// 6. CHECK RECENT PLEDGE PAYMENTS
// ============================================
$results['recent_payments'] = [
    'title' => '6. Recent Pledge Payments (Last 20)',
    'items' => [],
    'data' => []
];

if (tableExists($db, 'pledge_payments')) {
    $has_plan_col = columnExists($db, 'pledge_payments', 'payment_plan_id');
    
    $query = "
        SELECT pp.id, pp.donor_id, pp.pledge_id, " . ($has_plan_col ? "pp.payment_plan_id," : "NULL as payment_plan_id,") . "
               pp.amount, pp.status, pp.payment_method, pp.payment_date,
               d.name as donor_name
        FROM pledge_payments pp
        LEFT JOIN donors d ON pp.donor_id = d.id
        ORDER BY pp.id DESC
        LIMIT 20
    ";
    
    try {
        $res = $db->query($query);
        while ($row = $res->fetch_assoc()) {
            $results['recent_payments']['data'][] = $row;
        }
        
        // Check how many have payment_plan_id set
        $with_plan = 0;
        $without_plan = 0;
        foreach ($results['recent_payments']['data'] as $p) {
            if ($p['payment_plan_id']) {
                $with_plan++;
            } else {
                $without_plan++;
            }
        }
        
        $results['recent_payments']['items'][] = [
            'name' => 'payment_plan_id column',
            'description' => $has_plan_col ? 'Column EXISTS' : 'Column MISSING - payments cannot link to plans!',
            'status' => $has_plan_col ? 'OK' : 'CRITICAL - MISSING',
            'ok' => $has_plan_col,
            'critical' => !$has_plan_col
        ];
        
        $results['recent_payments']['items'][] = [
            'name' => 'Payments with plan link',
            'description' => "{$with_plan} payments linked to plans, {$without_plan} without plan link",
            'status' => $with_plan > 0 ? 'OK' : 'WARNING',
            'ok' => $with_plan > 0
        ];
        
    } catch (Exception $e) {
        $results['recent_payments']['items'][] = [
            'name' => 'Query failed',
            'description' => $e->getMessage(),
            'status' => 'ERROR',
            'ok' => false
        ];
    }
}

// ============================================
// 7. CHECK PAYMENT PLANS DATA
// ============================================
$results['payment_plans'] = [
    'title' => '7. Active Payment Plans',
    'items' => [],
    'data' => []
];

if (tableExists($db, 'donor_payment_plans')) {
    $query = "
        SELECT dpp.*, d.name as donor_name, d.phone as donor_phone
        FROM donor_payment_plans dpp
        LEFT JOIN donors d ON dpp.donor_id = d.id
        WHERE dpp.status = 'active'
        ORDER BY dpp.id DESC
        LIMIT 20
    ";
    
    try {
        $res = $db->query($query);
        while ($row = $res->fetch_assoc()) {
            $results['payment_plans']['data'][] = $row;
        }
        $results['payment_plans']['items'][] = [
            'name' => 'Active plans found',
            'description' => count($results['payment_plans']['data']) . ' active payment plans',
            'status' => count($results['payment_plans']['data']) > 0 ? 'OK' : 'INFO',
            'ok' => true
        ];
    } catch (Exception $e) {
        $results['payment_plans']['items'][] = [
            'name' => 'Query failed',
            'description' => $e->getMessage(),
            'status' => 'ERROR',
            'ok' => false
        ];
    }
}

// ============================================
// 8. CHECK MISMATCHES
// ============================================
$results['mismatches'] = [
    'title' => '8. Data Integrity Checks',
    'items' => []
];

// Check for donors with has_active_plan=1 but no matching plan
if (tableExists($db, 'donors') && tableExists($db, 'donor_payment_plans') && 
    columnExists($db, 'donors', 'has_active_plan') && columnExists($db, 'donors', 'active_payment_plan_id')) {
    
    $query = "
        SELECT d.id, d.name, d.active_payment_plan_id
        FROM donors d
        LEFT JOIN donor_payment_plans dpp ON d.active_payment_plan_id = dpp.id AND dpp.status = 'active'
        WHERE d.has_active_plan = 1 AND dpp.id IS NULL
    ";
    
    try {
        $res = $db->query($query);
        $orphaned = $res->num_rows;
        $results['mismatches']['items'][] = [
            'name' => 'Orphaned plan references',
            'description' => $orphaned > 0 ? "{$orphaned} donors have has_active_plan=1 but no matching active plan!" : 'All donor plan references are valid',
            'status' => $orphaned > 0 ? 'WARNING' : 'OK',
            'ok' => $orphaned == 0
        ];
    } catch (Exception $e) {
        $results['mismatches']['items'][] = [
            'name' => 'Check failed',
            'description' => $e->getMessage(),
            'status' => 'ERROR',
            'ok' => false
        ];
    }
}

// Check for confirmed payments with plan_id but plan not updated
if (tableExists($db, 'pledge_payments') && tableExists($db, 'donor_payment_plans') && 
    columnExists($db, 'pledge_payments', 'payment_plan_id')) {
    
    $query = "
        SELECT COUNT(*) as cnt
        FROM pledge_payments pp
        JOIN donor_payment_plans dpp ON pp.payment_plan_id = dpp.id
        WHERE pp.status = 'confirmed' AND dpp.payments_made = 0
    ";
    
    try {
        $res = $db->query($query);
        $row = $res->fetch_assoc();
        $cnt = (int)$row['cnt'];
        $results['mismatches']['items'][] = [
            'name' => 'Confirmed payments not counted',
            'description' => $cnt > 0 ? "{$cnt} confirmed payments linked to plans but payments_made=0!" : 'All confirmed payments are properly counted',
            'status' => $cnt > 0 ? 'WARNING' : 'OK',
            'ok' => $cnt == 0
        ];
    } catch (Exception $e) {
        // Ignore
    }
}

// ============================================
// 9. GENERATE SQL FIXES
// ============================================
$results['fixes'] = [
    'title' => '9. Suggested SQL Fixes',
    'items' => [],
    'sql' => []
];

// Fix: Add payment_plan_id column
if (tableExists($db, 'pledge_payments') && !columnExists($db, 'pledge_payments', 'payment_plan_id')) {
    $results['fixes']['sql'][] = [
        'description' => 'Add payment_plan_id column to pledge_payments',
        'sql' => "ALTER TABLE pledge_payments ADD COLUMN payment_plan_id INT NULL DEFAULT NULL AFTER donor_id, ADD INDEX idx_pledge_payment_plan_id (payment_plan_id);"
    ];
}

// Fix: Add has_active_plan column
if (tableExists($db, 'donors') && !columnExists($db, 'donors', 'has_active_plan')) {
    $results['fixes']['sql'][] = [
        'description' => 'Add has_active_plan column to donors',
        'sql' => "ALTER TABLE donors ADD COLUMN has_active_plan TINYINT(1) NOT NULL DEFAULT 0;"
    ];
}

// Fix: Add active_payment_plan_id column
if (tableExists($db, 'donors') && !columnExists($db, 'donors', 'active_payment_plan_id')) {
    $results['fixes']['sql'][] = [
        'description' => 'Add active_payment_plan_id column to donors',
        'sql' => "ALTER TABLE donors ADD COLUMN active_payment_plan_id INT NULL DEFAULT NULL, ADD INDEX idx_donor_active_plan (active_payment_plan_id);"
    ];
}

// Fix: Add payments_made column
if (tableExists($db, 'donor_payment_plans') && !columnExists($db, 'donor_payment_plans', 'payments_made')) {
    $results['fixes']['sql'][] = [
        'description' => 'Add payments_made column to donor_payment_plans',
        'sql' => "ALTER TABLE donor_payment_plans ADD COLUMN payments_made INT NOT NULL DEFAULT 0;"
    ];
}

// Fix: Add amount_paid column
if (tableExists($db, 'donor_payment_plans') && !columnExists($db, 'donor_payment_plans', 'amount_paid')) {
    $results['fixes']['sql'][] = [
        'description' => 'Add amount_paid column to donor_payment_plans',
        'sql' => "ALTER TABLE donor_payment_plans ADD COLUMN amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00;"
    ];
}

if (empty($results['fixes']['sql'])) {
    $results['fixes']['items'][] = [
        'name' => 'No fixes needed',
        'description' => 'All required columns exist',
        'status' => 'OK',
        'ok' => true
    ];
}

$page_title = 'Payment Plan Diagnostic';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { background: #f5f5f5; padding: 20px; }
        .diagnostic-card { margin-bottom: 20px; }
        .status-ok { color: #198754; font-weight: bold; }
        .status-missing { color: #dc3545; font-weight: bold; }
        .status-warning { color: #fd7e14; font-weight: bold; }
        .status-critical { color: #dc3545; font-weight: bold; background: #fff3cd; padding: 2px 6px; border-radius: 4px; }
        .sql-block { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 13px; overflow-x: auto; margin-bottom: 10px; }
        .data-table { font-size: 12px; }
        .data-table th { background: #f8f9fa; }
        .copy-btn { cursor: pointer; }
        .copy-btn:hover { color: #0d6efd; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="fas fa-stethoscope text-primary me-2"></i><?php echo $page_title; ?></h1>
            <a href="../index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Back to Admin
            </a>
        </div>
        
        <?php foreach ($results as $section_key => $section): ?>
        <div class="card diagnostic-card">
            <div class="card-header">
                <h5 class="mb-0"><?php echo htmlspecialchars($section['title']); ?></h5>
            </div>
            <div class="card-body">
                <?php if (!empty($section['items'])): ?>
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width: 250px;">Item</th>
                            <th>Description</th>
                            <th style="width: 150px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($section['items'] as $item): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($item['name']); ?></code></td>
                            <td><?php echo htmlspecialchars($item['description']); ?></td>
                            <td>
                                <?php if (!empty($item['critical'])): ?>
                                    <span class="status-critical"><i class="fas fa-exclamation-triangle me-1"></i><?php echo $item['status']; ?></span>
                                <?php elseif ($item['ok']): ?>
                                    <span class="status-ok"><i class="fas fa-check-circle me-1"></i><?php echo $item['status']; ?></span>
                                <?php elseif ($item['status'] === 'WARNING'): ?>
                                    <span class="status-warning"><i class="fas fa-exclamation-circle me-1"></i><?php echo $item['status']; ?></span>
                                <?php else: ?>
                                    <span class="status-missing"><i class="fas fa-times-circle me-1"></i><?php echo $item['status']; ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <?php if (!empty($section['data'])): ?>
                <div class="table-responsive mt-3">
                    <table class="table table-sm table-bordered data-table">
                        <thead>
                            <tr>
                                <?php foreach (array_keys($section['data'][0]) as $col): ?>
                                <th><?php echo htmlspecialchars($col); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($section['data'] as $row): ?>
                            <tr>
                                <?php foreach ($row as $val): ?>
                                <td><?php echo htmlspecialchars($val ?? 'NULL'); ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($section['sql'])): ?>
                <div class="alert alert-warning mb-3">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Action Required:</strong> Run these SQL statements in phpMyAdmin to fix the issues.
                </div>
                <?php foreach ($section['sql'] as $fix): ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <strong><?php echo htmlspecialchars($fix['description']); ?></strong>
                        <button class="btn btn-sm btn-outline-primary copy-btn" onclick="copySQL(this)">
                            <i class="fas fa-copy me-1"></i>Copy
                        </button>
                    </div>
                    <div class="sql-block"><?php echo htmlspecialchars($fix['sql']); ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <div class="card diagnostic-card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>How Payment Plan Flow Works</h5>
            </div>
            <div class="card-body">
                <ol class="mb-0">
                    <li><strong>Donor has active plan:</strong> <code>donors.has_active_plan = 1</code> and <code>donors.active_payment_plan_id</code> points to <code>donor_payment_plans.id</code></li>
                    <li><strong>Donor submits payment:</strong> <code>donor/make-payment.php</code> inserts into <code>pledge_payments</code> with <code>payment_plan_id</code> set</li>
                    <li><strong>Admin approves:</strong> <code>approve-pledge-payment.php</code> checks for <code>payment_plan_id</code> in the payment record</li>
                    <li><strong>Plan is updated:</strong> <code>donor_payment_plans.payments_made</code> is incremented, <code>amount_paid</code> is increased</li>
                    <li><strong>Donor views plan:</strong> <code>donor/payment-plan.php</code> reads <code>payments_made</code> from <code>donor_payment_plans</code> to show progress (e.g., 1/12)</li>
                </ol>
                <hr>
                <p class="mb-0"><strong>Critical Column:</strong> If <code>pledge_payments.payment_plan_id</code> is missing, payments cannot be linked to plans and the plan will never update!</p>
            </div>
        </div>
    </div>
    
    <script>
    function copySQL(btn) {
        const sql = btn.parentElement.nextElementSibling.textContent;
        navigator.clipboard.writeText(sql).then(() => {
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
            setTimeout(() => btn.innerHTML = original, 2000);
        });
    }
    </script>
</body>
</html>

