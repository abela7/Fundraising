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
validate_donor_device(); // Check if device was revoked
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
    <style>
        /* Mobile-friendly payment cards */
        .payment-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
            background: white;
        }
        .payment-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transform: translateY(-1px);
        }
        .payment-card:active {
            transform: translateY(0);
        }
        .payment-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .payment-row:last-child {
            margin-bottom: 0;
        }
        .payment-label {
            font-size: 0.875rem;
            color: #6c757d;
            font-weight: 500;
        }
        .payment-value {
            font-weight: 600;
            text-align: right;
        }
        .payment-amount {
            font-size: 1.25rem;
            color: #198754;
            font-weight: 700;
        }
        
        /* Desktop table - hide on mobile */
        @media (max-width: 767px) {
            .payment-table {
                display: none;
            }
            .payment-cards {
                display: block;
            }
        }
        
        /* Mobile cards - hide on desktop */
        @media (min-width: 768px) {
            .payment-cards {
                display: none;
            }
            .payment-table {
                display: table;
            }
        }
        
        /* Clickable table rows */
        .table tbody tr {
            cursor: pointer;
        }
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Modal styling */
        .payment-detail-item {
            padding: 0.75rem 0;
            border-bottom: 1px solid #e9ecef;
        }
        .payment-detail-item:last-child {
            border-bottom: none;
        }
        .payment-detail-label {
            font-size: 0.875rem;
            color: #6c757d;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        .payment-detail-value {
            font-size: 1rem;
            font-weight: 600;
            color: #212529;
        }
    </style>
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
                <?php if (isset($_GET['debug']) && isset($_SESSION['user']) && (($_SESSION['user']['role'] ?? '') === 'admin')): ?>
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
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list text-primary"></i>All Payments
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($payments)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3"></i>
                                <p class="mb-3">No payments recorded yet.</p>
                                <a href="make-payment.php" class="btn btn-primary">
                                    <i class="fas fa-plus me-2"></i>Make Your First Payment
                                </a>
                            </div>
                        <?php else: ?>
                            <!-- Desktop Table View -->
                            <div class="table-responsive payment-table">
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
                                        <?php foreach ($payments as $idx => $payment): ?>
                                        <?php 
                                        $date = $payment['payment_date'] ?? $payment['created_at'];
                                        $date_display = $date ? date('d M Y', strtotime($date)) : '-';
                                        $date_full = $date ? date('d M Y \a\t g:i A', strtotime($date)) : '-';
                                        $amount = number_format($payment['amount'], 2);
                                        $method = ucfirst(str_replace('_', ' ', $payment['method'] ?? 'N/A'));
                                        $reference = htmlspecialchars($payment['reference'] ?? '-');
                                        $notes = htmlspecialchars($payment['notes'] ?? '');
                                        $status = $payment['status'] ?? 'pending';
                                        
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
                                        <tr class="payment-row-clickable" 
                                            data-date="<?php echo htmlspecialchars($date_full); ?>"
                                            data-amount="£<?php echo htmlspecialchars($amount); ?>"
                                            data-method="<?php echo htmlspecialchars($method); ?>"
                                            data-reference="<?php echo htmlspecialchars($reference); ?>"
                                            data-status="<?php echo htmlspecialchars($display_status); ?>"
                                            data-status-class="<?php echo htmlspecialchars($badge_class); ?>"
                                            data-notes="<?php echo htmlspecialchars($notes); ?>">
                                            <td><?php echo $date_display; ?></td>
                                            <td><strong>£<?php echo $amount; ?></strong></td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo $method; ?></span>
                                            </td>
                                            <td><?php echo $reference; ?></td>
                                            <td>
                                                <span class="badge <?php echo $badge_class; ?>"><?php echo $display_status; ?></span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Mobile Card View -->
                            <div class="payment-cards p-3">
                                <?php foreach ($payments as $payment): ?>
                                <?php 
                                $date = $payment['payment_date'] ?? $payment['created_at'];
                                $date_display = $date ? date('d M Y', strtotime($date)) : '-';
                                $date_full = $date ? date('d M Y \a\t g:i A', strtotime($date)) : '-';
                                $amount = number_format($payment['amount'], 2);
                                $method = ucfirst(str_replace('_', ' ', $payment['method'] ?? 'N/A'));
                                $reference = htmlspecialchars($payment['reference'] ?? '-');
                                $notes = htmlspecialchars($payment['notes'] ?? '');
                                $status = $payment['status'] ?? 'pending';
                                
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
                                <div class="payment-card payment-row-clickable"
                                    data-date="<?php echo htmlspecialchars($date_full); ?>"
                                    data-amount="£<?php echo htmlspecialchars($amount); ?>"
                                    data-method="<?php echo htmlspecialchars($method); ?>"
                                    data-reference="<?php echo htmlspecialchars($reference); ?>"
                                    data-status="<?php echo htmlspecialchars($display_status); ?>"
                                    data-status-class="<?php echo htmlspecialchars($badge_class); ?>"
                                    data-notes="<?php echo htmlspecialchars($notes); ?>">
                                    <div class="payment-row">
                                        <span class="payment-label">Amount</span>
                                        <span class="payment-value payment-amount">£<?php echo $amount; ?></span>
                                    </div>
                                    <div class="payment-row">
                                        <span class="payment-label">Date</span>
                                        <span class="payment-value"><?php echo $date_display; ?></span>
                                    </div>
                                    <div class="payment-row">
                                        <span class="payment-label">Method</span>
                                        <span class="payment-value">
                                            <span class="badge bg-secondary"><?php echo $method; ?></span>
                                        </span>
                                    </div>
                                    <div class="payment-row">
                                        <span class="payment-label">Status</span>
                                        <span class="payment-value">
                                            <span class="badge <?php echo $badge_class; ?>"><?php echo $display_status; ?></span>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
        </main>
    </div>
</div>

<!-- Payment Details Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paymentModalLabel">
                    <i class="fas fa-receipt text-primary me-2"></i>Payment Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="payment-detail-item">
                    <div class="payment-detail-label">Amount</div>
                    <div class="payment-detail-value" id="modalAmount">-</div>
                </div>
                <div class="payment-detail-item">
                    <div class="payment-detail-label">Date</div>
                    <div class="payment-detail-value" id="modalDate">-</div>
                </div>
                <div class="payment-detail-item">
                    <div class="payment-detail-label">Payment Method</div>
                    <div class="payment-detail-value" id="modalMethod">-</div>
                </div>
                <div class="payment-detail-item">
                    <div class="payment-detail-label">Reference Number</div>
                    <div class="payment-detail-value" id="modalReference">-</div>
                </div>
                <div class="payment-detail-item">
                    <div class="payment-detail-label">Status</div>
                    <div class="payment-detail-value">
                        <span class="badge" id="modalStatus">-</span>
                    </div>
                </div>
                <div class="payment-detail-item" id="modalNotesItem" style="display:none;">
                    <div class="payment-detail-label">Notes</div>
                    <div class="payment-detail-value" id="modalNotes">-</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/donor.js"></script>
<script>
// Handle click on payment rows/cards
document.addEventListener('DOMContentLoaded', function() {
    const paymentRows = document.querySelectorAll('.payment-row-clickable');
    
    paymentRows.forEach(function(row) {
        row.addEventListener('click', function() {
            const payment = {
                date: this.getAttribute('data-date') || '-',
                amount: this.getAttribute('data-amount') || '-',
                method: this.getAttribute('data-method') || '-',
                reference: this.getAttribute('data-reference') || '-',
                status: this.getAttribute('data-status') || '-',
                statusClass: this.getAttribute('data-status-class') || 'bg-secondary',
                notes: this.getAttribute('data-notes') || ''
            };
            
            showPaymentDetails(payment);
        });
    });
});

function showPaymentDetails(payment) {
    // Populate modal
    document.getElementById('modalAmount').textContent = payment.amount || '-';
    document.getElementById('modalDate').textContent = payment.date || '-';
    document.getElementById('modalMethod').textContent = payment.method || '-';
    document.getElementById('modalReference').textContent = payment.reference || '-';
    
    // Status badge
    const statusBadge = document.getElementById('modalStatus');
    statusBadge.textContent = payment.status || '-';
    statusBadge.className = 'badge ' + (payment.statusClass || 'bg-secondary');
    
    // Notes (show only if exists)
    const notesItem = document.getElementById('modalNotesItem');
    const notesValue = document.getElementById('modalNotes');
    if (payment.notes && payment.notes.trim() !== '') {
        notesValue.textContent = payment.notes;
        notesItem.style.display = 'block';
    } else {
        notesItem.style.display = 'none';
    }
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
    modal.show();
}
</script>
</body>
</html>

