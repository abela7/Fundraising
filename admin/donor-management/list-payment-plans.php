<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();

// Check if table exists
$table_exists = false;
$table_check = $db->query("SHOW TABLES LIKE 'donor_payment_plans'");
if ($table_check && $table_check->num_rows > 0) {
    $table_exists = true;
}

$plans = [];
$error_message = '';

if ($table_exists) {
    try {
        // Simple query to get all payment plans
        $query = $db->query("
            SELECT 
                dpp.*,
                d.name as donor_name,
                d.phone as donor_phone
            FROM donor_payment_plans dpp
            LEFT JOIN donors d ON dpp.donor_id = d.id
            ORDER BY dpp.id DESC
            LIMIT 100
        ");
        
        if ($query) {
            $plans = $query->fetch_all(MYSQLI_ASSOC);
        } else {
            $error_message = "Query failed: " . $db->error;
        }
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
} else {
    $error_message = "Table 'donor_payment_plans' does not exist!";
}

$page_title = 'Payment Plans List';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?></title>
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
            <div class="container-fluid p-4">
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-list me-2"></i>Payment Plans Verification</h2>
                    <a href="donors.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Donors
                    </a>
                </div>

                <!-- Status Alert -->
                <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Error</h5>
                    <p class="mb-0"><?php echo htmlspecialchars($error_message); ?></p>
                </div>
                <?php endif; ?>

                <!-- Table Status -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-database me-2"></i>Database Status</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Table Exists:</strong> 
                            <?php if ($table_exists): ?>
                                <span class="badge bg-success">YES</span>
                            <?php else: ?>
                                <span class="badge bg-danger">NO</span>
                            <?php endif; ?>
                        </p>
                        <p><strong>Total Payment Plans Found:</strong> 
                            <span class="badge bg-info"><?php echo count($plans); ?></span>
                        </p>
                    </div>
                </div>

                <!-- Payment Plans Table -->
                <?php if (empty($plans) && !$error_message): ?>
                <div class="alert alert-warning">
                    <h5 class="alert-heading"><i class="fas fa-info-circle me-2"></i>No Payment Plans Found</h5>
                    <p class="mb-0">There are no payment plans in the database. Payment plans are created when donors complete calls in the call center.</p>
                </div>
                <?php elseif (!empty($plans)): ?>
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Payment Plans Found (<?php echo count($plans); ?>)</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Donor</th>
                                        <th>Phone</th>
                                        <th>Total Amount</th>
                                        <th>Monthly Amount</th>
                                        <th>Paid</th>
                                        <th>Remaining</th>
                                        <th>Payments</th>
                                        <th>Status</th>
                                        <th>Start Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($plans as $p): ?>
                                    <tr>
                                        <td><strong>#<?php echo $p['id']; ?></strong></td>
                                        <td><?php echo htmlspecialchars($p['donor_name'] ?? 'Unknown'); ?></td>
                                        <td><?php echo htmlspecialchars($p['donor_phone'] ?? 'N/A'); ?></td>
                                        <td><strong>£<?php echo number_format($p['total_amount'], 2); ?></strong></td>
                                        <td>£<?php echo number_format($p['monthly_amount'], 2); ?></td>
                                        <td class="text-success">£<?php echo number_format($p['amount_paid'] ?? 0, 2); ?></td>
                                        <td class="text-warning">£<?php echo number_format(($p['total_amount'] - ($p['amount_paid'] ?? 0)), 2); ?></td>
                                        <td>
                                            <?php echo ($p['payments_made'] ?? 0); ?>/<?php echo ($p['total_payments'] ?? 0); ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status_colors = [
                                                'active' => 'success',
                                                'completed' => 'primary',
                                                'paused' => 'warning',
                                                'defaulted' => 'danger',
                                                'cancelled' => 'secondary'
                                            ];
                                            $status_color = $status_colors[$p['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?php echo $status_color; ?>">
                                                <?php echo strtoupper($p['status'] ?? 'unknown'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $p['start_date'] ? date('d M Y', strtotime($p['start_date'])) : 'N/A'; ?></td>
                                        <td>
                                            <a href="view-payment-plan.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-eye me-1"></i>View
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Debug Info -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bug me-2"></i>Debug Information</h5>
                    </div>
                    <div class="card-body">
                        <h6>Query Used:</h6>
                        <pre class="bg-light p-3 rounded"><code>SELECT dpp.*, d.name as donor_name, d.phone as donor_phone
FROM donor_payment_plans dpp
LEFT JOIN donors d ON dpp.donor_id = d.id
ORDER BY dpp.id DESC
LIMIT 100</code></pre>
                        
                        <?php if (!empty($plans)): ?>
                        <h6 class="mt-3">Sample Plan Data (First Record):</h6>
                        <pre class="bg-light p-3 rounded"><code><?php print_r($plans[0]); ?></code></pre>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

