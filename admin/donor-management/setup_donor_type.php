<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$page_title = 'Add Donor Type Identifier';
$current_user = current_user();
$db = db();

$message = '';
$message_type = '';
$stats = [];

// Check if donor_type column already exists
$check_column = $db->query("SHOW COLUMNS FROM donors LIKE 'donor_type'");
$column_exists = $check_column && $check_column->num_rows > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_donor_type') {
    verify_csrf();
    
    try {
        $db->begin_transaction();
        
        if (!$column_exists) {
            // Step 1: Add donor_type column
            // DEFAULT is 'immediate_payment' because current donors paid immediately
            $db->query("
                ALTER TABLE donors 
                ADD COLUMN donor_type ENUM('immediate_payment', 'pledge') NOT NULL DEFAULT 'immediate_payment' 
                COMMENT 'Type of donor: immediate_payment (paid right away) or pledge (needs tracking)' 
                AFTER id
            ");
            
            // Step 2: Add index
            $db->query("ALTER TABLE donors ADD INDEX idx_donor_type (donor_type)");
            
            // Step 3: Update existing records
            // If total_pledged > 0, they are PLEDGE donors (need tracking)
            // If total_pledged = 0, they are IMMEDIATE payers (already done)
            $db->query("
                UPDATE donors 
                SET donor_type = CASE 
                    WHEN total_pledged > 0 THEN 'pledge'
                    ELSE 'immediate_payment'
                END
            ");
            
            $db->commit();
            $message = 'Donor type column added successfully!';
            $message_type = 'success';
            $column_exists = true;
        } else {
            $message = 'Donor type column already exists!';
            $message_type = 'info';
        }
        
    } catch (Exception $e) {
        $db->rollback();
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Get statistics after migration
if ($column_exists) {
    $result = $db->query("
        SELECT 
            donor_type,
            COUNT(*) as count,
            SUM(total_pledged) as total_pledged,
            SUM(total_paid) as total_paid
        FROM donors
        GROUP BY donor_type
    ");
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $stats[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                
                <!-- Page Header -->
                <div class="mb-4">
                    <h1 class="h3 mb-1 text-primary">
                        <i class="fas fa-tag me-2"></i>Add Donor Type Identifier
                    </h1>
                    <p class="text-muted mb-0">Add a clear identifier to distinguish between immediate payers and pledge donors</p>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'info-circle'; ?> me-2"></i>
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="row g-4">
                    <!-- Explanation Card -->
                    <div class="col-12 col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-info-circle text-primary me-2"></i>
                                    What This Does
                                </h5>
                            </div>
                            <div class="card-body">
                                <p>This migration adds a <code>donor_type</code> column to clearly identify two types of donors:</p>
                                
                                <div class="mb-3">
                                    <h6 class="fw-semibold">
                                        <i class="fas fa-bolt text-success me-2"></i>
                                        Immediate Payment
                                    </h6>
                                    <p class="text-muted small mb-0">
                                        Donors who paid right away. No tracking needed - they're already done!
                                    </p>
                                </div>
                                
                                <div class="mb-3">
                                    <h6 class="fw-semibold">
                                        <i class="fas fa-handshake text-warning me-2"></i>
                                        Pledge
                                    </h6>
                                    <p class="text-muted small mb-0">
                                        Donors who promised to pay later. Need tracking, reminders, and payment plans. (~186 people)
                                    </p>
                                </div>

                                <hr>

                                <h6 class="fw-semibold">What Happens:</h6>
                                <ul class="small text-muted mb-0">
                                    <li>Adds <code>donor_type</code> column to donors table</li>
                                    <li>Sets type based on existing data (pledge if <code>total_pledged > 0</code>)</li>
                                    <li>Adds index for fast filtering</li>
                                    <li>Report page will use this to show only pledge donors</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Action Card -->
                    <div class="col-12 col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-play-circle text-success me-2"></i>
                                    Run Migration
                                </h5>
                            </div>
                            <div class="card-body">
                                
                                <?php if ($column_exists): ?>
                                    <div class="alert alert-success mb-3">
                                        <i class="fas fa-check-circle me-2"></i>
                                        <strong>Column Already Exists!</strong>
                                        <p class="mb-0 small mt-2">The <code>donor_type</code> column has already been added to your database.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning mb-3">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <strong>Action Required</strong>
                                        <p class="mb-0 small mt-2">Click the button below to add the donor type identifier to your database.</p>
                                    </div>
                                <?php endif; ?>

                                <form method="post">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="add_donor_type">
                                    
                                    <button type="submit" class="btn btn-primary w-100" <?php echo $column_exists ? 'disabled' : ''; ?>>
                                        <i class="fas fa-play me-2"></i>
                                        <?php echo $column_exists ? 'Already Added' : 'Add Donor Type Column'; ?>
                                    </button>
                                </form>

                                <?php if ($column_exists): ?>
                                    <div class="mt-3">
                                        <a href="donor.php" class="btn btn-success w-100">
                                            <i class="fas fa-chart-line me-2"></i>
                                            View Pledge Donor Report
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Statistics -->
                        <?php if (!empty($stats)): ?>
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-chart-bar text-info me-2"></i>
                                    Current Statistics
                                </h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th class="text-end">Count</th>
                                            <th class="text-end">Pledged</th>
                                            <th class="text-end">Paid</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stats as $stat): ?>
                                        <tr>
                                            <td>
                                                <?php if ($stat['donor_type'] === 'pledge'): ?>
                                                    <i class="fas fa-handshake text-warning me-1"></i> Pledge
                                                <?php else: ?>
                                                    <i class="fas fa-bolt text-success me-1"></i> Immediate
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end fw-bold"><?php echo number_format((int)$stat['count']); ?></td>
                                            <td class="text-end">£<?php echo number_format((float)$stat['total_pledged'], 2); ?></td>
                                            <td class="text-end">£<?php echo number_format((float)$stat['total_paid'], 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
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

