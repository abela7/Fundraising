<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';

// Set JSON header
header('Content-Type: application/json');

require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    verify_csrf();
    
    $db = db();
    $action = $_POST['action'] ?? '';
    $pledgeId = (int)($_POST['pledge_id'] ?? 0);
    $uid = (int)current_user()['id'];
    
    $debug = [];
    $debug['step'] = 'Starting approval';
    $debug['pledge_id'] = $pledgeId;
    $debug['action'] = $action;
    
    if ($action !== 'approve' || $pledgeId <= 0) {
        throw new Exception('Invalid action or pledge ID');
    }
    
    $db->begin_transaction();
    
    // Get pledge
    $debug['step'] = 'Getting pledge';
    $stmt = $db->prepare('SELECT id, amount, type, status, donor_name, package_id FROM pledges WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $pledgeId);
    $stmt->execute();
    $pledge = $stmt->get_result()->fetch_assoc();
    
    if (!$pledge || $pledge['status'] !== 'pending') {
        throw new Exception('Pledge not found or not pending');
    }
    
    $debug['pledge_data'] = $pledge;
    
    // Update pledge status
    $debug['step'] = 'Updating pledge status';
    $upd = $db->prepare("UPDATE pledges SET status='approved', approved_by_user_id=?, approved_at=NOW() WHERE id=?");
    $upd->bind_param('ii', $uid, $pledgeId);
    $upd->execute();
    
    // Update counters
    $debug['step'] = 'Updating counters';
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
    
    // FLOOR ALLOCATION - DEBUG VERSION
    $debug['step'] = 'Starting floor allocation';
    $gridMessage = "Grid allocation not attempted";
    $allocationResult = ['success' => false, 'error' => 'Not started'];
    
    try {
        $debug['step'] = 'Loading CustomAmountAllocator';
        require_once __DIR__ . '/../../shared/CustomAmountAllocator.php';
        
        $debug['step'] = 'Creating allocator instance';
        $customAllocator = new CustomAmountAllocator($db);
        
        $donorName = (string)($pledge['donor_name'] ?? 'Anonymous');
        $amount = (float)$pledge['amount'];
        $status = ($pledge['type'] === 'paid') ? 'paid' : 'pledged';
        
        $debug['allocation_params'] = [
            'donor_name' => $donorName,
            'amount' => $amount,
            'status' => $status,
            'type' => $pledge['type']
        ];
        
        $debug['step'] = 'Calling allocation method';
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
        
        $debug['allocation_result'] = $allocationResult;
        
        if (isset($allocationResult['success']) && $allocationResult['success']) {
            $gridMessage = "Grid allocated successfully";
        } else {
            $gridMessage = "Grid allocation failed: " . ($allocationResult['error'] ?? 'Unknown error');
        }
        
    } catch (Throwable $gridError) {
        $debug['grid_error'] = [
            'message' => $gridError->getMessage(),
            'file' => $gridError->getFile(),
            'line' => $gridError->getLine(),
            'trace' => $gridError->getTraceAsString()
        ];
        $gridMessage = "Grid allocation error: " . $gridError->getMessage();
    }
    
    $debug['grid_message'] = $gridMessage;
    $debug['step'] = 'Committing transaction';
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Pledge #{$pledgeId} approved successfully. $gridMessage",
        'debug' => $debug
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->ping()) {
        $db->rollback();
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => $debug ?? [],
        'error_details' => [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}
?>
