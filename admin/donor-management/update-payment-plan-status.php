<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: donors.php');
    exit;
}

$db = db();

try {
    $plan_id = isset($_POST['plan_id']) ? (int)$_POST['plan_id'] : 0;
    $status = trim($_POST['status'] ?? '');

    // Validation
    if ($plan_id <= 0) {
        throw new Exception('Invalid payment plan ID');
    }
    
    if (!in_array($status, ['active', 'paused', 'completed', 'defaulted', 'cancelled'])) {
        throw new Exception('Invalid status');
    }

    $db->begin_transaction();

    // Get current status for logging
    $current_query = $db->prepare("SELECT status, donor_id FROM donor_payment_plans WHERE id = ?");
    $current_query->bind_param('i', $plan_id);
    $current_query->execute();
    $result = $current_query->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Payment plan not found');
    }
    
    $plan = $result->fetch_object();
    $old_status = $plan->status;
    $donor_id = $plan->donor_id;
    $current_query->close();

    // Update payment plan status
    $update_query = $db->prepare("
        UPDATE donor_payment_plans 
        SET status = ?, updated_at = NOW()
        WHERE id = ?
    ");

    $update_query->bind_param('si', $status, $plan_id);

    if (!$update_query->execute()) {
        throw new Exception('Failed to update payment plan status: ' . $update_query->error);
    }

    $update_query->close();

    // If plan is completed or cancelled, update donor's status
    if (in_array($status, ['completed', 'cancelled'])) {
        $donor_query = $db->prepare("
            UPDATE donors 
            SET active_payment_plan_id = NULL,
                payment_status = ?
            WHERE id = ?
        ");
        
        $new_donor_status = $status === 'completed' ? 'completed' : 'not_started';
        $donor_query->bind_param('si', $new_donor_status, $donor_id);
        $donor_query->execute();
        $donor_query->close();
    } elseif ($status === 'active') {
        // Reactivating the plan
        $donor_query = $db->prepare("
            UPDATE donors 
            SET active_payment_plan_id = ?,
                payment_status = 'paying'
            WHERE id = ?
        ");
        
        $donor_query->bind_param('ii', $plan_id, $donor_id);
        $donor_query->execute();
        $donor_query->close();
    }

    // Log the status change
    $user_id = (int)$_SESSION['user']['id'];
    $log_query = $db->prepare("
        INSERT INTO donor_audit_log (donor_id, action, field_name, old_value, new_value, user_id, created_at)
        VALUES (?, 'plan_status_changed', 'plan_status', ?, ?, ?, NOW())
    ");
    
    $log_query->bind_param('issi', $donor_id, $old_status, $status, $user_id);
    $log_query->execute();
    $log_query->close();

    $db->commit();

    $_SESSION['success_message'] = 'Payment plan status updated to ' . strtoupper($status) . ' successfully.';
    header('Location: view-payment-plan.php?id=' . $plan_id);
    exit;

} catch (Exception $e) {
    if ($db) {
        $db->rollback();
    }
    
    error_log("Update payment plan status error: " . $e->getMessage());
    $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    
    $redirect_id = isset($plan_id) && $plan_id > 0 ? $plan_id : '';
    header('Location: view-payment-plan.php' . ($redirect_id ? '?id=' . $redirect_id : ''));
    exit;
}

