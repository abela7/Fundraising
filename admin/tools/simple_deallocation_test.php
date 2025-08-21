<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/IntelligentGridDeallocator.php';
require_login();
require_admin();

$page_title = 'Simple Deallocation Test';
$db = db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pledgeId = (int)($_POST['pledge_id'] ?? 0);
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    
    try {
        if ($pledgeId > 0) {
            $deallocator = new IntelligentGridDeallocator($db);
            $result = $deallocator->deallocatePledge($pledgeId);
            $message = json_encode($result, JSON_PRETTY_PRINT);
        } elseif ($paymentId > 0) {
            $deallocator = new IntelligentGridDeallocator($db);
            $result = $deallocator->deallocatePayment($paymentId);
            $message = json_encode($result, JSON_PRETTY_PRINT);
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get current allocations
$allocatedPledges = $db->query("
    SELECT DISTINCT p.id, p.amount, p.donor_name, COUNT(fgc.cell_id) as cells
    FROM pledges p 
    INNER JOIN floor_grid_cells fgc ON p.id = fgc.pledge_id 
    WHERE p.status = 'approved' AND fgc.status IN ('pledged', 'paid')
    GROUP BY p.id 
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

$allocatedPayments = $db->query("
    SELECT DISTINCT pay.id, pay.amount, pay.donor_name, COUNT(fgc.cell_id) as cells
    FROM payments pay 
    INNER JOIN floor_grid_cells fgc ON pay.id = fgc.payment_id 
    WHERE pay.status = 'approved' AND fgc.status IN ('pledged', 'paid')
    GROUP BY pay.id 
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Fundraising System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/admin.css" rel="stylesheet">
</head>
<body>
    <div class="wrapper">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="main">
            <?php include '../includes/topbar.php'; ?>
            
            <main class="content">
                <div class="container-fluid p-0">
                    <h1 class="h3 mb-3"><?= htmlspecialchars($page_title) ?></h1>
                    
                    <?php if ($message): ?>
                        <div class="alert alert-success">
                            <pre><?= htmlspecialchars($message) ?></pre>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Test Deallocation -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-handshake me-2"></i>Test Pledge Deallocation
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($allocatedPledges)): ?>
                                        <p class="text-muted">No pledges with allocations found.</p>
                                    <?php else: ?>
                                        <form method="post">
                                            <div class="mb-3">
                                                <label class="form-label">Select Pledge to Deallocate:</label>
                                                <select name="pledge_id" class="form-select">
                                                    <option value="">Choose a pledge...</option>
                                                    <?php foreach ($allocatedPledges as $pledge): ?>
                                                        <option value="<?= $pledge['id'] ?>">
                                                            ID: <?= $pledge['id'] ?> - <?= htmlspecialchars($pledge['donor_name']) ?> 
                                                            (£<?= number_format($pledge['amount'], 2) ?> - <?= $pledge['cells'] ?> cells)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <button type="submit" class="btn btn-warning">
                                                <i class="fas fa-undo me-2"></i>Deallocate Pledge
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-credit-card me-2"></i>Test Payment Deallocation
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($allocatedPayments)): ?>
                                        <p class="text-muted">No payments with allocations found.</p>
                                    <?php else: ?>
                                        <form method="post">
                                            <div class="mb-3">
                                                <label class="form-label">Select Payment to Deallocate:</label>
                                                <select name="payment_id" class="form-select">
                                                    <option value="">Choose a payment...</option>
                                                    <?php foreach ($allocatedPayments as $payment): ?>
                                                        <option value="<?= $payment['id'] ?>">
                                                            ID: <?= $payment['id'] ?> - <?= htmlspecialchars($payment['donor_name']) ?> 
                                                            (£<?= number_format($payment['amount'], 2) ?> - <?= $payment['cells'] ?> cells)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <button type="submit" class="btn btn-warning">
                                                <i class="fas fa-undo me-2"></i>Deallocate Payment
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Current Status -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-info-circle me-2"></i>Current Floor Status
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php
                                    $statusQuery = $db->query("SELECT status, COUNT(*) as count FROM floor_grid_cells GROUP BY status");
                                    $statuses = $statusQuery ? $statusQuery->fetch_all(MYSQLI_ASSOC) : [];
                                    ?>
                                    <div class="row">
                                        <?php foreach ($statuses as $status): ?>
                                            <div class="col-md-3 mb-3">
                                                <div class="card bg-light">
                                                    <div class="card-body text-center">
                                                        <h4 class="text-primary"><?= $status['count'] ?></h4>
                                                        <p class="mb-0 text-muted"><?= ucfirst($status['status']) ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/admin.js"></script>
</body>
</html>
