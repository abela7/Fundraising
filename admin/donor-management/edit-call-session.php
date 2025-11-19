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
        "[%s] CALL SESSION EDIT ERROR - Section: %s\nData: %s\nError: %s\nContext: %s\n\n",
        $timestamp, $section, json_encode($data, JSON_PRETTY_PRINT), $error, json_encode($context, JSON_PRETTY_PRINT)
    );
    @file_put_contents($log_file, $log_entry, FILE_APPEND);
}

$db = db();
$session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
$donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$session_id || !$donor_id) {
    header('Location: view-donor.php?id=' . ($donor_id ?: ''));
    exit;
}

try {
    // Verify session exists and belongs to donor
    $check_stmt = $db->prepare("SELECT id, donor_id FROM call_center_sessions WHERE id = ? AND donor_id = ?");
    $check_stmt->bind_param('ii', $session_id, $donor_id);
    $check_stmt->execute();
    $session = $check_stmt->get_result()->fetch_assoc();
    
    if (!$session) {
        throw new Exception("Call session not found or doesn't belong to this donor");
    }
    
    $db->begin_transaction();
    
    $updates = [];
    $types = '';
    $values = [];
    
    if (isset($_POST['agent_id'])) {
        $agent_id = (int)$_POST['agent_id'];
        if ($agent_id > 0) {
            // Verify agent exists
            $agent_check = $db->prepare("SELECT id FROM users WHERE id = ?");
            $agent_check->bind_param('i', $agent_id);
            $agent_check->execute();
            if ($agent_check->get_result()->num_rows === 0) {
                throw new Exception("Invalid agent ID");
            }
            $updates[] = "`agent_id` = ?";
            $types .= 'i';
            $values[] = $agent_id;
        }
    }
    
    if (isset($_POST['duration_minutes'])) {
        $duration_minutes = (int)$_POST['duration_minutes'];
        if ($duration_minutes < 0) {
            throw new Exception("Duration cannot be negative");
        }
        $duration_seconds = $duration_minutes * 60;
        $updates[] = "`duration_seconds` = ?";
        $types .= 'i';
        $values[] = $duration_seconds;
    }
    
    if (isset($_POST['outcome'])) {
        $outcome = trim($_POST['outcome']);
        $valid_outcomes = [
            'no_answer', 'busy', 'not_working', 'not_interested', 
            'callback_requested', 'payment_plan_created', 'not_ready_to_pay'
        ];
        if (!in_array($outcome, $valid_outcomes)) {
            throw new Exception("Invalid outcome");
        }
        $updates[] = "`outcome` = ?";
        $types .= 's';
        $values[] = $outcome;
    }
    
    if (isset($_POST['notes'])) {
        $notes = trim($_POST['notes']);
        $updates[] = "`notes` = ?";
        $types .= 's';
        $values[] = $notes;
    }
    
    if (empty($updates)) {
        throw new Exception("No fields to update");
    }
    
    $sql = "UPDATE call_center_sessions SET " . implode(', ', $updates) . " WHERE id = ?";
    $types .= 'i';
    $values[] = $session_id;
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }
    $stmt->bind_param($types, ...$values);
    
    if (!$stmt->execute()) {
        throw new Exception("Update failed: " . $stmt->error);
    }
    
    $db->commit();
    
    header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode('Call session updated successfully'));
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

