<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone
date_default_timezone_set('Europe/London');

$conn = db();
$payment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';

if ($payment_id <= 0 || $donor_id <= 0) {
    header('Location: view-donor.php?id=' . $donor_id);
    exit;
}

// Fetch payment details
try {
    $query = "
        SELECT 
            p.*,
            d.name as donor_name,
            d.phone as donor_phone,
            pl.amount as pledge_amount,
            (SELECT COUNT(*) FROM floor_grid_cells WHERE payment_id = p.id) as allocated_cells
        FROM payments p
        JOIN donors d ON p.donor_id = d.id
        LEFT JOIN pledges pl ON p.pledge_id = pl.id
        WHERE p.id = ? AND p.donor_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $payment_id, $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $payment = $result->fetch_object();
    
    if (!$payment) {
        header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Payment not found'));
        exit;
    }
    
    // Get allocated cell details
    $cells = [];
    if ($payment->allocated_cells > 0) {
        $cells_query = "SELECT cell_id, area_size FROM floor_grid_cells WHERE payment_id = ? ORDER BY cell_id";
        $cells_stmt = $conn->prepare($cells_query);
        $cells_stmt->bind_param('i', $payment_id);
        $cells_stmt->execute();
        $cells_result = $cells_stmt->get_result();
        while ($cell = $cells_result->fetch_object()) {
            $cells[] = $cell;
        }
    }
    
} catch (Exception $e) {
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Database error: ' . $e->getMessage()));
    exit;
}

// Handle deletion confirmation
if ($confirm === 'yes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->begin_transaction();
        
        // Step 1: Deallocate floor grid cells if any
        if ($payment->allocated_cells > 0) {
            $deallocate_query = "
                UPDATE floor_grid_cells 
                SET payment_id = NULL
                WHERE payment_id = ?
            ";
            $deallocate_stmt = $conn->prepare($deallocate_query);
            $deallocate_stmt->bind_param('i', $payment_id);
            $deallocate_stmt->execute();
        }
        
        // Step 2: Delete the payment
        $delete_query = "DELETE FROM payments WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param('i', $payment_id);
        $delete_stmt->execute();
        
        // Step 3: Recalculate donor totals
        $recalc_query = "
            UPDATE donors 
            SET total_paid = (
                    SELECT COALESCE(SUM(amount), 0) 
                    FROM payments 
                    WHERE donor_id = ? AND status = 'approved'
                ),
                balance = total_pledged - total_paid
            WHERE id = ?
        ";
        $recalc_stmt = $conn->prepare($recalc_query);
        $recalc_stmt->bind_param('ii', $donor_id, $donor_id);
        $recalc_stmt->execute();
        
        $conn->commit();
        
        header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode('Payment deleted successfully.'));
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Failed to delete: ' . $e->getMessage()));
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Delete Payment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .confirm-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 600px;
            width: 100%;
        }
        .confirm-header {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 2rem;
            border-radius: 16px 16px 0 0;
            text-align: center;
        }
        .confirm-header i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.9;
        }
        .confirm-body {
            padding: 2rem;
        }
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #1e3c72;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 4px;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 4px;
        }
        .danger-box {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 4px;
        }
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }
        .action-buttons .btn {
            flex: 1;
            padding: 0.75rem;
            font-weight: 600;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #eee;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #6c757d;
        }
        .cell-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        .cell-tag {
            background: #e9ecef;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.85rem;
            text-align: center;
            border: 1px solid #dee2e6;
        }
        
        @media (max-width: 768px) {
            .confirm-header {
                padding: 1.5rem;
            }
            .confirm-header i {
                font-size: 3rem;
            }
            .confirm-body {
                padding: 1.5rem;
            }
            .action-buttons {
                flex-direction: column-reverse;
            }
            .cell-grid {
                grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="confirm-card">
        <div class="confirm-header">
            <i class="fas fa-exclamation-triangle"></i>
            <h2 class="mb-0">Confirm Delete Payment</h2>
        </div>
        
        <div class="confirm-body">
            <div class="danger-box">
                <strong><i class="fas fa-exclamation-circle me-2"></i>Warning:</strong>
                You are about to permanently delete this payment record. This action cannot be undone!
            </div>
            
            <div class="info-box">
                <h6 class="mb-3"><strong>Payment Details</strong></h6>
                <div class="detail-row">
                    <span class="detail-label">Donor:</span>
                    <span><?php echo htmlspecialchars($payment->donor_name); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Phone:</span>
                    <span><?php echo htmlspecialchars($payment->donor_phone); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Amount:</span>
                    <span class="fw-bold text-primary">£<?php echo number_format((float)$payment->amount, 2); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Method:</span>
                    <span class="badge bg-info"><?php echo ucfirst($payment->method); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Date:</span>
                    <span><?php echo date('M d, Y', strtotime($payment->received_at ?? $payment->created_at)); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="badge bg-<?php echo $payment->status == 'approved' ? 'success' : 'warning'; ?>">
                        <?php echo ucfirst($payment->status); ?>
                    </span>
                </div>
                <?php if ($payment->reference): ?>
                <div class="detail-row">
                    <span class="detail-label">Reference:</span>
                    <span><?php echo htmlspecialchars($payment->reference); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($payment->installment_number): ?>
                <div class="detail-row">
                    <span class="detail-label">Installment:</span>
                    <span>#<?php echo $payment->installment_number; ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($payment->allocated_cells > 0): ?>
            <div class="warning-box">
                <strong><i class="fas fa-th-large me-2"></i>Allocated Cells:</strong>
                This payment has <?php echo $payment->allocated_cells; ?> floor grid cell(s) linked to it. The cells will be unlinked but NOT deallocated.
                <div class="cell-grid">
                    <?php foreach ($cells as $cell): ?>
                        <div class="cell-tag">
                            <i class="fas fa-th-large"></i> <?php echo htmlspecialchars($cell->cell_id); ?>
                            <br><small><?php echo $cell->area_size; ?>m²</small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="info-box mt-3">
                <h6 class="mb-2"><strong>What will happen:</strong></h6>
                <ul class="mb-0">
                    <li>The payment record will be permanently deleted</li>
                    <?php if ($payment->allocated_cells > 0): ?>
                    <li><?php echo $payment->allocated_cells; ?> floor grid cell(s) will be unlinked from this payment</li>
                    <?php endif; ?>
                    <li>Donor's total paid amount will be recalculated (reduced by £<?php echo number_format((float)$payment->amount, 2); ?>)</li>
                    <li>Donor's balance will be updated</li>
                </ul>
            </div>
            
            <form method="POST">
                <div class="action-buttons">
                    <a href="view-donor.php?id=<?php echo $donor_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash-alt me-2"></i>Yes, Delete Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

