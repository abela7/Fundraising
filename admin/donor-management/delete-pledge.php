<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

// Set timezone
date_default_timezone_set('Europe/London');

$conn = db();
$pledge_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';

if ($pledge_id <= 0 || $donor_id <= 0) {
    header('Location: view-donor.php?id=' . $donor_id);
    exit;
}

// Fetch pledge details with dependencies
try {
    $query = "
        SELECT 
            p.*,
            d.name as donor_name,
            d.phone as donor_phone,
            d.total_pledged,
            d.balance,
            (SELECT COUNT(*) FROM donor_payment_plans WHERE pledge_id = p.id) as linked_plans,
            (SELECT COUNT(*) FROM payments WHERE pledge_id = p.id) as linked_payments,
            (SELECT COUNT(*) FROM floor_grid_cells WHERE pledge_id = p.id) as allocated_cells
        FROM pledges p
        JOIN donors d ON p.donor_id = d.id
        WHERE p.id = ? AND p.donor_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $pledge_id, $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $pledge = $result->fetch_object();
    
    if (!$pledge) {
        header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Pledge not found'));
        exit;
    }
    
    // Get allocated cell details
    $cells = [];
    if ($pledge->allocated_cells > 0) {
        $cells_query = "SELECT cell_id, area_size FROM floor_grid_cells WHERE pledge_id = ? ORDER BY cell_id";
        $cells_stmt = $conn->prepare($cells_query);
        $cells_stmt->bind_param('i', $pledge_id);
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

// Check if pledge can be deleted
$can_delete = true;
$block_reason = '';

// Only allow deletion of REJECTED pledges
if ($pledge->status !== 'rejected') {
    $can_delete = false;
    $block_reason = 'Only rejected pledges can be deleted. This pledge has status: "' . ucfirst($pledge->status) . '". Please reject the pledge first before deleting.';
}

if ($pledge->linked_plans > 0) {
    $can_delete = false;
    $block_reason = 'This pledge has ' . $pledge->linked_plans . ' active payment plan(s). You must delete the payment plan(s) first.';
}

// Handle deletion confirmation
if ($confirm === 'yes' && $_SERVER['REQUEST_METHOD'] === 'POST' && $can_delete) {
    try {
        // Verify CSRF token
        verify_csrf();
        
        $conn->begin_transaction();
        
        // Step 1: Deallocate floor grid cells (set back to available)
        $cells_deallocated = 0;
        if ($pledge->allocated_cells > 0) {
            $deallocate_query = "
                UPDATE floor_grid_cells 
                SET status = 'available',
                    pledge_id = NULL,
                    donor_name = NULL,
                    amount = NULL,
                    assigned_date = NULL
                WHERE pledge_id = ?
            ";
            $deallocate_stmt = $conn->prepare($deallocate_query);
            $deallocate_stmt->bind_param('i', $pledge_id);
            $deallocate_stmt->execute();
            $cells_deallocated = $deallocate_stmt->affected_rows;
        }
        
        // Step 2: Unlink payments from pledge_payments table (if any)
        $unlinked_pledge_payments = 0;
        $unlink_pp_query = "UPDATE pledge_payments SET pledge_id = NULL WHERE pledge_id = ?";
        $unlink_pp_stmt = $conn->prepare($unlink_pp_query);
        $unlink_pp_stmt->bind_param('i', $pledge_id);
        $unlink_pp_stmt->execute();
        $unlinked_pledge_payments = $unlink_pp_stmt->affected_rows;
        
        // Step 3: Unlink payments from payments table (don't delete them, just unlink)
        $unlinked_payments = 0;
        if ($pledge->linked_payments > 0) {
            $unlink_payments_query = "UPDATE payments SET pledge_id = NULL WHERE pledge_id = ?";
            $unlink_stmt = $conn->prepare($unlink_payments_query);
            $unlink_stmt->bind_param('i', $pledge_id);
            $unlink_stmt->execute();
            $unlinked_payments = $unlink_stmt->affected_rows;
        }
        
        // Step 4: Audit log before deletion (comprehensive)
        $pledgeData = [
            'id' => $pledge_id,
            'donor_id' => $donor_id,
            'donor_name' => $pledge->donor_name,
            'donor_phone' => $pledge->donor_phone,
            'amount' => (float)$pledge->amount,
            'status' => $pledge->status,
            'type' => $pledge->type ?? 'pledge',
            'notes' => $pledge->notes ?? '',
            'created_at' => $pledge->created_at ?? '',
            'allocated_cells' => (int)$pledge->allocated_cells,
            'cells_deallocated' => $cells_deallocated,
            'linked_plans' => (int)$pledge->linked_plans,
            'linked_payments' => (int)$pledge->linked_payments,
            'unlinked_payments' => $unlinked_payments,
            'unlinked_pledge_payments' => $unlinked_pledge_payments,
            'deletion_reason' => 'Rejected pledge deleted by admin'
        ];
        
        log_audit(
            $conn,
            'delete',
            'pledge',
            $pledge_id,
            $pledgeData,
            ['deleted' => true, 'status_at_deletion' => 'rejected'],
            'admin_portal',
            (int)($_SESSION['user']['id'] ?? 0)
        );
        
        // Step 5: Delete the pledge
        $delete_query = "DELETE FROM pledges WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param('i', $pledge_id);
        $delete_stmt->execute();
        
        // Step 6: Update donor's pledge_count (rejected pledges don't affect totals)
        // Since we're only deleting rejected pledges, we don't need to recalculate total_pledged
        // But we should update the pledge_count
        $update_count_query = "
            UPDATE donors 
            SET pledge_count = (
                    SELECT COUNT(*) 
                    FROM pledges 
                    WHERE donor_id = ?
                ),
                updated_at = NOW()
            WHERE id = ?
        ";
        $update_count_stmt = $conn->prepare($update_count_query);
        $update_count_stmt->bind_param('ii', $donor_id, $donor_id);
        $update_count_stmt->execute();
        
        $conn->commit();
        
        $success_msg = 'Rejected pledge deleted successfully.';
        if ($cells_deallocated > 0) {
            $success_msg .= ' ' . $cells_deallocated . ' cell(s) deallocated.';
        }
        if ($unlinked_payments > 0 || $unlinked_pledge_payments > 0) {
            $success_msg .= ' ' . ($unlinked_payments + $unlinked_pledge_payments) . ' payment(s) unlinked.';
        }
        
        header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode($success_msg));
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
    <title>Confirm Delete Pledge</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
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
            max-width: 700px;
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
        .blocked-box {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 1.5rem;
            margin: 1rem 0;
            border-radius: 4px;
            text-align: center;
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
            <h2 class="mb-0">Confirm Delete Pledge</h2>
        </div>
        
        <div class="confirm-body">
            <?php if (!$can_delete): ?>
                <div class="blocked-box">
                    <i class="fas fa-ban fa-3x text-danger mb-3"></i>
                    <h4 class="text-danger">Cannot Delete Pledge</h4>
                    <p class="mb-0"><?php echo htmlspecialchars($block_reason); ?></p>
                </div>
                <div class="action-buttons">
                    <a href="view-donor.php?id=<?php echo $donor_id; ?>" class="btn btn-primary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Donor Profile
                    </a>
                </div>
            <?php else: ?>
                <div class="alert alert-success py-2 mb-3">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Eligible for Deletion:</strong> This pledge has status "Rejected" and can be safely deleted.
                </div>
                
                <div class="danger-box">
                    <strong><i class="fas fa-exclamation-circle me-2"></i>Warning:</strong>
                    You are about to permanently delete this rejected pledge. This action cannot be undone!
                </div>
                
                <div class="info-box">
                    <h6 class="mb-3"><strong>Pledge Details</strong></h6>
                    <div class="detail-row">
                        <span class="detail-label">Donor:</span>
                        <span><?php echo htmlspecialchars($pledge->donor_name); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Phone:</span>
                        <span><?php echo htmlspecialchars($pledge->donor_phone); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Pledge Amount:</span>
                        <span class="fw-bold text-primary">£<?php echo number_format((float)$pledge->amount, 2); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Date:</span>
                        <span><?php echo date('M d, Y', strtotime($pledge->created_at)); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Status:</span>
                        <span class="badge bg-<?php echo $pledge->status == 'approved' ? 'success' : 'warning'; ?>">
                            <?php echo ucfirst($pledge->status); ?>
                        </span>
                    </div>
                    <?php if ($pledge->notes): ?>
                    <div class="detail-row">
                        <span class="detail-label">Notes:</span>
                        <span><?php echo htmlspecialchars($pledge->notes); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($pledge->allocated_cells > 0): ?>
                <div class="warning-box">
                    <strong><i class="fas fa-th-large me-2"></i>Allocated Cells:</strong>
                    This pledge has <?php echo $pledge->allocated_cells; ?> floor grid cell(s) allocated. These will be deallocated and made available.
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
                
                <?php if ($pledge->linked_payments > 0): ?>
                <div class="warning-box">
                    <strong><i class="fas fa-link me-2"></i>Linked Payments:</strong>
                    This pledge has <?php echo $pledge->linked_payments; ?> payment(s) linked to it. These payments will be unlinked but NOT deleted.
                </div>
                <?php endif; ?>
                
                <div class="info-box mt-3">
                    <h6 class="mb-2"><strong>What will happen:</strong></h6>
                    <ul class="mb-0">
                        <li>The rejected pledge will be permanently deleted</li>
                        <?php if ($pledge->allocated_cells > 0): ?>
                        <li><?php echo $pledge->allocated_cells; ?> floor grid cell(s) will be deallocated and made available</li>
                        <?php endif; ?>
                        <?php if ($pledge->linked_payments > 0): ?>
                        <li><?php echo $pledge->linked_payments; ?> payment(s) will be unlinked (but kept in the system)</li>
                        <?php endif; ?>
                        <li class="text-success"><i class="fas fa-check me-1"></i>Donor's total pledged amount will NOT be affected (rejected pledges don't count)</li>
                        <li class="text-success"><i class="fas fa-check me-1"></i>Donor's balance will NOT be affected</li>
                        <li>This action will be recorded in the audit log</li>
                    </ul>
                </div>
                
                <form method="POST" action="delete-pledge.php?id=<?php echo $pledge_id; ?>&donor_id=<?php echo $donor_id; ?>&confirm=yes">
                    <?php require_once __DIR__ . '/../../shared/csrf.php'; echo csrf_input(); ?>
                    <div class="action-buttons">
                        <a href="view-donor.php?id=<?php echo $donor_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash-alt me-2"></i>Yes, Delete Pledge
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

