<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$messages = [];
$fixed_plans = 0;
$synced_donors = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_fix'])) {
    try {
        $db->begin_transaction();

        // --- STEP 1: Fix Broken Payment Plans (Monthly Amount = 0) ---
        $plans_query = $db->query("SELECT * FROM donor_payment_plans WHERE monthly_amount = 0 AND total_amount > 0");
        while ($plan = $plans_query->fetch_assoc()) {
            $total = (float)$plan['total_amount'];
            $payments = (int)$plan['total_payments'];
            
            if ($payments > 0) {
                $correct_monthly = $total / $payments;
                
                $update = $db->prepare("UPDATE donor_payment_plans SET monthly_amount = ? WHERE id = ?");
                $update->bind_param('di', $correct_monthly, $plan['id']);
                $update->execute();
                $update->close();
                
                $messages[] = "✅ Fixed Plan #{$plan['id']}: Set Monthly Amount to £" . number_format($correct_monthly, 2);
                $fixed_plans++;
            }
        }

        // --- STEP 2: Sync Donors Table with Active Plans ---
        // Get all active plans
        $active_plans = $db->query("
            SELECT * FROM donor_payment_plans 
            WHERE status = 'active' 
            ORDER BY created_at DESC
        ");

        while ($plan = $active_plans->fetch_assoc()) {
            // Check if donor is out of sync
            $donor_check = $db->prepare("
                SELECT id, plan_monthly_amount, active_payment_plan_id 
                FROM donors 
                WHERE id = ?
            ");
            $donor_check->bind_param('i', $plan['donor_id']);
            $donor_check->execute();
            $donor = $donor_check->get_result()->fetch_assoc();
            $donor_check->close();

            if ($donor) {
                // Update donor with plan details
                $update_donor = $db->prepare("
                    UPDATE donors 
                    SET 
                        active_payment_plan_id = ?,
                        plan_monthly_amount = ?,
                        plan_duration_months = ?,
                        plan_start_date = ?,
                        plan_next_due_date = ?,
                        payment_status = 'paying'
                    WHERE id = ?
                ");
                
                $update_donor->bind_param(
                    'idsssi', 
                    $plan['id'],
                    $plan['monthly_amount'],
                    $plan['total_months'],
                    $plan['start_date'],
                    $plan['next_payment_due'],
                    $plan['donor_id']
                );
                $update_donor->execute();
                $update_donor->close();
                
                $synced_donors++;
            }
        }

        $db->commit();
        $messages[] = "🎉 <strong>Success!</strong> Database repair complete.";

    } catch (Exception $e) {
        $db->rollback();
        $messages[] = "❌ Error: " . $e->getMessage();
    }
}

$page_title = 'Fix Payment Plan Data';
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
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-4">
                
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white p-3">
                        <h4 class="mb-0"><i class="fas fa-tools me-2"></i>Data Integrity Fix Tool</h4>
                    </div>
                    <div class="card-body p-4">
                        
                        <div class="alert alert-info border-start border-4 border-info">
                            <h5><i class="fas fa-info-circle me-2"></i>What this tool does:</h5>
                            <ol class="mb-0">
                                <li><strong>Recalculates £0.00 Amounts:</strong> Finds active plans where Total > 0 but Monthly = 0, and fixes them.</li>
                                <li><strong>Syncs Donor Records:</strong> Copies the plan details (Amount, Date, Duration) to the main Donors table so searches work.</li>
                            </ol>
                        </div>

                        <?php if (!empty($messages)): ?>
                            <div class="alert alert-success">
                                <?php foreach ($messages as $msg): ?>
                                    <div class="mb-1"><?php echo $msg; ?></div>
                                <?php endforeach; ?>
                                <hr>
                                <div>
                                    <strong>Summary:</strong> Fixed <?php echo $fixed_plans; ?> broken plans and synced <?php echo $synced_donors; ?> donor records.
                                </div>
                            </div>
                            
                            <a href="view-payment-plan.php?id=<?php echo $_GET['id'] ?? ''; ?>" class="btn btn-success">
                                <i class="fas fa-check me-2"></i>Verify Fix on Plan Page
                            </a>
                        <?php else: ?>
                            <form method="POST">
                                <div class="text-center py-4">
                                    <button type="submit" name="run_fix" class="btn btn-warning btn-lg px-5 py-3">
                                        <i class="fas fa-magic me-2"></i>Run Smart Fix
                                    </button>
                                    <p class="text-muted mt-3">Safe to run multiple times.</p>
                                </div>
                            </form>
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

