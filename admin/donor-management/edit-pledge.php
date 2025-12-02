<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
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
        "[%s] PLEDGE EDIT ERROR - Section: %s\nData: %s\nError: %s\nContext: %s\n\n",
        $timestamp, $section, json_encode($data, JSON_PRETTY_PRINT), $error, json_encode($context, JSON_PRETTY_PRINT)
    );
    @file_put_contents($log_file, $log_entry, FILE_APPEND);
}

$db = db();
$pledge_id = isset($_POST['pledge_id']) ? (int)$_POST['pledge_id'] : 0;
$donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$pledge_id || !$donor_id) {
    header('Location: view-donor.php?id=' . ($donor_id ?: ''));
    exit;
}

try {
    // Verify pledge exists and belongs to donor
    $check_stmt = $db->prepare("SELECT id, donor_id, amount FROM pledges WHERE id = ? AND donor_id = ?");
    $check_stmt->bind_param('ii', $pledge_id, $donor_id);
    $check_stmt->execute();
    $pledge = $check_stmt->get_result()->fetch_assoc();
    
    if (!$pledge) {
        throw new Exception("Pledge not found or doesn't belong to this donor");
    }
    
    $old_amount = (float)$pledge['amount'];
    
    $db->begin_transaction();
    
    // Get fields to update
    $updates = [];
    $types = '';
    $values = [];
    
    if (isset($_POST['amount'])) {
        $new_amount = (float)$_POST['amount'];
        if ($new_amount <= 0) {
            throw new Exception("Pledge amount must be greater than 0");
        }
        $updates[] = "`amount` = ?";
        $types .= 'd';
        $values[] = $new_amount;
    }
    
    if (isset($_POST['status'])) {
        $status = trim($_POST['status']);
        $valid_statuses = ['pending', 'approved', 'rejected', 'cancelled'];
        if (!in_array($status, $valid_statuses)) {
            throw new Exception("Invalid status");
        }
        $updates[] = "`status` = ?";
        $types .= 's';
        $values[] = $status;
    }
    
    if (isset($_POST['created_at'])) {
        $created_at = trim($_POST['created_at']);
        if (!strtotime($created_at)) {
            throw new Exception("Invalid date format");
        }
        $updates[] = "`created_at` = ?";
        $types .= 's';
        $values[] = $created_at;
    }
    
    if (empty($updates)) {
        throw new Exception("No fields to update");
    }
    
    // Update pledge
    $sql = "UPDATE pledges SET " . implode(', ', $updates) . " WHERE id = ?";
    $types .= 'i';
    $values[] = $pledge_id;
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }
    $stmt->bind_param($types, ...$values);
    
    // Get before data for audit
    $beforeData = [
        'amount' => $old_amount,
        'status' => $pledge['status'] ?? 'pending'
    ];
    
    if (!$stmt->execute()) {
        throw new Exception("Update failed: " . $stmt->error);
    }
    
    // Get after data for audit
    $after_stmt = $db->prepare("SELECT amount, status FROM pledges WHERE id = ?");
    $after_stmt->bind_param('i', $pledge_id);
    $after_stmt->execute();
    $after_data = $after_stmt->get_result()->fetch_assoc();
    $after_stmt->close();
    
    $afterData = [
        'amount' => $after_data['amount'] ?? $old_amount,
        'status' => $after_data['status'] ?? 'pending'
    ];
    
    // Audit log
    log_audit(
        $db,
        'update',
        'pledge',
        $pledge_id,
        $beforeData,
        $afterData,
        'admin_portal',
        (int)($_SESSION['user']['id'] ?? 0)
    );
    
    // If amount changed and status is approved, recalculate donor totals
    if (isset($new_amount) && $old_amount != $new_amount) {
        $status_check = $db->prepare("SELECT status FROM pledges WHERE id = ?");
        $status_check->bind_param('i', $pledge_id);
        $status_check->execute();
        $current_status = $status_check->get_result()->fetch_assoc()['status'] ?? 'pending';
        
        if ($current_status === 'approved') {
            // Recalculate total_pledged for donor
            $recalc_stmt = $db->prepare("
                UPDATE donors 
                SET total_pledged = (
                    SELECT COALESCE(SUM(amount), 0) 
                    FROM pledges 
                    WHERE donor_id = ? AND status = 'approved'
                ),
                balance = total_pledged - total_paid
                WHERE id = ?
            ");
            $recalc_stmt->bind_param('ii', $donor_id, $donor_id);
            if (!$recalc_stmt->execute()) {
                throw new Exception("Failed to recalculate donor totals: " . $recalc_stmt->error);
            }
        }
    }
    
    $db->commit();
    
    header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode('Pledge updated successfully'));
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

