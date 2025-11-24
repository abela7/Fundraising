<?php
declare(strict_types=1);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
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
    
    // Calculate monthly_amount (installment amount) - ensure it's never 0
    $monthly_amount = $total_amount / ($total_payments > 0 ? $total_payments : 1);
    $monthly_amount = round($monthly_amount, 2); // Round to 2 decimal places
    if ($monthly_amount <= 0 && $total_amount > 0) {
        // Fallback: if calculation fails, use total_amount as monthly
        $monthly_amount = $total_amount;
        error_log("WARNING: Monthly amount calculation resulted in 0, using total_amount as fallback");
    }
    
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
    $cash_representative_id = isset($_POST['cash_representative_id']) && $_POST['cash_representative_id'] !== '' ? (int)$_POST['cash_representative_id'] : null;
    $cash_church_id = isset($_POST['cash_church_id']) && $_POST['cash_church_id'] !== '' ? (int)$_POST['cash_church_id'] : null;
    
    error_log("Payment Method: " . $payment_method);
    error_log("Cash Representative ID: " . ($cash_representative_id ?? 'null'));
    error_log("Cash Church ID: " . ($cash_church_id ?? 'null'));
    error_log("Step 2 Church ID: " . ($church_id ?? 'null'));
    
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
                conversation_stage = 'plan_finalized',
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

