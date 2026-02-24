<?php
declare(strict_types=1);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_login();

// Set timezone
date_default_timezone_set('Europe/London');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Log all POST data for debugging
error_log("=== PROCESS CONVERSATION DEBUG ===");
error_log("POST Data: " . print_r($_POST, true));

try {
    $db = db();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    $user_id = (int)$_SESSION['user']['id'];
    error_log("User ID: " . $user_id);
    
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
        $plan_frequency_unit = $_POST['custom_frequency_unit'] ?? 'month';
        $plan_frequency_number = (int)($_POST['custom_frequency_number'] ?? 1);
        
        // Handle legacy inputs if any (though UI is updated)
        if (isset($_POST['custom_frequency'])) {
            $legacy_freq = $_POST['custom_frequency'];
            if ($legacy_freq === 'weekly') { $plan_frequency_unit = 'week'; $plan_frequency_number = 1; }
            elseif ($legacy_freq === 'biweekly') { $plan_frequency_unit = 'week'; $plan_frequency_number = 2; }
            elseif ($legacy_freq === 'quarterly') { $plan_frequency_unit = 'month'; $plan_frequency_number = 3; }
            elseif ($legacy_freq === 'annually') { $plan_frequency_unit = 'year'; $plan_frequency_number = 1; }
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
    
    // Calculate monthly_amount (installment amount) - ensure it's never 0
    $monthly_amount = $total_amount / ($total_payments > 0 ? $total_payments : 1);
    $monthly_amount = round($monthly_amount, 2); // Round to 2 decimal places
    if ($monthly_amount <= 0 && $total_amount > 0) {
        // Fallback: if calculation fails, use total_amount as monthly
        $monthly_amount = $total_amount;
        error_log("WARNING: Monthly amount calculation resulted in 0, using total_amount as fallback");
    }
    
    // Calculate Total Months (Approx for DB)
    $days_per_unit = match($plan_frequency_unit) {
        'day' => 1,
        'week' => 7,
        'month' => 30.44,
        'year' => 365.25,
        default => 30.44
    };
    $total_days = $total_payments * $plan_frequency_number * $days_per_unit;
    $total_months = ceil($total_days / 30.44);
    
    $db->begin_transaction();
    
    // 0. Update Donor Information (from Step 2)
    $baptism_name = trim($_POST['baptism_name'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $preferred_language = $_POST['preferred_language'] ?? 'en';
    $church_id = isset($_POST['church_id']) && $_POST['church_id'] !== '' ? (int)$_POST['church_id'] : null;
    
    // Payment Method (from Step 6)
    $payment_method = trim($_POST['payment_method'] ?? '');
    $cash_representative_id = isset($_POST['cash_representative_id']) && $_POST['cash_representative_id'] !== '' ? (int)$_POST['cash_representative_id'] : null;
    $cash_church_id = isset($_POST['cash_church_id']) && $_POST['cash_church_id'] !== '' ? (int)$_POST['cash_church_id'] : null;
    $donor_already_paid = isset($_POST['donor_already_paid']) && strtolower(trim((string)$_POST['donor_already_paid'])) === 'yes';
    $paid_payment_method = trim((string)($_POST['paid_payment_method'] ?? ''));
    $paid_payment_evidence = trim((string)($_POST['paid_payment_evidence'] ?? ''));
    $paid_whatsapp_sent = ((int)($_POST['paid_whatsapp_sent'] ?? 0)) === 1;
    $conversation_stage_input = trim((string)($_POST['conversation_stage_input'] ?? ''));
    
    // Use paid flow method if step 6 was not reached.
    $fallback_methods = ['bank_transfer', 'card', 'cash', 'other'];
    if ($donor_already_paid && in_array($paid_payment_method, $fallback_methods, true) && empty($payment_method)) {
        $payment_method = $paid_payment_method;
    }
    
    error_log("Payment Method: " . $payment_method);
    error_log("Donor already paid: " . ($donor_already_paid ? 'yes' : 'no'));
    error_log("Paid flow method: " . ($paid_payment_method ?: 'none'));
    error_log("Paid WhatsApp sent: " . ($paid_whatsapp_sent ? 'yes' : 'no'));
    error_log("Conversation Stage Input: " . ($conversation_stage_input !== '' ? $conversation_stage_input : 'empty'));
    error_log("Cash Representative ID: " . ($cash_representative_id ?? 'null'));
    error_log("Cash Church ID: " . ($cash_church_id ?? 'null'));
    error_log("Step 2 Church ID: " . ($church_id ?? 'null'));

    $session_has_claimed_paid_column = false;
    $claimed_paid_column_check = $db->query("SHOW COLUMNS FROM call_center_sessions LIKE 'donor_claimed_already_paid'");
    if ($claimed_paid_column_check && $claimed_paid_column_check->num_rows > 0) {
        $session_has_claimed_paid_column = true;
    }
    
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
    
    // Update payment method
    if (!empty($payment_method) && in_array($payment_method, ['bank_transfer', 'card', 'cash'])) {
        $donor_updates[] = "preferred_payment_method = ?";
        $donor_params[] = $payment_method;
        $donor_types .= 's';
    }
    
    // Handle church_id - use cash_church_id if cash payment and changed, otherwise use Step 2 church_id
    $final_church_id = null;
    if ($payment_method === 'cash' && $cash_church_id !== null) {
        $final_church_id = $cash_church_id;
        error_log("Using cash_church_id: " . $final_church_id);
    } elseif ($church_id !== null) {
        $final_church_id = $church_id;
        error_log("Using Step 2 church_id: " . $final_church_id);
    }
    
    if ($final_church_id !== null) {
        $donor_updates[] = "church_id = ?";
        $donor_params[] = $final_church_id;
        $donor_types .= 'i';
    }
    
    // If cash payment, update representative
    if ($payment_method === 'cash' && $cash_representative_id !== null) {
        // Check if representative_id column exists
        $check_rep_column = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
        $has_rep_column = $check_rep_column && $check_rep_column->num_rows > 0;
        error_log("Has representative_id column: " . ($has_rep_column ? 'yes' : 'no'));
        
        if ($has_rep_column) {
            $donor_updates[] = "representative_id = ?";
            $donor_params[] = $cash_representative_id;
            $donor_types .= 'i';
        }
    }
    
    error_log("Donor updates count: " . count($donor_updates));
    error_log("Donor updates: " . print_r($donor_updates, true));
    error_log("Donor params count: " . count($donor_params));
    error_log("Donor types: " . $donor_types);
    
    // Update donor if there are any changes
    if (!empty($donor_updates)) {
        $donor_params[] = $donor_id;
        $donor_types .= 'i';
        
        $update_sql = "UPDATE donors SET " . implode(', ', $donor_updates) . " WHERE id = ?";
        error_log("Donor UPDATE SQL: " . $update_sql);
        error_log("Donor params: " . print_r($donor_params, true));
        error_log("Donor types: " . $donor_types);
        
        $update_donor_info = $db->prepare($update_sql);
        
        if (!$update_donor_info) {
            error_log("Prepare failed. Error: " . $db->error);
            throw new Exception("Failed to prepare donor update: " . $db->error);
        }
        
        if (!$update_donor_info->bind_param($donor_types, ...$donor_params)) {
            error_log("Bind param failed. Error: " . $update_donor_info->error);
            throw new Exception("Failed to bind parameters: " . $update_donor_info->error);
        }
        
        if (!$update_donor_info->execute()) {
            error_log("Execute failed. Error: " . $update_donor_info->error);
            throw new Exception("Failed to update donor: " . $update_donor_info->error);
        }
        
        error_log("Donor updated successfully");
        $update_donor_info->close();
    } else {
        error_log("No donor updates to perform");
    }
    
    // 1. Handle "already paid" donor flow before creating plans.
    if ($donor_already_paid) {
        if (empty($payment_method)) {
            throw new Exception('Please select how the donor paid before completing a paid-claim call.');
        }

        // Record paid claim details and evidence notes for follow-up.
        $proof_method = $payment_method !== '' ? ucfirst(str_replace('_', ' ', $payment_method)) : 'Unknown';
        $notes_parts = [
            'Donor reported that they already paid the full pledge.',
            "Payment method: {$proof_method}."
        ];

        if ($paid_payment_evidence !== '') {
            $notes_parts[] = "Evidence notes: {$paid_payment_evidence}.";
        }

        $notes_parts[] = 'Proof request sent via WhatsApp: ' . ($paid_whatsapp_sent ? 'Yes' : 'No (skipped)');
        $session_notes = ' ' . implode(' ', $notes_parts);
        
        // 1a. Update session outcome and close call
        if ($session_id > 0) {
            $session_update_sql = "
                UPDATE call_center_sessions
                SET outcome = ?,
                    conversation_stage = 'success_pledged',
                    payment_plan_id = NULL,
                    duration_seconds = COALESCE(duration_seconds, 0) + ?,
                    notes = CONCAT(COALESCE(notes, ''), ?),
                    call_ended_at = NOW()";
            if ($session_has_claimed_paid_column) {
                $session_update_sql .= ",
                    donor_claimed_already_paid = 1";
            }
            $session_update_sql .= "
                WHERE id = ?
            ";
            $update_session = $db->prepare($session_update_sql);
            $call_outcome = 'agreed_to_pay_full';
            $update_session->bind_param('sisi', $call_outcome, $duration_seconds, $session_notes, $session_id);
            $update_session->execute();
            $update_session->close();
        }
        
        // 1b. Update queue outcome for reporting/analytics
        if ($queue_id > 0) {
            $update_queue = $db->prepare("
                UPDATE call_center_queues
                SET status = 'completed',
                    completed_at = NOW(),
                    last_attempt_outcome = 'agreed_to_pay_full'
                WHERE id = ?
            ");
            $update_queue->bind_param('i', $queue_id);
            $update_queue->execute();
            $update_queue->close();
        }
        
        // 1c. Log conversation outcome
        log_audit(
            $db,
            'update',
            'call_center_conversation',
            $session_id,
            null,
            [
                'donor_id' => $donor_id,
                'queue_id' => $queue_id,
                'donor_already_paid' => true,
                'proof_method' => $proof_method,
                'proof_sent' => $paid_whatsapp_sent ? 'yes' : 'no',
                'proof_evidence' => $paid_payment_evidence
            ],
            'admin_portal',
            $user_id
        );
        
        $db->commit();
        header("Location: call-complete.php?session_id={$session_id}&donor_id={$donor_id}");
        exit;
    }

    // 2. Create Payment Plan
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
    
    // Fix: plan_frequency_number is integer, not string. Also handle NULL template_id
    // Parameters: donor_id(i), pledge_id(i), template_id(i/null), total_amount(d), monthly_amount(d),
    //             total_months(i), total_payments(i), start_date(s), payment_day(i),
    //             plan_frequency_unit(s), plan_frequency_number(i), plan_payment_method(s), start_date(s)
    
    error_log("Binding plan parameters...");
    error_log("Template ID: " . ($template_id_db ?? 'NULL'));
    error_log("Plan frequency number type: " . gettype($plan_frequency_number) . " = " . $plan_frequency_number);
    
    // Handle NULL template_id properly
    // Type string: donor_id(i), pledge_id(i), template_id(i), total_amount(d), monthly_amount(d),
    //              total_months(i), total_payments(i), start_date(s), payment_day(i),
    //              plan_frequency_unit(s), plan_frequency_number(i), payment_method(s), next_payment_due(s)
    // Total: 13 parameters = 'iiiddiisisiss'
    
    if ($template_id_db === null) {
        // Even for NULL, use 'i' type for integer columns
        $null_template = null;
        $bind_result = $insert_plan->bind_param('iiiddiisisiss',
            $donor_id,
            $pledge_id,
            $null_template, // template_id (NULL but type 'i')
            $total_amount,
            $monthly_amount,
            $total_months,
            $total_payments,
            $start_date,
            $payment_day,
            $plan_frequency_unit,
            $plan_frequency_number, // integer
            $plan_payment_method,
            $start_date
        );
    } else {
        $bind_result = $insert_plan->bind_param('iiiddiisisiss',
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
            $plan_frequency_number, // integer
            $plan_payment_method,
            $start_date
        );
    }
    
    if (!$bind_result) {
        error_log("Bind param failed. Error: " . $insert_plan->error);
        throw new Exception("Failed to bind plan parameters: " . $insert_plan->error);
    }
    
    if (!$insert_plan->execute()) {
        error_log("Plan insert failed. Error: " . $insert_plan->error);
        throw new Exception("Failed to create plan: " . $insert_plan->error);
    }
    
    error_log("Payment plan created successfully. Plan ID: " . $db->insert_id);
    $plan_id = $db->insert_id;
    $insert_plan->close();
    
    // 1.5 Generate Schedule Rows
    try {
        $current_date = new DateTime($start_date);
        $freq_unit = $plan_frequency_unit;
        $freq_num = $plan_frequency_number;
        
        $schedule_stmt = $db->prepare("INSERT INTO payment_plan_schedule (plan_id, installment_number, due_date, amount, status) VALUES (?, ?, ?, ?, 'pending')");
        
        for ($i = 1; $i <= $total_payments; $i++) {
            $due_date = $current_date->format('Y-m-d');
            $schedule_stmt->bind_param('iisd', $plan_id, $i, $due_date, $monthly_amount);
            $schedule_stmt->execute();
            
            // Advance date
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
        $schedule_stmt->close();
        error_log("Schedule rows generated.");
    } catch (Exception $e) {
        error_log("Failed to generate schedule rows: " . $e->getMessage());
        // Continue, don't block success
    }
    
    // 2. Update Donor (Active Plan) - Set flags only, no cache duplication
    $update_donor = $db->prepare("
        UPDATE donors 
        SET active_payment_plan_id = ?, 
            has_active_plan = 1,
            payment_status = 'paying' 
        WHERE id = ?
    ");
    $update_donor->bind_param('ii', $plan_id, $donor_id);
    $update_donor->execute();
    $update_donor->close();
    
    error_log("Donor flags updated: has_active_plan=1, active_payment_plan_id=$plan_id");
    
    // 3. Update Session
    if ($session_id > 0) {
        $update_session = $db->prepare("
            UPDATE call_center_sessions 
            SET outcome = 'payment_plan_created',
                conversation_stage = 'success_pledged',
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
    
    // Audit log the conversation outcome
    log_audit(
        $db,
        'create',
        'payment_plan',
        $plan_id,
        null,
        [
            'donor_id' => $donor_id,
            'session_id' => $session_id,
            'queue_id' => $queue_id,
            'plan_frequency_unit' => $plan_frequency_unit,
            'plan_frequency_number' => $plan_frequency_number,
            'total_payments' => $total_payments,
            'source' => 'call_center'
        ],
        'admin_portal',
        $user_id
    );
    
    $db->commit();
    
    // Redirect to success page
    header("Location: plan-success.php?plan_id={$plan_id}&donor_id={$donor_id}");
    exit;
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollback();
    }
    
    $error_msg = $e->getMessage();
    $error_trace = $e->getTraceAsString();
    
    error_log("=== PROCESS CONVERSATION ERROR ===");
    error_log("Error Message: " . $error_msg);
    error_log("Stack Trace: " . $error_trace);
    if (isset($db) && $db->error) {
        error_log("Database Error: " . $db->error);
    }
    
    // Show detailed error for debugging
    echo "<!DOCTYPE html><html><head><title>Error</title>";
    echo "<style>body{font-family:Arial;padding:20px;background:#f5f5f5;} .error-box{background:white;padding:20px;border-radius:8px;border-left:4px solid #dc3545;}</style>";
    echo "</head><body>";
    echo "<div class='error-box'>";
    echo "<h1 style='color:#dc3545;'>Error Processing Conversation</h1>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($error_msg) . "</p>";
    
    if (isset($db) && $db->error) {
        echo "<p><strong>Database Error:</strong> " . htmlspecialchars($db->error) . "</p>";
    }
    
    echo "<hr>";
    echo "<h3>Debug Information:</h3>";
    echo "<pre style='background:#f8f9fa;padding:10px;border-radius:4px;overflow:auto;'>";
    echo "POST Data:\n";
    print_r($_POST);
    echo "\n\nError Trace:\n";
    echo htmlspecialchars($error_trace);
    echo "</pre>";
    
    echo "<p><a href='conversation.php?donor_id=" . (isset($donor_id) ? $donor_id : 0) . "&queue_id=" . (isset($queue_id) ? $queue_id : 0) . "' style='display:inline-block;margin-top:20px;padding:10px 20px;background:#007bff;color:white;text-decoration:none;border-radius:4px;'>Go Back</a></p>";
    echo "</div>";
    echo "</body></html>";
    exit;
}

