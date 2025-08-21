<?php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/IntelligentGridDeallocator.php';

require_login();
require_admin();

$db = db();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pledgeId = (int)($_POST['pledge_id'] ?? 0);
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    
    if ($pledgeId || $paymentId) {
        try {
            $deallocator = new IntelligentGridDeallocator($db);
            $result = $deallocator->deallocateDonation($pledgeId, $paymentId);
            
            if ($result['success']) {
                $message = "✅ " . $result['message'];
            } else {
                $message = "❌ Error: " . $result['error'];
            }
        } catch (Exception $e) {
            $message = "❌ Exception: " . $e->getMessage();
        }
    }
}

// Get some sample pledges and payments for testing
$pledges = $db->query("SELECT id, donor_name, amount, status FROM pledges WHERE status = 'approved' LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$payments = $db->query("SELECT id, donor_name, amount, status FROM payments WHERE status = 'approved' LIMIT 5")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Grid Deallocation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h1>🧪 Test Grid Deallocation</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-6">
                <h3>Test Pledge Deallocation</h3>
                <?php if (empty($pledges)): ?>
                    <p class="text-muted">No approved pledges found</p>
                <?php else: ?>
                    <?php foreach ($pledges as $pledge): ?>
                        <form method="post" class="mb-2">
                            <div class="card">
                                <div class="card-body">
                                    <h6><?= htmlspecialchars($pledge['donor_name']) ?></h6>
                                    <p class="mb-1">£<?= number_format($pledge['amount'], 2) ?></p>
                                    <input type="hidden" name="pledge_id" value="<?= $pledge['id'] ?>">
                                    <button type="submit" class="btn btn-warning btn-sm">
                                        🗑️ Deallocate Floor Cells
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="col-md-6">
                <h3>Test Payment Deallocation</h3>
                <?php if (empty($payments)): ?>
                    <p class="text-muted">No approved payments found</p>
                <?php else: ?>
                    <?php foreach ($payments as $payment): ?>
                        <form method="post" class="mb-2">
                            <div class="card">
                                <div class="card-body">
                                    <h6><?= htmlspecialchars($payment['donor_name']) ?></h6>
                                    <p class="mb-1">£<?= number_format($payment['amount'], 2) ?></p>
                                    <input type="hidden" name="payment_id" value="<?= $payment['id'] ?>">
                                    <button type="submit" class="btn btn-warning btn-sm">
                                        🗑️ Deallocate Floor Cells
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="mt-4">
            <a href="../tools/" class="btn btn-secondary">← Back to Tools</a>
            <a href="../../public/projector/floor/" target="_blank" class="btn btn-success">View Floor Map</a>
        </div>
    </div>
</body>
</html>
