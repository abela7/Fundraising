<?php
// admin/donor-management/ajax-reschedule-plan.php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

try {
    require_login();
    require_admin();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $plan_id = isset($input['plan_id']) ? (int)$input['plan_id'] : 0;
    $new_start_date = isset($input['start_date']) ? $input['start_date'] : '';
    
    if ($plan_id <= 0 || empty($new_start_date)) {
        throw new Exception("Invalid input");
    }
    
    $db = db();
    
    // 1. Get Plan Frequency
    $stmt = $db->prepare("SELECT plan_frequency_unit, plan_frequency_number FROM donor_payment_plans WHERE id = ?");
    $stmt->bind_param('i', $plan_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $plan = $res->fetch_assoc();
    
    if (!$plan) {
        throw new Exception("Plan not found");
    }
    
    $freq_unit = $plan['plan_frequency_unit'] ?? 'month';
    $freq_num = (int)($plan['plan_frequency_number'] ?? 1);
    
    // 2. Get Pending Installments
    $stmt = $db->prepare("SELECT id FROM payment_plan_schedule WHERE plan_id = ? AND status = 'pending' ORDER BY installment_number ASC");
    $stmt->bind_param('i', $plan_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $pending_rows = [];
    while ($row = $res->fetch_assoc()) {
        $pending_rows[] = $row['id'];
    }
    
    if (empty($pending_rows)) {
        throw new Exception("No pending installments to reschedule");
    }
    
    // 3. Update Loop
    $current_date = new DateTime($new_start_date);
    $update_stmt = $db->prepare("UPDATE payment_plan_schedule SET due_date = ? WHERE id = ?");
    
    foreach ($pending_rows as $row_id) {
        $date_str = $current_date->format('Y-m-d');
        $update_stmt->bind_param('si', $date_str, $row_id);
        $update_stmt->execute();
        
        // Advance
        if ($freq_unit === 'day') {
            $current_date->modify("+{$freq_num} days");
        } elseif ($freq_unit === 'week') {
            $current_date->modify("+{$freq_num} weeks");
        } elseif ($freq_unit === 'month') {
            $current_date->modify("+{$freq_num} months");
        } elseif ($freq_unit === 'year') {
            $current_date->modify("+{$freq_num} years");
        } else {
            $current_date->modify("+1 month");
        }
    }
    
    // 4. Sync 'Next Due' in Main Plan
    // The user provided 'start_date' is the new due date for the first pending installment
    $sync_stmt = $db->prepare("UPDATE donor_payment_plans SET next_payment_due = ?, updated_at = NOW() WHERE id = ?");
    $sync_stmt->bind_param('si', $new_start_date, $plan_id);
    $sync_stmt->execute();
    
    echo json_encode(['success' => true, 'count' => count($pending_rows)]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

