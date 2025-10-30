<?php
/**
 * Donor Portal - Payment History
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../admin/includes/resilient_db_loader.php';

function current_donor(): ?array {
    if (isset($_SESSION['donor'])) {
        return $_SESSION['donor'];
    }
    return null;
}

function require_donor_login(): void {
    if (!current_donor()) {
        header('Location: login.php');
        exit;
    }
}

require_donor_login();
$donor = current_donor();
$page_title = 'Payment History';
$current_donor = $donor;

// Load all payments
$payments = [];
if ($db_connection_ok) {
    try {
        $payments_stmt = $db->prepare("
            SELECT id, amount, method, reference, status, received_at, created_at, notes
            FROM payments
            WHERE donor_phone = ?
            ORDER BY received_at DESC, created_at DESC
        ");
        $payments_stmt->bind_param('s', $donor['phone']);
        $payments_stmt->execute();
        $payments_result = $payments_stmt->get_result();
        $payments = $payments_result->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        // Silent fail
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($donor['preferred_language'] ?? 'en'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Donor Portal</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css">
    <link rel="stylesheet" href="assets/donor.css">
</head>
<body>
<div class="donor-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="donor-content">
        <?php include 'includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1 text-primary">
                            <i class="fas fa-history me-2"></i>Payment History
                        </h1>
                        <p class="text-muted mb-0">View all your payment records</p>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="fas fa-list text-primary me-2"></i>All Payments
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($payments)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3"></i>
                                <p>No payments recorded yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td>
                                                <?php 
                                                $date = $payment['received_at'] ?? $payment['created_at'];
                                                echo $date ? date('d M Y', strtotime($date)) : '-';
                                                ?>
                                            </td>
                                            <td><strong>£<?php echo number_format($payment['amount'], 2); ?></strong></td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php echo ucfirst(str_replace('_', ' ', $payment['method'] ?? 'N/A')); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($payment['reference'] ?? '-'); ?></td>
                                            <td>
                                                <?php 
                                                $status = $payment['status'] ?? 'pending';
                                                $badge_class = $status === 'approved' ? 'bg-success' : ($status === 'pending' ? 'bg-warning' : 'bg-secondary');
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo ucfirst($status); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/donor.js"></script>
</body>
</html>

