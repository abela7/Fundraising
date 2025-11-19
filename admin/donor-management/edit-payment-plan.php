<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

function log_edit_error(string $section, array $data, string $error, array $context = []): void {
    $log_dir = __DIR__ . '/../../logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    $log_file = $log_dir . '/edit_errors.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = sprintf(
        "[%s] PAYMENT PLAN EDIT ERROR - Section: %s\nData: %s\nError: %s\nContext: %s\n\n",
        $timestamp, $section, json_encode($data, JSON_PRETTY_PRINT), $error, json_encode($context, JSON_PRETTY_PRINT)
    );
    @file_put_contents($log_file, $log_entry, FILE_APPEND);
}

$db = db();
$plan_id = isset($_POST['plan_id']) ? (int)$_POST['plan_id'] : 0;
$donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$plan_id || !$donor_id) {
    header('Location: view-donor.php?id=' . ($donor_id ?: ''));
    exit;
}

try {
    // Verify plan exists and belongs to donor
    $check_stmt = $db->prepare("SELECT id, donor_id FROM donor_payment_plans WHERE id = ? AND donor_id = ?");
    $check_stmt->bind_param('ii', $plan_id, $donor_id);
    $check_stmt->execute();
    $plan = $check_stmt->get_result()->fetch_assoc();
    
    if (!$plan) {
        throw new Exception("Payment plan not found or doesn't belong to this donor");
    }
    
    $db->begin_transaction();
    
    $updates = [];
    $types = '';
    $values = [];
    
    if (isset($_POST['total_amount'])) {
        $total_amount = (float)$_POST['total_amount'];
        if ($total_amount <= 0) {
            throw new Exception("Total amount must be greater than 0");
        }
        $updates[] = "`total_amount` = ?";
        $types .= 'd';
        $values[] = $total_amount;
    }
    
    if (isset($_POST['monthly_amount'])) {
        $monthly_amount = (float)$_POST['monthly_amount'];
        if ($monthly_amount <= 0) {
            throw new Exception("Monthly amount must be greater than 0");
        }
        $updates[] = "`monthly_amount` = ?";
        $types .= 'd';
        $values[] = $monthly_amount;
    }
    
    if (isset($_POST['total_payments'])) {
        $total_payments = (int)$_POST['total_payments'];
        if ($total_payments <= 0) {
            throw new Exception("Total payments must be greater than 0");
        }
        $updates[] = "`total_payments` = ?";
        $types .= 'i';
        $values[] = $total_payments;
    }
    
    if (isset($_POST['start_date'])) {
        $start_date = trim($_POST['start_date']);
        if (!strtotime($start_date)) {
            throw new Exception("Invalid date format");
        }
        $updates[] = "`start_date` = ?";
        $types .= 's';
        $values[] = $start_date;
    }
    
    if (isset($_POST['status'])) {
        $status = trim($_POST['status']);
        $valid_statuses = ['active', 'completed', 'cancelled', 'paused'];
        if (!in_array($status, $valid_statuses)) {
            throw new Exception("Invalid status");
        }
        $updates[] = "`status` = ?";
        $types .= 's';
        $values[] = $status;
    }
    
    if (empty($updates)) {
        throw new Exception("No fields to update");
    }
    
    $sql = "UPDATE donor_payment_plans SET " . implode(', ', $updates) . " WHERE id = ?";
    $types .= 'i';
    $values[] = $plan_id;
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }
    $stmt->bind_param($types, ...$values);
    
    if (!$stmt->execute()) {
        throw new Exception("Update failed: " . $stmt->error);
    }
    
    // Update donor's cached plan data if this is the active plan
    $active_check = $db->prepare("SELECT active_payment_plan_id FROM donors WHERE id = ?");
    $active_check->bind_param('i', $donor_id);
    $active_check->execute();
    $donor = $active_check->get_result()->fetch_assoc();
    
    if ($donor && $donor['active_payment_plan_id'] == $plan_id) {
        $update_donor_stmt = $db->prepare("
            UPDATE donors 
            SET plan_monthly_amount = (SELECT monthly_amount FROM donor_payment_plans WHERE id = ?),
                plan_duration_months = (SELECT total_payments FROM donor_payment_plans WHERE id = ?),
                plan_start_date = (SELECT start_date FROM donor_payment_plans WHERE id = ?)
            WHERE id = ?
        ");
        $update_donor_stmt->bind_param('iiii', $plan_id, $plan_id, $plan_id, $donor_id);
        if (!$update_donor_stmt->execute()) {
            throw new Exception("Failed to update donor cached plan data: " . $update_donor_stmt->error);
        }
    }
    
    $db->commit();
    
    header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode('Payment plan updated successfully'));
    exit;
    
} catch (mysqli_sql_exception $e) {
    $db->rollback();
    log_edit_error('DATABASE', $_POST, $e->getMessage(), ['error_code' => $e->getCode()]);
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Database error: ' . $e->getMessage()));
    exit;
} catch (Exception $e) {
    $db->rollback();
    log_edit_error('GENERAL', $_POST, $e->getMessage());
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode($e->getMessage()));
    exit;
}

