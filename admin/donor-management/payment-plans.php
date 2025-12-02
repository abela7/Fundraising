<?php
// Enable output buffering to catch any accidental output before headers
ob_start();

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../includes/resilient_db_loader.php';

require_login();
require_admin();

// If an 'id' parameter is provided, redirect to the view-payment-plan.php page
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $plan_id = (int)$_GET['id'];
    header('Location: view-payment-plan.php?id=' . $plan_id);
    exit;
}

$page_title = 'Payment Plan Templates';
$current_user = current_user();
$db = db();

$success_message = '';
$error_message = '';

// Check if payment_plan_templates table exists, if not create it
if ($db_connection_ok) {
    $table_check = $db->query("SHOW TABLES LIKE 'payment_plan_templates'");
    if ($table_check->num_rows === 0) {
        $create_table = "CREATE TABLE IF NOT EXISTS payment_plan_templates (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            duration_months INT NOT NULL,
            suggested_monthly_amount DECIMAL(10,2) NULL COMMENT 'Optional suggested amount per month',
            is_active TINYINT(1) DEFAULT 1,
            is_default TINYINT(1) DEFAULT 0,
            sort_order INT DEFAULT 0,
            created_by_user_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_active (is_active),
            INDEX idx_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->query($create_table);
        
        // Insert default templates
        $defaults = [
            ['3-Month Plan', 'Pay your pledge in 3 monthly installments', 3, 0],
            ['6-Month Plan', 'Pay your pledge in 6 monthly installments', 6, 1],
            ['12-Month Plan', 'Pay your pledge in 12 monthly installments', 12, 0],
            ['18-Month Plan', 'Pay your pledge in 18 monthly installments', 18, 0],
            ['24-Month Plan', 'Pay your pledge in 24 monthly installments', 24, 0],
            ['Custom Plan', 'Create a custom payment schedule', 0, 0]
        ];
        
        $sort = 1;
        foreach ($defaults as $default) {
            $stmt = $db->prepare("INSERT INTO payment_plan_templates (name, description, duration_months, is_default, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('ssiii', $default[0], $default[1], $default[2], $default[3], $sort);
            $stmt->execute();
            $sort++;
        }
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_connection_ok) {
    verify_csrf();
    
    $action = $_POST['action'] ?? '';
    
    try {
        $db->begin_transaction();
        
        if ($action === 'create_template') {
            // Create new payment plan template
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $duration_months = (int)($_POST['duration_months'] ?? 0);
            $suggested_monthly_amount = isset($_POST['suggested_monthly_amount']) && $_POST['suggested_monthly_amount'] !== '' 
                ? (float)$_POST['suggested_monthly_amount'] 
                : null;
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            
            // Validation
            if (empty($name)) {
                throw new Exception('Plan name is required');
            }
            if ($duration_months < 0 || $duration_months > 60) {
                throw new Exception('Duration must be between 0 and 60 months (0 for custom)');
            }
            
            // If setting as default, unset other defaults
            if ($is_default) {
                $db->query("UPDATE payment_plan_templates SET is_default = 0");
            }
            
            // Get next sort order
            $sort_result = $db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_sort FROM payment_plan_templates");
            $next_sort = $sort_result->fetch_assoc()['next_sort'];
            
            // Insert template
            $stmt = $db->prepare("
                INSERT INTO payment_plan_templates (
                    name, description, duration_months, suggested_monthly_amount,
                    is_default, sort_order, created_by_user_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('ssidiiii', 
                $name, 
                $description, 
                $duration_months,  // int
                $suggested_monthly_amount,  // double (nullable)
                $is_default,  // int
                $next_sort,  // int
                $current_user['id']  // int
            );
            $stmt->execute();
            $template_id = $db->insert_id;
            
            // Audit log
            $audit_data = json_encode([
                'name' => $name,
                'duration_months' => $duration_months,
                'is_default' => $is_default
            ], JSON_UNESCAPED_SLASHES);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(?, 'payment_plan_template', ?, 'create', ?, 'admin')");
            $audit->bind_param('iis', $current_user['id'], $template_id, $audit_data);
            $audit->execute();
            
            $db->commit();
            $_SESSION['success_message'] = "Payment plan template '{$name}' created successfully!";
            header('Location: payment-plans.php');
            exit;
            
        } elseif ($action === 'edit_template') {
            // Edit existing template
            $template_id = (int)($_POST['template_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $duration_months = (int)($_POST['duration_months'] ?? 0);
            $suggested_monthly_amount = isset($_POST['suggested_monthly_amount']) && $_POST['suggested_monthly_amount'] !== '' 
                ? (float)$_POST['suggested_monthly_amount'] 
                : null;
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            
            if ($template_id <= 0) {
                throw new Exception('Invalid template ID');
            }
            
            if (empty($name)) {
                throw new Exception('Plan name is required');
            }
            
            if ($duration_months < 0 || $duration_months > 60) {
                throw new Exception('Duration must be between 0 and 60 months');
            }
            
            // Get current state for audit
            $get_stmt = $db->prepare("SELECT name, description, duration_months, suggested_monthly_amount, is_default FROM payment_plan_templates WHERE id = ?");
            $get_stmt->bind_param('i', $template_id);
            $get_stmt->execute();
            $current = $get_stmt->get_result()->fetch_assoc();
            if (!$current) {
                throw new Exception('Template not found');
            }
            
            // If setting as default, unset other defaults
            if ($is_default && !$current['is_default']) {
                $db->query("UPDATE payment_plan_templates SET is_default = 0 WHERE id != $template_id");
            }
            
            // Update template
            $stmt = $db->prepare("
                UPDATE payment_plan_templates 
                SET name = ?, description = ?, duration_months = ?, 
                    suggested_monthly_amount = ?, is_default = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('ssidii', 
                $name,  // string
                $description,  // string
                $duration_months,  // int
                $suggested_monthly_amount,  // double (nullable)
                $is_default,  // int
                $template_id  // int
            );
            $stmt->execute();
            
            if ($stmt->affected_rows === 0) {
                throw new Exception('No changes detected or template not found');
            }
            
            // Audit log
            $before_data = json_encode([
                'name' => $current['name'],
                'description' => $current['description'],
                'duration_months' => (int)$current['duration_months'],
                'suggested_monthly_amount' => $current['suggested_monthly_amount'],
                'is_default' => (int)$current['is_default']
            ], JSON_UNESCAPED_SLASHES);
            $after_data = json_encode([
                'name' => $name,
                'description' => $description,
                'duration_months' => $duration_months,
                'suggested_monthly_amount' => $suggested_monthly_amount,
                'is_default' => $is_default
            ], JSON_UNESCAPED_SLASHES);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment_plan_template', ?, 'update', ?, ?, 'admin')");
            $audit->bind_param('iiss', $current_user['id'], $template_id, $before_data, $after_data);
            $audit->execute();
            
            $db->commit();
            $_SESSION['success_message'] = "Payment plan template '{$name}' updated successfully!";
            header('Location: payment-plans.php');
            exit;
            
        } elseif ($action === 'toggle_active') {
            $template_id = (int)($_POST['template_id'] ?? 0);
            $is_active = (int)($_POST['is_active'] ?? 0);
            
            if ($template_id <= 0) {
                throw new Exception('Invalid template ID');
            }
            
            // Get current state for audit
            $get_stmt = $db->prepare("SELECT name, is_active FROM payment_plan_templates WHERE id = ?");
            $get_stmt->bind_param('i', $template_id);
            $get_stmt->execute();
            $current = $get_stmt->get_result()->fetch_assoc();
            if (!$current) {
                throw new Exception('Template not found');
            }
            
            // Update status
            $stmt = $db->prepare("UPDATE payment_plan_templates SET is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $is_active, $template_id);
            $stmt->execute();
            
            // Audit log
            $before_data = json_encode(['is_active' => (int)$current['is_active']], JSON_UNESCAPED_SLASHES);
            $after_data = json_encode(['is_active' => $is_active], JSON_UNESCAPED_SLASHES);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment_plan_template', ?, 'toggle_active', ?, ?, 'admin')");
            $audit->bind_param('iiss', $current_user['id'], $template_id, $before_data, $after_data);
            $audit->execute();
            
            $db->commit();
            $_SESSION['success_message'] = $is_active ? "Plan '{$current['name']}' activated successfully" : "Plan '{$current['name']}' deactivated successfully";
            header('Location: payment-plans.php');
            exit;
            
        } elseif ($action === 'delete_template') {
            $template_id = (int)($_POST['template_id'] ?? 0);
            
            if ($template_id <= 0) {
                throw new Exception('Invalid template ID');
            }
            
            // Get template info for audit
            $get_stmt = $db->prepare("SELECT name, duration_months, is_default FROM payment_plan_templates WHERE id = ?");
            $get_stmt->bind_param('i', $template_id);
            $get_stmt->execute();
            $template = $get_stmt->get_result()->fetch_assoc();
            if (!$template) {
                throw new Exception('Template not found');
            }
            
            // Check if it's the default template
            if ($template['is_default']) {
                throw new Exception('Cannot delete the default template. Please set another template as default first.');
            }
            
            // Delete template
            $stmt = $db->prepare("DELETE FROM payment_plan_templates WHERE id = ?");
            $stmt->bind_param('i', $template_id);
            $stmt->execute();
            
            if ($stmt->affected_rows === 0) {
                throw new Exception('Failed to delete template');
            }
            
            // Audit log
            $before_data = json_encode([
                'name' => $template['name'],
                'duration_months' => (int)$template['duration_months']
            ], JSON_UNESCAPED_SLASHES);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, source) VALUES(?, 'payment_plan_template', ?, 'delete', ?, 'admin')");
            $audit->bind_param('iis', $current_user['id'], $template_id, $before_data);
            $audit->execute();
            
            $db->commit();
            $_SESSION['success_message'] = "Payment plan template '{$template['name']}' deleted successfully!";
            header('Location: payment-plans.php');
            exit;
            
        } elseif ($action === 'reorder') {
            $order = json_decode($_POST['order'] ?? '[]', true);
            if (!is_array($order)) {
                throw new Exception('Invalid order data');
            }
            
            foreach ($order as $index => $id) {
                $templateId = (int)$id;
                $sortOrder = (int)$index;
                $stmt = $db->prepare("UPDATE payment_plan_templates SET sort_order = ? WHERE id = ?");
                $stmt->bind_param('ii', $sortOrder, $templateId);
                $stmt->execute();
            }
            
            $db->commit();
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
            
        } elseif ($action === 'save_payment_plan') {
            // Save payment plan to donor
            // Clear any output buffer
            ob_clean();
            header('Content-Type: application/json');
            
            // STEP 4: Log all received POST data
            $debug_log = [];
            $debug_log[] = '=== BACKEND STEP 4: Received POST Data ===';
            $debug_log[] = 'All POST keys: ' . implode(', ', array_keys($_POST));
            foreach ($_POST as $key => $value) {
                if ($key !== 'schedule') {
                    $debug_log[] = "POST[$key] = " . (is_array($value) ? json_encode($value) : $value);
                }
            }
            
            $donor_id = (int)($_POST['donor_id'] ?? 0);
            // Handle template_id - can be empty string for custom plans
            $template_id_raw = $_POST['template_id'] ?? '';
            $template_id = ($template_id_raw !== '' && $template_id_raw !== null) ? (int)$template_id_raw : null;
            $total_amount = (float)($_POST['total_amount'] ?? 0);
            $monthly_amount = (float)($_POST['monthly_amount'] ?? 0);
            $total_months = isset($_POST['total_months']) && $_POST['total_months'] !== '' ? (int)$_POST['total_months'] : null;
            $total_payments = isset($_POST['total_payments']) && $_POST['total_payments'] !== '' ? (int)$_POST['total_payments'] : null;
            $start_date = $_POST['start_date'] ?? '';
            $payment_day = isset($_POST['payment_day']) ? (int)$_POST['payment_day'] : 1;
            $next_payment_due = $_POST['next_payment_due'] ?? '';
            $payment_method = $_POST['payment_method'] ?? 'bank_transfer';
            $schedule_json = $_POST['schedule'] ?? '[]';
            
            // Custom plan fields
            $plan_frequency_unit = $_POST['plan_frequency_unit'] ?? 'month';
            $plan_frequency_number = isset($_POST['plan_frequency_number']) ? (int)$_POST['plan_frequency_number'] : 1;
            $plan_payment_day_type = $_POST['plan_payment_day_type'] ?? 'day_of_month';
            
            $debug_log[] = '=== BACKEND STEP 5: Parsed Values ===';
            $debug_log[] = "donor_id: $donor_id";
            $debug_log[] = "template_id: " . ($template_id ?? 'NULL');
            $debug_log[] = "total_amount: $total_amount";
            $debug_log[] = "monthly_amount: $monthly_amount";
            $debug_log[] = "total_months: " . ($total_months ?? 'NULL');
            $debug_log[] = "total_payments: " . ($total_payments ?? 'NULL');
            $debug_log[] = "start_date: $start_date";
            $debug_log[] = "payment_day: $payment_day";
            $debug_log[] = "next_payment_due: $next_payment_due";
            $debug_log[] = "payment_method: $payment_method";
            $debug_log[] = "plan_frequency_unit: $plan_frequency_unit";
            $debug_log[] = "plan_frequency_number: $plan_frequency_number";
            $debug_log[] = "plan_payment_day_type: $plan_payment_day_type";
            
            // Validate
            $debug_log[] = '=== BACKEND STEP 6: Validation ===';
            if ($donor_id <= 0) {
                throw new Exception('STEP 6 FAILED: Invalid donor ID: ' . $donor_id);
            }
            if ($total_amount <= 0) {
                throw new Exception('STEP 6 FAILED: Total amount must be greater than 0, got: ' . $total_amount);
            }
            if ($monthly_amount <= 0) {
                throw new Exception('STEP 6 FAILED: Monthly amount must be greater than 0, got: ' . $monthly_amount);
            }
            if (empty($start_date)) {
                throw new Exception('STEP 6 FAILED: Start date is required, got: ' . var_export($start_date, true));
            }
            if (empty($next_payment_due)) {
                throw new Exception('STEP 6 FAILED: Next payment due date is required, got: ' . var_export($next_payment_due, true));
            }
            
            // Validate date format
            $start_date_obj = DateTime::createFromFormat('Y-m-d', $start_date);
            $next_due_obj = DateTime::createFromFormat('Y-m-d', $next_payment_due);
            if (!$start_date_obj || !$next_due_obj) {
                throw new Exception('STEP 6 FAILED: Invalid date format. start_date: ' . var_export($start_date, true) . ', next_payment_due: ' . var_export($next_payment_due, true));
            }
            
            $debug_log[] = 'Validation passed';
            
            // Check if donor exists
            $debug_log[] = '=== BACKEND STEP 7: Check Donor Exists ===';
            $donor_check = $db->prepare("SELECT id, total_pledged, last_pledge_id FROM donors WHERE id = ?");
            if (!$donor_check) {
                throw new Exception('STEP 7 FAILED: Prepare failed: ' . $db->error);
            }
            $donor_check->bind_param('i', $donor_id);
            $donor_check->execute();
            if ($donor_check->error) {
                throw new Exception('STEP 7 FAILED: Execute failed: ' . $donor_check->error);
            }
            $donor = $donor_check->get_result()->fetch_assoc();
            if (!$donor) {
                throw new Exception('STEP 7 FAILED: Donor not found with ID: ' . $donor_id);
            }
            $debug_log[] = 'Donor found: ' . json_encode($donor);
            
            // Get or create pledge_id
            $debug_log[] = '=== BACKEND STEP 8: Get/Create Pledge ID ===';
            $pledge_id = $donor['last_pledge_id'];
            $debug_log[] = "Existing pledge_id: " . ($pledge_id ?? 'NULL');
            
            if (!$pledge_id) {
                $debug_log[] = 'Creating new pledge...';
                // Create a pledge record if none exists
                $donor_info = $db->prepare("SELECT name, phone FROM donors WHERE id = ?");
                if (!$donor_info) {
                    throw new Exception('STEP 8 FAILED: Prepare failed - ' . $db->error);
                }
                $donor_info->bind_param('i', $donor_id);
                $donor_info->execute();
                if ($donor_info->error) {
                    throw new Exception('STEP 8 FAILED: Execute failed - ' . $donor_info->error);
                }
                $donor_data = $donor_info->get_result()->fetch_assoc();
                if (!$donor_data) {
                    throw new Exception('STEP 8 FAILED: Could not fetch donor data');
                }
                $debug_log[] = "Donor data: " . json_encode($donor_data);
                
                $create_pledge = $db->prepare("
                    INSERT INTO pledges (donor_id, donor_phone, donor_name, amount, type, status, approved_by_user_id, created_at)
                    VALUES (?, ?, ?, ?, 'pledge', 'approved', ?, NOW())
                ");
                if (!$create_pledge) {
                    throw new Exception('STEP 8 FAILED: Prepare INSERT failed - ' . $db->error);
                }
                $create_pledge->bind_param('issdi', $donor_id, $donor_data['phone'], $donor_data['name'], $total_amount, $current_user['id']);
                $create_pledge->execute();
                if ($create_pledge->error) {
                    throw new Exception('STEP 8 FAILED: Execute INSERT failed - ' . $create_pledge->error);
                }
                $pledge_id = $db->insert_id;
                $debug_log[] = "Created pledge_id: $pledge_id";
                
                // Update donor's last_pledge_id
                $update_pledge_id = $db->prepare("UPDATE donors SET last_pledge_id = ? WHERE id = ?");
                if (!$update_pledge_id) {
                    throw new Exception('STEP 8 FAILED: Prepare UPDATE failed - ' . $db->error);
                }
                $update_pledge_id->bind_param('ii', $pledge_id, $donor_id);
                $update_pledge_id->execute();
                if ($update_pledge_id->error) {
                    throw new Exception('STEP 8 FAILED: Execute UPDATE failed - ' . $update_pledge_id->error);
                }
            }
            
            // Check if donor already has an active plan
            $debug_log[] = '=== BACKEND STEP 9: Check Existing Active Plan ===';
            $existing_plan = $db->prepare("SELECT id FROM donor_payment_plans WHERE donor_id = ? AND status = 'active'");
            if (!$existing_plan) {
                throw new Exception('STEP 9 FAILED: Prepare failed - ' . $db->error);
            }
            $existing_plan->bind_param('i', $donor_id);
            $existing_plan->execute();
            if ($existing_plan->error) {
                throw new Exception('STEP 9 FAILED: Execute failed - ' . $existing_plan->error);
            }
            $existing = $existing_plan->get_result()->fetch_assoc();
            
            if ($existing) {
                $debug_log[] = 'Found existing active plan, pausing it...';
                // Pause existing plan
                $pause_existing = $db->prepare("UPDATE donor_payment_plans SET status = 'paused', updated_at = NOW() WHERE id = ?");
                if (!$pause_existing) {
                    throw new Exception('STEP 9 FAILED: Prepare UPDATE failed - ' . $db->error);
                }
                $pause_existing->bind_param('i', $existing['id']);
                $pause_existing->execute();
                if ($pause_existing->error) {
                    throw new Exception('STEP 9 FAILED: Execute UPDATE failed - ' . $pause_existing->error);
                }
                $debug_log[] = 'Existing plan paused';
            } else {
                $debug_log[] = 'No existing active plan found';
            }
            
            // Calculate reminder dates
            $debug_log[] = '=== BACKEND STEP 10: Calculate Reminder Dates ===';
            $next_due_timestamp = strtotime($next_payment_due);
            if ($next_due_timestamp === false) {
                throw new Exception('STEP 10 FAILED: Could not parse next_payment_due: ' . $next_payment_due);
            }
            $reminder_date = date('Y-m-d', $next_due_timestamp - (2 * 24 * 60 * 60)); // 2 days before payment
            $miss_notification_date = date('Y-m-d', $next_due_timestamp + (1 * 24 * 60 * 60)); // 1 day after payment due
            $overdue_reminder_date = date('Y-m-d', $next_due_timestamp + (7 * 24 * 60 * 60)); // 7 days after payment due (if still not paid)
            $debug_log[] = "reminder_date: $reminder_date";
            $debug_log[] = "miss_notification_date: $miss_notification_date";
            $debug_log[] = "overdue_reminder_date: $overdue_reminder_date";
            
            // Determine total_months for standard plans (estimate from total_payments if custom)
            $debug_log[] = '=== BACKEND STEP 11: Calculate total_months ===';
            // total_months is NOT NULL in database, so we must have a value
            if ($total_months === null || $total_months <= 0) {
                if ($total_payments !== null && $total_payments > 0) {
                    // For custom plans, estimate months based on frequency
                    if ($plan_frequency_unit === 'week') {
                        // Rough estimate: 4.33 weeks per month
                        $total_months = max(1, ceil(($total_payments * $plan_frequency_number) / 4.33));
                    } else {
                        // Monthly frequency
                        $total_months = max(1, $total_payments * $plan_frequency_number);
                    }
                } else {
                    // Default to 1 month if we can't calculate
                    $total_months = 1;
                }
            }
            $debug_log[] = "Final total_months: $total_months";
            
            // Insert new payment plan
            $debug_log[] = '=== BACKEND STEP 12: Insert Payment Plan ===';
            $debug_log[] = "About to insert with values:";
            $debug_log[] = "  donor_id: $donor_id";
            $debug_log[] = "  pledge_id: $pledge_id";
            $debug_log[] = "  template_id: " . ($template_id ?? 'NULL');
            $debug_log[] = "  total_amount: $total_amount";
            $debug_log[] = "  monthly_amount: $monthly_amount";
            $debug_log[] = "  total_months: $total_months";
            $debug_log[] = "  total_payments: " . ($total_payments ?? 'NULL');
            $debug_log[] = "  start_date: $start_date";
            $debug_log[] = "  payment_day: $payment_day";
            $debug_log[] = "  plan_frequency_unit: $plan_frequency_unit";
            $debug_log[] = "  plan_frequency_number: $plan_frequency_number";
            $debug_log[] = "  plan_payment_day_type: $plan_payment_day_type";
            $debug_log[] = "  payment_method: $payment_method";
            $debug_log[] = "  next_payment_due: $next_payment_due";
            $debug_log[] = "  reminder_date: $reminder_date";
            $debug_log[] = "  miss_notification_date: $miss_notification_date";
            $debug_log[] = "  overdue_reminder_date: $overdue_reminder_date";
            
            $insert_plan = $db->prepare("
                INSERT INTO donor_payment_plans (
                    donor_id, pledge_id, template_id, total_amount, monthly_amount, 
                    total_months, total_payments, start_date, payment_day,
                    plan_frequency_unit, plan_frequency_number, plan_payment_day_type,
                    payment_method, next_payment_due, next_reminder_date,
                    miss_notification_date, overdue_reminder_date, status,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
            ");
            if (!$insert_plan) {
                throw new Exception('STEP 12 FAILED: Prepare INSERT failed - ' . $db->error);
            }
            
            // For nullable integers in bind_param, we need to pass NULL explicitly
            // mysqli can handle NULL for 'i' type, but we need to ensure it's actually NULL, not 0
            $template_id_bind = $template_id ?? null;
            // total_months is NOT NULL in database, so it should never be null here (we ensure this in STEP 11)
            // But for bind_param, we still pass it as is (it should be an integer)
            $total_months_bind = $total_months; // This should never be null due to STEP 11 logic
            $total_payments_bind = $total_payments ?? null;
            
            // Validate that total_months is not null before binding (safety check)
            if ($total_months_bind === null || $total_months_bind <= 0) {
                throw new Exception('STEP 12 FAILED: total_months cannot be NULL or <= 0, got: ' . var_export($total_months_bind, true));
            }
            
            $debug_log[] = "Binding parameters - template_id: " . ($template_id_bind ?? 'NULL') . ", total_months: $total_months_bind, total_payments: " . ($total_payments_bind ?? 'NULL');
            
            // Bind 17 parameters: iiiddiisisississs (17 characters = 17 placeholders)
            // Mapping: iii(1-3) + dd(4-5) + ii(6-7) + s(8) + i(9) + s(10) + i(11) + s(12) + s(13) + s(14) + s(15) + s(16) + s(17)
            // Positions: 1-3=iii, 4-5=dd, 6-7=ii, 8=s, 9=i, 10=s, 11=i, 12=s, 13=s(payment_method), 14-17=ssss(dates)
            $insert_plan->bind_param('iiiddiisisississs',
                $donor_id,              // 1: i - integer
                $pledge_id,             // 2: i - integer
                $template_id_bind,      // 3: i - integer (nullable) - can be NULL
                $total_amount,          // 4: d - decimal
                $monthly_amount,        // 5: d - decimal
                $total_months_bind,     // 6: i - integer (NOT NULL in DB - must be > 0)
                $total_payments_bind,   // 7: i - integer (nullable) - can be NULL
                $start_date,            // 8: s - string/date
                $payment_day,           // 9: i - integer
                $plan_frequency_unit,    // 10: s - string/enum
                $plan_frequency_number,  // 11: i - integer
                $plan_payment_day_type, // 12: s - string/enum
                $payment_method,        // 13: s - string/enum
                $next_payment_due,      // 14: s - string/date
                $reminder_date,         // 15: s - string/date
                $miss_notification_date,// 16: s - string/date
                $overdue_reminder_date  // 17: s - string/date
            );
            $insert_plan->execute();
            if ($insert_plan->error) {
                throw new Exception('STEP 12 FAILED: Execute INSERT failed - ' . $insert_plan->error . ' | SQL Error: ' . $db->error);
            }
            $plan_id = $db->insert_id;
            $debug_log[] = "Payment plan created with ID: $plan_id";
            
            // Update donor table
            $debug_log[] = '=== BACKEND STEP 13: Update Donor Table ===';
            $plan_duration_for_donor = $total_months ?? ($total_payments ?? 0);
            $debug_log[] = "plan_duration_for_donor: $plan_duration_for_donor";
            $debug_log[] = "About to update donor with plan_id: $plan_id";
            
            $update_donor = $db->prepare("
                UPDATE donors SET 
                    has_active_plan = 1,
                    active_payment_plan_id = ?,
                    plan_monthly_amount = ?,
                    plan_duration_months = ?,
                    plan_start_date = ?,
                    plan_next_due_date = ?,
                    payment_status = 'paying',
                    updated_at = NOW()
                WHERE id = ?
            ");
            if (!$update_donor) {
                throw new Exception('STEP 13 FAILED: Prepare UPDATE failed - ' . $db->error);
            }
            $update_donor->bind_param('idissi', $plan_id, $monthly_amount, $plan_duration_for_donor, $start_date, $next_payment_due, $donor_id);
            $update_donor->execute();
            if ($update_donor->error) {
                throw new Exception('STEP 13 FAILED: Execute UPDATE failed - ' . $update_donor->error);
            }
            $debug_log[] = 'Donor table updated successfully';
            
            // Audit log
            require_once __DIR__ . '/../../shared/audit_helper.php';
            log_audit(
                $db,
                'create',
                'donor_payment_plan',
                $plan_id,
                null,
                [
                    'donor_id' => $donor_id,
                    'pledge_id' => $pledge_id,
                    'template_id' => $template_id,
                    'total_amount' => $total_amount,
                    'monthly_amount' => $monthly_amount,
                    'total_payments' => $total_payments,
                    'start_date' => $start_date,
                    'next_payment_due' => $next_payment_due,
                    'schedule' => json_decode($schedule_json, true)
                ],
                'admin_portal',
                $current_user['id']
            );
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Payment plan saved successfully!']);
            exit;
            
        } elseif ($action === 'clear_payment_plan') {
            // Clear payment plan from donor
            header('Content-Type: application/json');
            
            $donor_id = (int)($_POST['donor_id'] ?? 0);
            
            if ($donor_id <= 0) {
                throw new Exception('Invalid donor ID');
            }
            
            // Get donor info and current plan for audit
            // Note: We match the plan regardless of status to allow clearing inconsistent states
            $donor_check = $db->prepare("
                SELECT d.id, d.name, d.active_payment_plan_id, d.has_active_plan,
                       pp.id as plan_id, pp.total_amount, pp.monthly_amount, pp.status as plan_status
                FROM donors d
                LEFT JOIN donor_payment_plans pp ON d.active_payment_plan_id = pp.id
                WHERE d.id = ?
            ");
            $donor_check->bind_param('i', $donor_id);
            $donor_check->execute();
            $donor = $donor_check->get_result()->fetch_assoc();
            
            if (!$donor) {
                throw new Exception('Donor not found');
            }
            
            // Allow clearing if there's a plan reference, regardless of actual plan status
            // This handles cases where has_active_plan flag doesn't match actual plan status
            if (!$donor['active_payment_plan_id']) {
                throw new Exception('Donor does not have a payment plan assigned');
            }
            
            $plan_id = (int)$donor['active_payment_plan_id'];
            
            // Verify the plan actually exists (handle orphaned references)
            if (!$donor['plan_id']) {
                // Plan doesn't exist - just clear the reference from donor table
                // Don't try to update non-existent plan
            } else {
                // Plan exists - update its status to cancelled if not already cancelled/completed
                if (!in_array($donor['plan_status'], ['cancelled', 'completed'])) {
                    $cancel_plan = $db->prepare("
                        UPDATE donor_payment_plans 
                        SET status = 'cancelled', updated_at = NOW() 
                        WHERE id = ? AND donor_id = ?
                    ");
                    $cancel_plan->bind_param('ii', $plan_id, $donor_id);
                    $cancel_plan->execute();
                }
            }
            
            // Clear payment plan info from donor table
            $clear_donor = $db->prepare("
                UPDATE donors SET 
                    has_active_plan = 0,
                    active_payment_plan_id = NULL,
                    plan_monthly_amount = NULL,
                    plan_duration_months = NULL,
                    plan_start_date = NULL,
                    plan_next_due_date = NULL,
                    payment_status = CASE 
                        WHEN total_paid >= total_pledged THEN 'completed'
                        WHEN total_paid > 0 THEN 'paying'
                        ELSE 'not_started'
                    END,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $clear_donor->bind_param('i', $donor_id);
            $clear_donor->execute();
            
            // Audit log (only if plan exists)
            if ($donor['plan_id']) {
                $audit_data = json_encode([
                    'donor_id' => $donor_id,
                    'donor_name' => $donor['name'],
                    'plan_id' => $plan_id,
                    'plan_total_amount' => $donor['total_amount'] ?? null,
                    'plan_monthly_amount' => $donor['monthly_amount'] ?? null,
                    'previous_status' => $donor['plan_status'] ?? null
                ], JSON_UNESCAPED_SLASHES);
                $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, source) VALUES(?, 'donor_payment_plan', ?, 'cancel', ?, 'admin')");
                $audit->bind_param('iis', $current_user['id'], $plan_id, $audit_data);
                $audit->execute();
            } else {
                // Audit log for orphaned reference cleanup
                $audit_data = json_encode([
                    'donor_id' => $donor_id,
                    'donor_name' => $donor['name'],
                    'plan_id' => $plan_id,
                    'note' => 'Orphaned plan reference - plan record does not exist'
                ], JSON_UNESCAPED_SLASHES);
                $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, source) VALUES(?, 'donor', ?, 'clear_orphaned_plan', ?, 'admin')");
                $audit->bind_param('iis', $current_user['id'], $donor_id, $audit_data);
                $audit->execute();
            }
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Payment plan cleared successfully!']);
            exit;
            
        }
        
    } catch (Exception $e) {
        if ($db_connection_ok) {
            $db->rollback();
        }
        if (isset($_POST['action']) && ($_POST['action'] === 'save_payment_plan' || $_POST['action'] === 'clear_payment_plan')) {
            // Clear any output buffer
            ob_clean();
            header('Content-Type: application/json');
            // Include more detailed error info in development
            $error_msg = $e->getMessage();
            if ($e->getCode() > 0) {
                $error_msg .= ' (Code: ' . $e->getCode() . ')';
            }
            
            // Include debug log if available
            $response = [
                'success' => false, 
                'message' => $error_msg,
                'error_details' => $e->getFile() . ':' . $e->getLine(),
                'sql_error' => ($db_connection_ok ? ($db->error ?? null) : null),
                'error_type' => get_class($e)
            ];
            
            if (isset($debug_log)) {
                $response['debug_log'] = $debug_log;
            }
            
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $error_message = $e->getMessage();
    } catch (mysqli_sql_exception $e) {
        if ($db_connection_ok) {
            $db->rollback();
        }
        if (isset($_POST['action']) && ($_POST['action'] === 'save_payment_plan' || $_POST['action'] === 'clear_payment_plan')) {
            // Clear any output buffer
            ob_clean();
            header('Content-Type: application/json');
            
            $response = [
                'success' => false, 
                'message' => 'Database error: ' . $e->getMessage(),
                'error_details' => $e->getFile() . ':' . $e->getLine(),
                'sql_error' => ($db_connection_ok ? ($db->error ?? 'No SQL error info') : 'DB not connected'),
                'mysqli_errno' => $e->getCode(),
                'error_type' => 'mysqli_sql_exception'
            ];
            
            if (isset($debug_log)) {
                $response['debug_log'] = $debug_log;
            }
            
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $error_message = 'Database error: ' . $e->getMessage();
    } catch (Throwable $e) {
        // Catch ANY other errors (Parse errors, fatal errors, etc.)
        if ($db_connection_ok) {
            try { $db->rollback(); } catch (Exception $re) {}
        }
        if (isset($_POST['action']) && ($_POST['action'] === 'save_payment_plan' || $_POST['action'] === 'clear_payment_plan')) {
            ob_clean();
            header('Content-Type: application/json');
            
            $response = [
                'success' => false, 
                'message' => 'Unexpected error: ' . $e->getMessage(),
                'error_details' => $e->getFile() . ':' . $e->getLine(),
                'error_type' => get_class($e),
                'trace' => $e->getTraceAsString()
            ];
            
            if (isset($debug_log)) {
                $response['debug_log'] = $debug_log;
            }
            
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $error_message = 'Unexpected error: ' . $e->getMessage();
    }
}

// Check for success message from redirect
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Load all payment plan templates (for display grid)
$templates_all = [];
if ($db_connection_ok) {
    try {
        $result = $db->query("SELECT * FROM payment_plan_templates ORDER BY sort_order ASC, created_at ASC");
        if ($result) {
            $templates_all = $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Exception $e) {
        // Silent fail
    }
}

// Load active templates for preview modal dropdown
$templates = [];
if ($db_connection_ok) {
    try {
        $result = $db->query("SELECT * FROM payment_plan_templates WHERE is_active = 1 ORDER BY sort_order ASC, created_at ASC");
        if ($result) {
            $templates = $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Exception $e) {
        // Silent fail
    }
}

// Load donors with outstanding balances for preview modal
$donors_for_preview = [];
if ($db_connection_ok) {
    try {
        // Get pledge donors with outstanding balances, including payment plan info
        $check_column = $db->query("SHOW COLUMNS FROM donors LIKE 'donor_type'");
        $has_donor_type = $check_column && $check_column->num_rows > 0;
        $pledge_filter = $has_donor_type ? "donor_type = 'pledge'" : "total_pledged > 0";
        
        $result = $db->query("
            SELECT 
                d.id, d.name, d.preferred_payment_method, d.payment_status, 
                d.total_pledged, d.total_paid, d.balance,
                d.has_active_plan, d.active_payment_plan_id,
                d.plan_monthly_amount as donor_plan_monthly_amount, 
                d.plan_duration_months, d.plan_start_date, d.plan_next_due_date,
                pp.status as plan_status, pp.total_amount as plan_total_amount,
                pp.monthly_amount as plan_monthly_amount, pp.total_payments as plan_total_payments,
                pp.start_date as plan_start_date_db, pp.next_payment_due as plan_next_due,
                pp.payments_made as plan_payments_made, pp.amount_paid as plan_amount_paid,
                t.name as template_name
            FROM donors d
            LEFT JOIN donor_payment_plans pp ON d.active_payment_plan_id = pp.id AND pp.status = 'active'
            LEFT JOIN payment_plan_templates t ON pp.template_id = t.id
            WHERE {$pledge_filter} 
                AND d.balance > 0
                AND d.name IS NOT NULL 
                AND TRIM(d.name) != ''
            ORDER BY d.name ASC
        ");
        if ($result) {
            $donors_for_preview = $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Exception $e) {
        // Silent fail
    }
}

// Calculate statistics
$stats = [
    'total_templates' => count($templates_all),
    'active_templates' => count(array_filter($templates_all, fn($t) => $t['is_active'])),
    'inactive_templates' => count(array_filter($templates_all, fn($t) => !$t['is_active'])),
    'default_template' => ''
];

foreach ($templates_all as $t) {
    if ($t['is_default']) {
        $stats['default_template'] = $t['name'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/donor-management.css">
    <style>
    .plan-card {
        transition: all 0.3s ease;
        cursor: move;
    }
    .plan-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
    }
    .plan-card.dragging {
        opacity: 0.5;
    }
    .plan-card .card-header {
        padding: 1.25rem 1.5rem;
    }
    .plan-card .card-body {
        padding: 1.5rem;
    }
    .duration-badge {
        font-size: 0.8rem;
        font-weight: 600;
        padding: 0.35rem 0.75rem;
    }
    .drag-handle {
        opacity: 0.3;
        transition: opacity 0.3s;
        cursor: move;
    }
    .plan-card:hover .drag-handle {
        opacity: 0.7;
    }
    .plan-description {
        min-height: 40px;
        line-height: 1.6;
    }
    .plan-actions {
        padding-top: 1rem;
        margin-top: 1rem;
        border-top: 1px solid #e9ecef;
    }
    
    /* Preview Plan Modal Styles */
    #previewPlanModal .table-responsive {
        border-radius: 0.375rem;
    }
    
    #previewPlanModal .table thead th {
        position: sticky;
        top: 0;
        background-color: #f8f9fa;
        z-index: 10;
    }
    
    #previewPlanModal .border-start {
        border-width: 3px !important;
    }
    
    #preview_donor {
        min-height: 60px;
        max-height: 250px;
        font-size: 0.95rem;
    }
    
    #preview_donor[size="2"] {
        min-height: 60px;
        max-height: 80px;
    }
    
    #preview_donor option {
        padding: 0.75rem 0.5rem;
        cursor: pointer;
        line-height: 1.6;
    }
    
    #preview_donor option:hover {
        background-color: #e7f1ff;
    }
    
    #donor_filter_count {
        font-size: 0.875rem;
        display: block;
    }
    
    #donor_search {
        border-radius: 0.375rem 0 0 0.375rem;
    }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                <?php include '../includes/db_error_banner.php'; ?>
                
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1 text-primary">
                            <i class="fas fa-th-list me-2"></i>Payment Plan Templates
                        </h1>
                        <p class="text-muted mb-0">Create and manage payment plan options that donors can choose from</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#previewPlanModal">
                            <i class="fas fa-calculator me-2"></i>Preview Plan
                        </button>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
                            <i class="fas fa-plus me-2"></i>Create Template
                        </button>
                    </div>
                </div>

                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show animate-fade-in">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show animate-fade-in">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Summary Statistics -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="color: #0a6286;">
                            <div class="stat-icon bg-primary">
                                <i class="fas fa-th-list"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?php echo $stats['total_templates']; ?></h3>
                                <p class="stat-label">Total Templates</p>
                                <div class="stat-trend text-primary">
                                    <i class="fas fa-layer-group"></i> Available
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="animation-delay: 0.1s; color: #0d7f4d;">
                            <div class="stat-icon bg-success">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?php echo $stats['active_templates']; ?></h3>
                                <p class="stat-label">Active Plans</p>
                                <div class="stat-trend text-success">
                                    <i class="fas fa-toggle-on"></i> Enabled
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="animation-delay: 0.2s; color: #6c757d;">
                            <div class="stat-icon bg-secondary">
                                <i class="fas fa-toggle-off"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?php echo $stats['inactive_templates']; ?></h3>
                                <p class="stat-label">Inactive Plans</p>
                                <div class="stat-trend text-secondary">
                                    <i class="fas fa-pause"></i> Disabled
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="animation-delay: 0.3s; color: #b88a1a;">
                            <div class="stat-icon bg-warning">
                                <i class="fas fa-star"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value" style="font-size: 1rem;"><?php echo $stats['default_template'] ?: 'None'; ?></h3>
                                <p class="stat-label">Default Plan</p>
                                <div class="stat-trend text-warning">
                                    <i class="fas fa-crown"></i> Recommended
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Plan Templates Grid -->
                <div class="row g-4 mb-4" id="templatesGrid">
                    <?php foreach ($templates_all as $index => $template): ?>
                    <div class="col-12 col-md-6 col-lg-4" data-template-id="<?php echo $template['id']; ?>">
                        <div class="plan-card card border-0 shadow-sm h-100 position-relative animate-fade-in" 
                             style="animation-delay: <?php echo ($index * 0.05); ?>s;">
                            
                            <div class="card-header bg-white border-bottom">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-grip-vertical me-2 drag-handle text-muted"></i>
                                        <h5 class="mb-0">
                                            <i class="fas fa-calendar-check text-<?php echo $template['is_active'] ? 'primary' : 'secondary'; ?> me-2"></i>
                                            <?php echo htmlspecialchars($template['name']); ?>
                                        </h5>
                                    </div>
                                    <span class="badge bg-<?php echo $template['is_active'] ? 'primary' : 'secondary'; ?> text-white duration-badge">
                                        <?php if ($template['duration_months'] == 0): ?>
                                            Custom
                                        <?php else: ?>
                                            <?php echo $template['duration_months']; ?>mo
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="card-body d-flex flex-column">
                                <p class="plan-description mb-3 text-muted">
                                    <?php echo htmlspecialchars($template['description'] ?: 'No description provided'); ?>
                                </p>
                                
                                <?php if ($template['suggested_monthly_amount']): ?>
                                <div class="border-start border-4 border-success ps-3 mb-3">
                                    <small class="text-muted d-block mb-1">Suggested Monthly Amount</small>
                                    <h4 class="mb-0 text-success">£<?php echo number_format($template['suggested_monthly_amount'], 2); ?></h4>
                                </div>
                                <?php endif; ?>
                                
                                <div class="plan-actions mt-auto">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" 
                                                   id="active_<?php echo $template['id']; ?>"
                                                   <?php echo $template['is_active'] ? 'checked' : ''; ?>
                                                   onchange="toggleActive(<?php echo $template['id']; ?>, this.checked)">
                                            <label class="form-check-label fw-semibold" for="active_<?php echo $template['id']; ?>">
                                                <?php echo $template['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </label>
                                        </div>
                                        
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($template['is_default']): ?>
                                            <span class="badge bg-warning text-dark" style="font-size: 0.7rem;">
                                                <i class="fas fa-star me-1"></i>DEFAULT
                                            </span>
                                            <?php endif; ?>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-primary edit-template" 
                                                        data-template='<?php echo htmlspecialchars(json_encode($template), ENT_QUOTES); ?>'
                                                        title="Edit Template">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger delete-template" 
                                                        data-id="<?php echo $template['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($template['name']); ?>"
                                                        title="Delete Template">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($templates)): ?>
                <div class="card border-0 shadow-sm animate-fade-in">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0">
                            <i class="fas fa-th-list text-primary me-2"></i>Payment Plan Templates
                        </h5>
                    </div>
                    <div class="card-body text-center py-5">
                        <div class="mb-4">
                            <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 rounded-circle" 
                                 style="width: 100px; height: 100px;">
                                <i class="fas fa-th-list fa-3x text-primary"></i>
                            </div>
                        </div>
                        <h4 class="mb-3">No Payment Plan Templates Yet</h4>
                        <p class="text-muted mb-4">Create your first template to offer flexible payment options to donors</p>
                        <button type="button" class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
                            <i class="fas fa-plus-circle me-2"></i>Create Your First Template
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Create Template Modal -->
<div class="modal fade" id="createTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="" id="createTemplateForm">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="create_template">
                
                <div class="modal-header bg-white border-bottom">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle text-primary me-2"></i>Create Payment Plan Template
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label fw-bold">Plan Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg" name="name" 
                                   placeholder="e.g., 6-Month Plan" required>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label fw-bold">Description</label>
                            <textarea class="form-control" name="description" rows="3" 
                                      placeholder="Describe this payment plan option..."></textarea>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Duration (Months) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control form-control-lg" name="duration_months" 
                                   min="0" max="60" placeholder="6" required>
                            <small class="text-muted">Enter 0 for custom/flexible duration</small>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Suggested Monthly Amount (Optional)</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text">£</span>
                                <input type="number" class="form-control" name="suggested_monthly_amount" 
                                       min="0" step="0.01" placeholder="Leave blank">
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_default" id="is_default_create">
                                <label class="form-check-label fw-bold" for="is_default_create">
                                    <i class="fas fa-star text-warning me-1"></i>
                                    Set as Default (Recommended to donors)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save me-2"></i>Create Template
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Preview Plan Modal -->
<div class="modal fade" id="previewPlanModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-white border-bottom">
                <h5 class="modal-title">
                    <i class="fas fa-calculator text-primary me-2"></i>Preview Payment Plan
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body p-4">
                <div class="row g-4">
                    <!-- Left Column: Input -->
                    <div class="col-lg-5">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-cog text-primary me-2"></i>Plan Configuration</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label fw-bold mb-0">Select Donor <span class="text-danger">*</span></label>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="toggle_filter_btn">
                                        <i class="fas fa-filter me-1"></i>Filter
                                    </button>
                                </div>
                                
                                <!-- Filter Panel (Hidden by default) -->
                                <div class="card border mb-2" id="donor_filter_panel" style="display: none;">
                                    <div class="card-body p-3">
                                        <h6 class="mb-3">
                                            <i class="fas fa-filter text-primary me-2"></i>Filter Donors
                                        </h6>
                                        
                                        <div class="row g-2 mb-2">
                                            <div class="col-12">
                                                <label class="form-label small fw-semibold">Payment Method</label>
                                                <select class="form-select form-select-sm" id="filter_payment_method">
                                                    <option value="">All Methods</option>
                                                    <option value="cash">Cash</option>
                                                    <option value="bank_transfer">Bank Transfer</option>
                                                    <option value="card">Card</option>
                                                </select>
                                            </div>
                                            
                                            <div class="col-12">
                                                <label class="form-label small fw-semibold">Payment Status</label>
                                                <select class="form-select form-select-sm" id="filter_payment_status">
                                                    <option value="">All Statuses</option>
                                                    <option value="not_started">Not Started</option>
                                                    <option value="paying">Paying</option>
                                                    <option value="overdue">Overdue</option>
                                                    <option value="completed">Completed</option>
                                                </select>
                                            </div>
                                            
                                            <div class="col-12">
                                                <label class="form-label small fw-semibold">Balance Range</label>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" class="form-control" id="filter_balance_min" 
                                                           placeholder="Min £" min="0" step="0.01">
                                                    <span class="input-group-text">-</span>
                                                    <input type="number" class="form-control" id="filter_balance_max" 
                                                           placeholder="Max £" min="0" step="0.01">
                                                </div>
                                            </div>
                                            
                                            <div class="col-12 mt-2">
                                                <button type="button" class="btn btn-sm btn-primary w-100" id="apply_filter_btn">
                                                    <i class="fas fa-check me-1"></i>Apply Filters
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-1" id="reset_filter_btn">
                                                    <i class="fas fa-times me-1"></i>Reset
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="input-group mb-2">
                                    <span class="input-group-text bg-light">
                                        <i class="fas fa-search text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control" id="donor_search" 
                                           placeholder="Search by name..." autocomplete="off">
                                    <button type="button" class="btn btn-outline-secondary" id="clear_donor_search" title="Clear">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                
                                <div class="mb-2">
                                    <small class="text-muted" id="donor_filter_count">
                                        <?php echo count($donors_for_preview); ?> pledge donor<?php echo count($donors_for_preview) !== 1 ? 's' : ''; ?> available
                                    </small>
                                </div>
                                
                                <select class="form-select" id="preview_donor" required size="6" style="overflow-y: auto; transition: height 0.2s ease;">
                                    <option value="">-- Select a donor --</option>
                                    <?php 
                                    $payment_method_labels = [
                                        'cash' => 'Cash',
                                        'bank_transfer' => 'Bank Transfer',
                                        'card' => 'Card'
                                    ];
                                    foreach ($donors_for_preview as $donor): 
                                        $payment_method = $donor['preferred_payment_method'] ?? 'bank_transfer';
                                        $payment_label = $payment_method_labels[$payment_method] ?? ucfirst(str_replace('_', ' ', $payment_method));
                                        $has_plan = !empty($donor['has_active_plan']) && !empty($donor['active_payment_plan_id']);
                                        $plan_data = $has_plan ? json_encode([
                                            'plan_id' => $donor['active_payment_plan_id'],
                                            'template_name' => $donor['template_name'] ?? 'Custom Plan',
                                            'monthly_amount' => $donor['plan_monthly_amount'] ?? $donor['donor_plan_monthly_amount'] ?? 0,
                                            'duration_months' => $donor['plan_duration_months'] ?? 0,
                                            'start_date' => $donor['plan_start_date'] ?? $donor['plan_start_date_db'] ?? '',
                                            'next_due' => $donor['plan_next_due_date'] ?? $donor['plan_next_due'] ?? '',
                                            'total_amount' => $donor['plan_total_amount'] ?? 0,
                                            'payments_made' => $donor['plan_payments_made'] ?? 0,
                                            'total_payments' => $donor['plan_total_payments'] ?? 0,
                                            'amount_paid' => $donor['plan_amount_paid'] ?? 0
                                        ], JSON_UNESCAPED_SLASHES) : '';
                                    ?>
                                    <option value="<?php echo $donor['id']; ?>" 
                                            data-balance="<?php echo $donor['balance']; ?>"
                                            data-name="<?php echo htmlspecialchars($donor['name']); ?>"
                                            data-payment-method="<?php echo htmlspecialchars($payment_method); ?>"
                                            data-payment-status="<?php echo htmlspecialchars($donor['payment_status'] ?? 'not_started'); ?>"
                                            data-has-plan="<?php echo $has_plan ? '1' : '0'; ?>"
                                            data-plan-data="<?php echo htmlspecialchars($plan_data, ENT_QUOTES); ?>"
                                            data-search-text="<?php echo strtolower(htmlspecialchars($donor['name'] . ' ' . $payment_label)); ?>">
                                        <?php echo htmlspecialchars($donor['name']); ?> • <?php echo htmlspecialchars($payment_label); ?> • £<?php echo number_format($donor['balance'], 2); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Existing Plan Info (Hidden by default) -->
                            <div id="existing_plan_info" class="card border-warning mb-3" style="display: none;">
                                <div class="card-header bg-warning bg-opacity-10">
                                    <h6 class="mb-0 text-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>Existing Payment Plan
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-2 mb-2">
                                        <div class="col-6">
                                            <small class="text-muted d-block">Plan Type</small>
                                            <strong id="existing_plan_type">-</strong>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted d-block">Monthly Amount</small>
                                            <strong id="existing_plan_monthly">-</strong>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted d-block">Start Date</small>
                                            <strong id="existing_plan_start">-</strong>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted d-block">Next Payment Due</small>
                                            <strong id="existing_plan_next_due">-</strong>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted d-block">Payments Made</small>
                                            <strong id="existing_plan_payments">-</strong>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted d-block">Amount Paid</small>
                                            <strong class="text-success" id="existing_plan_paid">-</strong>
                                        </div>
                                    </div>
                                    <div class="mt-3 pt-2 border-top">
                                        <button type="button" class="btn btn-sm btn-danger w-100" id="clearPlanBtn">
                                            <i class="fas fa-times-circle me-2"></i>Clear Existing Plan
                                        </button>
                                        <small class="text-muted d-block mt-2 text-center">
                                            Clear this plan to assign a new one
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Payment Plan Template <span class="text-danger">*</span></label>
                                <select class="form-select" id="preview_template" required>
                                    <option value="">Choose a plan...</option>
                                    <?php foreach ($templates as $template): ?>
                                    <option value="<?php echo $template['id']; ?>" 
                                            data-duration="<?php echo $template['duration_months']; ?>"
                                            data-name="<?php echo htmlspecialchars($template['name']); ?>">
                                        <?php echo htmlspecialchars($template['name']); ?>
                                        <?php if ($template['duration_months'] > 0): ?>
                                            (<?php echo $template['duration_months']; ?> months)
                                        <?php else: ?>
                                            (Custom)
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Custom Plan Options (Hidden by default) -->
                            <div id="custom_plan_options" style="display: none;">
                                <div class="card border-info mb-3">
                                    <div class="card-header bg-info bg-opacity-10">
                                        <h6 class="mb-0 text-info">
                                            <i class="fas fa-cog me-2"></i>Custom Payment Plan Configuration
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Payment Frequency <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text">Every</span>
                                                <input type="number" class="form-control" id="custom_frequency_number" 
                                                       min="1" max="24" value="1" placeholder="1">
                                                <select class="form-select" id="custom_frequency_unit" style="max-width: 120px;">
                                                    <option value="week">Week(s)</option>
                                                    <option value="month" selected>Month(s)</option>
                                                </select>
                                            </div>
                                            <small class="text-muted">How often payments will be made</small>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Number of Payments <span class="text-danger">*</span></label>
                                            <input type="number" class="form-control" id="custom_total_payments" 
                                                   min="1" max="120" value="1" placeholder="e.g., 12">
                                            <small class="text-muted">Total number of payments to complete the plan</small>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Payment Day/Date</label>
                                            <div class="input-group">
                                                <select class="form-select" id="custom_payment_day_type">
                                                    <option value="day_of_month" selected>Day of Month (1-28)</option>
                                                    <option value="weekday">Same Weekday</option>
                                                </select>
                                                <input type="number" class="form-control" id="custom_payment_day" 
                                                       min="1" max="28" value="1" placeholder="Day" style="max-width: 100px;">
                                            </div>
                                            <small class="text-muted" id="custom_day_help">
                                                Payment will be on this day of each month
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row g-3" id="standard_date_options">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Start Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="preview_start_date" 
                                           value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Payment Day (1-28)</label>
                                    <input type="number" class="form-control" id="preview_payment_day" 
                                           min="1" max="28" value="1" required>
                                    <small class="text-muted">Day of month</small>
                                </div>
                            </div>
                            
                            <div class="mt-4 pt-3 border-top">
                                <button type="button" class="btn btn-primary w-100" id="calculatePlanBtn">
                                    <i class="fas fa-calculator me-2"></i>Calculate Plan
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column: Results -->
                <div class="col-lg-7">
                    <div id="planPreviewResults" style="display: none;">
                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-chart-line text-success me-2"></i>Plan Summary</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3 mb-3">
                                    <div class="col-6">
                                        <div class="border-start border-4 border-primary ps-3">
                                            <small class="text-muted d-block">Total Amount</small>
                                            <h4 class="mb-0 text-primary" id="preview_total_amount">-</h4>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border-start border-4 border-success ps-3">
                                            <small class="text-muted d-block">Monthly Payment</small>
                                            <h4 class="mb-0 text-success" id="preview_monthly_amount">-</h4>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border-start border-4 border-info ps-3">
                                            <small class="text-muted d-block">Duration</small>
                                            <h5 class="mb-0 text-info" id="preview_duration">-</h5>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border-start border-4 border-warning ps-3">
                                            <small class="text-muted d-block">Donor Balance</small>
                                            <h5 class="mb-0 text-warning" id="preview_donor_balance">-</h5>
                                        </div>
                                    </div>
                                </div>
                                
                                <div id="preview_plan_match" class="alert" style="display: none;">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <span id="preview_match_message"></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-calendar-alt text-primary me-2"></i>Payment Schedule</h6>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                    <table class="table table-hover table-sm align-middle mb-0">
                                        <thead class="table-light sticky-top">
                                            <tr>
                                                <th>#</th>
                                                <th>Payment Date</th>
                                                <th class="text-end">Amount</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody id="preview_schedule_body">
                                            <!-- Schedule rows will be generated here -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div id="planPreviewEmpty" class="text-center py-5">
                        <div class="mb-4">
                            <div class="d-inline-flex align-items-center justify-content-center bg-light rounded-circle" 
                                 style="width: 80px; height: 80px;">
                                <i class="fas fa-calculator fa-2x text-muted"></i>
                            </div>
                        </div>
                        <h6 class="text-muted mb-2">Plan Preview</h6>
                        <p class="text-muted small mb-0">Select a donor and plan template, then click "Calculate Plan" to see the payment schedule</p>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Close
                </button>
                <button type="button" class="btn btn-success" id="savePlanBtn" style="display: none;">
                    <i class="fas fa-save me-2"></i>Save Plan to Donor
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Template Modal -->
<div class="modal fade" id="editTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="" id="editTemplateForm">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="edit_template">
                <input type="hidden" name="template_id" id="edit_template_id">
                
                <div class="modal-header bg-white border-bottom">
                    <h5 class="modal-title">
                        <i class="fas fa-edit text-primary me-2"></i>Edit Payment Plan Template
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label fw-bold">Plan Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg" name="name" id="edit_name" required>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label fw-bold">Description</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Duration (Months) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control form-control-lg" name="duration_months" 
                                   id="edit_duration_months" min="0" max="60" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Suggested Monthly Amount</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text">£</span>
                                <input type="number" class="form-control" name="suggested_monthly_amount" 
                                       id="edit_suggested_monthly_amount" min="0" step="0.01">
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_default" id="edit_is_default">
                                <label class="form-check-label fw-bold" for="edit_is_default">
                                    <i class="fas fa-star text-warning me-1"></i>
                                    Set as Default
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save me-2"></i>Update Template
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="../assets/admin.js"></script>

<script>
// Get CSRF token helper
function getCSRFToken() {
    const csrfInput = document.querySelector('input[name="csrf_token"]');
    if (csrfInput) {
        return csrfInput.value;
    }
    // Try to find it in any form
    const form = document.querySelector('form');
    if (form) {
        const token = form.querySelector('input[name="csrf_token"]');
        if (token) return token.value;
    }
    return '';
}

// Initialize drag-and-drop sorting
new Sortable(document.getElementById('templatesGrid'), {
    animation: 150,
    handle: '.drag-handle',
    ghostClass: 'dragging',
    onEnd: function(evt) {
        // Get new order
        const order = [];
        $('#templatesGrid > div').each(function() {
            order.push($(this).data('template-id'));
        });
        
        // Save to server
        $.ajax({
            url: '',
            method: 'POST',
            data: {
                action: 'reorder',
                order: JSON.stringify(order),
                csrf_token: getCSRFToken()
            },
            success: function(response) {
                // Silent success - order is saved
            },
            error: function() {
                alert('Failed to save order. Please refresh the page.');
            }
        });
    }
});

// Toggle active status
function toggleActive(templateId, isActive) {
    if (!templateId || templateId <= 0) {
        alert('Invalid template ID');
        return;
    }
    
    const form = $('<form>', {
        method: 'POST',
        action: ''
    });
    form.append('<?php echo csrf_input(); ?>');
    form.append($('<input>', { type: 'hidden', name: 'action', value: 'toggle_active' }));
    form.append($('<input>', { type: 'hidden', name: 'template_id', value: templateId }));
    form.append($('<input>', { type: 'hidden', name: 'is_active', value: isActive ? 1 : 0 }));
    $('body').append(form);
    form.submit();
}

// Edit template
$(document).on('click', '.edit-template', function(e) {
    e.stopPropagation();
    const templateData = $(this).attr('data-template');
    if (!templateData) {
        alert('Template data not found');
        return;
    }
    
    try {
        const template = JSON.parse(templateData);
        
        if (!template || !template.id) {
            alert('Invalid template data');
            return;
        }
        
        $('#edit_template_id').val(template.id);
        $('#edit_name').val(template.name || '');
        $('#edit_description').val(template.description || '');
        $('#edit_duration_months').val(template.duration_months || 0);
        $('#edit_suggested_monthly_amount').val(template.suggested_monthly_amount || '');
        $('#edit_is_default').prop('checked', template.is_default == 1);
        
        $('#editTemplateModal').modal('show');
    } catch (error) {
        console.error('Error parsing template data:', error);
        alert('Error loading template data');
    }
});

// Delete template
$(document).on('click', '.delete-template', function(e) {
    e.stopPropagation();
    const id = $(this).data('id');
    const name = $(this).data('name');
    
    if (!id || id <= 0) {
        alert('Invalid template ID');
        return;
    }
    
    if (confirm(`Are you sure you want to delete "${name || 'this template'}"? This action cannot be undone.`)) {
        const form = $('<form>', {
            method: 'POST',
            action: ''
        });
        form.append('<?php echo csrf_input(); ?>');
        form.append($('<input>', { type: 'hidden', name: 'action', value: 'delete_template' }));
        form.append($('<input>', { type: 'hidden', name: 'template_id', value: id }));
        $('body').append(form);
        form.submit();
    }
});

// Prevent row click from triggering when clicking buttons
$(document).on('click', '.plan-card .btn, .plan-card .form-check-input, .plan-card .form-check-label', function(e) {
    e.stopPropagation();
});

// Show/hide custom plan options based on template selection
document.getElementById('preview_template').addEventListener('change', function() {
    const selectedTemplate = this.options[this.selectedIndex];
    const duration = parseInt(selectedTemplate.getAttribute('data-duration') || 0);
    const customOptions = document.getElementById('custom_plan_options');
    const standardDateOptions = document.getElementById('standard_date_options');
    
    if (duration === 0) {
        // Custom plan - show custom options, hide standard date options
        customOptions.style.display = 'block';
        standardDateOptions.style.display = 'none';
        
        // Clear standard date inputs requirement
        document.getElementById('preview_payment_day').required = false;
    } else {
        // Standard plan - hide custom options, show standard date options
        customOptions.style.display = 'none';
        standardDateOptions.style.display = 'flex';
        document.getElementById('preview_payment_day').required = true;
    }
});

// Update help text for custom payment day type
document.getElementById('custom_payment_day_type').addEventListener('change', function() {
    const helpText = document.getElementById('custom_day_help');
    if (this.value === 'weekday') {
        helpText.textContent = 'Payment will be on the same weekday (e.g., every Monday, every Friday)';
        document.getElementById('custom_payment_day').style.display = 'none';
    } else {
        helpText.textContent = 'Payment will be on this day of each month (1-28)';
        document.getElementById('custom_payment_day').style.display = 'block';
    }
});

// Helper function to add weeks to a date
function addWeeks(date, weeks) {
    const result = new Date(date);
    result.setDate(result.getDate() + (weeks * 7));
    return result;
}

// Helper function to add months to a date
function addMonths(date, months) {
    const result = new Date(date);
    result.setMonth(result.getMonth() + months);
    return result;
}

// Helper function to get days in month
function getDaysInMonth(year, month) {
    return new Date(year, month + 1, 0).getDate();
}

// Helper function to get next occurrence of a weekday
function getNextWeekday(date, targetWeekday) {
    const currentWeekday = date.getDay();
    let daysToAdd = (targetWeekday - currentWeekday + 7) % 7;
    // If already on the target weekday, always move to next week (add 7 days)
    if (daysToAdd === 0) {
        daysToAdd = 7;
    }
    const result = new Date(date);
    result.setDate(result.getDate() + daysToAdd);
    return result;
}

// Store current plan data globally for saving
let currentPlanData = null;

// Display existing plan in Plan Preview
function displayExistingPlanPreview(planData) {
    // Show plan preview section
    document.getElementById('planPreviewResults').style.display = 'block';
    document.getElementById('planPreviewEmpty').style.display = 'none';
    
    // Update plan summary
    document.getElementById('preview_total_amount').textContent = 
        '£' + parseFloat(planData.total_amount || 0).toFixed(2);
    document.getElementById('preview_monthly_amount').textContent = 
        '£' + parseFloat(planData.monthly_amount || 0).toFixed(2);
    document.getElementById('preview_duration').textContent = 
        planData.total_payments ? `${planData.total_payments} payments` : '-';
    
    // Get donor balance (try to extract from selected option)
    const donorSelect = document.getElementById('preview_donor');
    const selectedOption = donorSelect.options[donorSelect.selectedIndex];
    const balance = parseFloat(selectedOption.getAttribute('data-balance') || 0);
    document.getElementById('preview_donor_balance').textContent = '£' + balance.toFixed(2);
    
    // Add "Existing Plan" badge/indicator
    const summaryHeader = document.querySelector('#planPreviewResults .card-header h6');
    if (summaryHeader && !summaryHeader.querySelector('.badge')) {
        const badge = document.createElement('span');
        badge.className = 'badge bg-warning text-dark ms-2';
        badge.innerHTML = '<i class="fas fa-info-circle me-1"></i>Current Plan';
        summaryHeader.appendChild(badge);
    }
    
    // Generate payment schedule based on existing plan data
    const tbody = document.getElementById('preview_schedule_body');
    tbody.innerHTML = '';
    
    if (planData.start_date && planData.monthly_amount && planData.total_payments) {
        const startDate = new Date(planData.start_date);
        const monthlyAmount = parseFloat(planData.monthly_amount);
        const totalPayments = parseInt(planData.total_payments || 0);
        const paymentsMade = parseInt(planData.payments_made || 0);
        
        // Distribute remaining amount over remaining payments
        const totalRemaining = parseFloat(planData.total_amount || 0) - parseFloat(planData.amount_paid || 0);
        const remainingPayments = totalPayments - paymentsMade;
        const remainingMonthlyAmount = remainingPayments > 0 ? totalRemaining / remainingPayments : 0;
        
        for (let i = 1; i <= totalPayments; i++) {
            const row = document.createElement('tr');
            
            // Calculate payment date (add months from start date)
            const paymentDate = new Date(startDate);
            paymentDate.setMonth(paymentDate.getMonth() + (i - 1));
            
            // Format date
            const dateStr = paymentDate.toLocaleDateString('en-GB', { 
                weekday: 'short', 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric' 
            });
            
            // Determine status and amount
            let statusBadge, amount, amountClass;
            if (i <= paymentsMade) {
                // Payment already made
                statusBadge = '<span class="badge bg-success">Paid</span>';
                amount = monthlyAmount;
                amountClass = 'text-success';
            } else if (i === paymentsMade + 1) {
                // Next payment due
                statusBadge = '<span class="badge bg-warning">Due</span>';
                amount = remainingMonthlyAmount;
                amountClass = 'text-warning';
            } else {
                // Future payment
                statusBadge = '<span class="badge bg-secondary">Pending</span>';
                amount = remainingMonthlyAmount;
                amountClass = 'text-muted';
            }
            
            row.innerHTML = `
                <td><strong>${i}</strong></td>
                <td><i class="fas fa-calendar text-muted me-2"></i>${dateStr}</td>
                <td class="text-end"><strong class="${amountClass}">£${amount.toFixed(2)}</strong></td>
                <td>${statusBadge}</td>
            `;
            
            tbody.appendChild(row);
        }
    } else {
        // No schedule data available
        tbody.innerHTML = `
            <tr>
                <td colspan="4" class="text-center text-muted py-4">
                    <i class="fas fa-info-circle me-2"></i>Schedule details not available
                </td>
            </tr>
        `;
    }
    
    // Hide match alert and save button for existing plans
    document.getElementById('preview_plan_match').style.display = 'none';
    document.getElementById('savePlanBtn').style.display = 'none';
}

// Plan Preview Calculation - Robust System
function calculatePaymentSchedule() {
    const donorSelect = document.getElementById('preview_donor');
    const templateSelect = document.getElementById('preview_template');
    const startDateInput = document.getElementById('preview_start_date');
    
    // Validation
    if (!donorSelect.value || !templateSelect.value || !startDateInput.value) {
        alert('Please fill in all required fields');
        return;
    }
    
    const selectedDonor = donorSelect.options[donorSelect.selectedIndex];
    const selectedTemplate = templateSelect.options[templateSelect.selectedIndex];
    
    const balance = parseFloat(selectedDonor.getAttribute('data-balance'));
    const duration = parseInt(selectedTemplate.getAttribute('data-duration') || '0');
    const templateName = selectedTemplate.getAttribute('data-name');
    const templateId = selectedTemplate.value;
    const startDate = new Date(startDateInput.value);
    
    if (isNaN(balance) || balance <= 0) {
        alert('Invalid donor balance');
        return;
    }
    
    let schedule = [];
    let paymentAmount = 0;
    let totalPayments = 0;
    let frequencyDescription = '';
    
    // Check if custom plan
    const customOptions = document.getElementById('custom_plan_options');
    const isCustom = customOptions.style.display !== 'none';
    
    // Custom plan settings for saving
    let plan_frequency_unit = 'month';
    let plan_frequency_number = 1;
    let plan_payment_day_type = 'day_of_month';
    let payment_day = 1;
    let total_months = null;
    
    if (isCustom) {
        // CUSTOM PLAN LOGIC
        const frequencyNumber = parseInt(document.getElementById('custom_frequency_number').value || '1');
        const frequencyUnit = document.getElementById('custom_frequency_unit').value;
        totalPayments = parseInt(document.getElementById('custom_total_payments').value || '1');
        const paymentDayType = document.getElementById('custom_payment_day_type').value;
        const paymentDayInput = parseInt(document.getElementById('custom_payment_day').value || '1');
        
        // Store for saving
        plan_frequency_unit = frequencyUnit;
        plan_frequency_number = frequencyNumber;
        plan_payment_day_type = paymentDayType;
        payment_day = paymentDayInput;
        total_months = null;
        
        // Validation for custom plan
        if (frequencyNumber < 1 || frequencyNumber > 24) {
            alert('Frequency must be between 1 and 24');
            return;
        }
        
        if (totalPayments < 1 || totalPayments > 120) {
            alert('Number of payments must be between 1 and 120');
            return;
        }
        
        if (paymentDayType === 'day_of_month' && (paymentDayInput < 1 || paymentDayInput > 28)) {
            alert('Payment day must be between 1 and 28');
            return;
        }
        
        // Calculate payment amount
        paymentAmount = balance / totalPayments;
        const roundedAmount = Math.floor(paymentAmount * 100) / 100;
        const remainder = balance - (roundedAmount * totalPayments);
        
        // Generate schedule based on frequency
        let currentDate = new Date(startDate);
        
        // Set initial payment date
        if (paymentDayType === 'day_of_month') {
            if (frequencyUnit === 'week') {
                // For weekly frequency with day preference, find the next occurrence
                // Try to use preferred day in current month if it hasn't passed
                const daysInStartMonth = getDaysInMonth(currentDate.getFullYear(), currentDate.getMonth());
                const preferredDay = Math.min(paymentDayInput, daysInStartMonth);
                
                // Check if preferred day in current month is still available (not in the past)
                if (startDate.getDate() < preferredDay) {
                    // Start date is before preferred day - use current month's preferred day
                    // This is in the future relative to start date, so it's valid
                    currentDate.setDate(preferredDay);
                } else {
                    // Start date is on or after preferred day - move to next week interval
                    // Then adjust to preferred day in the new month
                    currentDate = addWeeks(startDate, frequencyNumber);
                    const daysInNewMonth = getDaysInMonth(currentDate.getFullYear(), currentDate.getMonth());
                    const newPreferredDay = Math.min(paymentDayInput, daysInNewMonth);
                    currentDate.setDate(newPreferredDay);
                }
            } else {
                // Monthly frequency: set to specific day of month
                const daysInMonth = getDaysInMonth(currentDate.getFullYear(), currentDate.getMonth());
                currentDate.setDate(Math.min(paymentDayInput, daysInMonth));
                
                // If start date is after payment day, move to next month interval
                if (startDate.getDate() > paymentDayInput) {
                    currentDate = addMonths(currentDate, frequencyNumber);
                    const daysInNewMonth = getDaysInMonth(currentDate.getFullYear(), currentDate.getMonth());
                    currentDate.setDate(Math.min(paymentDayInput, daysInNewMonth));
                }
            }
        } else {
            // Weekday-based: use the start date's weekday
            const targetWeekday = startDate.getDay();
            currentDate = getNextWeekday(startDate, targetWeekday);
        }
        
        // Generate all payments
        for (let i = 0; i < totalPayments; i++) {
            const paymentDate = new Date(currentDate);
            
            // Last payment gets remainder
            const amount = (i === totalPayments - 1) 
                ? roundedAmount + remainder 
                : roundedAmount;
            
            schedule.push({
                installment: i + 1,
                date: new Date(paymentDate),
                amount: amount,
                status: 'pending'
            });
            
            // Calculate next payment date
            if (i < totalPayments - 1) {
                if (frequencyUnit === 'week') {
                    // Weekly frequency: add weeks directly
                    currentDate = addWeeks(currentDate, frequencyNumber);
                    
                    if (paymentDayType === 'day_of_month') {
                        // For weekly + day_of_month preference, adjust to preferred day if in same month
                        // This is optional preference - don't force it if it would skip too far
                        const daysInMonth = getDaysInMonth(currentDate.getFullYear(), currentDate.getMonth());
                        const preferredDay = Math.min(paymentDayInput, daysInMonth);
                        
                        // Only adjust if the preferred day is within 3 days of the calculated date
                        // This prevents jumping around too much
                        const dayDifference = Math.abs(currentDate.getDate() - preferredDay);
                        if (dayDifference <= 3) {
                            currentDate.setDate(preferredDay);
                        }
                    }
                } else {
                    // Monthly frequency
                    currentDate = addMonths(currentDate, frequencyNumber);
                    
                    if (paymentDayType === 'day_of_month') {
                        // Set to specific day of month
                        const daysInMonth = getDaysInMonth(currentDate.getFullYear(), currentDate.getMonth());
                        currentDate.setDate(Math.min(paymentDayInput, daysInMonth));
                    } else {
                        // Weekday-based: maintain same weekday
                        const targetWeekday = startDate.getDay();
                        currentDate = getNextWeekday(currentDate, targetWeekday);
                    }
                }
            }
        }
        
        frequencyDescription = `Every ${frequencyNumber} ${frequencyUnit}${frequencyNumber > 1 ? 's' : ''} (${totalPayments} payments)`;
        
    } else {
        // STANDARD PLAN LOGIC (existing monthly logic)
        if (duration <= 0) {
            alert('Please select a valid payment plan template with duration');
            return;
        }
        
        const paymentDayInput = parseInt(document.getElementById('preview_payment_day').value || '1');
        
        // Store for saving
        plan_frequency_unit = 'month';
        plan_frequency_number = 1;
        plan_payment_day_type = 'day_of_month';
        payment_day = paymentDayInput;
        total_months = duration;
        
        if (isNaN(paymentDayInput) || paymentDayInput < 1 || paymentDayInput > 28) {
            alert('Payment day must be between 1 and 28');
            return;
        }
        
        // Calculate monthly payment
        paymentAmount = balance / duration;
        const roundedMonthly = Math.floor(paymentAmount * 100) / 100;
        const remainder = balance - (roundedMonthly * duration);
        
        // Generate payment schedule
        let currentDate = new Date(startDate);
        
        // Set the first payment date to the payment day of the month
        if (currentDate.getDate() > paymentDayInput) {
            currentDate.setMonth(currentDate.getMonth() + 1);
        }
        const daysInMonth = getDaysInMonth(currentDate.getFullYear(), currentDate.getMonth());
        currentDate.setDate(Math.min(paymentDayInput, daysInMonth));
        
        for (let i = 0; i < duration; i++) {
            const paymentDate = new Date(currentDate);
            const amount = (i === duration - 1) 
                ? roundedMonthly + remainder 
                : roundedMonthly;
            
            schedule.push({
                installment: i + 1,
                date: new Date(paymentDate),
                amount: amount,
                status: 'pending'
            });
            
            // Move to next month
            currentDate.setMonth(currentDate.getMonth() + 1);
            const daysInNextMonth = getDaysInMonth(currentDate.getFullYear(), currentDate.getMonth());
            currentDate.setDate(Math.min(paymentDayInput, daysInNextMonth));
        }
        
        totalPayments = duration;
        frequencyDescription = `${duration} months`;
    }
    
    // Store plan data for saving
    const nextPaymentDue = schedule.length > 0 ? schedule[0].date.toISOString().split('T')[0] : '';
    const donorPaymentMethod = selectedDonor.getAttribute('data-payment-method') || 'bank_transfer';
    
    currentPlanData = {
        donor_id: parseInt(donorSelect.value),
        template_id: templateId || null,
        total_amount: balance,
        monthly_amount: paymentAmount,
        total_months: total_months,
        total_payments: totalPayments,
        start_date: startDateInput.value,
        payment_day: payment_day,
        next_payment_due: nextPaymentDue,
        payment_method: donorPaymentMethod,
        schedule: schedule,
        plan_frequency_unit: plan_frequency_unit,
        plan_frequency_number: plan_frequency_number,
        plan_payment_day_type: plan_payment_day_type
    };
    
    // Update UI
    document.getElementById('preview_total_amount').textContent = '£' + balance.toFixed(2);
    document.getElementById('preview_monthly_amount').textContent = '£' + (schedule[0]?.amount || 0).toFixed(2);
    document.getElementById('preview_duration').textContent = frequencyDescription || `${totalPayments} payments`;
    document.getElementById('preview_donor_balance').textContent = '£' + balance.toFixed(2);
    
    // Check if plan matches balance perfectly
    const totalCalculated = schedule.reduce((sum, p) => sum + p.amount, 0);
    const difference = Math.abs(totalCalculated - balance);
    
    if (difference < 0.01) {
        document.getElementById('preview_plan_match').className = 'alert alert-success';
        document.getElementById('preview_match_message').textContent = 
            '✓ Plan matches donor balance perfectly!';
        document.getElementById('preview_plan_match').style.display = 'block';
    } else {
        document.getElementById('preview_plan_match').className = 'alert alert-warning';
        document.getElementById('preview_match_message').textContent = 
            `Note: Total calculated (£${totalCalculated.toFixed(2)}) differs from balance (£${balance.toFixed(2)}) by £${difference.toFixed(2)}`;
        document.getElementById('preview_plan_match').style.display = 'block';
    }
    
    // Generate schedule table
    const tbody = document.getElementById('preview_schedule_body');
    tbody.innerHTML = '';
    
    schedule.forEach((payment, index) => {
        const row = document.createElement('tr');
        const dateStr = payment.date.toLocaleDateString('en-GB', { 
            weekday: 'short', 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        });
        
        row.innerHTML = `
            <td><strong>${payment.installment}</strong></td>
            <td><i class="fas fa-calendar text-muted me-2"></i>${dateStr}</td>
            <td class="text-end"><strong class="text-success">£${payment.amount.toFixed(2)}</strong></td>
            <td><span class="badge bg-secondary">Pending</span></td>
        `;
        
        tbody.appendChild(row);
    });
    
    // Show results
    document.getElementById('planPreviewResults').style.display = 'block';
    document.getElementById('planPreviewEmpty').style.display = 'none';
    
    // Remove "Existing Plan" badge if present (this is a new calculation)
    const summaryHeader = document.querySelector('#planPreviewResults .card-header h6');
    if (summaryHeader) {
        const existingBadge = summaryHeader.querySelector('.badge.bg-warning');
        if (existingBadge) {
            existingBadge.remove();
        }
    }
    
    // Show save button
    document.getElementById('savePlanBtn').style.display = 'inline-block';
}

// Calculate button click
document.getElementById('calculatePlanBtn').addEventListener('click', calculatePaymentSchedule);

// Save Plan button click
document.getElementById('savePlanBtn').addEventListener('click', function() {
    if (!currentPlanData) {
        alert('Please calculate the payment plan first');
        return;
    }
    
    if (!confirm('Are you sure you want to save this payment plan to the donor?\n\nThis will create an active payment plan record.')) {
        return;
    }
    
    // Prepare data for submission
    console.log('=== STEP 1: Preparing formData ===');
    console.log('currentPlanData:', currentPlanData);
    
    const formData = {
        action: 'save_payment_plan',
        csrf_token: getCSRFToken(),
        donor_id: currentPlanData.donor_id,
        template_id: currentPlanData.template_id || '',
        total_amount: currentPlanData.total_amount,
        monthly_amount: currentPlanData.monthly_amount,
        total_months: currentPlanData.total_months || '',
        total_payments: currentPlanData.total_payments,
        start_date: currentPlanData.start_date,
        payment_day: currentPlanData.payment_day,
        next_payment_due: currentPlanData.next_payment_due,
        payment_method: currentPlanData.payment_method,
        plan_frequency_unit: currentPlanData.plan_frequency_unit,
        plan_frequency_number: currentPlanData.plan_frequency_number,
        plan_payment_day_type: currentPlanData.plan_payment_day_type,
        schedule: JSON.stringify(currentPlanData.schedule.map(p => ({
            installment: p.installment,
            date: p.date.toISOString().split('T')[0],
            amount: p.amount,
            status: p.status
        })))
    };
    
    console.log('=== STEP 2: FormData prepared ===');
    console.log('FormData being sent:', JSON.stringify(formData, null, 2));
    console.log('CSRF Token:', formData.csrf_token ? 'Present' : 'MISSING!');
    
    // Show loading state
    const btn = this;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
    
    console.log('=== STEP 3: Sending AJAX request ===');
    // Submit via AJAX
    $.ajax({
        url: '',
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert('Payment plan saved successfully!');
                $('#previewPlanModal').modal('hide');
                // Optionally reload page to show updated data
                setTimeout(() => {
                    location.reload();
                }, 500);
            } else {
                alert('Error: ' + (response.message || 'Failed to save payment plan'));
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        },
        error: function(xhr, status, error) {
            console.error('=== STEP 4: AJAX ERROR ===');
            console.error('Status:', status);
            console.error('Error:', error);
            console.error('XHR Status:', xhr.status);
            console.error('XHR StatusText:', xhr.statusText);
            console.error('Content-Type:', xhr.getResponseHeader('Content-Type'));
            
            let errorMsg = 'Server error. Please try again.';
            let fullErrorDetails = '';
            
            // ALWAYS log the raw response text first
            if (xhr.responseText) {
                console.error('=== RAW Response Text (first 3000 chars) ===');
                console.error(xhr.responseText.substring(0, 3000));
            }
            
            // Try to parse error response
            if (xhr.responseJSON) {
                console.error('=== Response JSON (from responseJSON) ===');
                console.error(JSON.stringify(xhr.responseJSON, null, 2));
                
                errorMsg = xhr.responseJSON.message || errorMsg;
                if (xhr.responseJSON.error_details) {
                    console.error('Error details:', xhr.responseJSON.error_details);
                    fullErrorDetails += '\n\nDetails: ' + xhr.responseJSON.error_details;
                }
                if (xhr.responseJSON.sql_error) {
                    console.error('SQL error:', xhr.responseJSON.sql_error);
                    fullErrorDetails += '\n\nSQL Error: ' + xhr.responseJSON.sql_error;
                }
                if (xhr.responseJSON.debug_log) {
                    console.error('=== DEBUG LOG (from responseJSON) ===');
                    xhr.responseJSON.debug_log.forEach(line => console.error(line));
                    fullErrorDetails += '\n\nCheck console for full debug log';
                }
            } else if (xhr.responseText) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    console.error('=== Parsed Response (from responseText) ===');
                    console.error(JSON.stringify(response, null, 2));
                    
                    errorMsg = response.message || errorMsg;
                    if (response.error_details) {
                        console.error('Error details:', response.error_details);
                        fullErrorDetails += '\n\nDetails: ' + response.error_details;
                    }
                    if (response.sql_error) {
                        console.error('SQL error:', response.sql_error);
                        fullErrorDetails += '\n\nSQL Error: ' + response.sql_error;
                    }
                    if (response.debug_log) {
                        console.error('=== DEBUG LOG (from responseText) ===');
                        response.debug_log.forEach(line => console.error(line));
                        fullErrorDetails += '\n\nCheck console for full debug log';
                    }
                } catch (e) {
                    // Not JSON, might be HTML error page or PHP fatal error
                    console.error('=== Failed to parse as JSON ===');
                    console.error('Parse error:', e);
                    console.error('=== Full Response Text ===');
                    console.error(xhr.responseText);
                    
                    // Try to extract PHP error from HTML
                    const fatalMatch = xhr.responseText.match(/Fatal error[^<]+/i);
                    const warningMatch = xhr.responseText.match(/Warning[^<]+/i);
                    const parseMatch = xhr.responseText.match(/Parse error[^<]+/i);
                    
                    if (fatalMatch) {
                        errorMsg = 'PHP Fatal Error: ' + fatalMatch[0].trim();
                    } else if (parseMatch) {
                        errorMsg = 'PHP Parse Error: ' + parseMatch[0].trim();
                    } else if (warningMatch) {
                        errorMsg = 'PHP Warning: ' + warningMatch[0].trim();
                    } else {
                        errorMsg = 'Server returned non-JSON response. Check console for details.';
                    }
                }
            }
            
            alert('Error: ' + errorMsg + fullErrorDetails);
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    });
});

// Allow Enter key to calculate
['preview_donor', 'preview_template', 'preview_start_date', 'preview_payment_day', 
 'custom_frequency_number', 'custom_total_payments', 'custom_payment_day'].forEach(id => {
    const element = document.getElementById(id);
    if (element) {
        element.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                calculatePaymentSchedule();
            }
        });
    }
});

// Donor Search/Filter Functionality - Now integrated with applyDonorFilters

// Store all donors data for filtering
let allDonorsData = [];
const donorSelect = document.getElementById('preview_donor');
if (donorSelect) {
    const options = donorSelect.querySelectorAll('option:not([value=""])');
    options.forEach(option => {
        allDonorsData.push({
            element: option,
            balance: parseFloat(option.getAttribute('data-balance') || 0),
            paymentMethod: option.getAttribute('data-payment-method') || '',
            paymentStatus: option.getAttribute('data-payment-status') || 'not_started'
        });
    });
}

// Toggle filter panel
document.getElementById('toggle_filter_btn').addEventListener('click', function() {
    const panel = document.getElementById('donor_filter_panel');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        this.innerHTML = '<i class="fas fa-filter me-1"></i>Hide Filter';
        this.classList.add('active');
    } else {
        panel.style.display = 'none';
        this.innerHTML = '<i class="fas fa-filter me-1"></i>Filter';
        this.classList.remove('active');
    }
});

// Apply filters
function applyDonorFilters() {
    const paymentMethod = document.getElementById('filter_payment_method').value;
    const paymentStatus = document.getElementById('filter_payment_status').value;
    const balanceMin = parseFloat(document.getElementById('filter_balance_min').value) || 0;
    const balanceMax = parseFloat(document.getElementById('filter_balance_max').value) || Infinity;
    const searchTerm = document.getElementById('donor_search').value.toLowerCase().trim();
    
    let visibleCount = 0;
    
    allDonorsData.forEach(donor => {
        const option = donor.element;
        let show = true;
        
        // Payment method filter
        if (paymentMethod && donor.paymentMethod !== paymentMethod) {
            show = false;
        }
        
        // Payment status filter
        if (paymentStatus && donor.paymentStatus !== paymentStatus) {
            show = false;
        }
        
        // Balance range filter
        if (donor.balance < balanceMin || donor.balance > balanceMax) {
            show = false;
        }
        
        // Name search filter
        if (searchTerm) {
            const searchText = option.getAttribute('data-search-text') || '';
            if (!searchText.includes(searchTerm)) {
                show = false;
            }
        }
        
        if (show) {
            option.style.display = '';
            visibleCount++;
        } else {
            option.style.display = 'none';
        }
    });
    
    // Update count
    const countElement = document.getElementById('donor_filter_count');
    const totalDonors = allDonorsData.length;
    
    if (visibleCount === 0) {
        countElement.textContent = 'No donors match the filters';
        countElement.className = 'text-danger';
    } else if (visibleCount === totalDonors && !paymentMethod && !paymentStatus && balanceMin === 0 && balanceMax === Infinity && !searchTerm) {
        countElement.textContent = `${totalDonors} pledge donor${totalDonors !== 1 ? 's' : ''} available`;
        countElement.className = 'text-muted';
    } else {
        countElement.textContent = `${visibleCount} of ${totalDonors} pledge donor${totalDonors !== 1 ? 's' : ''} found`;
        countElement.className = 'text-success';
    }
    
    // Expand dropdown if filters are active
    if (paymentMethod || paymentStatus || balanceMin > 0 || balanceMax < Infinity) {
        donorSelect.size = 6;
    }
}

// Reset filters
function resetDonorFilters() {
    document.getElementById('filter_payment_method').value = '';
    document.getElementById('filter_payment_status').value = '';
    document.getElementById('filter_balance_min').value = '';
    document.getElementById('filter_balance_max').value = '';
    document.getElementById('donor_search').value = '';
    
    // Show all donors
    allDonorsData.forEach(donor => {
        donor.element.style.display = '';
    });
    
    // Reset count
    const totalDonors = allDonorsData.length;
    document.getElementById('donor_filter_count').textContent = `${totalDonors} pledge donor${totalDonors !== 1 ? 's' : ''} available`;
    document.getElementById('donor_filter_count').className = 'text-muted';
}

// Event listeners for filters
document.getElementById('apply_filter_btn').addEventListener('click', applyDonorFilters);
document.getElementById('reset_filter_btn').addEventListener('click', resetDonorFilters);

// Allow Enter key in filter inputs
['filter_payment_method', 'filter_payment_status', 'filter_balance_min', 'filter_balance_max'].forEach(id => {
    const element = document.getElementById(id);
    if (element) {
        element.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyDonorFilters();
            }
        });
    }
});

// Setup donor search
document.getElementById('donor_search').addEventListener('input', function() {
    // When searching, combine with active filters
    applyDonorFilters();
});

// Handle donor selection - hide other options when one is selected
document.getElementById('preview_donor').addEventListener('change', function() {
    const donorSelect = this;
    const selectedValue = donorSelect.value;
    const existingPlanInfo = document.getElementById('existing_plan_info');
    
    if (selectedValue) {
        const selectedOption = donorSelect.options[donorSelect.selectedIndex];
        const hasPlan = selectedOption.getAttribute('data-has-plan') === '1';
        const planDataStr = selectedOption.getAttribute('data-plan-data');
        
        // Show/hide existing plan info
        if (hasPlan && planDataStr) {
            try {
                const planData = JSON.parse(planDataStr);
                
                // Populate existing plan info card (left side)
                document.getElementById('existing_plan_type').textContent = planData.template_name || 'Custom Plan';
                document.getElementById('existing_plan_monthly').textContent = '£' + parseFloat(planData.monthly_amount || 0).toFixed(2);
                document.getElementById('existing_plan_start').textContent = planData.start_date || '-';
                document.getElementById('existing_plan_next_due').textContent = planData.next_due || '-';
                document.getElementById('existing_plan_payments').textContent = 
                    (planData.payments_made || 0) + ' / ' + (planData.total_payments || 0);
                document.getElementById('existing_plan_paid').textContent = 
                    '£' + parseFloat(planData.amount_paid || 0).toFixed(2);
                
                existingPlanInfo.style.display = 'block';
                
                // Store plan_id for clearing
                existingPlanInfo.setAttribute('data-plan-id', planData.plan_id || '');
                existingPlanInfo.setAttribute('data-donor-id', selectedValue);
                
                // Disable template selection until plan is cleared
                document.getElementById('preview_template').disabled = true;
                
                // Show existing plan in Plan Preview (right side)
                displayExistingPlanPreview(planData);
                
            } catch (e) {
                console.error('Error parsing plan data:', e);
                existingPlanInfo.style.display = 'none';
                document.getElementById('preview_template').disabled = false;
            }
        } else {
            existingPlanInfo.style.display = 'none';
            document.getElementById('preview_template').disabled = false;
            
            // Hide plan preview if no plan exists
            document.getElementById('planPreviewResults').style.display = 'none';
            document.getElementById('planPreviewEmpty').style.display = 'block';
        }
        
        // Hide all other options except the selected one and placeholder
        const options = donorSelect.querySelectorAll('option:not([value=""])');
        options.forEach(option => {
            if (option.value === selectedValue) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
            }
        });
        
        // Reduce dropdown size to show only selected option + placeholder
        donorSelect.size = 2;
        
        // Update count
        document.getElementById('donor_filter_count').textContent = '1 donor selected';
        document.getElementById('donor_filter_count').className = 'text-success';
        
        // Clear search input
        document.getElementById('donor_search').value = '';
        } else {
            // Reset: show all options when "Choose a donor..." is selected
            existingPlanInfo.style.display = 'none';
            document.getElementById('preview_template').disabled = false;
            
            // Hide plan preview
            document.getElementById('planPreviewResults').style.display = 'none';
            document.getElementById('planPreviewEmpty').style.display = 'block';
            
            const options = donorSelect.querySelectorAll('option:not([value=""])');
            options.forEach(option => {
                option.style.display = '';
            });
            
            // Restore dropdown size for selection
            donorSelect.size = 6;
            
            // Reset count
            const totalDonors = options.length;
            document.getElementById('donor_filter_count').textContent = `${totalDonors} donor${totalDonors !== 1 ? 's' : ''} available`;
            document.getElementById('donor_filter_count').className = 'text-muted';
            
            // Focus search for new selection
            setTimeout(() => {
                document.getElementById('donor_search').focus();
            }, 100);
        }
});

// Clear search button
document.getElementById('clear_donor_search').addEventListener('click', function() {
    document.getElementById('donor_search').value = '';
    applyDonorFilters();
    document.getElementById('donor_search').focus();
});

// Reset modal when opened
$('#previewPlanModal').on('show.bs.modal', function() {
    // Reset form
    document.getElementById('preview_donor').value = '';
    document.getElementById('preview_template').value = '';
    document.getElementById('preview_start_date').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('preview_payment_day').value = '1';
    
    // Reset custom plan options
    document.getElementById('custom_plan_options').style.display = 'none';
    document.getElementById('standard_date_options').style.display = 'flex';
    document.getElementById('custom_frequency_number').value = '1';
    document.getElementById('custom_frequency_unit').value = 'month';
    document.getElementById('custom_total_payments').value = '1';
    document.getElementById('custom_payment_day_type').value = 'day_of_month';
    document.getElementById('custom_payment_day').value = '1';
    document.getElementById('custom_payment_day').style.display = 'block';
    document.getElementById('preview_payment_day').required = true;
    
    // Reset filters
    resetDonorFilters();
    
    // Hide filter panel
    document.getElementById('donor_filter_panel').style.display = 'none';
    document.getElementById('toggle_filter_btn').innerHTML = '<i class="fas fa-filter me-1"></i>Filter';
    document.getElementById('toggle_filter_btn').classList.remove('active');
    
    // Hide results
    document.getElementById('planPreviewResults').style.display = 'none';
    document.getElementById('planPreviewEmpty').style.display = 'block';
    
    // Hide match alert
    document.getElementById('preview_plan_match').style.display = 'none';
    
    // Hide save button
    document.getElementById('savePlanBtn').style.display = 'none';
    
    // Reset plan data
    currentPlanData = null;
    
    // Hide existing plan info
    document.getElementById('existing_plan_info').style.display = 'none';
    document.getElementById('preview_template').disabled = false;
    
    // Focus on search input
    setTimeout(() => {
        document.getElementById('donor_search').focus();
    }, 300);
});

// Clear Plan button
document.getElementById('clearPlanBtn').addEventListener('click', function() {
    const existingPlanInfo = document.getElementById('existing_plan_info');
    const donorId = existingPlanInfo.getAttribute('data-donor-id');
    const planId = existingPlanInfo.getAttribute('data-plan-id');
    
    if (!donorId || donorId <= 0) {
        alert('Invalid donor ID');
        return;
    }
    
    if (!confirm('Are you sure you want to clear the existing payment plan?\n\nThis will cancel the current plan and allow you to assign a new one. This action cannot be undone.')) {
        return;
    }
    
    // Show loading state
    const btn = this;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Clearing...';
    
    // Submit via AJAX
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'clear_payment_plan',
            csrf_token: getCSRFToken(),
            donor_id: donorId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Hide existing plan info
                existingPlanInfo.style.display = 'none';
                
                // Enable template selection
                document.getElementById('preview_template').disabled = false;
                
                // Reset donor selection to trigger reload
                const donorSelect = document.getElementById('preview_donor');
                const currentValue = donorSelect.value;
                donorSelect.value = '';
                
                // Reload page to refresh donor list
                setTimeout(() => {
                    location.reload();
                }, 500);
            } else {
                alert('Error: ' + (response.message || 'Failed to clear payment plan'));
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        },
        error: function(xhr, status, error) {
            console.error('Clear error:', error);
            alert('Server error. Please try again.');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    });
});
</script>
</body>
</html>
