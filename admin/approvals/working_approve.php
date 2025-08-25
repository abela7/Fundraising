<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    verify_csrf();
    
    $db = db();
    $action = $_POST['action'] ?? '';
    $pledgeId = (int)($_POST['pledge_id'] ?? 0);
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $uid = (int)current_user()['id'];
    
    $validActions = ['approve', 'reject', 'approve_payment', 'reject_payment'];
    if (!in_array($action, $validActions)) {
        throw new Exception('Invalid action');
    }
    
    $db->begin_transaction();
    
    if ($action === 'approve') {
        if ($pledgeId <= 0) throw new Exception('Invalid pledge ID');
        
        // Get pledge data
        $stmt = $db->prepare('SELECT id, amount, type, status, donor_name, package_id FROM pledges WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $pledgeId);
        $stmt->execute();
        $pledge = $stmt->get_result()->fetch_assoc();
        
        if (!$pledge || $pledge['status'] !== 'pending') {
            throw new Exception('Pledge not found or not pending');
        }
        
        // Update pledge status
        $upd = $db->prepare("UPDATE pledges SET status='approved', approved_by_user_id=?, approved_at=NOW() WHERE id=?");
        $upd->bind_param('ii', $uid, $pledgeId);
        $upd->execute();
        
        // Update counters
        $deltaPaid = 0.0;
        $deltaPledged = 0.0;
        if ((string)$pledge['type'] === 'paid') {
            $deltaPaid = (float)$pledge['amount'];
        } else {
            $deltaPledged = (float)$pledge['amount'];
        }
        
        $grandDelta = $deltaPaid + $deltaPledged;
        $ctr = $db->prepare(
            "INSERT INTO counters (id, paid_total, pledged_total, grand_total, version, recalc_needed)
             VALUES (1, ?, ?, ?, 1, 0)
             ON DUPLICATE KEY UPDATE
               paid_total = paid_total + VALUES(paid_total),
               pledged_total = pledged_total + VALUES(pledged_total),
               grand_total = grand_total + VALUES(grand_total),
               version = version + 1,
               recalc_needed = 0"
        );
        $ctr->bind_param('ddd', $deltaPaid, $deltaPledged, $grandDelta);
        $ctr->execute();
        
        // FLOOR ALLOCATION - CLEAN IMPLEMENTATION
        $gridMessage = "Grid allocation completed";
        $allocationResult = ['success' => true, 'message' => 'Default success'];
        
        try {
            require_once __DIR__ . '/../../shared/CustomAmountAllocator.php';
            $customAllocator = new CustomAmountAllocator($db);
            
            $donorName = (string)($pledge['donor_name'] ?? 'Anonymous');
            $amount = (float)$pledge['amount'];
            $status = ($pledge['type'] === 'paid') ? 'paid' : 'pledged';
            
            if ($pledge['type'] === 'paid') {
                $allocationResult = $customAllocator->processPaymentCustomAmount(
                    $pledgeId,
                    $amount,
                    $donorName,
                    $status
                );
            } else {
                $allocationResult = $customAllocator->processCustomAmount(
                    $pledgeId,
                    $amount,
                    $donorName,
                    $status
                );
            }
            
            if (isset($allocationResult['success']) && $allocationResult['success']) {
                $gridMessage = "Grid allocated successfully";
            } else {
                $gridMessage = "Grid allocation failed: " . ($allocationResult['error'] ?? 'Unknown error');
            }
            
        } catch (Exception $gridError) {
            $gridMessage = "Grid allocation error: " . $gridError->getMessage();
            error_log("Grid allocation failed for pledge {$pledgeId}: " . $gridError->getMessage());
            // Don't fail the approval - continue
        }
        
        $message = "Pledge #{$pledgeId} approved successfully. $gridMessage";
        
    } elseif ($action === 'approve_payment') {
        if ($paymentId <= 0) throw new Exception('Invalid payment ID');
        
        // Get payment data
        $sel = $db->prepare("SELECT id, amount, status, package_id FROM payments WHERE id=? FOR UPDATE");
        $sel->bind_param('i', $paymentId);
        $sel->execute();
        $pay = $sel->get_result()->fetch_assoc();
        
        if (!$pay || $pay['status'] !== 'pending') {
            throw new Exception('Payment not found or not pending');
        }
        
        // Update payment status
        $upd = $db->prepare("UPDATE payments SET status='approved' WHERE id=?");
        $upd->bind_param('i', $paymentId);
        $upd->execute();
        
        // Update counters
        $amt = (float)$pay['amount'];
        $ctr = $db->prepare(
            "INSERT INTO counters (id, paid_total, pledged_total, grand_total, version, recalc_needed)
             VALUES (1, ?, 0, ?, 1, 0)
             ON DUPLICATE KEY UPDATE
               paid_total = paid_total + VALUES(paid_total),
               grand_total = grand_total + VALUES(grand_total),
               version = version + 1,
               recalc_needed = 0"
        );
        $ctr->bind_param('dd', $amt, $amt);
        $ctr->execute();
        
        // FLOOR ALLOCATION FOR PAYMENTS
        $gridMessage = "Grid allocation completed";
        $allocationResult = ['success' => true, 'message' => 'Default success'];
        
        try {
            require_once __DIR__ . '/../../shared/CustomAmountAllocator.php';
            $customAllocator = new CustomAmountAllocator($db);
            
            $allocationResult = $customAllocator->processPaymentCustomAmount(
                null, // No pledge ID for standalone payments
                $amt,
                'Payment Donor',
                'paid'
            );
            
            if (isset($allocationResult['success']) && $allocationResult['success']) {
                $gridMessage = "Grid allocated successfully";
            } else {
                $gridMessage = "Grid allocation failed: " . ($allocationResult['error'] ?? 'Unknown error');
            }
            
        } catch (Exception $gridError) {
            $gridMessage = "Grid allocation error: " . $gridError->getMessage();
            error_log("Grid allocation failed for payment {$paymentId}: " . $gridError->getMessage());
        }
        
        $message = "Payment #{$paymentId} approved successfully. $gridMessage";
        
    } elseif ($action === 'reject') {
        if ($pledgeId <= 0) throw new Exception('Invalid pledge ID');
        
        $upd = $db->prepare("UPDATE pledges SET status='rejected', approved_by_user_id=?, approved_at=NOW() WHERE id=? AND status='pending'");
        $upd->bind_param('ii', $uid, $pledgeId);
        $upd->execute();
        
        if ($upd->affected_rows === 0) {
            throw new Exception('Pledge not found or not pending');
        }
        
        $message = "Pledge #{$pledgeId} rejected successfully";
        
    } elseif ($action === 'reject_payment') {
        if ($paymentId <= 0) throw new Exception('Invalid payment ID');
        
        $upd = $db->prepare("UPDATE payments SET status='rejected' WHERE id=? AND status='pending'");
        $upd->bind_param('i', $paymentId);
        $upd->execute();
        
        if ($upd->affected_rows === 0) {
            throw new Exception('Payment not found or not pending');
        }
        
        $message = "Payment #{$paymentId} rejected successfully";
        
    } else {
        throw new Exception('Invalid action');
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => $message
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->ping()) {
        $db->rollback();
    }
    
    error_log("Approval error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
