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
        "[%s] PAYMENT EDIT ERROR - Section: %s\nData: %s\nError: %s\nContext: %s\n\n",
        $timestamp, $section, json_encode($data, JSON_PRETTY_PRINT), $error, json_encode($context, JSON_PRETTY_PRINT)
    );
    @file_put_contents($log_file, $log_entry, FILE_APPEND);
}

$db = db();
$payment_id = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
$donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$payment_id || !$donor_id) {
    header('Location: view-donor.php?id=' . ($donor_id ?: ''));
    exit;
}

try {
    // Check payment table columns
    $col_query = $db->query("SHOW COLUMNS FROM payments");
    $columns = [];
    while ($col = $col_query->fetch_assoc()) {
        $columns[] = $col['Field'];
    }
    
    $date_col = in_array('payment_date', $columns) ? 'payment_date' : 
               (in_array('received_at', $columns) ? 'received_at' : 'created_at');
    $method_col = in_array('payment_method', $columns) ? 'payment_method' : 'method';
    $ref_col = in_array('transaction_ref', $columns) ? 'transaction_ref' : 'reference';
    
    // Verify payment exists
    $check_stmt = $db->prepare("SELECT id, amount, donor_phone FROM payments WHERE id = ?");
    $check_stmt->bind_param('i', $payment_id);
    $check_stmt->execute();
    $payment = $check_stmt->get_result()->fetch_assoc();
    
    if (!$payment) {
        throw new Exception("Payment not found");
    }
    
    $old_amount = (float)$payment['amount'];
    
    $db->begin_transaction();
    
    $updates = [];
    $types = '';
    $values = [];
    
    if (isset($_POST['amount'])) {
        $new_amount = (float)$_POST['amount'];
        if ($new_amount <= 0) {
            throw new Exception("Payment amount must be greater than 0");
        }
        $updates[] = "`amount` = ?";
        $types .= 'd';
        $values[] = $new_amount;
    }
    
    if (isset($_POST['method']) && in_array($method_col, $columns)) {
        $method = trim($_POST['method']);
        $updates[] = "`{$method_col}` = ?";
        $types .= 's';
        $values[] = $method;
    }
    
    if (isset($_POST['reference']) && in_array($ref_col, $columns)) {
        $reference = trim($_POST['reference']);
        $updates[] = "`{$ref_col}` = ?";
        $types .= 's';
        $values[] = $reference;
    }
    
    if (isset($_POST['status'])) {
        $status = trim($_POST['status']);
        $valid_statuses = ['pending', 'approved', 'rejected'];
        if (!in_array($status, $valid_statuses)) {
            throw new Exception("Invalid status");
        }
        $updates[] = "`status` = ?";
        $types .= 's';
        $values[] = $status;
    }
    
    if (isset($_POST['date']) && in_array($date_col, $columns)) {
        $date = trim($_POST['date']);
        if (!strtotime($date)) {
            throw new Exception("Invalid date format");
        }
        $updates[] = "`{$date_col}` = ?";
        $types .= 's';
        $values[] = $date;
    }
    
    if (empty($updates)) {
        throw new Exception("No fields to update");
    }
    
    $sql = "UPDATE payments SET " . implode(', ', $updates) . " WHERE id = ?";
    $types .= 'i';
    $values[] = $payment_id;
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }
    $stmt->bind_param($types, ...$values);
    
    if (!$stmt->execute()) {
        throw new Exception("Update failed: " . $stmt->error);
    }
    
    // If amount changed and status is approved, recalculate donor totals
    if (isset($new_amount) && $old_amount != $new_amount) {
        $status_check = $db->prepare("SELECT status FROM payments WHERE id = ?");
        $status_check->bind_param('i', $payment_id);
        $status_check->execute();
        $current_status = $status_check->get_result()->fetch_assoc()['status'] ?? 'pending';
        
        if ($current_status === 'approved') {
            // Recalculate total_paid for donor
            $donor_phone = $payment['donor_phone'];
            $recalc_stmt = $db->prepare("
                UPDATE donors 
                SET total_paid = (
                    SELECT COALESCE(SUM(amount), 0) 
                    FROM payments 
                    WHERE donor_phone = ? AND status = 'approved'
                ),
                balance = total_pledged - total_paid
                WHERE phone = ?
            ");
            $recalc_stmt->bind_param('ss', $donor_phone, $donor_phone);
            if (!$recalc_stmt->execute()) {
                throw new Exception("Failed to recalculate donor totals: " . $recalc_stmt->error);
            }
        }
    }
    
    $db->commit();
    
    header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode('Payment updated successfully'));
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

