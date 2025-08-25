<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';

// Set JSON header
header('Content-Type: application/json');

// Check authentication
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
    
    // Validate action and IDs
    $validActions = ['approve', 'reject', 'approve_payment', 'reject_payment'];
    if (!in_array($action, $validActions)) {
        throw new Exception('Invalid action');
    }
    
    if (($action === 'approve' || $action === 'reject') && $pledgeId <= 0) {
        throw new Exception('Invalid pledge ID');
    }
    
    if (($action === 'approve_payment' || $action === 'reject_payment') && $paymentId <= 0) {
        throw new Exception('Invalid payment ID');
    }
    
    $db->begin_transaction();
    
    try {
        if ($action === 'approve') {
            // Approve pledge (simplified without heavy grid allocation)
            $stmt = $db->prepare('SELECT id, amount, type, status, donor_name FROM pledges WHERE id = ? FOR UPDATE');
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
            
            // Update counters (lightweight version)
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
            
            // IMMEDIATE grid allocation (fast version)
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
                
                $gridMessage = $allocationResult['success'] ? 
                    "Grid allocated successfully." : 
                    "Grid allocation failed: " . ($allocationResult['error'] ?? 'Unknown error');
                    
            } catch (Exception $gridError) {
                $gridMessage = "Grid allocation error: " . $gridError->getMessage();
                // Don't fail the approval if grid allocation fails
                error_log("Grid allocation failed for pledge {$pledgeId}: " . $gridError->getMessage());
            }
            
            // Audit log with grid allocation result
            $before = json_encode(['status' => 'pending', 'type' => $pledge['type'], 'amount' => (float)$pledge['amount']]);
            $after = json_encode([
                'status' => 'approved',
                'grid_allocation' => $allocationResult ?? ['success' => false, 'error' => $gridMessage ?? 'Not processed']
            ]);
            $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'approve', ?, ?, 'admin_ajax')");
            $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
            $log->execute();
            
            $message = "Pledge #{$pledgeId} approved successfully. " . ($gridMessage ?? 'Grid allocation processed.');
            
        } elseif ($action === 'reject') {
            // Reject pledge
            $stmt = $db->prepare('SELECT id, status FROM pledges WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $pledgeId);
            $stmt->execute();
            $pledge = $stmt->get_result()->fetch_assoc();
            
            if (!$pledge || $pledge['status'] !== 'pending') {
                throw new Exception('Pledge not found or not pending');
            }
            
            $upd = $db->prepare("UPDATE pledges SET status='rejected' WHERE id=?");
            $upd->bind_param('i', $pledgeId);
            $upd->execute();
            
            // Audit log
            $before = json_encode(['status' => 'pending']);
            $after = json_encode(['status' => 'rejected']);
            $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'reject', ?, ?, 'admin_ajax')");
            $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
            $log->execute();
            
            $message = "Pledge #{$pledgeId} rejected successfully.";
            
        } elseif ($action === 'approve_payment') {
            // Approve payment (simplified)
            $sel = $db->prepare("SELECT id, amount, status FROM payments WHERE id=? FOR UPDATE");
            $sel->bind_param('i', $paymentId);
            $sel->execute();
            $pay = $sel->get_result()->fetch_assoc();
            
            if (!$pay || $pay['status'] !== 'pending') {
                throw new Exception('Payment not found or not pending');
            }
            
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
            
            // IMMEDIATE grid allocation for payment
            try {
                require_once __DIR__ . '/../../shared/CustomAmountAllocator.php';
                $customAllocator = new CustomAmountAllocator($db);
                
                // Get payment details for allocation
                $paymentDetails = $db->prepare("SELECT donor_name, amount FROM payments WHERE id = ?");
                $paymentDetails->bind_param('i', $paymentId);
                $paymentDetails->execute();
                $paymentData = $paymentDetails->get_result()->fetch_assoc();
                
                if ($paymentData) {
                    $allocationResult = $customAllocator->processPaymentCustomAmount(
                        $paymentId,
                        (float)$paymentData['amount'],
                        (string)$paymentData['donor_name'],
                        'paid'
                    );
                    
                    $gridMessage = $allocationResult['success'] ? 
                        "Grid allocated successfully." : 
                        "Grid allocation failed: " . ($allocationResult['error'] ?? 'Unknown error');
                } else {
                    $gridMessage = "Payment data not found for grid allocation.";
                }
                
            } catch (Exception $gridError) {
                $gridMessage = "Grid allocation error: " . $gridError->getMessage();
                error_log("Grid allocation failed for payment {$paymentId}: " . $gridError->getMessage());
            }
            
            $message = "Payment #{$paymentId} approved successfully. " . ($gridMessage ?? 'Grid allocation processed.');
            
        } elseif ($action === 'reject_payment') {
            // Reject payment
            $sel = $db->prepare("SELECT id, status FROM payments WHERE id=? FOR UPDATE");
            $sel->bind_param('i', $paymentId);
            $sel->execute();
            $pay = $sel->get_result()->fetch_assoc();
            
            if (!$pay || $pay['status'] !== 'pending') {
                throw new Exception('Payment not found or not pending');
            }
            
            $upd = $db->prepare("UPDATE payments SET status='voided' WHERE id=?");
            $upd->bind_param('i', $paymentId);
            $upd->execute();
            
            $message = "Payment #{$paymentId} rejected successfully.";
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => $message
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("AJAX approval error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
