<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();

$database = db();
$results = [];

// Function to check if table exists
function tableExists($database, string $tableName): bool {
    $result = $database->query("SHOW TABLES LIKE '{$tableName}'");
    return $result && $result->num_rows > 0;
}

// Function to check if column exists
function columnExists($database, string $tableName, string $columnName): bool {
    $result = $database->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
    return $result && $result->num_rows > 0;
}

// Function to check if foreign key exists
function foreignKeyExists($database, string $tableName, string $constraintName): bool {
    $query = "SELECT CONSTRAINT_NAME 
              FROM information_schema.TABLE_CONSTRAINTS 
              WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = '{$tableName}' 
              AND CONSTRAINT_NAME = '{$constraintName}'";
    $result = $database->query($query);
    return $result && $result->num_rows > 0;
}

// Function to get table row count
function getTableCount($database, string $tableName): int {
    if (!tableExists($database, $tableName)) {
        return 0;
    }
    $result = $database->query("SELECT COUNT(*) as count FROM `{$tableName}`");
    $row = $result->fetch_assoc();
    return (int)$row['count'];
}

// Check tables
$tables = [
    'donors' => 'Main donor registry',
    'donor_payment_plans' => 'Payment plan instances',
    'donor_audit_log' => 'Audit trail',
    'donor_portal_tokens' => 'Security tokens'
];

$results['tables'] = [];
foreach ($tables as $tableName => $description) {
    $exists = tableExists($database, $tableName);
    $count = $exists ? getTableCount($database, $tableName) : 0;
    $results['tables'][$tableName] = [
        'exists' => $exists,
        'description' => $description,
        'count' => $count
    ];
}

// Check columns in existing tables
$results['columns'] = [];

// Pledges table columns
if (tableExists($database, 'pledges')) {
    $results['columns']['pledges'] = [
        'donor_id' => columnExists($database, 'pledges', 'donor_id')
    ];
}

// Payments table columns
if (tableExists($database, 'payments')) {
    $results['columns']['payments'] = [
        'donor_id' => columnExists($database, 'payments', 'donor_id'),
        'pledge_id' => columnExists($database, 'payments', 'pledge_id'),
        'installment_number' => columnExists($database, 'payments', 'installment_number')
    ];
}

// Check foreign keys
$results['foreign_keys'] = [];

if (tableExists($database, 'donors')) {
    $results['foreign_keys']['fk_donors_active_payment_plan'] = 
        foreignKeyExists($database, 'donors', 'fk_donors_active_payment_plan');
}

if (tableExists($database, 'donor_payment_plans')) {
    $results['foreign_keys']['fk_payment_plans_donor'] = 
        foreignKeyExists($database, 'donor_payment_plans', 'fk_payment_plans_donor');
    $results['foreign_keys']['fk_payment_plans_pledge'] = 
        foreignKeyExists($database, 'donor_payment_plans', 'fk_payment_plans_pledge');
}

if (tableExists($database, 'pledges')) {
    $results['foreign_keys']['fk_pledges_donor'] = 
        foreignKeyExists($database, 'pledges', 'fk_pledges_donor');
}

if (tableExists($database, 'payments')) {
    $results['foreign_keys']['fk_payments_donor'] = 
        foreignKeyExists($database, 'payments', 'fk_payments_donor');
    $results['foreign_keys']['fk_payments_pledge'] = 
        foreignKeyExists($database, 'payments', 'fk_payments_pledge');
}

// Check data linking status
$results['data_status'] = [];

if (tableExists($database, 'donors')) {
    $results['data_status']['donors_count'] = getTableCount($database, 'donors');
}

if (tableExists($database, 'pledges') && columnExists($database, 'pledges', 'donor_id')) {
    $linked = $database->query("SELECT COUNT(*) as count FROM pledges WHERE donor_id IS NOT NULL")->fetch_assoc();
    $total = getTableCount($database, 'pledges');
    $results['data_status']['pledges_linked'] = (int)$linked['count'];
    $results['data_status']['pledges_total'] = $total;
}

if (tableExists($database, 'payments') && columnExists($database, 'payments', 'donor_id')) {
    $linked = $database->query("SELECT COUNT(*) as count FROM payments WHERE donor_id IS NOT NULL")->fetch_assoc();
    $total = getTableCount($database, 'payments');
    $results['data_status']['payments_linked'] = (int)$linked['count'];
    $results['data_status']['payments_total'] = $total;
}

// Calculate completion percentage
$totalSteps = 13;
$completedSteps = 0;

// Table creation (4 steps)
foreach ($results['tables'] as $tableName => $info) {
    if ($info['exists']) $completedSteps++;
}

// Column additions (2 steps)
if (isset($results['columns']['pledges']['donor_id']) && $results['columns']['pledges']['donor_id']) {
    $completedSteps++;
}
if (isset($results['columns']['payments']) && 
    $results['columns']['payments']['donor_id'] && 
    $results['columns']['payments']['pledge_id'] && 
    $results['columns']['payments']['installment_number']) {
    $completedSteps++;
}

// Data population (3 steps)
if (isset($results['data_status']['donors_count']) && $results['data_status']['donors_count'] > 0) {
    $completedSteps++;
}
if (isset($results['data_status']['pledges_linked']) && $results['data_status']['pledges_linked'] > 0) {
    $completedSteps++;
}
if (isset($results['data_status']['payments_linked']) && $results['data_status']['payments_linked'] > 0) {
    $completedSteps++;
}

// Foreign keys (3 steps) - but we skip checking these for completion to avoid complexity
// Since FKs failed, we'll handle them separately

$completionPercentage = round(($completedSteps / $totalSteps) * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migration Status Check | Admin Tools</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        
        .status-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .status-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .status-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .status-body {
            padding: 2rem;
        }
        
        .progress-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .status-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #e9ecef;
        }
        
        .status-item:last-child {
            border-bottom: none;
        }
        
        .status-icon {
            font-size: 1.5rem;
            width: 40px;
            flex-shrink: 0;
        }
        
        .status-check {
            color: #10b981;
        }
        
        .status-cross {
            color: #ef4444;
        }
        
        .status-warning {
            color: #f59e0b;
        }
        
        .section-card {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .section-card h5 {
            margin-bottom: 1rem;
            color: #667eea;
        }
        
        .badge-custom {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="status-container">
        <div class="status-card">
            <div class="status-header">
                <h1><i class="bi bi-clipboard-check"></i> Migration Status Check</h1>
                <p class="mb-0">Diagnostic Report - Donor System Migration</p>
            </div>
            
            <div class="status-body">
                <!-- Overall Progress -->
                <div class="progress-section">
                    <h4 class="mb-3"><i class="bi bi-bar-chart-fill"></i> Overall Progress</h4>
                    <div class="progress" style="height: 30px;">
                        <div class="progress-bar bg-success" role="progressbar" 
                             style="width: <?= $completionPercentage ?>%;" 
                             aria-valuenow="<?= $completionPercentage ?>" 
                             aria-valuemin="0" 
                             aria-valuemax="100">
                            <?= $completionPercentage ?>% Complete
                        </div>
                    </div>
                    <p class="mt-2 text-muted">
                        <strong><?= $completedSteps ?></strong> of <strong><?= $totalSteps ?></strong> steps completed
                    </p>
                </div>
                
                <!-- Tables Status -->
                <div class="section-card">
                    <h5><i class="bi bi-table"></i> Tables Status</h5>
                    <?php foreach ($results['tables'] as $tableName => $info): ?>
                        <div class="status-item">
                            <div class="status-icon">
                                <?php if ($info['exists']): ?>
                                    <i class="bi bi-check-circle-fill status-check"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill status-cross"></i>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <strong><?= htmlspecialchars($tableName) ?></strong>
                                <br>
                                <small class="text-muted"><?= htmlspecialchars($info['description']) ?></small>
                            </div>
                            <div>
                                <?php if ($info['exists']): ?>
                                    <span class="badge bg-success badge-custom">
                                        ✓ Exists (<?= $info['count'] ?> rows)
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger badge-custom">✗ Missing</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Columns Status -->
                <div class="section-card">
                    <h5><i class="bi bi-layout-three-columns"></i> Column Additions</h5>
                    
                    <?php if (isset($results['columns']['pledges'])): ?>
                        <div class="status-item">
                            <div class="status-icon">
                                <?php if ($results['columns']['pledges']['donor_id']): ?>
                                    <i class="bi bi-check-circle-fill status-check"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill status-cross"></i>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <strong>pledges.donor_id</strong>
                                <br>
                                <small class="text-muted">Links pledges to donors</small>
                            </div>
                            <div>
                                <?php if ($results['columns']['pledges']['donor_id']): ?>
                                    <span class="badge bg-success badge-custom">✓ Added</span>
                                <?php else: ?>
                                    <span class="badge bg-danger badge-custom">✗ Missing</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($results['columns']['payments'])): ?>
                        <div class="status-item">
                            <div class="status-icon">
                                <?php 
                                $allPaymentColumnsExist = $results['columns']['payments']['donor_id'] && 
                                                         $results['columns']['payments']['pledge_id'] && 
                                                         $results['columns']['payments']['installment_number'];
                                ?>
                                <?php if ($allPaymentColumnsExist): ?>
                                    <i class="bi bi-check-circle-fill status-check"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill status-cross"></i>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <strong>payments.donor_id, pledge_id, installment_number</strong>
                                <br>
                                <small class="text-muted">Links payments to donors and pledges</small>
                            </div>
                            <div>
                                <?php if ($allPaymentColumnsExist): ?>
                                    <span class="badge bg-success badge-custom">✓ All Added</span>
                                <?php else: ?>
                                    <span class="badge bg-warning badge-custom">
                                        ⚠ Partial (
                                        <?= $results['columns']['payments']['donor_id'] ? '✓' : '✗' ?> donor_id,
                                        <?= $results['columns']['payments']['pledge_id'] ? '✓' : '✗' ?> pledge_id,
                                        <?= $results['columns']['payments']['installment_number'] ? '✓' : '✗' ?> installment
                                        )
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Foreign Keys Status -->
                <div class="section-card">
                    <h5><i class="bi bi-link-45deg"></i> Foreign Key Constraints</h5>
                    <?php foreach ($results['foreign_keys'] as $fkName => $exists): ?>
                        <div class="status-item">
                            <div class="status-icon">
                                <?php if ($exists): ?>
                                    <i class="bi bi-check-circle-fill status-check"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill status-cross"></i>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <strong><?= htmlspecialchars($fkName) ?></strong>
                            </div>
                            <div>
                                <?php if ($exists): ?>
                                    <span class="badge bg-success badge-custom">✓ Exists</span>
                                <?php else: ?>
                                    <span class="badge bg-danger badge-custom">✗ Missing</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Data Linking Status -->
                <?php if (isset($results['data_status'])): ?>
                    <div class="section-card">
                        <h5><i class="bi bi-database-fill-check"></i> Data Linking Status</h5>
                        
                        <?php if (isset($results['data_status']['donors_count'])): ?>
                            <div class="status-item">
                                <div class="status-icon">
                                    <i class="bi bi-people-fill status-check"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <strong>Donors Created</strong>
                                </div>
                                <div>
                                    <span class="badge bg-info badge-custom">
                                        <?= $results['data_status']['donors_count'] ?> donors
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($results['data_status']['pledges_linked'])): ?>
                            <div class="status-item">
                                <div class="status-icon">
                                    <?php if ($results['data_status']['pledges_linked'] === $results['data_status']['pledges_total']): ?>
                                        <i class="bi bi-check-circle-fill status-check"></i>
                                    <?php elseif ($results['data_status']['pledges_linked'] > 0): ?>
                                        <i class="bi bi-exclamation-triangle-fill status-warning"></i>
                                    <?php else: ?>
                                        <i class="bi bi-x-circle-fill status-cross"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <strong>Pledges Linked to Donors</strong>
                                </div>
                                <div>
                                    <span class="badge bg-info badge-custom">
                                        <?= $results['data_status']['pledges_linked'] ?> / <?= $results['data_status']['pledges_total'] ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($results['data_status']['payments_linked'])): ?>
                            <div class="status-item">
                                <div class="status-icon">
                                    <?php if ($results['data_status']['payments_linked'] === $results['data_status']['payments_total']): ?>
                                        <i class="bi bi-check-circle-fill status-check"></i>
                                    <?php elseif ($results['data_status']['payments_linked'] > 0): ?>
                                        <i class="bi bi-exclamation-triangle-fill status-warning"></i>
                                    <?php else: ?>
                                        <i class="bi bi-x-circle-fill status-cross"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <strong>Payments Linked to Donors</strong>
                                </div>
                                <div>
                                    <span class="badge bg-info badge-custom">
                                        <?= $results['data_status']['payments_linked'] ?> / <?= $results['data_status']['payments_total'] ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Actions -->
                <div class="mt-4 text-center">
                    <a href="/admin/tools/" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Tools
                    </a>
                    
                    <?php if ($completionPercentage < 100): ?>
                        <a href="fix_partial_migration.php" class="btn btn-warning">
                            <i class="bi bi-tools"></i> Fix Partial Migration
                        </a>
                    <?php else: ?>
                        <a href="/admin/dashboard/" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Migration Complete - Go to Dashboard
                        </a>
                    <?php endif; ?>
                    
                    <button class="btn btn-info" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh Status
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

