<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';

require_login();
require_admin();

$page_title = 'Reset Floor Map & Custom Amounts';
$db = db();
$message = '';
$message_type = '';

// Handle the reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        try {
            // Reset floor grid cells
            $sql = "
                UPDATE floor_grid_cells
                SET
                    status = 'available',
                    pledge_id = NULL,
                    payment_id = NULL,
                    donor_name = NULL,
                    amount = NULL,
                    assigned_date = NULL
                WHERE status != 'available'
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute();
            
            $affected_rows = $stmt->affected_rows;
            
            // Reset custom amount tracking table to use single ID = 1
            $customResetSql = "
                TRUNCATE TABLE custom_amount_tracking;
                ALTER TABLE custom_amount_tracking AUTO_INCREMENT = 1;
                INSERT INTO custom_amount_tracking (
                    id, donor_id, donor_name, total_amount, allocated_amount, remaining_amount, last_updated, created_at
                ) VALUES (
                    1, 0, 'Collective Custom', 0.00, 0.00, 0.00, NOW(), NOW()
                );
            ";
            
            // Execute each statement separately since TRUNCATE and ALTER can't be combined
            $db->query("TRUNCATE TABLE custom_amount_tracking");
            $db->query("ALTER TABLE custom_amount_tracking AUTO_INCREMENT = 1");
            
            $insertStmt = $db->prepare("
                INSERT INTO custom_amount_tracking (
                    id, donor_id, donor_name, total_amount, allocated_amount, remaining_amount, last_updated, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $insertStmt->bind_param('iisddd', $id, $donorId, $donorName, $totalAmount, $allocatedAmount, $remainingAmount);
            
            $id = 1;
            $donorId = 0;
            $donorName = 'Collective Custom';
            $totalAmount = 0.00;
            $allocatedAmount = 0.00;
            $remainingAmount = 0.00;
            
            $insertStmt->execute();
            
            $message = "✅ Success! Reset {$affected_rows} allocated/blocked cells back to 'available' AND reset custom amount tracking table. The floor map and custom amounts are now clean.";
            $message_type = 'success';

        } catch (Exception $e) {
            $message = "❌ Error: Could not reset the floor map. " . $e->getMessage();
            $message_type = 'danger';
        }
    } else {
        $message = '❌ Error: Invalid CSRF token.';
        $message_type = 'danger';
    }
}

// Generate a new CSRF token for the form
$csrf_token = csrf_token();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h1 class="h3"><i class="fas fa-undo me-2"></i><?= htmlspecialchars($page_title) ?></h1>
                    </div>
                    <div class="card-body">
                        
                        <?php if ($message): ?>
                            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                                <?= htmlspecialchars($message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <div class="alert alert-warning">
                            <h4 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Warning!</h4>
                            <p>This is a destructive action. Clicking this button will permanently reset:</p>
                            <ul>
                                <li><strong>Floor Map:</strong> All allocated/blocked cells will be marked as 'available'</li>
                                <li><strong>Custom Amount Tracking:</strong> All custom amount data will be reset to zero</li>
                            </ul>
                            <p>Any links to pledges or payments will be removed from the grid, and custom amount accumulation will start fresh.</p>
                            <hr>
                            <p class="mb-0">This cannot be undone. Use this tool only when you need a completely clean slate for testing.</p>
                        </div>
                        
                        <form method="POST" onsubmit="return confirm('Are you absolutely sure you want to reset the entire floor map? This action is irreversible.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <div class="d-grid">
                                <button type="submit" class="btn btn-danger btn-lg">
                                    <i class="fas fa-power-off me-2"></i>Reset Floor Map & Custom Amounts
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="card-footer">
                        <a href="../" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Admin Tools
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
