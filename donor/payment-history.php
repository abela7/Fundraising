<?php
/**
 * Donor Portal - Payment History
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../shared/url.php';
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

// Load all payments (instant payments + pledge payments)
$payments = [];
$debug_info = '';

if ($db_connection_ok) {
    try {
        // Check if pledge_payments table exists
        $has_pledge_payments = $db->query("SHOW TABLES LIKE 'pledge_payments'")->num_rows > 0;
        
        // First, get pledge payments (the new system used by make-payment.php)
        if ($has_pledge_payments && isset($donor['id'])) {
            $pp_stmt = $db->prepare("
                SELECT 
                    pp.id,
                    pp.amount,
                    pp.payment_method as method,
                    pp.reference_number as reference,
                    pp.status,
                    pp.payment_date,
                    pp.created_at,
                    pp.notes,
                    'pledge' as payment_type
                FROM pledge_payments pp
                WHERE pp.donor_id = ?
                ORDER BY pp.payment_date DESC, pp.created_at DESC
            ");
            $pp_stmt->bind_param('i', $donor['id']);
            $pp_stmt->execute();
            $pledge_payments = $pp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $pp_stmt->close();
            
            $payments = array_merge($payments, $pledge_payments);
        }
        
        // Then, get instant payments (older system)
        if (isset($donor['phone'])) {
            $p_stmt = $db->prepare("
                SELECT 
                    p.id,
                    p.amount,
                    p.method,
                    p.reference,
                    p.status,
                    COALESCE(p.received_at, p.created_at) as payment_date,
                    p.created_at,
                    p.notes,
                    'instant' as payment_type
                FROM payments p
                WHERE p.donor_phone = ?
                ORDER BY p.received_at DESC, p.created_at DESC
            ");
            $p_stmt->bind_param('s', $donor['phone']);
            $p_stmt->execute();
            $instant_payments = $p_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $p_stmt->close();
            
            $payments = array_merge($payments, $instant_payments);
        }
        
        // Sort all payments by date (newest first)
        usort($payments, function($a, $b) {
            $date_a = strtotime($a['payment_date'] ?? $a['created_at'] ?? '1970-01-01');
            $date_b = strtotime($b['payment_date'] ?? $b['created_at'] ?? '1970-01-01');
            return $date_b - $date_a;
        });
        
    } catch (Exception $e) {
        error_log("Payment history error: " . $e->getMessage());
        $debug_info = $e->getMessage();
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
    <link rel="stylesheet" href="../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/donor.css?v=<?php echo @filemtime(__DIR__ . '/assets/donor.css'); ?>">
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="app-content">
        <?php include 'includes/topbar.php'; ?>
        
        <main class="main-content">
                <div class="page-header">
                    <h1 class="page-title">Payment History</h1>
                </div>

                <!-- Debug Info (remove in production) -->
                <?php if (isset($_GET['debug'])): ?>
                <div class="alert alert-info mb-3">
                    <strong>Debug Info:</strong><br>
                    Donor ID: <?php echo $donor['id'] ?? 'NOT SET'; ?><br>
                    Donor Phone: <?php echo $donor['phone'] ?? 'NOT SET'; ?><br>
                    Payments Found: <?php echo count($payments); ?><br>
                    DB Connection: <?php echo $db_connection_ok ? 'OK' : 'FAILED'; ?><br>
                    <?php if ($debug_info): ?>Error: <?php echo htmlspecialchars($debug_info); ?><?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-list text-primary"></i>All Payments
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($payments)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3"></i>
                                <p>No payments recorded yet.</p>
                                <a href="make-payment.php" class="btn btn-primary mt-2">
                                    <i class="fas fa-plus me-2"></i>Make Your First Payment
                                </a>
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
                                                $date = $payment['payment_date'] ?? $payment['created_at'];
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
                                                // Handle both 'approved' (instant) and 'confirmed' (pledge) statuses
                                                if ($status === 'approved' || $status === 'confirmed') {
                                                    $badge_class = 'bg-success';
                                                    $display_status = 'Approved';
                                                } elseif ($status === 'pending') {
                                                    $badge_class = 'bg-warning text-dark';
                                                    $display_status = 'Pending';
                                                } elseif ($status === 'voided' || $status === 'rejected') {
                                                    $badge_class = 'bg-danger';
                                                    $display_status = ucfirst($status);
                                                } else {
                                                    $badge_class = 'bg-secondary';
                                                    $display_status = ucfirst($status);
                                                }
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo $display_status; ?>
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
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/donor.js"></script>
</body>
</html>

