<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_login();
require_admin();

$db = db();
$donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$donor_id) {
    header('Location: donors.php');
    exit;
}

verify_csrf();

try {
    // Get current donor data for audit
    $stmt = $db->prepare("SELECT id, name, phone, total_pledged, total_paid, balance, payment_status FROM donors WHERE id = ?");
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $donor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$donor) {
        throw new Exception("Donor not found");
    }
    
    $beforeData = [
        'total_pledged' => (float)$donor['total_pledged'],
        'total_paid' => (float)$donor['total_paid'],
        'balance' => (float)$donor['balance'],
        'payment_status' => $donor['payment_status']
    ];
    
    $db->begin_transaction();
    
    $update_method = $_POST['update_method'] ?? 'recalculate';
    
    if ($update_method === 'recalculate') {
        // Recalculate from database
        
        // Sum approved pledges
        $pledge_sum = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM pledges WHERE donor_id = ? AND status = 'approved'");
        $pledge_sum->bind_param('i', $donor_id);
        $pledge_sum->execute();
        $total_pledged = (float)$pledge_sum->get_result()->fetch_assoc()['total'];
        $pledge_sum->close();
        
        // Sum approved payments from payments table (by donor_id or donor_phone)
        $payment_sum1 = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM payments 
            WHERE (donor_id = ? OR donor_phone = ?) AND status = 'approved'
        ");
        $payment_sum1->bind_param('is', $donor_id, $donor['phone']);
        $payment_sum1->execute();
        $paid_from_payments = (float)$payment_sum1->get_result()->fetch_assoc()['total'];
        $payment_sum1->close();
        
        // Sum confirmed payments from pledge_payments table
        $payment_sum2 = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM pledge_payments WHERE donor_id = ? AND status = 'confirmed'");
        $payment_sum2->bind_param('i', $donor_id);
        $payment_sum2->execute();
        $paid_from_pledge_payments = (float)$payment_sum2->get_result()->fetch_assoc()['total'];
        $payment_sum2->close();
        
        $total_paid = $paid_from_payments + $paid_from_pledge_payments;
        $balance = max(0, $total_pledged - $total_paid);
        
        $action_note = 'Recalculated from database';
        
    } else {
        // Manual override
        $total_pledged = isset($_POST['total_pledged']) ? (float)$_POST['total_pledged'] : (float)$donor['total_pledged'];
        $total_paid = isset($_POST['total_paid']) ? (float)$_POST['total_paid'] : (float)$donor['total_paid'];
        
        // Check if auto-calculate balance is enabled
        if (isset($_POST['auto_calc_balance'])) {
            $balance = max(0, $total_pledged - $total_paid);
        } else {
            $balance = isset($_POST['balance']) ? (float)$_POST['balance'] : max(0, $total_pledged - $total_paid);
        }
        
        $action_note = 'Manual override by admin';
    }
    
    // Determine payment status
    $payment_status = 'not_started';
    if ($total_pledged == 0 && $total_paid > 0) {
        $payment_status = 'completed'; // Immediate payment, no pledge
    } elseif ($total_paid >= $total_pledged && $total_pledged > 0) {
        $payment_status = 'completed';
    } elseif ($total_paid > 0) {
        $payment_status = 'paying';
    } elseif ($total_pledged > 0) {
        $payment_status = 'not_started';
    } else {
        $payment_status = 'no_pledge';
    }
    
    // Update donor record
    $update_stmt = $db->prepare("
        UPDATE donors SET 
            total_pledged = ?,
            total_paid = ?,
            balance = ?,
            payment_status = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $update_stmt->bind_param('dddsi', $total_pledged, $total_paid, $balance, $payment_status, $donor_id);
    
    if (!$update_stmt->execute()) {
        throw new Exception("Failed to update donor: " . $update_stmt->error);
    }
    $update_stmt->close();
    
    // Audit log
    $afterData = [
        'total_pledged' => $total_pledged,
        'total_paid' => $total_paid,
        'balance' => $balance,
        'payment_status' => $payment_status,
        'update_method' => $update_method,
        'note' => $action_note
    ];
    
    log_audit(
        $db,
        'update_financial',
        'donor',
        $donor_id,
        $beforeData,
        $afterData,
        'admin_portal',
        (int)($_SESSION['user']['id'] ?? 0)
    );
    
    $db->commit();
    
    // Build success message
    $changes = [];
    if ($beforeData['total_pledged'] != $total_pledged) {
        $changes[] = "Pledged: £" . number_format($beforeData['total_pledged'], 2) . " → £" . number_format($total_pledged, 2);
    }
    if ($beforeData['total_paid'] != $total_paid) {
        $changes[] = "Paid: £" . number_format($beforeData['total_paid'], 2) . " → £" . number_format($total_paid, 2);
    }
    if ($beforeData['balance'] != $balance) {
        $changes[] = "Balance: £" . number_format($beforeData['balance'], 2) . " → £" . number_format($balance, 2);
    }
    
    $success_msg = "Financial summary updated successfully";
    if (!empty($changes)) {
        $success_msg .= " (" . implode(", ", $changes) . ")";
    }
    
    header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode($success_msg));
    exit;
    
} catch (Exception $e) {
    if (isset($db) && $db->ping()) {
        $db->rollback();
    }
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode($e->getMessage()));
    exit;
}

