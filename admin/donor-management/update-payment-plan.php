<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: donors.php');
    exit;
}

verify_csrf();

$db = db();

try {
    $plan_id = isset($_POST['plan_id']) ? (int)$_POST['plan_id'] : 0;
    $monthly_amount = isset($_POST['monthly_amount']) ? (float)$_POST['monthly_amount'] : 0;
    $payment_day = isset($_POST['payment_day']) ? (int)$_POST['payment_day'] : 1;
    $payment_method = trim($_POST['payment_method'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $next_payment_due = !empty($_POST['next_payment_due']) ? $_POST['next_payment_due'] : null;
    
    // New fields
    $total_payments = isset($_POST['total_payments']) ? (int)$_POST['total_payments'] : 1;
    $plan_frequency_unit = trim($_POST['plan_frequency_unit'] ?? 'month');
    $plan_frequency_number = isset($_POST['plan_frequency_number']) ? (int)$_POST['plan_frequency_number'] : 1;

    // Validation
    if ($plan_id <= 0) {
        throw new Exception('Invalid payment plan ID');
    }
    
    if ($monthly_amount <= 0) {
        throw new Exception('Installment amount must be greater than 0');
    }
    
    if ($payment_day < 1 || $payment_day > 28) {
        throw new Exception('Payment day must be between 1 and 28');
    }
    
    if (!in_array($payment_method, ['cash', 'bank_transfer', 'card'])) {
        throw new Exception('Invalid payment method');
    }
    
    if (!in_array($status, ['active', 'paused', 'completed', 'defaulted', 'cancelled'])) {
        throw new Exception('Invalid status');
    }
    
    if ($total_payments < 1) {
        throw new Exception('Total payments must be at least 1');
    }
    
    if (!in_array($plan_frequency_unit, ['week', 'month', 'year'])) {
        throw new Exception('Invalid frequency unit');
    }
    
    if ($plan_frequency_number < 1 || $plan_frequency_number > 12) {
        throw new Exception('Frequency number must be between 1 and 12');
    }

    // Recalculate total_months based on frequency
    $total_months = $total_payments;
    if ($plan_frequency_unit === 'week') {
        $total_months = (int)ceil(($total_payments * $plan_frequency_number) / 4.33);
    } elseif ($plan_frequency_unit === 'year') {
        $total_months = $total_payments * 12 * $plan_frequency_number;
    } elseif ($plan_frequency_number > 1) {
        $total_months = $total_payments * $plan_frequency_number;
    }

    $db->begin_transaction();

    // Update payment plan
    $update_query = $db->prepare("
        UPDATE donor_payment_plans 
        SET 
            monthly_amount = ?,
            total_payments = ?,
            total_months = ?,
            plan_frequency_unit = ?,
            plan_frequency_number = ?,
            payment_day = ?,
            payment_method = ?,
            status = ?,
            next_payment_due = ?,
            updated_at = NOW()
        WHERE id = ?
    ");

    $update_query->bind_param('diiisiisssi', 
        $monthly_amount,
        $total_payments,
        $total_months,
        $plan_frequency_unit,
        $plan_frequency_number,
        $payment_day,
        $payment_method,
        $status,
        $next_payment_due,
        $plan_id
    );

    if (!$update_query->execute()) {
        throw new Exception('Failed to update payment plan: ' . $update_query->error);
    }

    $update_query->close();

    // If plan is completed or cancelled, update donor's active_payment_plan_id
    if (in_array($status, ['completed', 'cancelled'])) {
        $donor_query = $db->prepare("
            UPDATE donors 
            SET active_payment_plan_id = NULL,
                payment_status = ?
            WHERE active_payment_plan_id = ?
        ");
        
        $new_donor_status = $status === 'completed' ? 'completed' : 'not_started';
        $donor_query->bind_param('si', $new_donor_status, $plan_id);
        $donor_query->execute();
        $donor_query->close();
    }

    // Log the update
    $user_id = (int)$_SESSION['user']['id'];
    $log_query = $db->prepare("
        INSERT INTO donor_audit_log (donor_id, action, field_name, old_value, new_value, user_id, created_at)
        SELECT donor_id, 'plan_updated', 'payment_plan', ?, ?, ?, NOW()
        FROM donor_payment_plans
        WHERE id = ?
    ");
    
    $old_value = "Plan #$plan_id";
    $new_value = "Updated: status=$status, installment=$monthly_amount, payments=$total_payments, frequency={$plan_frequency_number}x{$plan_frequency_unit}, payment_day=$payment_day, method=$payment_method";
    $log_query->bind_param('ssii', $old_value, $new_value, $user_id, $plan_id);
    $log_query->execute();
    $log_query->close();

    $db->commit();

    $_SESSION['success_message'] = 'Payment plan updated successfully.';
    header('Location: view-payment-plan.php?id=' . $plan_id);
    exit;

} catch (Exception $e) {
    if ($db) {
        $db->rollback();
    }
    
    error_log("Update payment plan error: " . $e->getMessage());
    $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    
    $redirect_id = isset($plan_id) && $plan_id > 0 ? $plan_id : '';
    header('Location: view-payment-plan.php' . ($redirect_id ? '?id=' . $redirect_id : ''));
    exit;
}

