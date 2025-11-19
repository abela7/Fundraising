<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

$db = db();
$success_messages = [];
$error_messages = [];

// Handle Fix Database action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_database'])) {
    try {
        $db->begin_transaction();
        
        // Add plan_frequency_unit
        $check = $db->query("SHOW COLUMNS FROM donor_payment_plans LIKE 'plan_frequency_unit'");
        if ($check->num_rows == 0) {
            $db->query("ALTER TABLE donor_payment_plans ADD COLUMN plan_frequency_unit ENUM('week', 'month', 'year') DEFAULT 'month' COMMENT 'Frequency unit for payments' AFTER payment_day");
            $success_messages[] = "✅ Added column: plan_frequency_unit";
        }
        
        // Add plan_frequency_number
        $check = $db->query("SHOW COLUMNS FROM donor_payment_plans LIKE 'plan_frequency_number'");
        if ($check->num_rows == 0) {
            $db->query("ALTER TABLE donor_payment_plans ADD COLUMN plan_frequency_number INT DEFAULT 1 COMMENT 'Frequency multiplier (e.g., 2 for biweekly)' AFTER plan_frequency_unit");
            $success_messages[] = "✅ Added column: plan_frequency_number";
        }
        
        // Add plan_payment_day_type
        $check = $db->query("SHOW COLUMNS FROM donor_payment_plans LIKE 'plan_payment_day_type'");
        if ($check->num_rows == 0) {
            $db->query("ALTER TABLE donor_payment_plans ADD COLUMN plan_payment_day_type ENUM('day_of_month', 'day_of_week') DEFAULT 'day_of_month' COMMENT 'Type of payment day scheduling' AFTER plan_frequency_number");
            $success_messages[] = "✅ Added column: plan_payment_day_type";
        }
        
        // Add total_payments
        $check = $db->query("SHOW COLUMNS FROM donor_payment_plans LIKE 'total_payments'");
        if ($check->num_rows == 0) {
            $db->query("ALTER TABLE donor_payment_plans ADD COLUMN total_payments INT DEFAULT 0 COMMENT 'Total number of payment installments' AFTER total_months");
            $success_messages[] = "✅ Added column: total_payments";
        }
        
        $db->commit();
        
        if (empty($success_messages)) {
            $success_messages[] = "✅ All columns already exist - no changes needed!";
        }
        
    } catch (Exception $e) {
        $db->rollback();
        $error_messages[] = "❌ Error: " . $e->getMessage();
    }
}

// Check current database structure
$required_columns = [
    'id' => 'INT - Primary key',
    'donor_id' => 'INT - Foreign key to donors table',
    'pledge_id' => 'INT - Foreign key to pledges table',
    'template_id' => 'INT - Foreign key to payment_plan_templates table',
    'total_amount' => 'DECIMAL - Total pledge amount to be paid',
    'monthly_amount' => 'DECIMAL - Amount due each installment',
    'total_months' => 'INT - Number of months in plan',
    'total_payments' => 'INT - Total number of payment installments',
    'start_date' => 'DATE - When plan starts',
    'payment_day' => 'INT - Day of month payment is due (1-28)',
    'payment_method' => 'ENUM - cash, bank_transfer, card',
    'plan_frequency_unit' => 'ENUM - week, month, year',
    'plan_frequency_number' => 'INT - Frequency multiplier',
    'plan_payment_day_type' => 'ENUM - day_of_month, day_of_week',
    'next_payment_due' => 'DATE - Next scheduled payment date',
    'last_payment_date' => 'DATE - Most recent payment received',
    'status' => 'ENUM - active, completed, paused, defaulted, cancelled',
    'payments_made' => 'INT - Number of installments received',
    'amount_paid' => 'DECIMAL - Total amount paid so far',
    'reminder_sent_at' => 'DATETIME - When last reminder was sent',
    'created_at' => 'DATETIME - Record creation timestamp',
    'updated_at' => 'DATETIME - Last update timestamp'
];

$column_status = [];
$missing_columns = [];

foreach ($required_columns as $col_name => $description) {
    $check = $db->query("SHOW COLUMNS FROM donor_payment_plans LIKE '$col_name'");
    if ($check && $check->num_rows > 0) {
        $col_info = $check->fetch_assoc();
        $column_status[$col_name] = [
            'exists' => true,
            'type' => $col_info['Type'],
            'nullable' => $col_info['Null'],
            'default' => $col_info['Default'],
            'description' => $description
        ];
    } else {
        $column_status[$col_name] = [
            'exists' => false,
            'description' => $description
        ];
        $missing_columns[] = $col_name;
    }
}

// Count stats
$total_required = count($required_columns);
$total_existing = count(array_filter($column_status, function($col) { return $col['exists']; }));
$total_missing = count($missing_columns);

// Check table existence
$table_exists = false;
$table_check = $db->query("SHOW TABLES LIKE 'donor_payment_plans'");
if ($table_check && $table_check->num_rows > 0) {
    $table_exists = true;
}

// Get sample data count
$plan_count = 0;
if ($table_exists) {
    $count_result = $db->query("SELECT COUNT(*) as total FROM donor_payment_plans");
    if ($count_result) {
        $plan_count = $count_result->fetch_assoc()['total'];
    }
}

$page_title = 'Payment Plan Database Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .report-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .stat-number {
            font-size: 3rem;
            font-weight: bold;
            margin: 0;
        }
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .column-card {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            border-left: 4px solid #28a745;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .column-card.missing {
            border-left-color: #dc3545;
            background: #fff5f5;
        }
        .column-name {
            font-weight: bold;
            color: #2c3e50;
        }
        .column-type {
            color: #6c757d;
            font-size: 0.9rem;
            font-family: monospace;
        }
        .column-description {
            color: #95a5a6;
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }
        .fix-button {
            font-size: 1.2rem;
            padding: 1rem 2rem;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/admin_nav.php'; ?>
    
    <div class="container-fluid mt-4">
        <!-- Report Header -->
        <div class="report-header">
            <h1><i class="fas fa-database me-2"></i>Payment Plan Database Report</h1>
            <p class="mb-0">Database structure verification for donor_payment_plans table</p>
        </div>

        <!-- Success/Error Messages -->
        <?php if (!empty($success_messages)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                <?php foreach ($success_messages as $msg): ?>
                    <div><?php echo htmlspecialchars($msg); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_messages)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                <?php foreach ($error_messages as $msg): ?>
                    <div><?php echo htmlspecialchars($msg); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <p class="stat-number text-<?php echo $table_exists ? 'success' : 'danger'; ?>">
                        <i class="fas fa-<?php echo $table_exists ? 'check-circle' : 'times-circle'; ?>"></i>
                    </p>
                    <p class="stat-label">Table Exists</p>
                    <small class="text-muted"><?php echo $table_exists ? 'donor_payment_plans' : 'Table not found!'; ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <p class="stat-number text-primary"><?php echo $total_existing; ?></p>
                    <p class="stat-label">Columns Found</p>
                    <small class="text-muted">Out of <?php echo $total_required; ?> required</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <p class="stat-number text-<?php echo $total_missing > 0 ? 'warning' : 'success'; ?>">
                        <?php echo $total_missing; ?>
                    </p>
                    <p class="stat-label">Missing Columns</p>
                    <small class="text-muted"><?php echo $total_missing > 0 ? 'Need to add' : 'All good!'; ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <p class="stat-number text-info"><?php echo $plan_count; ?></p>
                    <p class="stat-label">Payment Plans</p>
                    <small class="text-muted">Total records in table</small>
                </div>
            </div>
        </div>

        <!-- Fix Button -->
        <?php if ($total_missing > 0): ?>
        <div class="text-center mb-4">
            <form method="POST" style="display: inline;">
                <button type="submit" name="fix_database" class="btn btn-warning btn-lg fix-button">
                    <i class="fas fa-wrench me-2"></i>
                    Fix Database - Add Missing Columns
                </button>
            </form>
            <p class="text-muted mt-2">
                <i class="fas fa-info-circle me-1"></i>
                This will add <?php echo $total_missing; ?> missing column<?php echo $total_missing > 1 ? 's' : ''; ?> to the database
            </p>
        </div>
        <?php endif; ?>

        <!-- Column Details -->
        <div class="row">
            <div class="col-lg-6">
                <h4 class="mb-3">
                    <i class="fas fa-check-circle text-success me-2"></i>
                    Existing Columns (<?php echo $total_existing; ?>)
                </h4>
                <?php foreach ($column_status as $col_name => $info): ?>
                    <?php if ($info['exists']): ?>
                    <div class="column-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="column-name">
                                    <i class="fas fa-check text-success me-2"></i>
                                    <?php echo htmlspecialchars($col_name); ?>
                                </div>
                                <div class="column-type">
                                    Type: <?php echo htmlspecialchars($info['type']); ?>
                                    <?php if ($info['nullable'] === 'YES'): ?>
                                        | Nullable
                                    <?php endif; ?>
                                    <?php if ($info['default'] !== null): ?>
                                        | Default: <?php echo htmlspecialchars($info['default']); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="column-description">
                                    <?php echo htmlspecialchars($info['description']); ?>
                                </div>
                            </div>
                            <span class="badge bg-success">Exists</span>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="col-lg-6">
                <?php if ($total_missing > 0): ?>
                <h4 class="mb-3">
                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                    Missing Columns (<?php echo $total_missing; ?>)
                </h4>
                <?php foreach ($column_status as $col_name => $info): ?>
                    <?php if (!$info['exists']): ?>
                    <div class="column-card missing">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="column-name">
                                    <i class="fas fa-times text-danger me-2"></i>
                                    <?php echo htmlspecialchars($col_name); ?>
                                </div>
                                <div class="column-description">
                                    <?php echo htmlspecialchars($info['description']); ?>
                                </div>
                            </div>
                            <span class="badge bg-danger">Missing</span>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="alert alert-success">
                    <h4 class="alert-heading">
                        <i class="fas fa-check-circle me-2"></i>
                        All Columns Present!
                    </h4>
                    <p class="mb-0">
                        The donor_payment_plans table has all required columns. 
                        You can now use the payment plan view system without any issues.
                    </p>
                    <hr>
                    <div class="mb-0">
                        <a href="view-payment-plan.php?id=<?php echo $plan_count > 0 ? '1' : ''; ?>" class="btn btn-success">
                            <i class="fas fa-eye me-2"></i>View Payment Plans
                        </a>
                        <a href="donors.php" class="btn btn-outline-primary ms-2">
                            <i class="fas fa-users me-2"></i>Back to Donors
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Additional Info -->
        <div class="card mt-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>What This Report Checks</h5>
            </div>
            <div class="card-body">
                <p><strong>This report verifies the database structure for the payment plan view system.</strong></p>
                <ul>
                    <li>✅ Checks if the <code>donor_payment_plans</code> table exists</li>
                    <li>✅ Verifies all <?php echo $total_required; ?> required columns are present</li>
                    <li>✅ Shows column types, defaults, and nullable status</li>
                    <li>✅ Provides a one-click fix to add missing columns</li>
                </ul>
                
                <h6 class="mt-3">Critical Columns for Payment Plan View:</h6>
                <ul>
                    <li><code>plan_frequency_unit</code> - How often payments are made (week/month/year)</li>
                    <li><code>plan_frequency_number</code> - Frequency multiplier (e.g., 2 for biweekly)</li>
                    <li><code>plan_payment_day_type</code> - Payment schedule type</li>
                    <li><code>total_payments</code> - Total number of installments in the plan</li>
                </ul>

                <?php if ($total_missing > 0): ?>
                <div class="alert alert-warning mt-3">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Action Required:</strong> Click the "Fix Database" button above to automatically add the missing columns.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

