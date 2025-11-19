<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone
date_default_timezone_set('Europe/London');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    
    // Get parameters
    $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    $donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;
    $queue_id = isset($_POST['queue_id']) ? (int)$_POST['queue_id'] : 0;
    $pledge_id = isset($_POST['pledge_id']) ? (int)$_POST['pledge_id'] : 0;
    $duration_seconds = isset($_POST['call_duration_seconds']) ? (int)$_POST['call_duration_seconds'] : 0;
    
    // Plan Details
    $template_id = $_POST['plan_template_id'] ?? '';
    $start_date = $_POST['start_date'] ?? date('Y-m-d');
    
    // If using a template
    $plan_frequency_unit = 'month';
    $plan_frequency_number = 1;
    $total_payments = 0;
    $payment_day = 1;
    
    if ($template_id === 'custom') {
        $plan_frequency_unit = $_POST['custom_frequency'] ?? 'month';
        // Simplify unit to DB enum if needed, assuming DB supports: week, month, year
        // Adjust biweekly/quarterly logic
        if ($plan_frequency_unit === 'biweekly') {
            $plan_frequency_unit = 'week';
            $plan_frequency_number = 2;
        } elseif ($plan_frequency_unit === 'quarterly') {
            $plan_frequency_unit = 'month';
            $plan_frequency_number = 3;
        } elseif ($plan_frequency_unit === 'annually') {
            $plan_frequency_unit = 'year';
            $plan_frequency_number = 1;
        } elseif ($plan_frequency_unit === 'weekly') {
            $plan_frequency_unit = 'week';
            $plan_frequency_number = 1;
        }
        
        $total_payments = (int)($_POST['custom_payments'] ?? 12);
        $payment_day = (int)($_POST['custom_payment_day'] ?? 1);
    } else {
        // Standard Templates
        // Fetch duration from template or logic
        $duration = (int)$_POST['plan_duration'];
        $total_payments = $duration; // Assuming monthly for standard templates
        $payment_day = (int)date('d', strtotime($start_date));
        if ($payment_day > 28) $payment_day = 28;
    }
    
    // Calculate Amounts
    // Fetch donor balance again to be safe
    $bal_query = $db->prepare("SELECT balance FROM donors WHERE id = ?");
    $bal_query->bind_param('i', $donor_id);
    $bal_query->execute();
    $res = $bal_query->get_result();
    $row = $res->fetch_assoc();
    $total_amount = (float)($row['balance'] ?? 0);
    $bal_query->close();
    
    $monthly_amount = $total_amount / ($total_payments > 0 ? $total_payments : 1); // Installment amount
    
    // Calculate Total Months (Approx for DB)
    $total_months = $total_payments;
    if ($plan_frequency_unit === 'week') {
        $total_months = ceil(($total_payments * $plan_frequency_number) / 4.33);
    } elseif ($plan_frequency_unit === 'year') {
        $total_months = $total_payments * 12;
    } elseif ($plan_frequency_number > 1) { // e.g. Quarterly
        $total_months = $total_payments * $plan_frequency_number;
    }
    
    $db->begin_transaction();
    
    // 0. Update Donor Information (from Step 2)
    $baptism_name = trim($_POST['baptism_name'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $preferred_language = $_POST['preferred_language'] ?? 'en';
    $church_id = isset($_POST['church_id']) && $_POST['church_id'] !== '' ? (int)$_POST['church_id'] : null;
    
    // Payment Method (from Step 6)
    $payment_method = trim($_POST['payment_method'] ?? '');
    $cash_church_id = isset($_POST['cash_church_id']) && $_POST['cash_church_id'] !== '' ? (int)$_POST['cash_church_id'] : null;
    $cash_representative_id = isset($_POST['cash_representative_id']) && $_POST['cash_representative_id'] !== '' ? (int)$_POST['cash_representative_id'] : null;
    
    // Build update query dynamically based on what's provided
    $donor_updates = [];
    $donor_params = [];
    $donor_types = '';
    
    if ($baptism_name !== '') {
        $donor_updates[] = "baptism_name = ?";
        $donor_params[] = $baptism_name;
        $donor_types .= 's';
    }
    
    if ($city !== '') {
        $donor_updates[] = "city = ?";
        $donor_params[] = $city;
        $donor_types .= 's';
    }
    
    if ($email !== '') {
        // Validate email format
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $donor_updates[] = "email = ?";
            $donor_params[] = $email;
            $donor_types .= 's';
        }
    }
    
    if (in_array($preferred_language, ['en', 'am', 'ti'])) {
        $donor_updates[] = "preferred_language = ?";
        $donor_params[] = $preferred_language;
        $donor_types .= 's';
    }
    
    if ($church_id !== null) {
        $donor_updates[] = "church_id = ?";
        $donor_params[] = $church_id;
        $donor_types .= 'i';
    }
    
    // Update payment method
    if (!empty($payment_method) && in_array($payment_method, ['bank_transfer', 'card', 'cash'])) {
        $donor_updates[] = "preferred_payment_method = ?";
        $donor_params[] = $payment_method;
        $donor_types .= 's';
    }
    
    // If cash payment, update church and representative
    if ($payment_method === 'cash') {
        if ($cash_church_id !== null) {
            $donor_updates[] = "church_id = ?";
            $donor_params[] = $cash_church_id;
            $donor_types .= 'i';
        }
        
        // Check if representative_id column exists
        $check_rep_column = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
        $has_rep_column = $check_rep_column && $check_rep_column->num_rows > 0;
        
        if ($has_rep_column && $cash_representative_id !== null) {
            $donor_updates[] = "representative_id = ?";
            $donor_params[] = $cash_representative_id;
            $donor_types .= 'i';
        }
    }
    
    // Update donor if there are any changes
    if (!empty($donor_updates)) {
        $donor_params[] = $donor_id;
        $donor_types .= 'i';
        
        $update_donor_info = $db->prepare("
            UPDATE donors 
            SET " . implode(', ', $donor_updates) . "
            WHERE id = ?
        ");
        
        if ($update_donor_info) {
            $update_donor_info->bind_param($donor_types, ...$donor_params);
            $update_donor_info->execute();
            $update_donor_info->close();
        }
    }
    
    // 1. Create Payment Plan
    // Using simplified INSERT based on table knowledge. 
    // Note: 'monthly_amount' column is often used for installment amount regardless of frequency.
    $plan_payment_method = !empty($payment_method) ? $payment_method : 'bank_transfer';
    
    $insert_plan = $db->prepare("
        INSERT INTO donor_payment_plans (
            donor_id, pledge_id, template_id, total_amount, monthly_amount, 
            total_months, total_payments, start_date, payment_day,
            plan_frequency_unit, plan_frequency_number, plan_payment_day_type,
            payment_method, next_payment_due, status,
            created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'day_of_month', ?, ?, 'active', NOW(), NOW())
    ");
    
    $template_id_db = ($template_id === 'custom' || strpos($template_id, 'def_') === 0) ? null : (int)$template_id;
    
    if (!$insert_plan) {
        throw new Exception("Prepare failed: " . $db->error);
    }
    
    $insert_plan->bind_param('iiiddiisssss',
        $donor_id,
        $pledge_id,
        $template_id_db,
        $total_amount,
        $monthly_amount,
        $total_months,
        $total_payments,
        $start_date,
        $payment_day,
        $plan_frequency_unit,
        $plan_frequency_number,
        $plan_payment_method, // payment_method
        $start_date // next_payment_due is usually start_date
    );
    
    if (!$insert_plan->execute()) {
        throw new Exception("Failed to create plan: " . $insert_plan->error);
    }
    $plan_id = $db->insert_id;
    $insert_plan->close();
    
    // 2. Update Donor (Active Plan)
    $update_donor = $db->prepare("UPDATE donors SET active_payment_plan_id = ?, payment_status = 'paying' WHERE id = ?");
    $update_donor->bind_param('ii', $plan_id, $donor_id);
    $update_donor->execute();
    $update_donor->close();
    
    // 3. Update Session
    if ($session_id > 0) {
        $update_session = $db->prepare("
            UPDATE call_center_sessions 
            SET outcome = 'payment_plan_created',
                conversation_stage = 'completed',
                payment_plan_id = ?,
                duration_seconds = COALESCE(duration_seconds, 0) + ?,
                call_ended_at = NOW()
            WHERE id = ?
        ");
        $update_session->bind_param('iii', $plan_id, $duration_seconds, $session_id);
        $update_session->execute();
        $update_session->close();
    }
    
    // 4. Update Queue
    if ($queue_id > 0) {
        $update_queue = $db->prepare("
            UPDATE call_center_queues 
            SET status = 'completed',
                completed_at = NOW(),
                last_attempt_outcome = 'payment_plan_created'
            WHERE id = ?
        ");
        $update_queue->bind_param('i', $queue_id);
        $update_queue->execute();
        $update_queue->close();
    }
    
    $db->commit();
    
    // Redirect to success page
    header("Location: plan-success.php?plan_id={$plan_id}&donor_id={$donor_id}");
    exit;
    
} catch (Exception $e) {
    if (isset($db)) $db->rollback();
    error_log("Process Conversation Error: " . $e->getMessage());
    echo "Error: " . $e->getMessage();
    // In prod, redirect to error page
}

