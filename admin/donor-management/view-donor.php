<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_login();
require_admin();

// Resiliently load settings and check for DB errors
require_once __DIR__ . '/../includes/resilient_db_loader.php';

$db = db();
$donor_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$donor_id) {
    header('Location: donors.php');
    exit;
}

// Get current user's phone for Twilio calling
$user_id = (int)$_SESSION['user']['id'];
$user_phone_query = "SELECT phone, phone_number FROM users WHERE id = ?";
$user_stmt = $db->prepare($user_phone_query);
$user_stmt->bind_param('i', $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_data = $user_result->fetch_assoc();
$user_stmt->close();
$agent_phone = $user_data['phone_number'] ?? $user_data['phone'] ?? '';

// Handle Financial Summary Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_financials') {
    require_once __DIR__ . '/../../shared/csrf.php';
    verify_csrf();
    
    try {
        $db->begin_transaction();
        
        // Get current donor data for audit
        $current_stmt = $db->prepare("SELECT total_pledged, total_paid, balance, payment_status FROM donors WHERE id = ?");
        $current_stmt->bind_param('i', $donor_id);
        $current_stmt->execute();
        $current_data = $current_stmt->get_result()->fetch_assoc();
        $current_stmt->close();
        
        if (!$current_data) {
            throw new Exception("Donor not found");
        }
        
        $update_mode = $_POST['update_mode'] ?? 'manual';
        $new_total_pledged = 0.0;
        $new_total_paid = 0.0;
        $new_balance = 0.0;
        
        if ($update_mode === 'recalculate') {
            // Recalculate from actual database records
            
            // Get donor phone for payment lookup
            $phone_stmt = $db->prepare("SELECT phone FROM donors WHERE id = ?");
            $phone_stmt->bind_param('i', $donor_id);
            $phone_stmt->execute();
            $phone_result = $phone_stmt->get_result()->fetch_assoc();
            $donor_phone = $phone_result['phone'] ?? '';
            $phone_stmt->close();
            
            // Calculate total_pledged from approved pledges
            $pledge_sum_stmt = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) as total 
                FROM pledges 
                WHERE donor_id = ? AND status = 'approved'
            ");
            $pledge_sum_stmt->bind_param('i', $donor_id);
            $pledge_sum_stmt->execute();
            $pledge_sum = $pledge_sum_stmt->get_result()->fetch_assoc();
            $new_total_pledged = (float)($pledge_sum['total'] ?? 0);
            $pledge_sum_stmt->close();
            
            // Calculate total_paid from:
            // 1. Approved payments (from payments table)
            // 2. Confirmed pledge_payments (from pledge_payments table)
            
            // From payments table (instant payments)
            $payment_sum_stmt = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) as total 
                FROM payments 
                WHERE donor_phone = ? AND status = 'approved'
            ");
            $payment_sum_stmt->bind_param('s', $donor_phone);
            $payment_sum_stmt->execute();
            $payment_sum = $payment_sum_stmt->get_result()->fetch_assoc();
            $payments_total = (float)($payment_sum['total'] ?? 0);
            $payment_sum_stmt->close();
            
            // From pledge_payments table
            $pledge_payment_sum_stmt = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) as total 
                FROM pledge_payments 
                WHERE donor_id = ? AND status = 'confirmed'
            ");
            $pledge_payment_sum_stmt->bind_param('i', $donor_id);
            $pledge_payment_sum_stmt->execute();
            $pledge_payment_sum = $pledge_payment_sum_stmt->get_result()->fetch_assoc();
            $pledge_payments_total = (float)($pledge_payment_sum['total'] ?? 0);
            $pledge_payment_sum_stmt->close();
            
            $new_total_paid = $payments_total + $pledge_payments_total;
            $new_balance = max(0, $new_total_pledged - $new_total_paid);
            
        } else {
            // Manual update with validation
            $new_total_pledged = isset($_POST['total_pledged']) ? (float)$_POST['total_pledged'] : (float)$current_data['total_pledged'];
            $new_total_paid = isset($_POST['total_paid']) ? (float)$_POST['total_paid'] : (float)$current_data['total_paid'];
            $new_balance = isset($_POST['balance']) ? (float)$_POST['balance'] : max(0, $new_total_pledged - $new_total_paid);
            
            // Validate amounts
            if ($new_total_pledged < 0) {
                throw new Exception("Total pledged cannot be negative");
            }
            if ($new_total_paid < 0) {
                throw new Exception("Total paid cannot be negative");
            }
            if ($new_balance < 0) {
                throw new Exception("Balance cannot be negative");
            }
        }
        
        // Determine payment status
        $new_payment_status = $_POST['payment_status'] ?? 'not_started';
        $valid_statuses = ['no_pledge', 'not_started', 'paying', 'overdue', 'completed', 'defaulted'];
        if (!in_array($new_payment_status, $valid_statuses)) {
            $new_payment_status = 'not_started';
        }
        
        // Auto-determine payment status if not manually set
        if ($update_mode === 'recalculate' || $_POST['auto_status'] ?? false) {
            if ($new_total_pledged == 0 && $new_total_paid == 0) {
                $new_payment_status = 'no_pledge';
            } elseif ($new_total_paid == 0) {
                $new_payment_status = 'not_started';
            } elseif ($new_total_paid >= $new_total_pledged && $new_total_pledged > 0) {
                $new_payment_status = 'completed';
            } elseif ($new_total_paid > 0) {
                $new_payment_status = 'paying';
            }
        }
        
        // Update donor record
        $update_stmt = $db->prepare("
            UPDATE donors SET
                total_pledged = ?,
                total_paid = ?,
                balance = ?,
                payment_status = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->bind_param('dddsi', $new_total_pledged, $new_total_paid, $new_balance, $new_payment_status, $donor_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update donor: " . $update_stmt->error);
        }
        $update_stmt->close();
        
        // Audit log
        log_audit(
            $db,
            'update_financials',
            'donor',
            $donor_id,
            [
                'total_pledged' => (float)$current_data['total_pledged'],
                'total_paid' => (float)$current_data['total_paid'],
                'balance' => (float)$current_data['balance'],
                'payment_status' => $current_data['payment_status']
            ],
            [
                'total_pledged' => $new_total_pledged,
                'total_paid' => $new_total_paid,
                'balance' => $new_balance,
                'payment_status' => $new_payment_status,
                'update_mode' => $update_mode
            ],
            'admin_portal',
            (int)($_SESSION['user']['id'] ?? 0)
        );
        
        $db->commit();
        
        header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode('Financial summary updated successfully'));
        exit;
        
    } catch (Exception $e) {
        $db->rollback();
        header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Error: ' . $e->getMessage()));
        exit;
    }
}

// Handle System Information Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_system_info') {
    require_once __DIR__ . '/../../shared/csrf.php';
    verify_csrf();
    
    try {
        $db->begin_transaction();
        
        // Get current donor data for audit
        $current_stmt = $db->prepare("SELECT login_count, portal_token, last_login_at, token_expires_at, token_generated_at FROM donors WHERE id = ?");
        $current_stmt->bind_param('i', $donor_id);
        $current_stmt->execute();
        $current_data = $current_stmt->get_result()->fetch_assoc();
        $current_stmt->close();
        
        if (!$current_data) {
            throw new Exception("Donor not found");
        }
        
        // Prepare update fields
        $updates = [];
        $params = [];
        $types = '';
        
        // Login Count
        if (isset($_POST['login_count']) && $_POST['login_count'] !== '') {
            $login_count = (int)$_POST['login_count'];
            if ($login_count >= 0) {
                $updates[] = "login_count = ?";
                $params[] = $login_count;
                $types .= 'i';
            }
        }
        
        // Portal Token
        if (isset($_POST['portal_token'])) {
            $portal_token = trim($_POST['portal_token']);
            if ($portal_token === '') {
                $portal_token = null;
            }
            $updates[] = "portal_token = ?";
            $params[] = $portal_token;
            $types .= 's';
        }
        
        // Helper function to convert datetime-local format to MySQL datetime
        $convertDateTime = function($value) {
            if (empty(trim($value))) {
                return null;
            }
            // Convert "YYYY-MM-DDTHH:mm" to "YYYY-MM-DD HH:mm:00"
            return str_replace('T', ' ', $value) . ':00';
        };
        
        // Last Login At
        if (isset($_POST['last_login_at'])) {
            $last_login_at = $convertDateTime($_POST['last_login_at']);
            $updates[] = "last_login_at = ?";
            $params[] = $last_login_at;
            $types .= 's';
        }
        
        // Token Expires At
        if (isset($_POST['token_expires_at'])) {
            $token_expires_at = $convertDateTime($_POST['token_expires_at']);
            $updates[] = "token_expires_at = ?";
            $params[] = $token_expires_at;
            $types .= 's';
        }
        
        // Token Generated At
        if (isset($_POST['token_generated_at'])) {
            $token_generated_at = $convertDateTime($_POST['token_generated_at']);
            $updates[] = "token_generated_at = ?";
            $params[] = $token_generated_at;
            $types .= 's';
        }
        
        if (empty($updates)) {
            throw new Exception("No fields to update");
        }
        
        // Add updated_at
        $updates[] = "updated_at = NOW()";
        
        // Add donor_id to params
        $params[] = $donor_id;
        $types .= 'i';
        
        // Build and execute update query
        $update_query = "UPDATE donors SET " . implode(', ', $updates) . " WHERE id = ?";
        $update_stmt = $db->prepare($update_query);
        
        if (!$update_stmt) {
            throw new Exception("Failed to prepare update statement: " . $db->error);
        }
        
        $update_stmt->bind_param($types, ...$params);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update donor: " . $update_stmt->error);
        }
        $update_stmt->close();
        
        // Audit log
        $new_data = [];
        if (isset($login_count)) $new_data['login_count'] = $login_count;
        if (isset($portal_token)) $new_data['portal_token'] = $portal_token;
        if (isset($last_login_at)) $new_data['last_login_at'] = $last_login_at;
        if (isset($token_expires_at)) $new_data['token_expires_at'] = $token_expires_at;
        if (isset($token_generated_at)) $new_data['token_generated_at'] = $token_generated_at;
        
        log_audit(
            $db,
            'update_system_info',
            'donor',
            $donor_id,
            $current_data,
            $new_data,
            'admin_portal',
            (int)($_SESSION['user']['id'] ?? 0)
        );
        
        $db->commit();
        
        header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode('System information updated successfully'));
        exit;
        
    } catch (Exception $e) {
        $db->rollback();
        header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Error: ' . $e->getMessage()));
        exit;
    }
}

// Handle Trusted Device Revocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revoke_device') {
    require_once __DIR__ . '/../../shared/csrf.php';
    verify_csrf();
    
    try {
        $device_id = (int)($_POST['device_id'] ?? 0);
        if ($device_id <= 0) {
            throw new Exception("Invalid device ID");
        }
        
        // Verify device belongs to this donor
        $check = $db->prepare("SELECT id, device_token, donor_id FROM donor_trusted_devices WHERE id = ? AND donor_id = ?");
        $check->bind_param('ii', $device_id, $donor_id);
        $check->execute();
        $device = $check->get_result()->fetch_assoc();
        $check->close();
        
        if (!$device) {
            throw new Exception("Device not found or doesn't belong to this donor");
        }
        
        // Revoke the device
        $revoke = $db->prepare("UPDATE donor_trusted_devices SET is_active = 0 WHERE id = ?");
        $revoke->bind_param('i', $device_id);
        $revoke->execute();
        $revoke->close();
        
        // Audit log
        log_audit(
            $db,
            'revoke_device',
            'donor_trusted_device',
            $device_id,
            ['device_token' => substr($device['device_token'], 0, 8) . '...', 'donor_id' => $donor_id],
            null,
            'admin_portal',
            (int)($_SESSION['user']['id'] ?? 0)
        );
        
        header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode('Device revoked successfully'));
        exit;
        
    } catch (Exception $e) {
        header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Error: ' . $e->getMessage()));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_device') {
    require_once __DIR__ . '/../../shared/csrf.php';
    verify_csrf();
    
    try {
        $device_id = (int)($_POST['device_id'] ?? 0);
        if ($device_id <= 0) {
            throw new Exception("Invalid device ID");
        }
        
        // Verify device belongs to this donor and get full device data for audit
        $check = $db->prepare("SELECT id, device_token, donor_id, device_name, ip_address, is_active, created_at, expires_at, last_used_at FROM donor_trusted_devices WHERE id = ? AND donor_id = ?");
        $check->bind_param('ii', $device_id, $donor_id);
        $check->execute();
        $device = $check->get_result()->fetch_assoc();
        $check->close();
        
        if (!$device) {
            throw new Exception("Device not found or doesn't belong to this donor");
        }
        
        // Prepare device data for audit (before deletion)
        $beforeData = [
            'device_token' => substr($device['device_token'], 0, 8) . '...',
            'donor_id' => $device['donor_id'],
            'device_name' => $device['device_name'] ?? null,
            'ip_address' => $device['ip_address'] ?? null,
            'is_active' => (bool)$device['is_active'],
            'created_at' => $device['created_at'],
            'expires_at' => $device['expires_at'],
            'last_used_at' => $device['last_used_at'] ?? null,
        ];
        
        // Delete the device
        $delete = $db->prepare("DELETE FROM donor_trusted_devices WHERE id = ?");
        $delete->bind_param('i', $device_id);
        $delete->execute();
        $delete->close();
        
        // Audit log
        log_audit(
            $db,
            'delete',
            'donor_trusted_device',
            $device_id,
            $beforeData,
            null,
            'admin_portal',
            (int)($_SESSION['user']['id'] ?? 0)
        );
        
        header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode('Device deleted successfully'));
        exit;
        
    } catch (Exception $e) {
        header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Error: ' . $e->getMessage()));
        exit;
    }
}

$page_title = 'Donor Profile';

// --- FETCH DATA ---
try {
    // 1. Donor Details
    // Check if representative_id column exists
    $check_rep_col = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
    $has_rep_col = $check_rep_col && $check_rep_col->num_rows > 0;
    
    $donor_query = "
        SELECT 
            d.*,
            u.name as registrar_name,
            c.name as church_name
        FROM donors d
        LEFT JOIN users u ON d.registered_by_user_id = u.id
        LEFT JOIN churches c ON d.church_id = c.id
        WHERE d.id = ?
    ";
    $stmt = $db->prepare($donor_query);
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $donor = $stmt->get_result()->fetch_assoc();

    if (!$donor) {
        die("Donor not found.");
    }

    // 2. Pledges & Grid Cells
    $pledges = [];
    $pledge_query = "
        SELECT p.*, u.name as registrar_name 
        FROM pledges p 
        LEFT JOIN users u ON p.created_by_user_id = u.id
        WHERE p.donor_id = ? 
        ORDER BY p.created_at DESC
    ";
    $stmt = $db->prepare($pledge_query);
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $pledge_result = $stmt->get_result();

    // Check if floor_grid_cells table exists
    $grid_table_exists = $db->query("SHOW TABLES LIKE 'floor_grid_cells'")->num_rows > 0;

    while ($p = $pledge_result->fetch_assoc()) {
        $cells = [];
        if ($grid_table_exists) {
            $cell_query = "SELECT * FROM floor_grid_cells WHERE pledge_id = ?";
            $c_stmt = $db->prepare($cell_query);
            if ($c_stmt) {
                $c_stmt->bind_param('i', $p['id']);
                $c_stmt->execute();
                $c_result = $c_stmt->get_result();
                while ($cell = $c_result->fetch_assoc()) {
                    $cells[] = $cell;
                }
            }
        }
        $p['allocated_cells'] = $cells;
        $pledges[] = $p;
    }
    
    // Extract 4-digit reference number from pledge notes
    $donor_reference = null;
    if (!empty($pledges)) {
        // Get the most recent pledge's notes
        $most_recent_pledge = $pledges[0];
        if (!empty($most_recent_pledge['notes'])) {
            // Look for a 4-digit number in the notes
            // Pattern: could be "REF: 1234", "Reference: 1234", "1234", or similar
            if (preg_match('/\b(\d{4})\b/', $most_recent_pledge['notes'], $matches)) {
                $donor_reference = $matches[1];
            }
        }
    }
    // Fallback to donor ID if no reference found
    if (!$donor_reference) {
        $donor_reference = str_pad((string)$donor['id'], 4, '0', STR_PAD_LEFT);
    }

    // 3. Payments (includes both instant payments and pledge payments)
    $payments = [];
    
    // Check if pledge_payments table exists
    $has_pledge_payments = $db->query("SHOW TABLES LIKE 'pledge_payments'")->num_rows > 0;
    
    // Check payments table columns first to handle schema variations
    $payment_columns = [];
    $col_query = $db->query("SHOW COLUMNS FROM payments");
    while ($col = $col_query->fetch_assoc()) {
        $payment_columns[] = $col['Field'];
    }

    $approver_col = in_array('approved_by_user_id', $payment_columns) ? 'approved_by_user_id' : 
                   (in_array('received_by_user_id', $payment_columns) ? 'received_by_user_id' : 'id'); // Fallback to something valid

    $date_col = in_array('payment_date', $payment_columns) ? 'payment_date' : 
               (in_array('received_at', $payment_columns) ? 'received_at' : 'created_at');

    $method_col = in_array('payment_method', $payment_columns) ? 'payment_method' : 'method';
    $ref_col = in_array('transaction_ref', $payment_columns) ? 'transaction_ref' : 'reference';

    if ($has_pledge_payments) {
        // UNION query to get both instant payments and pledge payments
        $payment_query = "
            SELECT 
                pay.id,
                pay.{$date_col} as payment_date,
                pay.amount,
                pay.{$method_col} as payment_method,
                pay.{$ref_col} as reference,
                pay.status,
                u.name as approver_name,
                'instant' as payment_type,
                NULL as pledge_id,
                pay.{$date_col} as sort_date
            FROM payments pay
            LEFT JOIN users u ON pay.{$approver_col} = u.id
            WHERE pay.donor_phone = ?
            
            UNION ALL
            
            SELECT 
                pp.id,
                pp.payment_date,
                pp.amount,
                pp.payment_method,
                pp.reference_number as reference,
                pp.status,
                approver.name as approver_name,
                'pledge' as payment_type,
                pp.pledge_id,
                pp.payment_date as sort_date
            FROM pledge_payments pp
            LEFT JOIN users approver ON pp.approved_by_user_id = approver.id
            WHERE pp.donor_id = ?
            
            ORDER BY sort_date DESC
        ";
        $stmt = $db->prepare($payment_query);
        $stmt->bind_param('si', $donor['phone'], $donor_id);
        $stmt->execute();
        $payment_result = $stmt->get_result();
        while ($pay = $payment_result->fetch_assoc()) {
            // Normalize keys for display
            $pay['display_date'] = $pay['payment_date'];
            $pay['display_method'] = $pay['payment_method'];
            $pay['display_ref'] = $pay['reference'];
            $payments[] = $pay;
        }
    } else {
        // Fallback: Only instant payments
        $payment_query = "
            SELECT pay.*, u.name as approver_name 
            FROM payments pay
            LEFT JOIN users u ON pay.{$approver_col} = u.id
            WHERE pay.donor_phone = ? 
            ORDER BY pay.{$date_col} DESC
        ";
        $stmt = $db->prepare($payment_query);
        $stmt->bind_param('s', $donor['phone']);
        $stmt->execute();
        $payment_result = $stmt->get_result();
        while ($pay = $payment_result->fetch_assoc()) {
            // Normalize keys for display
            $pay['display_date'] = $pay[$date_col];
            $pay['display_method'] = $pay[$method_col];
            $pay['display_ref'] = $pay[$ref_col];
            $pay['payment_type'] = 'instant';
            $payments[] = $pay;
        }
    }

    // 4. Payment Plans
    $plans = [];
    $plan_query = "
        SELECT pp.*, t.name as template_name 
        FROM donor_payment_plans pp
        LEFT JOIN payment_plan_templates t ON pp.template_id = t.id
        WHERE pp.donor_id = ? 
        ORDER BY pp.created_at DESC
    ";
    // Only run if donor_payment_plans exists
    if ($db->query("SHOW TABLES LIKE 'donor_payment_plans'")->num_rows > 0) {
        $stmt = $db->prepare($plan_query);
        if ($stmt) {
            $stmt->bind_param('i', $donor_id);
            $stmt->execute();
            $plan_result = $stmt->get_result();
            while ($plan = $plan_result->fetch_assoc()) {
                $plans[] = $plan;
            }
        }
    }

    // 5. Call History
    $calls = [];
    $call_query = "
        SELECT cs.*, u.name as agent_name 
        FROM call_center_sessions cs
        LEFT JOIN users u ON cs.agent_id = u.id
        WHERE cs.donor_id = ? 
        ORDER BY cs.call_started_at DESC
    ";
    // Only run if call_center_sessions exists
    if ($db->query("SHOW TABLES LIKE 'call_center_sessions'")->num_rows > 0) {
        $stmt = $db->prepare($call_query);
        if ($stmt) {
            $stmt->bind_param('i', $donor_id);
            $stmt->execute();
            $call_result = $stmt->get_result();
            while ($call = $call_result->fetch_assoc()) {
                $calls[] = $call;
            }
        }
    }

    // 6. Trusted Devices
    $trusted_devices = [];
    $device_table_exists = $db->query("SHOW TABLES LIKE 'donor_trusted_devices'")->num_rows > 0;
    if ($device_table_exists) {
        $device_query = "
            SELECT td.*
            FROM donor_trusted_devices td
            WHERE td.donor_id = ?
            ORDER BY td.created_at DESC
        ";
        $stmt = $db->prepare($device_query);
        if ($stmt) {
            $stmt->bind_param('i', $donor_id);
            $stmt->execute();
            $device_result = $stmt->get_result();
            while ($device = $device_result->fetch_assoc()) {
                $trusted_devices[] = $device;
            }
            $stmt->close();
        }
    }

    // 7. Assignment Info (Church, Representative & Agent)
    $assignment = [
        'church_id' => $donor['church_id'] ?? null,
        'church_name' => $donor['church_name'] ?? null,
        'representative_id' => null,
        'representative_name' => null,
        'representative_role' => null,
        'representative_phone' => null,
        'agent_id' => $donor['agent_id'] ?? null,
        'agent_name' => null
    ];
    
    // Check if representative_id column exists
    $check_rep_column = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
    $has_rep_column = $check_rep_column && $check_rep_column->num_rows > 0;
    
    if ($has_rep_column && !empty($donor['representative_id'])) {
        $rep_query = "
            SELECT id, name, role, phone 
            FROM church_representatives 
            WHERE id = ?
        ";
        $rep_stmt = $db->prepare($rep_query);
        if ($rep_stmt) {
            $rep_stmt->bind_param('i', $donor['representative_id']);
            $rep_stmt->execute();
            $rep_result = $rep_stmt->get_result()->fetch_assoc();
            if ($rep_result) {
                $assignment['representative_id'] = $rep_result['id'];
                $assignment['representative_name'] = $rep_result['name'];
                $assignment['representative_role'] = $rep_result['role'];
                $assignment['representative_phone'] = $rep_result['phone'];
            }
        }
    }
    
    // Fetch agent information if assigned
    if (!empty($assignment['agent_id'])) {
        $agent_query = "SELECT id, name FROM users WHERE id = ?";
        $agent_stmt = $db->prepare($agent_query);
        if ($agent_stmt) {
            $agent_stmt->bind_param('i', $assignment['agent_id']);
            $agent_stmt->execute();
            $agent_result = $agent_stmt->get_result()->fetch_assoc();
            if ($agent_result) {
                $assignment['agent_name'] = $agent_result['name'];
            }
        }
    }

} catch (Exception $e) {
    die("Error loading donor profile: " . $e->getMessage());
}

// Helpers
function formatMoney($amount) {
    return '£' . number_format((float)$amount, 2);
}
function formatDate($date) {
    return $date ? date('M j, Y', strtotime($date)) : '-';
}
/**
 * Parse user agent to get friendly device name
 */
function parseDeviceName(string $userAgent): array {
    $browser = 'Unknown Browser';
    $os = 'Unknown Device';
    $icon = 'fa-desktop';
    
    // Detect browser
    if (preg_match('/Chrome\/[\d.]+/', $userAgent) && !preg_match('/Edg/', $userAgent)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Firefox\/[\d.]+/', $userAgent)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Safari\/[\d.]+/', $userAgent) && !preg_match('/Chrome/', $userAgent)) {
        $browser = 'Safari';
    } elseif (preg_match('/Edg\/[\d.]+/', $userAgent)) {
        $browser = 'Edge';
    } elseif (preg_match('/MSIE|Trident/', $userAgent)) {
        $browser = 'Internet Explorer';
    } elseif (preg_match('/Opera|OPR/', $userAgent)) {
        $browser = 'Opera';
    }
    
    // Detect OS
    if (preg_match('/iPhone/', $userAgent)) {
        $os = 'iPhone';
        $icon = 'fa-mobile-alt';
    } elseif (preg_match('/iPad/', $userAgent)) {
        $os = 'iPad';
        $icon = 'fa-tablet-alt';
    } elseif (preg_match('/Android/', $userAgent)) {
        if (preg_match('/Mobile/', $userAgent)) {
            $os = 'Android Phone';
            $icon = 'fa-mobile-alt';
        } else {
            $os = 'Android Tablet';
            $icon = 'fa-tablet-alt';
        }
    } elseif (preg_match('/Windows/', $userAgent)) {
        $os = 'Windows';
        $icon = 'fa-desktop';
    } elseif (preg_match('/Macintosh/', $userAgent)) {
        $os = 'Mac';
        $icon = 'fa-laptop';
    } elseif (preg_match('/Linux/', $userAgent)) {
        $os = 'Linux';
        $icon = 'fa-desktop';
    }
    
    return [
        'browser' => $browser,
        'os' => $os,
        'icon' => $icon,
        'display' => "$browser on $os"
    ];
}

function formatDateTime($date) {
    if (empty($date) || $date === null) {
        return '-';
    }
    $timestamp = strtotime($date);
    return $timestamp !== false ? date('M j, Y g:i A', $timestamp) : '-';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($donor['name']); ?> - Donor Profile</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        :root {
            --primary-color: #0a6286;
            --secondary-color: #6c757d;
            --accent-color: #e2ca18;
            --danger-color: #ef4444;
            --success-color: #10b981;
            /* Golden theme for completed donors */
            --gold-light: #ffd700;
            --gold-main: #d4af37;
            --gold-dark: #b8860b;
            --gold-shine: #fff8dc;
        }
        
        /* Profile Header - Modern Mobile-First Design */
        .profile-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #075985 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 16px;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 20px rgba(10, 98, 134, 0.25);
        }
        
        /* ========================================
           GOLDEN PREMIUM STYLE - FULLY PAID DONORS
           ======================================== */
        .profile-header.golden-premium {
            background: 
                linear-gradient(135deg, rgba(26, 26, 46, 0.92) 0%, rgba(22, 33, 62, 0.88) 50%, rgba(15, 15, 35, 0.92) 100%),
                url('../../New_Church.png');
            background-size: cover, cover;
            background-position: center, center;
            background-repeat: no-repeat, no-repeat;
            position: relative;
            overflow: hidden;
            box-shadow: 
                0 4px 30px rgba(212, 175, 55, 0.4),
                0 0 60px rgba(255, 215, 0, 0.15),
                inset 0 1px 0 rgba(255, 215, 0, 0.2);
            border: 2px solid rgba(212, 175, 55, 0.4);
        }
        
        /* Animated shimmer effect */
        .profile-header.golden-premium::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                45deg,
                transparent 30%,
                rgba(255, 215, 0, 0.06) 40%,
                rgba(255, 215, 0, 0.12) 50%,
                rgba(255, 215, 0, 0.06) 60%,
                transparent 70%
            );
            animation: goldenShimmer 4s ease-in-out infinite;
            pointer-events: none;
        }
        
        @keyframes goldenShimmer {
            0%, 100% { transform: translateX(-100%) rotate(45deg); }
            50% { transform: translateX(100%) rotate(45deg); }
        }
        
        /* Share button at top right */
        .golden-share-icon {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--gold-light), var(--gold-main));
            border: none;
            color: #1a1a2e;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.5);
            transition: all 0.3s ease;
            z-index: 10;
        }
        
        .golden-share-icon:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 25px rgba(212, 175, 55, 0.7);
        }
        
        .golden-share-icon:active {
            transform: scale(0.95);
        }
        
        /* Golden Avatar */
        .golden-premium .avatar-circle {
            background: linear-gradient(145deg, var(--gold-light), var(--gold-dark));
            border: 3px solid var(--gold-main);
            box-shadow: 
                0 0 25px rgba(255, 215, 0, 0.5),
                0 0 50px rgba(212, 175, 55, 0.3),
                inset 0 2px 4px rgba(255, 255, 255, 0.4);
            color: #1a1a2e;
            animation: goldenGlow 3s ease-in-out infinite;
        }
        
        @keyframes goldenGlow {
            0%, 100% { box-shadow: 0 0 25px rgba(255, 215, 0, 0.5), 0 0 50px rgba(212, 175, 55, 0.3), inset 0 2px 4px rgba(255, 255, 255, 0.4); }
            50% { box-shadow: 0 0 35px rgba(255, 215, 0, 0.7), 0 0 70px rgba(212, 175, 55, 0.5), inset 0 2px 4px rgba(255, 255, 255, 0.4); }
        }
        
        /* Golden Name with gradient */
        .golden-premium .profile-name {
            background: linear-gradient(135deg, var(--gold-light), var(--gold-main), var(--gold-light));
            background-size: 200% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: goldenTextShine 3s linear infinite;
            text-shadow: none;
            font-weight: 800;
        }
        
        @keyframes goldenTextShine {
            0% { background-position: 0% center; }
            100% { background-position: 200% center; }
        }
        
        /* Golden pills and badges */
        .golden-premium .info-pill {
            background: rgba(212, 175, 55, 0.2);
            border: 1px solid rgba(212, 175, 55, 0.4);
            color: var(--gold-light);
        }
        
        .golden-premium .info-pill:hover {
            background: rgba(212, 175, 55, 0.35);
            color: var(--gold-light);
        }
        
        .golden-premium .donor-badge.ref {
            background: linear-gradient(135deg, var(--gold-light), var(--gold-main));
            color: #1a1a2e;
            font-weight: 700;
            box-shadow: 0 2px 10px rgba(212, 175, 55, 0.4);
        }
        
        /* FULLY PAID Premium Badge */
        .fully-paid-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: linear-gradient(135deg, var(--gold-light), var(--gold-main), var(--gold-dark));
            color: #1a1a2e;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 0.75rem;
            box-shadow: 
                0 4px 15px rgba(212, 175, 55, 0.5),
                inset 0 1px 0 rgba(255, 255, 255, 0.5);
            animation: badgePulse 2s ease-in-out infinite;
        }
        
        .fully-paid-badge i {
            font-size: 0.875rem;
        }
        
        @keyframes badgePulse {
            0%, 100% { transform: scale(1); box-shadow: 0 4px 15px rgba(212, 175, 55, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.5); }
            50% { transform: scale(1.02); box-shadow: 0 6px 25px rgba(212, 175, 55, 0.7), inset 0 1px 0 rgba(255, 255, 255, 0.5); }
        }
        
        /* Golden Financial Grid */
        .golden-premium .financial-grid .fin-card {
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
        }
        
        .golden-premium .financial-grid .fin-card:hover {
            background: rgba(212, 175, 55, 0.2);
            border-color: rgba(212, 175, 55, 0.5);
        }
        
        .golden-premium .fin-card.paid .fin-icon {
            background: linear-gradient(135deg, var(--gold-light), var(--gold-main));
            color: #1a1a2e;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.5);
        }
        
        .golden-premium .fin-card.balance .fin-icon {
            background: linear-gradient(135deg, #10b981, #059669);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }
        
        .golden-premium .fin-amount {
            color: var(--gold-light);
        }
        
        .golden-premium .fin-label {
            color: rgba(255, 215, 0, 0.7);
        }
        
        .golden-premium .fin-edit-btn {
            background: rgba(212, 175, 55, 0.3);
            border-color: rgba(212, 175, 55, 0.5);
            color: var(--gold-light);
        }
        
        .golden-premium .fin-edit-btn:hover {
            background: rgba(212, 175, 55, 0.5);
        }
        
        /* Hide elements in golden premium mode */
        .golden-premium .fin-card.balance,
        .golden-premium .fin-edit-btn,
        .golden-premium .info-pill[href^="tel:"],
        .golden-premium .donor-badge.ref {
            display: none !important;
        }
        
        /* Adjust financial grid for 2 columns in golden mode */
        .golden-premium .financial-grid {
            grid-template-columns: repeat(2, 1fr);
            max-width: 320px;
            margin: 0 auto;
        }
        
        /* Confetti animation on load for golden */
        @keyframes confettiFall {
            0% { transform: translateY(-100%) rotate(0deg); opacity: 1; }
            100% { transform: translateY(100vh) rotate(720deg); opacity: 0; }
        }
        
        /* Mobile optimizations for golden */
        @media (max-width: 767.98px) {
            .profile-header.golden-premium {
                padding: 1.5rem 1.25rem;
                padding-top: 3.5rem; /* Space for share icon */
                border-radius: 20px;
                text-align: center;
            }
            
            .golden-share-icon {
                top: 10px;
                right: 10px;
                width: 36px;
                height: 36px;
                font-size: 0.9rem;
            }
            
            .golden-church-name {
                font-size: 0.6rem !important;
                letter-spacing: 1.5px !important;
            }
            
            .fully-paid-badge {
                font-size: 0.7rem;
                padding: 0.5rem 1rem;
            }
            
            .golden-premium .profile-name {
                font-size: 1.5rem;
            }
            
            .golden-message {
                font-size: 0.75rem !important;
                padding: 0 1rem;
            }
            
            .golden-premium .financial-grid {
                max-width: 280px;
                gap: 0.75rem;
            }
            
            .golden-premium .fin-amount {
                font-size: 1.25rem;
            }
        }
        
        /* Print styles for sharing */
        @media print {
            .profile-header.golden-premium {
                background: #1a1a2e !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        /* END GOLDEN PREMIUM STYLES */
        
        .profile-top {
            text-align: center;
            margin-bottom: 1.25rem;
        }
        
        .avatar-circle {
            width: 70px;
            height: 70px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            font-weight: bold;
            margin: 0 auto 0.75rem;
            border: 3px solid rgba(255,255,255,0.3);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .profile-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 0 0.75rem;
            line-height: 1.2;
        }
        
        /* Donor Info Pills */
        .donor-info-pills {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        
        .info-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            background: rgba(255,255,255,0.15);
            border-radius: 50px;
            font-size: 0.8125rem;
            text-decoration: none;
            color: white;
            transition: background 0.2s;
        }
        
        .info-pill:hover {
            background: rgba(255,255,255,0.25);
            color: white;
        }
        
        .info-pill i {
            font-size: 0.75rem;
            opacity: 0.8;
        }
        
        /* Donor Badges */
        .donor-badges {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .donor-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.625rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .donor-badge.ref {
            background: rgba(255,255,255,0.9);
            color: var(--primary-color);
        }
        
        .donor-badge.baptism {
            background: #38bdf8;
            color: white;
        }
        
        /* Financial Grid - Compact 3-Column */
        .financial-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            position: relative;
        }
        
        .fin-card {
            background: rgba(255,255,255,0.12);
            border-radius: 12px;
            padding: 0.75rem 0.5rem;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.15);
            transition: transform 0.2s, background 0.2s;
        }
        
        .fin-card:hover {
            background: rgba(255,255,255,0.18);
            transform: translateY(-2px);
        }
        
        .fin-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-size: 1rem;
        }
        
        .fin-card.pledged .fin-icon {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }
        
        .fin-card.paid .fin-icon {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        
        .fin-card.balance .fin-icon {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        
        .fin-amount {
            font-size: 1.125rem;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 0.125rem;
        }
        
        .fin-label {
            font-size: 0.625rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.75;
        }
        
        .fin-edit-btn {
            position: absolute;
            top: -0.5rem;
            right: -0.5rem;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .fin-edit-btn:hover {
            background: rgba(255,255,255,0.35);
        }
        
        /* Desktop Enhancements */
        @media (min-width: 768px) {
            .profile-header {
                padding: 2rem;
            }
            
            .profile-top {
                display: flex;
                align-items: center;
                text-align: left;
                gap: 1.25rem;
            }
            
            .avatar-circle {
                width: 80px;
                height: 80px;
                font-size: 2rem;
                margin: 0;
                flex-shrink: 0;
            }
            
            .profile-name {
                font-size: 1.75rem;
                margin-bottom: 0.5rem;
            }
            
            .donor-info-pills {
                justify-content: flex-start;
            }
            
            .donor-badges {
                justify-content: flex-start;
            }
            
            .financial-grid {
                margin-top: 1.5rem;
                gap: 0.75rem;
            }
            
            .fin-card {
                padding: 1rem;
            }
            
            .fin-icon {
                width: 42px;
                height: 42px;
                font-size: 1.125rem;
            }
            
            .fin-amount {
                font-size: 1.375rem;
            }
            
            .fin-label {
                font-size: 0.7rem;
            }
            
            .fin-edit-btn {
                width: 32px;
                height: 32px;
                font-size: 0.875rem;
            }
        }
        
        /* Large Desktop - Side by Side Layout */
        @media (min-width: 992px) {
            .profile-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 2rem;
            }
            
            .profile-top {
                flex: 1;
                margin-bottom: 0;
            }
            
            .financial-grid {
                margin-top: 0;
                min-width: 320px;
            }
        }
        
        .accordion-button:not(.collapsed) {
            background-color: rgba(10, 98, 134, 0.05);
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .accordion-button:focus {
            box-shadow: none;
            border-color: rgba(10, 98, 134, 0.1);
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #eee;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            color: #666;
            font-weight: 500;
        }
        
        .info-value {
            color: #333;
            font-weight: 600;
            text-align: right;
        }
        
        .cell-tag {
            display: inline-block;
            background: #e3f2fd;
            color: var(--primary-color);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            margin: 2px;
            border: 1px solid #bbdefb;
        }
        
        .table-custom th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Mobile Responsive Optimizations */
        @media (max-width: 768px) {
            /* Financial modal mobile improvements */
            #editFinancialsModal .alert .d-flex {
                flex-direction: column;
                gap: 0.25rem;
            }
            #editFinancialsModal .btn-group {
                flex-direction: column;
            }
            #editFinancialsModal .btn-group .btn {
                border-radius: 0.375rem !important;
                margin-bottom: 0.25rem;
            }
            
            /* Modal responsive */
            .modal-dialog {
                margin: 0.5rem;
            }
            .modal-body .row > div {
                margin-bottom: 1rem;
            }
            
            .info-row {
                flex-direction: column;
                align-items: flex-start;
            }
            .info-value {
                text-align: left;
                margin-top: 0.25rem;
                word-break: break-word;
            }
            
            /* Mobile Table Card View */
            .table-custom thead {
                display: none;
            }
            .table-custom tbody tr {
                display: block;
                background: white;
                border: 1px solid #eee;
                border-radius: 8px;
                margin-bottom: 1rem;
                padding: 1rem;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }
            .table-custom td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0.5rem 0;
                border: none;
                border-bottom: 1px solid #f5f5f5;
                text-align: right;
            }
            .table-custom td:last-child {
                border-bottom: none;
            }
            .table-custom td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #6c757d;
                margin-right: 1rem;
                text-align: left;
                flex: 1;
            }
            
            /* Adjust buttons on mobile */
            .container-fluid .d-flex.justify-content-between {
                flex-direction: column;
                gap: 1rem;
            }
            .container-fluid .d-flex.justify-content-between > * {
                width: 100%;
            }
            .btn {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            .d-flex.gap-2 {
                flex-direction: column;
                width: 100%;
            }
        }
        
        /* Contact accordion styles */
        #collapseContact .btn {
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        #collapseContact .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        #collapseContact .btn:active {
            transform: translateY(0);
        }
        
        /* Extra small screens */
        @media (max-width: 400px) {
            .financial-stat {
                flex: 1 1 100%;
                justify-content: center;
            }
            
            .financial-stats-container {
                flex-direction: column;
                gap: 0.375rem;
            }
            
            .profile-header {
                padding: 1rem !important;
            }
            
            .profile-header h1 {
                font-size: 1.25rem !important;
            }
            
            .avatar-circle {
                width: 50px !important;
                height: 50px !important;
                font-size: 1.25rem !important;
            }
        }
        
        /* SMS & Call Modal mobile styles */
        @media (max-width: 575px) {
            #sendSmsModal .modal-dialog,
            #twilioQuickCallModal .modal-dialog {
                margin: 0.5rem;
            }
            #sendSmsModal .modal-content,
            #twilioQuickCallModal .modal-content {
                border-radius: 12px;
            }
            #sendSmsModal .modal-body,
            #twilioQuickCallModal .modal-body {
                padding: 1rem;
            }
            #sendSmsModal textarea,
            #twilioQuickCallModal input[type="tel"] {
                font-size: 16px; /* Prevents zoom on iOS */
            }
            #sendSmsModal .sms-template-btn {
                font-size: 0.75rem;
                padding: 0.375rem 0.5rem;
            }
            #twilioQuickCallModal .modal-footer {
                flex-direction: column;
                gap: 0.5rem;
            }
            #twilioQuickCallModal .modal-footer .btn {
                width: 100%;
            }
        }

        /* ===== Certificate Preview Styles - Premium Responsive Design ===== */
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@200;600;800;900&display=swap');

        /* ---------- Action Buttons Bar ---------- */
        .cert-actions-bar {
            display: flex;
            gap: 8px;
            padding: 16px;
            background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 100%);
            border-bottom: 1px solid rgba(10, 98, 134, 0.1);
        }

        .cert-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            border: none;
            border-radius: 10px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            text-decoration: none;
            flex: 1;
            justify-content: center;
        }

        @media (min-width: 576px) {
            .cert-action-btn { flex: 0 0 auto; }
        }

        .cert-btn-download {
            background: linear-gradient(135deg, #0a6286 0%, #075985 100%);
            color: #fff;
            box-shadow: 0 2px 8px rgba(10, 98, 134, 0.3);
        }
        .cert-btn-download:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(10, 98, 134, 0.4);
            color: #fff;
        }

        .cert-btn-whatsapp {
            background: linear-gradient(135deg, #25d366 0%, #128c7e 100%);
            color: #fff;
            box-shadow: 0 2px 8px rgba(37, 211, 102, 0.3);
        }
        .cert-btn-whatsapp:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(37, 211, 102, 0.4);
            color: #fff;
        }

        .cert-btn-fullview {
            background: #fff;
            color: var(--primary-color);
            border: 2px solid rgba(10, 98, 134, 0.2);
        }
        .cert-btn-fullview:hover {
            background: rgba(10, 98, 134, 0.06);
            border-color: var(--primary-color);
            transform: translateY(-1px);
            color: var(--primary-color);
        }

        /* ---------- Premium Donor Stats Cards ---------- */
        .cert-donor-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            padding: 16px;
            background: #fff;
            border-bottom: 1px solid rgba(10, 98, 134, 0.08);
        }

        @media (max-width: 575.98px) {
            .cert-donor-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
                padding: 12px;
            }
        }

        .cert-donor-stat-card {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid transparent;
            transition: all 0.25s ease;
        }

        .cert-donor-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .cert-donor-stat-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        @media (max-width: 575.98px) {
            .cert-donor-stat-icon {
                width: 32px;
                height: 32px;
                font-size: 0.8rem;
            }
        }

        .cert-donor-stat-content {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .cert-donor-stat-label {
            font-size: 0.68rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #8899a6;
            line-height: 1;
            margin-bottom: 3px;
        }

        .cert-donor-stat-val {
            font-size: 0.95rem;
            font-weight: 800;
            line-height: 1.2;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        @media (max-width: 575.98px) {
            .cert-donor-stat-val { font-size: 0.85rem; }
            .cert-donor-stat-label { font-size: 0.62rem; }
        }

        /* Card color themes */
        .cert-stat-ref {
            background: rgba(10, 98, 134, 0.04);
            border-color: rgba(10, 98, 134, 0.12);
        }
        .cert-stat-ref .cert-donor-stat-icon {
            background: rgba(10, 98, 134, 0.1);
            color: var(--primary-color);
        }
        .cert-stat-ref .cert-donor-stat-val { color: var(--primary-color); }

        .cert-stat-pledged {
            background: rgba(26, 115, 232, 0.04);
            border-color: rgba(26, 115, 232, 0.12);
        }
        .cert-stat-pledged .cert-donor-stat-icon {
            background: rgba(26, 115, 232, 0.1);
            color: #1a73e8;
        }
        .cert-stat-pledged .cert-donor-stat-val { color: #1a73e8; }

        .cert-stat-paid {
            background: rgba(226, 202, 24, 0.06);
            border-color: rgba(226, 202, 24, 0.2);
        }
        .cert-stat-paid .cert-donor-stat-icon {
            background: rgba(226, 202, 24, 0.15);
            color: #b8a000;
        }
        .cert-stat-paid .cert-donor-stat-val { color: #b8a000; }

        .cert-stat-paid.cert-stat-paid-full {
            background: rgba(16, 185, 129, 0.06);
            border-color: rgba(16, 185, 129, 0.15);
        }
        .cert-stat-paid.cert-stat-paid-full .cert-donor-stat-icon {
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
        }
        .cert-stat-paid.cert-stat-paid-full .cert-donor-stat-val { color: #059669; }

        .cert-stat-area {
            background: rgba(16, 185, 129, 0.04);
            border-color: rgba(16, 185, 129, 0.12);
        }
        .cert-stat-area .cert-donor-stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }
        .cert-stat-area .cert-donor-stat-val { color: #059669; }

        /* ---------- Enhanced Payment Progress ---------- */
        .cert-payment-progress {
            padding: 16px;
            background: linear-gradient(135deg, #fefce8 0%, #fffbeb 50%, #fff 100%);
            border-bottom: 1px solid rgba(226, 202, 24, 0.2);
        }

        .cert-payment-progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 4px;
        }

        .cert-payment-progress-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--primary-color);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .cert-payment-progress-amount {
            font-size: 0.85rem;
            font-weight: 700;
            color: #333;
        }

        .cert-payment-progress-of {
            font-weight: 400;
            color: #999;
            margin: 0 2px;
        }

        .cert-payment-track {
            width: 100%;
            height: 28px;
            background: #e5e7eb;
            border-radius: 14px;
            overflow: hidden;
            position: relative;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.06);
        }

        .cert-payment-fill {
            height: 100%;
            border-radius: 14px;
            background: linear-gradient(90deg, #e2ca18 0%, #f0b400 50%, #d4a000 100%);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 12px;
            min-width: 60px;
            transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(226, 202, 24, 0.4);
        }

        .cert-payment-fill::before {
            content: '';
            position: absolute;
            top: 0;
            left: -150%;
            width: 200%;
            height: 100%;
            background: linear-gradient(
                90deg,
                transparent 0%,
                rgba(255,255,255,0.3) 50%,
                transparent 100%
            );
            animation: cert-shimmer 2.5s ease-in-out infinite;
        }

        @keyframes cert-shimmer {
            0% { left: -150%; }
            100% { left: 150%; }
        }

        .cert-payment-fill.cert-payment-complete {
            background: linear-gradient(90deg, #10b981 0%, #059669 50%, #047857 100%);
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
        }

        .cert-payment-pct {
            font-size: 0.78rem;
            font-weight: 800;
            color: #fff;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
            position: relative;
            z-index: 1;
        }

        .cert-payment-remaining {
            margin-top: 8px;
            font-size: 0.78rem;
            color: #92400e;
            font-weight: 500;
        }

        /* ---------- Status Banners ---------- */
        .cert-status-banner {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 16px;
            padding: 14px 18px;
            border-radius: 14px;
            border: 1px solid transparent;
        }

        .cert-status-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .cert-status-text {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .cert-status-text strong {
            font-size: 0.88rem;
            line-height: 1.3;
        }

        .cert-status-text span {
            font-size: 0.78rem;
            opacity: 0.8;
        }

        .cert-status-info {
            background: rgba(10, 98, 134, 0.04);
            border-color: rgba(10, 98, 134, 0.12);
            color: var(--primary-color);
        }
        .cert-status-info .cert-status-icon {
            background: rgba(10, 98, 134, 0.1);
            color: var(--primary-color);
        }

        .cert-status-progress {
            background: linear-gradient(135deg, rgba(226, 202, 24, 0.08) 0%, rgba(245, 158, 11, 0.06) 100%);
            border-color: rgba(226, 202, 24, 0.25);
            color: #92400e;
        }
        .cert-status-progress .cert-status-icon {
            background: rgba(226, 202, 24, 0.15);
            color: #d97706;
        }

        .cert-status-complete {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.06) 0%, rgba(5, 150, 105, 0.04) 100%);
            border-color: rgba(16, 185, 129, 0.2);
            color: #065f46;
        }
        .cert-status-complete .cert-status-icon {
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
        }

        /* ---------- Certificate Preview Wrapper ---------- */
        .cert-preview-wrapper {
            background: linear-gradient(145deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
            border-radius: 0;
            padding: 12px;
            overflow: hidden;
            position: relative;
        }

        .cert-preview-wrapper::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(226, 202, 24, 0.4), transparent);
        }

        /* Aspect ratio container */
        .cert-aspect-ratio {
            position: relative;
            width: 100%;
            padding-bottom: 72.5%; /* 870/1200 */
            overflow: hidden;
            border-radius: 8px;
            box-shadow:
                0 0 0 1px rgba(226, 202, 24, 0.15),
                0 8px 32px rgba(0,0,0,0.5),
                0 2px 8px rgba(0,0,0,0.3);
        }

        /* Capture wrapper */
        .cert-capture-wrapper {
            position: absolute;
            top: 0;
            left: 0;
            width: 1200px;
            height: 870px;
            transform-origin: top left;
        }

        /* The actual certificate */
        .donor-certificate {
            position: relative;
            width: 1200px;
            height: 750px;
            background-image: url('../../assets/images/cert-bg.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            color: white;
            font-family: 'Montserrat', sans-serif;
            transform-origin: top left;
        }

        /* All internal elements use fixed pixels */
        .cert-church-overlay {
            position: absolute;
            top: 0;
            right: 0;
            width: 500px;
            height: 100%;
            pointer-events: none;
            overflow: hidden;
            z-index: 0;
        }

        .cert-church-overlay::before {
            content: '';
            position: absolute;
            top: 50%;
            right: -50px;
            transform: translateY(-50%);
            width: 450px;
            height: 450px;
            background-image: url('../../assets/images/new-church.png');
            background-size: cover;
            background-position: center;
            border-radius: 50%;
            opacity: 0.15;
            filter: saturate(0.6) brightness(1.1);
        }

        .cert-top-section {
            position: absolute;
            top: 25px;
            left: 0;
            right: 0;
            text-align: center;
            z-index: 1;
        }

        .cert-top-verse {
            color: #ffcc33;
            font-size: 41px;
            font-weight: 200;
            line-height: 1.3;
            font-family: "Nyala", "Segoe UI Ethiopic", serif;
            padding: 0 60px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .cert-church-name {
            font-size: 48px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-top: 15px;
            margin-bottom: 15px;
            padding-top: 10px;
            padding-bottom: 10px;
        }

        .cert-center-section {
            position: absolute;
            top: 200px;
            left: 0;
            right: 0;
            text-align: center;
            z-index: 1;
        }

        .cert-title-am {
            font-size: 135px;
            font-weight: 900;
            line-height: 1;
            font-family: "Nyala", "Segoe UI Ethiopic", sans-serif;
            text-shadow: 0 5px 15px rgba(0,0,0,0.2);
            margin-bottom: 10px;
            padding-top: 45px;
        }

        .cert-title-en {
            font-size: 120px;
            font-weight: 900;
            line-height: 1;
            letter-spacing: -3px;
            margin-top: 5px;
            margin-bottom: 10px;
            text-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .cert-bottom-section {
            position: absolute;
            bottom: 40px;
            left: 50px;
            right: 50px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            z-index: 1;
        }

        .cert-bank-area {
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .cert-qr-code {
            width: 160px;
            height: 160px;
            background: white;
            padding: 10px;
            flex-shrink: 0;
        }

        .cert-qr-code img {
            width: 100%;
            height: 100%;
            display: block;
        }

        .cert-bank-details {
            font-size: 38px;
            font-weight: 800;
            line-height: 1.3;
            max-width: 650px;
        }

        .cert-bank-row {
            display: flex;
            gap: 15px;
        }

        .cert-bank-label { 
            color: #fff; 
            white-space: nowrap;
        }

        .cert-bank-val { 
            color: #ffcc33; 
            white-space: normal;
            word-break: break-word;
        }

        .cert-right-area {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }

        .cert-pill-box {
            width: 280px;
            height: 100px;
            background: #ffffff;
            border-radius: 50px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .cert-sqm-value {
            font-size: 48px;
            font-weight: 900;
            color: #333;
            text-shadow: none;
        }

        .cert-reference-number {
            font-size: 20px;
            font-weight: 600;
            color: #fff;
            margin-top: 8px;
            text-align: right;
            letter-spacing: 2px;
            font-family: 'Courier New', monospace;
        }

        /* ---- Certificate Stats Strip (inside the image) - Enhanced ---- */
        .cert-stats-strip {
            width: 1200px;
            height: 120px;
            background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
            padding: 14px 40px 10px;
            box-sizing: border-box;
            border-top: 3px solid var(--accent-color, #e2ca18);
        }

        .cert-stats-row {
            display: flex;
            justify-content: space-around;
            align-items: center;
            margin-bottom: 0;
        }

        .cert-stats-row.cert-has-progress {
            margin-bottom: 8px;
        }

        .cert-stat-item {
            text-align: center;
            flex: 1;
        }

        .cert-stat-label {
            font-size: 15px;
            font-weight: 700;
            color: #8899a6;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 2px;
        }

        .cert-stat-value {
            font-size: 28px;
            font-weight: 800;
            color: #333;
            line-height: 1.2;
        }

        .cert-stat-value.cert-val-pledged { color: #1a73e8; }
        .cert-stat-value.cert-val-paid-full { color: #059669; }
        .cert-stat-value.cert-val-paid-partial { color: #d97706; }
        .cert-stat-value.cert-val-area { color: #059669; }
        .cert-stat-value.cert-val-ref {
            font-family: 'Courier New', monospace;
            letter-spacing: 2px;
            color: var(--primary-color, #0a6286);
        }

        .cert-stat-divider {
            width: 2px;
            height: 40px;
            background: linear-gradient(180deg, transparent, #d1d5db, transparent);
            flex-shrink: 0;
        }

        /* Progress bar inside certificate image */
        .cert-progress-wrap {
            margin-top: 0;
        }

        .cert-progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }

        .cert-progress-label {
            font-size: 13px;
            font-weight: 700;
            color: #8899a6;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .cert-progress-pct {
            font-size: 14px;
            font-weight: 800;
            color: #333;
        }

        .cert-progress-bar {
            width: 100%;
            height: 12px;
            background: #e5e7eb;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.08);
        }

        .cert-progress-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 0.3s ease;
        }

        .cert-progress-fill.cert-fill-full {
            background: linear-gradient(90deg, #10b981, #059669);
        }

        .cert-progress-fill.cert-fill-partial {
            background: linear-gradient(90deg, #e2ca18, #d4a000);
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-0">
                
                <!-- Actions Bar -->
                <div class="mb-4">
                    <a href="donors.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Donor List
                    </a>
                </div>

                <?php 
                // Check if donor is fully paid (completed status)
                $is_fully_paid = (
                    ($donor['payment_status'] ?? '') === 'completed' || 
                    ((float)($donor['total_pledged'] ?? 0) > 0 && (float)($donor['balance'] ?? 0) <= 0.01)
                );
                ?>
                
                <!-- Top Summary Card - Mobile Optimized -->
                <div class="profile-header<?php echo $is_fully_paid ? ' golden-premium' : ''; ?>" id="donorProfileCard">
                    <?php if ($is_fully_paid): ?>
                    <!-- Share Icon Button - Top Right -->
                    <button type="button" class="golden-share-icon" onclick="shareGoldenProfile()" title="Invite Others to Help">
                        <i class="fas fa-share-alt"></i>
                    </button>
                    <?php endif; ?>
                    
                    <!-- Top Section: Avatar + Name -->
                    <div class="profile-top">
                        <div class="avatar-circle">
                            <?php if ($is_fully_paid): ?>
                                <i class="fas fa-crown" style="font-size: 1.5rem;"></i>
                            <?php else: ?>
                                <?php echo strtoupper(substr($donor['name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($is_fully_paid): ?>
                        <!-- Church Name -->
                        <div class="golden-church-name" style="color: rgba(255,215,0,0.6); font-size: 0.65rem; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 0.5rem;">
                            Liverpool Abune Teklehaymanot EOTC
                        </div>
                        
                        <!-- FULLY PAID Premium Badge -->
                        <div class="fully-paid-badge">
                            <i class="fas fa-trophy"></i>
                            <span>Fully Paid</span>
                            <i class="fas fa-star"></i>
                        </div>
                        <?php endif; ?>
                        
                        <h1 class="profile-name"><?php echo htmlspecialchars($donor['name']); ?></h1>
                        
                        <!-- Donor Info Pills -->
                        <div class="donor-info-pills">
                            <a href="tel:<?php echo htmlspecialchars($donor['phone']); ?>" class="info-pill">
                                <i class="fas fa-phone"></i>
                                <span><?php echo htmlspecialchars($donor['phone']); ?></span>
                            </a>
                            <?php if (!empty($donor['city'])): ?>
                            <span class="info-pill">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?php echo htmlspecialchars($donor['city']); ?></span>
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Badges Row -->
                        <div class="donor-badges">
                            <span class="donor-badge ref">
                                <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($donor_reference); ?>
                            </span>
                            <?php if($donor['baptism_name']): ?>
                            <span class="donor-badge baptism">
                                <i class="fas fa-water"></i> <?php echo htmlspecialchars($donor['baptism_name']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($is_fully_paid): ?>
                        <!-- Motivational message -->
                        <div class="golden-message" style="color: rgba(255,215,0,0.8); font-size: 0.8rem; margin-top: 0.75rem; text-align: center; font-style: italic;">
                            "የበረከት ተካፋይ" — Part of this historic achievement!
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Financial Stats Grid -->
                    <div class="financial-grid">
                        <div class="fin-card pledged">
                            <div class="fin-icon"><i class="fas fa-hand-holding-usd"></i></div>
                            <div class="fin-amount"><?php echo formatMoney($donor['total_pledged']); ?></div>
                            <div class="fin-label">Pledged</div>
                        </div>
                        <div class="fin-card paid">
                            <div class="fin-icon"><i class="fas fa-check-circle"></i></div>
                            <div class="fin-amount"><?php echo formatMoney($donor['total_paid']); ?></div>
                            <div class="fin-label">Paid</div>
                        </div>
                        <div class="fin-card balance">
                            <div class="fin-icon"><i class="fas fa-coins"></i></div>
                            <div class="fin-amount"><?php echo formatMoney($donor['balance']); ?></div>
                            <div class="fin-label">Balance</div>
                        </div>
                        <button type="button" class="fin-edit-btn" data-bs-toggle="modal" data-bs-target="#editFinancialsModal" title="Edit">
                            <i class="fas fa-pen"></i>
                        </button>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_GET['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($_GET['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Accordion Sections -->
                <div class="accordion" id="donorAccordion">
                    
                    <!-- 1. Personal Information -->
                    <div class="accordion-item border-0 shadow-sm mb-3 rounded overflow-hidden">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePersonal">
                                <i class="fas fa-user-circle me-3 text-primary"></i> Personal Information
                            </button>
                        </h2>
                        <div id="collapsePersonal" class="accordion-collapse collapse" data-bs-parent="#donorAccordion">
                            <div class="accordion-body">
                                <div class="d-flex justify-content-end mb-3">
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editDonorModal" onclick="loadDonorData(<?php echo $donor_id; ?>)">
                                        <i class="fas fa-edit me-1"></i>Edit
                                    </button>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-row">
                                            <span class="info-label">Full Name</span>
                                            <span class="info-value"><?php echo htmlspecialchars($donor['name']); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">Baptism Name</span>
                                            <span class="info-value"><?php echo htmlspecialchars($donor['baptism_name'] ?? '-'); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">Phone Number</span>
                                            <span class="info-value"><a href="tel:<?php echo $donor['phone']; ?>"><?php echo htmlspecialchars($donor['phone']); ?></a></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">Email Address</span>
                                            <span class="info-value"><?php echo htmlspecialchars($donor['email'] ?? '-'); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-row">
                                            <span class="info-label">City / Address</span>
                                            <span class="info-value"><?php echo htmlspecialchars($donor['city'] ?? '-'); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">Preferred Language</span>
                                            <span class="info-value"><?php echo strtoupper($donor['preferred_language'] ?? 'EN'); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">Preferred Payment</span>
                                            <span class="info-value"><?php echo ucwords(str_replace('_', ' ', $donor['preferred_payment_method'] ?? '-')); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">Church Affiliation</span>
                                            <span class="info-value"><?php echo htmlspecialchars($donor['church_name'] ?? 'Unknown'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Donor -->
                    <div class="accordion-item border-0 shadow-sm mb-3 rounded overflow-hidden">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseContact">
                                <i class="fas fa-address-book me-3 text-info"></i> Contact Donor
                            </button>
                        </h2>
                        <div id="collapseContact" class="accordion-collapse collapse" data-bs-parent="#donorAccordion">
                            <div class="accordion-body py-3">
                                <!-- Contact Buttons - Compact Design -->
                                <div class="d-flex flex-wrap gap-2 mb-3">
                                    <!-- SMS Button -->
                                    <button type="button" class="btn d-inline-flex align-items-center gap-2 px-3 py-2" 
                                            style="background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); color: white; border: none; border-radius: 8px; font-size: 0.875rem;"
                                            data-bs-toggle="modal" data-bs-target="#sendSmsModal">
                                        <i class="fas fa-sms"></i>
                                        <span class="fw-semibold">SMS</span>
                                    </button>
                                    
                                    <!-- WhatsApp Button -->
                                    <a href="../messaging/whatsapp/new-chat.php?phone=<?php echo urlencode($donor['phone']); ?>&donor_id=<?php echo $donor_id; ?>" 
                                       class="btn d-inline-flex align-items-center gap-2 px-3 py-2 text-decoration-none" 
                                       style="background: linear-gradient(135deg, #25D366 0%, #128C7E 100%); color: white; border: none; border-radius: 8px; font-size: 0.875rem;">
                                        <i class="fab fa-whatsapp"></i>
                                        <span class="fw-semibold">WhatsApp</span>
                                    </a>
                                    
                                    <!-- Call Button (Twilio Quick Call) -->
                                    <button type="button" class="btn d-inline-flex align-items-center gap-2 px-3 py-2" 
                                       style="background: linear-gradient(135deg, #059669 0%, #047857 100%); color: white; border: none; border-radius: 8px; font-size: 0.875rem;"
                                       data-bs-toggle="modal" data-bs-target="#twilioQuickCallModal">
                                        <i class="fas fa-phone-alt"></i>
                                        <span class="fw-semibold">Call</span>
                                    </button>
                                </div>
                                
                                <!-- Quick Info - Compact -->
                                <div class="d-flex flex-wrap gap-3 small text-muted">
                                    <span><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor['phone']); ?></span>
                                    <span><i class="fas fa-language me-1"></i><?php echo strtoupper($donor['preferred_language'] ?? 'EN'); ?></span>
                                    <span><i class="fas fa-hashtag me-1"></i><?php echo htmlspecialchars($donor_reference); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Pledges & Allocations -->
                    <div class="accordion-item border-0 shadow-sm mb-3 rounded overflow-hidden">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePledges">
                                <i class="fas fa-hand-holding-usd me-3 text-success"></i> Pledges & Allocations
                                <span class="badge bg-secondary ms-auto me-2"><?php echo count($pledges); ?></span>
                            </button>
                        </h2>
                        <div id="collapsePledges" class="accordion-collapse collapse" data-bs-parent="#donorAccordion">
                            <div class="accordion-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover table-custom mb-0">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Date</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Grid Allocation</th>
                                                <th>Registered By</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($pledges)): ?>
                                                <tr><td colspan="7" class="text-center py-3 text-muted">No pledges found.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($pledges as $pledge): ?>
                                                <tr>
                                                    <td data-label="ID">#<?php echo $pledge['id']; ?></td>
                                                    <td data-label="Date"><?php echo formatDate($pledge['created_at']); ?></td>
                                                    <td data-label="Amount" class="fw-bold text-primary"><?php echo formatMoney($pledge['amount']); ?></td>
                                                    <td data-label="Status">
                                                        <span class="badge bg-<?php echo $pledge['status'] == 'approved' ? 'success' : 'warning'; ?>">
                                                            <?php echo ucfirst($pledge['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td data-label="Grid Allocation">
                                                        <?php if (!empty($pledge['allocated_cells'])): ?>
                                                            <?php foreach ($pledge['allocated_cells'] as $cell): ?>
                                                                <span class="cell-tag" title="<?php echo $cell['area_size']; ?>m²">
                                                                    <i class="fas fa-th-large me-1"></i><?php echo htmlspecialchars($cell['cell_id']); ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <span class="text-muted small">No cells allocated</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td data-label="Registered By"><?php echo htmlspecialchars($pledge['registrar_name'] ?? 'System'); ?></td>
                                                    <td data-label="Actions">
                                                        <div class="d-flex gap-1">
                                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                    data-bs-toggle="modal" data-bs-target="#editPledgeModal" 
                                                                    onclick="loadPledgeData(<?php echo $pledge['id']; ?>, <?php echo $donor_id; ?>)"
                                                                    title="Edit Pledge">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <a href="delete-pledge.php?id=<?php echo $pledge['id']; ?>&donor_id=<?php echo $donor_id; ?>&confirm=no" 
                                                               class="btn btn-sm btn-danger" 
                                                               title="Delete Pledge">
                                                                <i class="fas fa-trash-alt"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Payment History -->
                    <div class="accordion-item border-0 shadow-sm mb-3 rounded overflow-hidden">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePayments">
                                <i class="fas fa-money-bill-wave me-3 text-warning"></i> Payment History
                                <span class="badge bg-secondary ms-auto me-2"><?php echo count($payments); ?></span>
                            </button>
                        </h2>
                        <div id="collapsePayments" class="accordion-collapse collapse" data-bs-parent="#donorAccordion">
                            <div class="accordion-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover table-custom mb-0">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Type</th>
                                                <th>Date</th>
                                                <th>Amount</th>
                                                <th>Method</th>
                                                <th>Reference</th>
                                                <th>Status</th>
                                                <th>Approved By</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($payments)): ?>
                                                <tr><td colspan="9" class="text-center py-3 text-muted">No payments found.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($payments as $pay): ?>
                                                <tr>
                                                    <td data-label="ID">#<?php echo $pay['id']; ?></td>
                                                    <td data-label="Type">
                                                        <?php if (isset($pay['payment_type']) && $pay['payment_type'] === 'pledge'): ?>
                                                            <span class="badge bg-info">
                                                                <i class="fas fa-file-invoice me-1"></i>Pledge Payment
                                                            </span>
                                                            <?php if ($pay['pledge_id']): ?>
                                                                <br><small class="text-muted">Pledge #<?php echo $pay['pledge_id']; ?></small>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="badge bg-primary">
                                                                <i class="fas fa-hand-holding-usd me-1"></i>Instant
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td data-label="Date"><?php echo formatDate($pay['display_date']); ?></td>
                                                    <td data-label="Amount" class="fw-bold text-success"><?php echo formatMoney($pay['amount']); ?></td>
                                                    <td data-label="Method"><?php echo ucwords(str_replace('_', ' ', $pay['display_method'])); ?></td>
                                                    <td data-label="Reference" class="text-muted small"><?php echo htmlspecialchars($pay['display_ref'] ?? '-'); ?></td>
                                                    <td data-label="Status">
                                                        <?php
                                                        // Handle different status values for different payment types
                                                        $status = $pay['status'];
                                                        $badge_class = 'secondary';
                                                        $status_text = ucfirst($status);
                                                        
                                                        if ($status === 'approved' || $status === 'confirmed') {
                                                            $badge_class = 'success';
                                                            $status_text = 'Approved';
                                                        } elseif ($status === 'pending') {
                                                            $badge_class = 'warning';
                                                            $status_text = 'Pending';
                                                        } elseif ($status === 'voided' || $status === 'rejected') {
                                                            $badge_class = 'danger';
                                                            $status_text = 'Voided';
                                                        }
                                                        ?>
                                                        <span class="badge bg-<?php echo $badge_class; ?>">
                                                            <?php echo $status_text; ?>
                                                        </span>
                                                    </td>
                                                    <td data-label="Approved By"><?php echo htmlspecialchars($pay['approver_name'] ?? 'System'); ?></td>
                                                    <td data-label="Actions">
                                                        <?php if (isset($pay['payment_type']) && $pay['payment_type'] === 'pledge'): ?>
                                                            <!-- Pledge payments are managed through review-pledge-payments.php -->
                                                            <div class="d-flex gap-1">
                                                                <a href="../donations/review-pledge-payments.php?filter=all" 
                                                                   class="btn btn-sm btn-outline-info" 
                                                                   title="View in Payment Review">
                                                                    <i class="fas fa-eye"></i>
                                                                </a>
                                                                <?php if ($pay['status'] === 'voided'): ?>
                                                                    <!-- Only allow deletion of voided pledge payments -->
                                                                    <button type="button" 
                                                                            class="btn btn-sm btn-danger" 
                                                                            onclick="deletePledgePayment(<?php echo $pay['id']; ?>, <?php echo $donor_id; ?>)"
                                                                            title="Delete Voided Payment">
                                                                        <i class="fas fa-trash-alt"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <!-- Instant payments can be edited/deleted here -->
                                                            <div class="d-flex gap-1">
                                                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                        data-bs-toggle="modal" data-bs-target="#editPaymentModal" 
                                                                        onclick="loadPaymentData(<?php echo $pay['id']; ?>, <?php echo $donor_id; ?>)"
                                                                        title="Edit Payment">
                                                                    <i class="fas fa-edit"></i>
                                                                </button>
                                                                <a href="delete-payment.php?id=<?php echo $pay['id']; ?>&donor_id=<?php echo $donor_id; ?>&confirm=no" 
                                                                   class="btn btn-sm btn-danger" 
                                                                   title="Delete Payment">
                                                                    <i class="fas fa-trash-alt"></i>
                                                                </a>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Payment Plans -->
                    <div class="accordion-item border-0 shadow-sm mb-3 rounded overflow-hidden">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapsePlans">
                                <i class="fas fa-calendar-alt me-3 text-info"></i> Payment Plans
                                <span class="badge bg-secondary ms-auto me-2"><?php echo count($plans); ?></span>
                            </button>
                        </h2>
                        <div id="collapsePlans" class="accordion-collapse collapse" data-bs-parent="#donorAccordion">
                            <div class="accordion-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover table-custom mb-0">
                                        <thead>
                                            <tr>
                                                <th>Plan ID</th>
                                                <th>Start Date</th>
                                                <th>Total Amount</th>
                                                <th>Monthly</th>
                                                <th>Progress</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($plans)): ?>
                                                <tr><td colspan="7" class="text-center py-3 text-muted">No payment plans found.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($plans as $plan): ?>
                                                <tr>
                                                    <td data-label="Plan ID">#<?php echo $plan['id']; ?></td>
                                                    <td data-label="Start Date"><?php echo formatDate($plan['start_date']); ?></td>
                                                    <td data-label="Total Amount"><?php echo formatMoney($plan['total_amount']); ?></td>
                                                    <td data-label="Monthly"><?php echo formatMoney($plan['monthly_amount']); ?></td>
                                                    <td data-label="Progress">
                                                        <?php 
                                                            $progress = $plan['total_payments'] > 0 ? ($plan['payments_made'] / $plan['total_payments']) * 100 : 0;
                                                        ?>
                                                        <div class="d-flex align-items-center">
                                                            <div class="progress flex-grow-1 me-2" style="height: 6px; min-width: 100px;">
                                                                <div class="progress-bar bg-success" style="width: <?php echo $progress; ?>%"></div>
                                                            </div>
                                                            <small class="text-muted"><?php echo $plan['payments_made']; ?>/<?php echo $plan['total_payments']; ?></small>
                                                        </div>
                                                    </td>
                                                    <td data-label="Status">
                                                        <span class="status-badge bg-<?php echo $plan['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                                            <?php echo ucfirst($plan['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td data-label="Action">
                                                        <div class="d-flex gap-1">
                                                            <a href="payment-plans.php?id=<?php echo $plan['id']; ?>" class="btn btn-sm btn-outline-primary" title="View Plan">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <button type="button" class="btn btn-sm btn-outline-warning" 
                                                                    data-bs-toggle="modal" data-bs-target="#editPaymentPlanModal" 
                                                                    onclick="loadPaymentPlanData(<?php echo $plan['id']; ?>, <?php echo $donor_id; ?>)"
                                                                    title="Edit Plan">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <a href="delete-payment-plan.php?id=<?php echo $plan['id']; ?>&donor_id=<?php echo $donor_id; ?>&confirm=no" 
                                                               class="btn btn-sm btn-danger" 
                                                               title="Delete Plan">
                                                                <i class="fas fa-trash-alt"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 5. Call History -->
                    <div class="accordion-item border-0 shadow-sm mb-3 rounded overflow-hidden">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseCalls">
                                <i class="fas fa-headset me-3 text-secondary"></i> Call Center History
                                <span class="badge bg-secondary ms-auto me-2"><?php echo count($calls); ?></span>
                            </button>
                        </h2>
                        <div id="collapseCalls" class="accordion-collapse collapse" data-bs-parent="#donorAccordion">
                            <div class="accordion-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover table-custom mb-0">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Agent</th>
                                                <th>Outcome</th>
                                                <th>Duration</th>
                                                <th>Stage</th>
                                                <th>Notes</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($calls)): ?>
                                                <tr><td colspan="7" class="text-center py-3 text-muted">No call history.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($calls as $call): ?>
                                                <tr>
                                                    <td data-label="Date"><?php echo formatDateTime($call['call_started_at'] ?? null); ?></td>
                                                    <td data-label="Agent"><?php echo htmlspecialchars($call['agent_name'] ?? 'Unknown'); ?></td>
                                                    <td data-label="Outcome">
                                                        <span class="badge bg-light text-dark border">
                                                            <?php echo ucwords(str_replace('_', ' ', $call['outcome'] ?? 'unknown')); ?>
                                                        </span>
                                                    </td>
                                                    <td data-label="Duration"><?php echo isset($call['duration_seconds']) && $call['duration_seconds'] ? gmdate("i:s", (int)$call['duration_seconds']) : '-'; ?></td>
                                                    <td data-label="Stage"><?php echo ucwords(str_replace('_', ' ', $call['conversation_stage'] ?? 'unknown')); ?></td>
                                                    <td data-label="Notes" class="text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($call['notes'] ?? ''); ?>">
                                                        <?php echo htmlspecialchars($call['notes'] ?? '-'); ?>
                                                    </td>
                                                    <td data-label="Actions">
                                                        <div class="d-flex gap-1">
                                                            <a href="../call-center/call-details.php?id=<?php echo $call['id']; ?>" 
                                                               class="btn btn-sm btn-outline-primary" 
                                                               title="View Call Details">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <button type="button" class="btn btn-sm btn-outline-warning" 
                                                                    data-bs-toggle="modal" data-bs-target="#editCallSessionModal" 
                                                                    onclick="loadCallSessionData(<?php echo $call['id']; ?>, <?php echo $donor_id; ?>)"
                                                                    title="Edit Call">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <a href="delete-call-session.php?id=<?php echo $call['id']; ?>&donor_id=<?php echo $donor_id; ?>&confirm=no" 
                                                               class="btn btn-sm btn-danger" 
                                                               title="Delete Call">
                                                                <i class="fas fa-trash-alt"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 6. Assignment -->
                    <div class="accordion-item border-0 shadow-sm mb-3 rounded overflow-hidden">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAssignment">
                                <i class="fas fa-church me-3 text-primary"></i> Assignment
                            </button>
                        </h2>
                        <div id="collapseAssignment" class="accordion-collapse collapse" data-bs-parent="#donorAccordion">
                            <div class="accordion-body">
                                <div class="d-flex flex-wrap justify-content-end gap-2 mb-3">
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            data-bs-toggle="modal" data-bs-target="#editAssignmentModal"
                                            onclick="loadAssignmentData(<?php echo $donor_id; ?>)">
                                        <i class="fas fa-edit me-1"></i>Edit Assignment
                                    </button>
                                    <?php if ($assignment['church_id'] || $assignment['representative_id']): ?>
                                    <a href="delete-assignment.php?donor_id=<?php echo $donor_id; ?>&confirm=no" 
                                       class="btn btn-sm btn-danger"
                                       onclick="return confirm('Are you sure you want to remove this assignment? This will unassign the donor from the church and representative.');">
                                        <i class="fas fa-trash-alt me-1"></i>Remove Assignment
                                    </a>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 col-lg-4">
                                        <div class="info-row">
                                            <span class="info-label">Church</span>
                                            <span class="info-value">
                                                <?php if ($assignment['church_name']): ?>
                                                    <i class="fas fa-church me-1"></i><?php echo htmlspecialchars($assignment['church_name']); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Not assigned</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="info-row">
                                            <span class="info-label">Representative</span>
                                            <span class="info-value">
                                                <?php if ($assignment['representative_name']): ?>
                                                    <i class="fas fa-user-tie me-1"></i><?php echo htmlspecialchars($assignment['representative_name']); ?>
                                                    <?php if ($assignment['representative_role']): ?>
                                                        <span class="badge bg-info ms-2"><?php echo htmlspecialchars($assignment['representative_role']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($assignment['representative_phone']): ?>
                                                        <br><small class="text-muted"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($assignment['representative_phone']); ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Not assigned</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-md-12 col-lg-4">
                                        <div class="info-row">
                                            <span class="info-label">Assigned Agent</span>
                                            <span class="info-value">
                                                <?php if ($assignment['agent_name']): ?>
                                                    <span class="badge bg-primary" style="font-size: 0.9rem; padding: 0.4rem 0.8rem;">
                                                        <i class="fas fa-user-cog me-1"></i><?php echo htmlspecialchars($assignment['agent_name']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">Not assigned</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (!$assignment['church_id'] && !$assignment['representative_id'] && !$assignment['agent_name']): ?>
                                <div class="alert alert-info mt-3 mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    This donor is not currently assigned to any church, representative, or agent.
                                    <div class="mt-2 d-flex flex-wrap gap-2">
                                        <a href="../church-management/assign-donors.php?donor_id=<?php echo $donor_id; ?>" class="btn btn-sm btn-outline-info">
                                            <i class="fas fa-church me-1"></i>Assign to Church
                                        </a>
                                        <a href="../call-center/assign-donors.php" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-user-cog me-1"></i>Assign to Agent
                                        </a>
                                    </div>
                                </div>
                                <?php elseif (!$assignment['agent_name']): ?>
                                <div class="alert alert-warning mt-3 mb-0">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    This donor is not assigned to an agent yet.
                                    <a href="../call-center/assign-donors.php" class="alert-link">Assign to agent</a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 7. System & Audit -->
                    <div class="accordion-item border-0 shadow-sm mb-3 rounded overflow-hidden">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSystem">
                                <i class="fas fa-server me-3 text-dark"></i> System Information
                            </button>
                        </h2>
                        <div id="collapseSystem" class="accordion-collapse collapse" data-bs-parent="#donorAccordion">
                            <div class="accordion-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="mb-0 text-muted">System & Portal Information</h6>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editSystemInfoModal">
                                        <i class="fas fa-edit me-1"></i>Edit
                                    </button>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-row">
                                            <span class="info-label">Registration Source</span>
                                            <span class="info-value"><?php echo ucfirst($donor['source'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">Registered By</span>
                                            <span class="info-value"><?php echo htmlspecialchars($donor['registrar_name'] ?? 'System'); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">Created Date</span>
                                            <span class="info-value"><?php echo formatDateTime($donor['created_at']); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">Last Updated</span>
                                            <span class="info-value"><?php echo formatDateTime($donor['updated_at']); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="info-row">
                                            <span class="info-label">Login Count</span>
                                            <span class="info-value">
                                                <span class="badge bg-primary"><?php echo (int)($donor['login_count'] ?? 0); ?></span>
                                            </span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">Last Login</span>
                                            <span class="info-value"><?php echo formatDateTime($donor['last_login_at']); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">Portal Token</span>
                                            <span class="info-value font-monospace small">
                                                <?php 
                                                if (!empty($donor['portal_token'])) {
                                                    echo '<span class="badge bg-success">Active</span>';
                                                    echo '<br><small class="text-muted">' . htmlspecialchars(substr($donor['portal_token'], 0, 20)) . '...</small>';
                                                } else {
                                                    echo '<span class="badge bg-secondary">None</span>';
                                                }
                                                ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($donor['token_expires_at'])): ?>
                                        <div class="info-row">
                                            <span class="info-label">Token Expires</span>
                                            <span class="info-value"><?php echo formatDateTime($donor['token_expires_at']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($donor['token_generated_at'])): ?>
                                        <div class="info-row">
                                            <span class="info-label">Token Generated</span>
                                            <span class="info-value"><?php echo formatDateTime($donor['token_generated_at']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="info-row">
                                            <span class="info-label">Last SMS Sent</span>
                                            <span class="info-value"><?php echo formatDateTime($donor['last_sms_sent_at']); ?></span>
                                        </div>
                                    </div>
                                    <?php if ($donor['admin_notes']): ?>
                                    <div class="col-12 mt-3">
                                        <div class="alert alert-warning mb-0">
                                            <strong><i class="fas fa-sticky-note me-2"></i>Admin Notes:</strong><br>
                                            <?php echo nl2br(htmlspecialchars($donor['admin_notes'])); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- Trusted Devices -->
                                    <div class="col-12 mt-4">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h6 class="mb-0">
                                                <i class="fas fa-mobile-alt me-2 text-primary"></i>
                                                Trusted Devices (<?php echo count($trusted_devices); ?>)
                                            </h6>
                                        </div>
                                        
                                        <?php if (empty($trusted_devices)): ?>
                                        <div class="text-center text-muted py-3">
                                            <i class="fas fa-mobile-alt fa-2x opacity-25 mb-2"></i>
                                            <p class="mb-0">No trusted devices found</p>
                                        </div>
                                        <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Device</th>
                                                        <th>IP Address</th>
                                                        <th>Last Used</th>
                                                        <th>Created</th>
                                                        <th>Expires</th>
                                                        <th>Status</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($trusted_devices as $device): 
                                                        $is_active = $device['is_active'] && strtotime($device['expires_at']) > time();
                                                        $is_expired = $device['is_active'] && strtotime($device['expires_at']) <= time();
                                                        $is_revoked = !$device['is_active'];
                                                        
                                                        $status_class = 'success';
                                                        $status_text = 'Active';
                                                        if ($is_expired) {
                                                            $status_class = 'warning';
                                                            $status_text = 'Expired';
                                                        } elseif ($is_revoked) {
                                                            $status_class = 'secondary';
                                                            $status_text = 'Revoked';
                                                        }
                                                        
                                                        $device_info = parseDeviceName($device['device_name'] ?? '');
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <i class="fas <?php echo $device_info['icon']; ?> me-2 text-muted"></i>
                                                                <div>
                                                                    <div class="fw-semibold small"><?php echo htmlspecialchars($device_info['display']); ?></div>
                                                                    <div class="text-muted small font-monospace" style="font-size: 0.7rem;">
                                                                        <?php echo htmlspecialchars(substr($device['device_token'], 0, 16)) . '...'; ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="small"><?php echo htmlspecialchars($device['ip_address'] ?? '-'); ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="small"><?php echo formatDateTime($device['last_used_at']); ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="small"><?php echo formatDate($device['created_at']); ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="small"><?php echo formatDate($device['expires_at']); ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex gap-1">
                                                                <?php if ($is_active || $is_expired): ?>
                                                                <form method="POST" class="d-inline" onsubmit="return confirm('Revoke this device? The donor will need to log in again with SMS verification.');">
                                                                    <?php echo csrf_input(); ?>
                                                                    <input type="hidden" name="action" value="revoke_device">
                                                                    <input type="hidden" name="device_id" value="<?php echo $device['id']; ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Revoke Device">
                                                                        <i class="fas fa-ban"></i>
                                                                    </button>
                                                                </form>
                                                                <?php endif; ?>
                                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this trusted device record? This action cannot be undone. The record will be permanently removed but will remain in audit logs.');">
                                                                    <?php echo csrf_input(); ?>
                                                                    <input type="hidden" name="action" value="delete_device">
                                                                    <input type="hidden" name="device_id" value="<?php echo $device['id']; ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Device Record">
                                                                        <i class="fas fa-trash"></i>
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 8. Certificate -->
                    <?php
                    $currency = '£';
                    
                    // Calculate square meters based on PLEDGE (commitment), not just paid
                    // This shows the donor their full allocated area based on their pledge
                    $totalPledged = (float)($donor['total_pledged'] ?? 0);
                    $totalPaid = (float)($donor['total_paid'] ?? 0);
                    
                    // Use the higher of pledged or paid (in case paid exceeds pledge)
                    $allocationBase = max($totalPledged, $totalPaid);
                    $sqmValue = round($allocationBase / 400, 2);
                    
                    // Calculate payment progress
                    $paymentProgress = $totalPledged > 0 ? min(100, round(($totalPaid / $totalPledged) * 100)) : ($totalPaid > 0 ? 100 : 0);
                    $isFullyPaid = $totalPledged > 0 && $totalPaid >= $totalPledged;
                    $hasPledge = $totalPledged > 0 || $totalPaid > 0;
                    ?>
                    <div class="accordion-item border-0 shadow-sm mb-3 rounded overflow-hidden">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseCertificate">
                                <i class="fas fa-certificate me-3 text-warning"></i> Certificate
                                <?php if ($sqmValue > 0): ?>
                                <span class="badge bg-warning text-dark ms-auto me-2"><?= $sqmValue ?> m²</span>
                                <?php endif; ?>
                            </button>
                        </h2>
                        <div id="collapseCertificate" class="accordion-collapse collapse" data-bs-parent="#donorAccordion">
                            <div class="accordion-body p-0">
                                <!-- Certificate Actions -->
                                <div class="cert-actions-bar">
                                    <button type="button" class="cert-action-btn cert-btn-download" onclick="downloadDonorCertificate()">
                                        <i class="fas fa-download"></i> <span>Download</span>
                                    </button>
                                    <button type="button" class="cert-action-btn cert-btn-whatsapp" onclick="sendCertificateWhatsApp()">
                                        <i class="fab fa-whatsapp"></i> <span>WhatsApp</span>
                                    </button>
                                    <a href="../certificates/?search=<?= urlencode($donor['phone']) ?>&donor_id=<?= $donor_id ?>" class="cert-action-btn cert-btn-fullview">
                                        <i class="fas fa-expand-alt"></i> <span>Full View</span>
                                    </a>
                                </div>

                                <!-- Premium Donor Stats Cards -->
                                <div class="cert-donor-stats">
                                    <div class="cert-donor-stat-card cert-stat-ref">
                                        <div class="cert-donor-stat-icon"><i class="fas fa-hashtag"></i></div>
                                        <div class="cert-donor-stat-content">
                                            <span class="cert-donor-stat-label">Reference</span>
                                            <span class="cert-donor-stat-val font-monospace"><?= htmlspecialchars($donor_reference) ?></span>
                                        </div>
                                    </div>
                                    <div class="cert-donor-stat-card cert-stat-pledged">
                                        <div class="cert-donor-stat-icon"><i class="fas fa-hand-holding-heart"></i></div>
                                        <div class="cert-donor-stat-content">
                                            <span class="cert-donor-stat-label">Pledged</span>
                                            <span class="cert-donor-stat-val"><?= $currency . number_format($totalPledged, 0) ?></span>
                                        </div>
                                    </div>
                                    <div class="cert-donor-stat-card cert-stat-paid <?= $isFullyPaid ? 'cert-stat-paid-full' : '' ?>">
                                        <div class="cert-donor-stat-icon"><i class="fas fa-coins"></i></div>
                                        <div class="cert-donor-stat-content">
                                            <span class="cert-donor-stat-label">Paid</span>
                                            <span class="cert-donor-stat-val"><?= $currency . number_format($totalPaid, 0) ?></span>
                                        </div>
                                    </div>
                                    <div class="cert-donor-stat-card cert-stat-area">
                                        <div class="cert-donor-stat-icon"><i class="fas fa-vector-square"></i></div>
                                        <div class="cert-donor-stat-content">
                                            <span class="cert-donor-stat-label">Your Area</span>
                                            <span class="cert-donor-stat-val"><?= $sqmValue ?> m²</span>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($hasPledge && !$isFullyPaid): ?>
                                <!-- Enhanced Payment Progress -->
                                <div class="cert-payment-progress">
                                    <div class="cert-payment-progress-header">
                                        <div class="cert-payment-progress-title">
                                            <i class="fas fa-chart-line me-2"></i>Payment Progress
                                        </div>
                                        <div class="cert-payment-progress-amount">
                                            <?= $currency . number_format($totalPaid, 0) ?>
                                            <span class="cert-payment-progress-of">of</span>
                                            <?= $currency . number_format($totalPledged, 0) ?>
                                        </div>
                                    </div>
                                    <div class="cert-payment-track">
                                        <div class="cert-payment-fill <?= $paymentProgress >= 100 ? 'cert-payment-complete' : '' ?>" style="width: <?= $paymentProgress ?>%">
                                            <span class="cert-payment-pct"><?= $paymentProgress ?>%</span>
                                        </div>
                                    </div>
                                    <?php
                                    $remaining = $totalPledged - $totalPaid;
                                    if ($remaining > 0): ?>
                                    <div class="cert-payment-remaining">
                                        <i class="fas fa-info-circle me-1"></i>
                                        <?= $currency . number_format($remaining, 0) ?> remaining to complete your pledge
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Certificate Preview - Responsive Fit -->
                                <div class="cert-preview-wrapper">
                                    <div class="cert-aspect-ratio" id="cert-aspect-container">
                                        <div class="cert-capture-wrapper" id="donor-certificate">
                                            <!-- Original Certificate (750px) -->
                                            <div class="donor-certificate">
                                                <div class="cert-church-overlay"></div>
                                                <div class="cert-top-section">
                                                    <div class="cert-top-verse">
                                                        "የምሠራውም ቤት እጅግ ታላቅና ድንቅ ይሆናልና ብዙ እንጨት ያዘጋጁልኝ ዘንድ እነሆ ባሪያዎቼ ከባሪያዎችህ ጋር ይሆናሉ፡፡" ፪ ዜና ፪፡፱
                                                    </div>
                                                    <div class="cert-church-name">LIVERPOOL ABUNE TEKLEHAYMANOT EOTC</div>
                                                </div>
                                                <div class="cert-center-section">
                                                    <div class="cert-title-am">ይህ ታሪኬ ነው</div>
                                                    <div class="cert-title-en">It is My History</div>
                                                </div>
                                                <div class="cert-bottom-section">
                                                    <div class="cert-bank-area">
                                                        <div class="cert-qr-code">
                                                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=http://donate.abuneteklehaymanot.org/" alt="QR">
                                                        </div>
                                                        <div class="cert-bank-details">
                                                            <div class="cert-bank-row">
                                                                <span class="cert-bank-label">Name -</span>
                                                                <span class="cert-bank-val"><?= htmlspecialchars($donor['name']) ?></span>
                                                            </div>
                                                            <div class="cert-bank-row" style="margin-top: 15px;">
                                                                <span class="cert-bank-label">Contribution -</span>
                                                                <span class="cert-bank-val"><?= $currency . number_format($allocationBase, 2) ?></span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="cert-right-area">
                                                        <div class="cert-pill-box">
                                                            <span class="cert-sqm-value"><?= $sqmValue ?>m²</span>
                                                        </div>
                                                        <?php if ($donor_reference): ?>
                                                        <div class="cert-reference-number"><?= htmlspecialchars($donor_reference) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <!-- Stats Strip - sits below the certificate -->
                                            <div class="cert-stats-strip">
                                                <div class="cert-stats-row <?= $hasPledge ? 'cert-has-progress' : '' ?>">
                                                    <div class="cert-stat-item">
                                                        <div class="cert-stat-label">Ref</div>
                                                        <div class="cert-stat-value cert-val-ref"><?= htmlspecialchars($donor_reference) ?></div>
                                                    </div>
                                                    <div class="cert-stat-divider"></div>
                                                    <div class="cert-stat-item">
                                                        <div class="cert-stat-label">Pledged</div>
                                                        <div class="cert-stat-value cert-val-pledged"><?= $currency . number_format($totalPledged, 0) ?></div>
                                                    </div>
                                                    <div class="cert-stat-divider"></div>
                                                    <div class="cert-stat-item">
                                                        <div class="cert-stat-label">Paid</div>
                                                        <div class="cert-stat-value <?= $isFullyPaid ? 'cert-val-paid-full' : 'cert-val-paid-partial' ?>"><?= $currency . number_format($totalPaid, 0) ?></div>
                                                    </div>
                                                    <div class="cert-stat-divider"></div>
                                                    <div class="cert-stat-item">
                                                        <div class="cert-stat-label">Area</div>
                                                        <div class="cert-stat-value cert-val-area"><?= $sqmValue ?> m²</div>
                                                    </div>
                                                </div>
                                                <?php if ($hasPledge): ?>
                                                <div class="cert-progress-wrap">
                                                    <div class="cert-progress-header">
                                                        <span class="cert-progress-label">Payment Progress</span>
                                                        <span class="cert-progress-pct"><?= $paymentProgress ?>%</span>
                                                    </div>
                                                    <div class="cert-progress-bar">
                                                        <div class="cert-progress-fill <?= $isFullyPaid ? 'cert-fill-full' : 'cert-fill-partial' ?>" style="width: <?= $paymentProgress ?>%"></div>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (!$hasPledge): ?>
                                <div class="cert-status-banner cert-status-info">
                                    <div class="cert-status-icon"><i class="fas fa-info-circle"></i></div>
                                    <div class="cert-status-text">
                                        <strong>No pledge recorded yet</strong>
                                        <span>Once a pledge is made, the certificate will show the allocated area.</span>
                                    </div>
                                </div>
                                <?php elseif (!$isFullyPaid): ?>
                                <div class="cert-status-banner cert-status-progress">
                                    <div class="cert-status-icon"><i class="fas fa-hourglass-half"></i></div>
                                    <div class="cert-status-text">
                                        <strong>Payment in progress</strong>
                                        <span><?= $currency . number_format($totalPaid, 0) ?> of <?= $currency . number_format($totalPledged, 0) ?> paid — <?= $paymentProgress ?>% complete</span>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="cert-status-banner cert-status-complete">
                                    <div class="cert-status-icon"><i class="fas fa-award"></i></div>
                                    <div class="cert-status-text">
                                        <strong>Fully Paid — Certificate Ready!</strong>
                                        <span>This certificate is ready for download and printing.</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div> <!-- End Accordion -->

            </div>
        </main>
    </div>
</div>

<!-- Edit Donor Modal -->
<div class="modal fade" id="editDonorModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit Personal Information</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editDonorForm" method="POST" action="edit-donor.php">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="donor_id" id="editDonorId" value="<?php echo $donor_id; ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="editDonorName" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Baptism Name</label>
                            <input type="text" class="form-control" name="baptism_name" id="editDonorBaptismName">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control" name="phone" id="editDonorPhone" pattern="07\d{9}" required>
                            <small class="text-muted">UK mobile format: 07xxxxxxxxx</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" name="email" id="editDonorEmail">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">City / Address</label>
                            <input type="text" class="form-control" name="city" id="editDonorCity">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Preferred Language</label>
                            <select class="form-select" name="preferred_language" id="editDonorLanguage">
                                <option value="en">English</option>
                                <option value="am">Amharic</option>
                                <option value="ti">Tigrinya</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Preferred Payment Method</label>
                            <select class="form-select" name="preferred_payment_method" id="editDonorPaymentMethod">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="card">Card</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Church</label>
                            <select class="form-select" name="church_id" id="editDonorChurch">
                                <option value="">-- Select Church --</option>
                                <?php
                                $churches_query = $db->query("SELECT id, name FROM churches ORDER BY name");
                                while ($church = $churches_query->fetch_assoc()):
                                ?>
                                <option value="<?php echo $church['id']; ?>"><?php echo htmlspecialchars($church['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Financial Summary Modal -->
<div class="modal fade" id="editFinancialsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-calculator me-2"></i>Edit Financial Summary</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="view-donor.php?id=<?php echo $donor_id; ?>" id="editFinancialsForm">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="update_financials">
                <input type="hidden" name="update_mode" id="financials_update_mode" value="manual">
                
                <div class="modal-body">
                    <!-- Current Values Display -->
                    <div class="alert alert-info py-2 mb-3">
                        <div class="d-flex justify-content-between small">
                            <span><strong>Current:</strong></span>
                            <span>Pledged: <strong><?php echo formatMoney($donor['total_pledged']); ?></strong></span>
                            <span>Paid: <strong><?php echo formatMoney($donor['total_paid']); ?></strong></span>
                            <span>Balance: <strong><?php echo formatMoney($donor['balance']); ?></strong></span>
                        </div>
                    </div>
                    
                    <!-- Update Mode Toggle -->
                    <div class="mb-4">
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="mode_toggle" id="mode_manual" value="manual" checked onclick="setFinancialsMode('manual')">
                            <label class="btn btn-outline-primary" for="mode_manual">
                                <i class="fas fa-edit me-1"></i>Manual Edit
                            </label>
                            <input type="radio" class="btn-check" name="mode_toggle" id="mode_recalculate" value="recalculate" onclick="setFinancialsMode('recalculate')">
                            <label class="btn btn-outline-success" for="mode_recalculate">
                                <i class="fas fa-sync-alt me-1"></i>Recalculate from DB
                            </label>
                        </div>
                    </div>
                    
                    <!-- Manual Edit Fields -->
                    <div id="manual_edit_section">
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-hand-holding-usd text-warning me-1"></i>Total Pledged (£)
                                </label>
                                <input type="number" class="form-control form-control-lg" name="total_pledged" 
                                       id="edit_total_pledged" step="0.01" min="0" 
                                       value="<?php echo number_format((float)$donor['total_pledged'], 2, '.', ''); ?>"
                                       onchange="calculateBalance()">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-pound-sign text-success me-1"></i>Total Paid (£)
                                </label>
                                <input type="number" class="form-control form-control-lg" name="total_paid" 
                                       id="edit_total_paid" step="0.01" min="0" 
                                       value="<?php echo number_format((float)$donor['total_paid'], 2, '.', ''); ?>"
                                       onchange="calculateBalance()">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-balance-scale text-danger me-1"></i>Balance (£)
                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="calculateBalance()" title="Auto-calculate balance">
                                        <i class="fas fa-calculator me-1"></i>Auto-calc
                                    </button>
                                </label>
                                <input type="number" class="form-control form-control-lg" name="balance"
                                       id="edit_balance" step="0.01" min="0"
                                       value="<?php echo number_format((float)$donor['balance'], 2, '.', ''); ?>">
                                <small class="text-muted">Click "Auto-calc" to set Balance = Pledged - Paid, or enter manually</small>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-flag text-info me-1"></i>Payment Status
                                </label>
                                <select class="form-select" name="payment_status" id="edit_payment_status">
                                    <option value="no_pledge" <?php echo ($donor['payment_status'] ?? '') === 'no_pledge' ? 'selected' : ''; ?>>No Pledge</option>
                                    <option value="not_started" <?php echo ($donor['payment_status'] ?? '') === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                    <option value="paying" <?php echo ($donor['payment_status'] ?? '') === 'paying' ? 'selected' : ''; ?>>Paying</option>
                                    <option value="overdue" <?php echo ($donor['payment_status'] ?? '') === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                    <option value="completed" <?php echo ($donor['payment_status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="defaulted" <?php echo ($donor['payment_status'] ?? '') === 'defaulted' ? 'selected' : ''; ?>>Defaulted</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="auto_status" id="auto_status_checkbox" checked>
                                    <label class="form-check-label small" for="auto_status_checkbox">
                                        Auto-determine payment status based on amounts
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recalculate Section -->
                    <div id="recalculate_section" style="display: none;">
                        <div class="text-center py-4">
                            <div class="mb-3">
                                <i class="fas fa-database fa-3x text-success mb-3"></i>
                            </div>
                            <h6>Recalculate from Database</h6>
                            <p class="text-muted small mb-3">
                                This will recalculate the totals from actual records:
                            </p>
                            <ul class="list-unstyled text-start small">
                                <li class="mb-2">
                                    <i class="fas fa-check text-success me-2"></i>
                                    <strong>Total Pledged:</strong> Sum of all <code>approved</code> pledges
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check text-success me-2"></i>
                                    <strong>Total Paid:</strong> Sum of all <code>approved</code> payments + <code>confirmed</code> pledge payments
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check text-success me-2"></i>
                                    <strong>Balance:</strong> Pledged - Paid (minimum 0)
                                </li>
                                <li>
                                    <i class="fas fa-check text-success me-2"></i>
                                    <strong>Status:</strong> Auto-determined based on amounts
                                </li>
                            </ul>
                            <div class="alert alert-warning py-2 small mt-3">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                This will overwrite current values with calculated values.
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="financials_submit_btn">
                        <i class="fas fa-save me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Pledge Modal -->
<div class="modal fade" id="editPledgeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-hand-holding-usd me-2"></i>Edit Pledge</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editPledgeForm" method="POST" action="edit-pledge.php">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="pledge_id" id="editPledgeId">
                <input type="hidden" name="donor_id" id="editPledgeDonorId" value="<?php echo $donor_id; ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Amount <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="amount" id="editPledgeAmount" step="0.01" min="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="editPledgeStatus">
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Registrar</label>
                        <select class="form-select" name="created_by_user_id" id="editPledgeRegistrar">
                            <option value="">-- Select Registrar --</option>
                            <?php
                            $registrar_query = $db->query("SELECT id, name, role FROM users WHERE role IN ('admin', 'registrar') ORDER BY name ASC");
                            while ($reg = $registrar_query->fetch_assoc()):
                            ?>
                            <option value="<?php echo (int)$reg['id']; ?>">
                                <?php echo htmlspecialchars($reg['name']); ?> (<?php echo htmlspecialchars($reg['role']); ?>)
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date</label>
                        <input type="datetime-local" class="form-control" name="created_at" id="editPledgeDate">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Payment Modal -->
<div class="modal fade" id="editPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i>Edit Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editPaymentForm" method="POST" action="edit-payment.php">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="payment_id" id="editPaymentId">
                <input type="hidden" name="donor_id" id="editPaymentDonorId" value="<?php echo $donor_id; ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Amount <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="amount" id="editPaymentAmount" step="0.01" min="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select class="form-select" name="method" id="editPaymentMethod">
                            <option value="cash">Cash</option>
                            <option value="bank">Bank Transfer</option>
                            <option value="card">Card</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reference/Transaction ID</label>
                        <input type="text" class="form-control" name="reference" id="editPaymentReference">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="editPaymentStatus">
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Registrar</label>
                        <select class="form-select" name="received_by_user_id" id="editPaymentRegistrar">
                            <option value="">-- Select Registrar --</option>
                            <?php
                            $pay_registrar_query = $db->query("SELECT id, name, role FROM users WHERE role IN ('admin', 'registrar') ORDER BY name ASC");
                            while ($preg = $pay_registrar_query->fetch_assoc()):
                            ?>
                            <option value="<?php echo (int)$preg['id']; ?>">
                                <?php echo htmlspecialchars($preg['name']); ?> (<?php echo htmlspecialchars($preg['role']); ?>)
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date</label>
                        <input type="datetime-local" class="form-control" name="date" id="editPaymentDate">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Payment Plan Modal -->
<div class="modal fade" id="editPaymentPlanModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-calendar-alt me-2"></i>Edit Payment Plan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editPaymentPlanForm" method="POST" action="edit-payment-plan.php">
                <input type="hidden" name="plan_id" id="editPlanId">
                <input type="hidden" name="donor_id" id="editPlanDonorId" value="<?php echo $donor_id; ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Total Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="total_amount" id="editPlanTotalAmount" step="0.01" min="0.01" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Monthly Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="monthly_amount" id="editPlanMonthlyAmount" step="0.01" min="0.01" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Total Payments <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="total_payments" id="editPlanTotalPayments" min="1" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="start_date" id="editPlanStartDate" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="editPlanStatus">
                                <option value="active">Active</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                                <option value="paused">Paused</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Call Session Modal -->
<div class="modal fade" id="editCallSessionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-headset me-2"></i>Edit Call Session</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editCallSessionForm" method="POST" action="edit-call-session.php">
                <input type="hidden" name="session_id" id="editCallSessionId">
                <input type="hidden" name="donor_id" id="editCallDonorId" value="<?php echo $donor_id; ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Agent</label>
                        <select class="form-select" name="agent_id" id="editCallAgentId">
                            <option value="">-- Select Agent --</option>
                            <?php
                            $agents_query = $db->query("SELECT id, name FROM users WHERE role IN ('admin', 'registrar') ORDER BY name");
                            while ($agent = $agents_query->fetch_assoc()):
                            ?>
                            <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Duration (minutes)</label>
                        <input type="number" class="form-control" name="duration_minutes" id="editCallDuration" min="0" step="1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Outcome</label>
                        <select class="form-select" name="outcome" id="editCallOutcome">
                            <option value="no_answer">No Answer</option>
                            <option value="busy">Busy</option>
                            <option value="not_working">Not Working</option>
                            <option value="not_interested">Not Interested</option>
                            <option value="callback_requested">Callback Requested</option>
                            <option value="payment_plan_created">Payment Plan Created</option>
                            <option value="not_ready_to_pay">Not Ready to Pay</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" id="editCallNotes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Assignment Modal -->
<div class="modal fade" id="editAssignmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-church me-2"></i>Edit Assignment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editAssignmentForm" method="POST" action="edit-assignment.php">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="donor_id" id="editAssignmentDonorId" value="<?php echo $donor_id; ?>">
                <div class="modal-body">
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <small>You can update church/representative, agent, or all at once. Leave fields empty to keep current values.</small>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Church</label>
                            <select class="form-select" name="church_id" id="editAssignmentChurchId">
                                <option value="">-- Keep Current / No Change --</option>
                                <?php
                                // Fetch all churches
                                $churches_query = "SELECT id, name, city FROM churches ORDER BY city ASC, name ASC";
                                $churches_result = $db->query($churches_query);
                                while ($church = $churches_result->fetch_assoc()):
                                ?>
                                <option value="<?php echo $church['id']; ?>" 
                                        data-city="<?php echo htmlspecialchars($church['city']); ?>">
                                    <?php echo htmlspecialchars($church['name']); ?> - <?php echo htmlspecialchars($church['city']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                            <small class="text-muted">Optional: Leave empty to keep current church</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Representative</label>
                            <select class="form-select" name="representative_id" id="editAssignmentRepId">
                                <option value="">-- Keep Current / No Change --</option>
                            </select>
                            <small class="text-muted">Optional: Select a church first to load representatives</small>
                        </div>
                        
                        <div class="col-12">
                            <hr class="my-3">
                            <h6 class="mb-3"><i class="fas fa-user-cog me-2 text-primary"></i>Agent Assignment</h6>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="form-label">Assign to Agent</label>
                            <select class="form-select" name="agent_id" id="editAssignmentAgentId">
                                <option value="">-- No Agent (Unassign) --</option>
                                <?php
                                // Fetch all agents (admins and registrars)
                                $agents_query = "SELECT id, name FROM users WHERE role IN ('admin', 'registrar') ORDER BY name ASC";
                                $agents_result = $db->query($agents_query);
                                while ($agent = $agents_result->fetch_assoc()):
                                ?>
                                <option value="<?php echo $agent['id']; ?>">
                                    <?php echo htmlspecialchars($agent['name']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                            <small class="text-muted">Optional: Assign this donor to an agent for follow-up calls</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit System Information Modal -->
<div class="modal fade" id="editSystemInfoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-server me-2"></i>Edit System Information</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="view-donor.php?id=<?php echo $donor_id; ?>" id="editSystemInfoForm">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="update_system_info">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Login Count</label>
                            <input type="number" class="form-control" name="login_count" id="editLoginCount" 
                                   value="<?php echo (int)($donor['login_count'] ?? 0); ?>" min="0" step="1">
                            <small class="text-muted">Total number of portal logins</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Last Login At</label>
                            <input type="datetime-local" class="form-control" name="last_login_at" id="editLastLoginAt" 
                                   value="<?php echo $donor['last_login_at'] ? date('Y-m-d\TH:i', strtotime($donor['last_login_at'])) : ''; ?>">
                            <small class="text-muted">Last portal login timestamp</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Portal Token</label>
                            <input type="text" class="form-control font-monospace" name="portal_token" id="editPortalToken" 
                                   value="<?php echo htmlspecialchars($donor['portal_token'] ?? ''); ?>" 
                                   placeholder="Leave empty to clear token">
                            <small class="text-muted">Secure token for portal access. Leave empty to remove.</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Token Generated At</label>
                            <input type="datetime-local" class="form-control" name="token_generated_at" id="editTokenGeneratedAt" 
                                   value="<?php echo $donor['token_generated_at'] ? date('Y-m-d\TH:i', strtotime($donor['token_generated_at'])) : ''; ?>">
                            <small class="text-muted">When the portal token was created</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Token Expires At</label>
                            <input type="datetime-local" class="form-control" name="token_expires_at" id="editTokenExpiresAt" 
                                   value="<?php echo $donor['token_expires_at'] ? date('Y-m-d\TH:i', strtotime($donor['token_expires_at'])) : ''; ?>">
                            <small class="text-muted">When the portal token expires</small>
                        </div>
                    </div>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> Changes to system information will be logged in the audit trail.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Send SMS Modal -->
<div class="modal fade" id="sendSmsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border: none; border-radius: 16px; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); color: white; border: none; padding: 1.25rem;">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-sms me-2"></i>Send SMS to <?php echo htmlspecialchars($donor['name']); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="sendSmsForm" method="POST" action="sms/send-ajax.php">
                <input type="hidden" name="donor_id" value="<?php echo $donor_id; ?>">
                <input type="hidden" name="phone" value="<?php echo htmlspecialchars($donor['phone']); ?>">
                <input type="hidden" name="donor_name" value="<?php echo htmlspecialchars($donor['name']); ?>">
                <div class="modal-body p-4">
                    <!-- Recipient Info -->
                    <div class="d-flex align-items-center mb-4 p-3 rounded" style="background: #f0f9ff; border: 1px solid #bae6fd;">
                        <div class="me-3" style="width: 48px; height: 48px; background: linear-gradient(135deg, #0ea5e9, #0284c7); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.25rem;">
                            <?php echo strtoupper(substr($donor['name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div class="fw-bold"><?php echo htmlspecialchars($donor['name']); ?></div>
                            <div class="text-muted small">
                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor['phone']); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Message Input -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-comment-alt me-1 text-primary"></i>Message
                        </label>
                        <textarea name="message" id="smsMessage" class="form-control" rows="5" 
                                  placeholder="Type your message here..." 
                                  style="border-radius: 12px; font-size: 1rem; padding: 1rem;"
                                  required></textarea>
                        
                        <!-- Character Counter -->
                        <div class="d-flex justify-content-between align-items-center mt-2" id="smsCharInfo">
                            <div>
                                <span class="badge bg-light text-dark" id="smsCharBadge">
                                    <span id="smsCharCount">0</span> / 160 characters
                                </span>
                            </div>
                            <div>
                                <span class="badge" id="smsParts" style="background: #0ea5e9; color: white;">
                                    <i class="fas fa-envelope me-1"></i><span id="smsPartCount">1</span> SMS
                                </span>
                            </div>
                        </div>
                        
                        <!-- SMS Info Box -->
                        <div class="mt-2 p-2 rounded small" id="smsInfoBox" style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;">
                            <i class="fas fa-info-circle me-1"></i>
                            <span id="smsInfoText">Standard SMS: 160 characters per message</span>
                        </div>
                    </div>
                    
                    <!-- Quick Templates (optional) -->
                    <div class="mb-3">
                        <label class="form-label text-muted small">Quick Templates</label>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary sms-template-btn" 
                                    data-template="Hi {name}, this is a reminder about your pledge. Please contact us if you have any questions.">
                                <i class="fas fa-clock me-1"></i>Reminder
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary sms-template-btn" 
                                    data-template="Thank you {name} for your generous donation! God bless you.">
                                <i class="fas fa-heart me-1"></i>Thank You
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary sms-template-btn" 
                                    data-template="Hi {name}, we wanted to follow up on your pledge. Please call us at your convenience.">
                                <i class="fas fa-phone me-1"></i>Follow Up
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid #e5e7eb; padding: 1rem 1.5rem; background: #f8fafc;">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" id="sendSmsBtn" style="background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); border: none;">
                        <i class="fas fa-paper-plane me-2"></i>Send SMS
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Twilio Quick Call Modal -->
<div class="modal fade" id="twilioQuickCallModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border: none; border-radius: 16px; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #059669 0%, #047857 100%); color: white; border: none; padding: 1.25rem;">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-phone-volume me-2"></i>Call <?php echo htmlspecialchars($donor['name']); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Recipient Info -->
                <div class="d-flex align-items-center mb-4 p-3 rounded" style="background: #ecfdf5; border: 1px solid #a7f3d0;">
                    <div class="me-3" style="width: 48px; height: 48px; background: linear-gradient(135deg, #059669, #047857); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.25rem;">
                        <?php echo strtoupper(substr($donor['name'], 0, 1)); ?>
                    </div>
                    <div>
                        <div class="fw-bold"><?php echo htmlspecialchars($donor['name']); ?></div>
                        <div class="text-muted small">
                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor['phone']); ?>
                        </div>
                    </div>
                </div>
                
                <!-- How it works -->
                <div class="alert alert-info mb-4" style="border-radius: 12px; border: none; background: #f0f9ff;">
                    <div class="fw-bold mb-2"><i class="fas fa-info-circle me-2"></i>How it works:</div>
                    <ol class="mb-0 ps-3" style="font-size: 0.875rem;">
                        <li>Enter your phone number below</li>
                        <li>Your phone will ring first</li>
                        <li>Answer, then you'll be connected to <?php echo htmlspecialchars(explode(' ', $donor['name'])[0]); ?></li>
                        <li>Donor sees the <strong>church number</strong>, not yours!</li>
                    </ol>
                </div>
                
                <!-- Phone Input -->
                <div class="mb-3">
                    <label class="form-label fw-bold">
                        <i class="fas fa-mobile-alt me-1 text-success"></i>Your Phone Number
                    </label>
                    <input type="tel" class="form-control form-control-lg" id="twilioAgentPhone" 
                           value="<?php echo htmlspecialchars($agent_phone); ?>"
                           placeholder="07XXXXXXXXX" 
                           pattern="^(07[0-9]{9}|447[0-9]{9}|\+447[0-9]{9})$"
                           style="border-radius: 12px; font-size: 1.125rem; padding: 0.875rem 1rem;"
                           required>
                    <div class="form-text">
                        <i class="fas fa-lock me-1"></i>UK mobile format (e.g., 07123456789)
                    </div>
                </div>
                
                <!-- Status Area -->
                <div id="twilioCallStatus" style="display: none;"></div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid #e5e7eb; padding: 1rem 1.5rem; background: #f8fafc;">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-success btn-lg" id="twilioCallBtn" onclick="initiateTwilioQuickCall()" style="background: linear-gradient(135deg, #059669 0%, #047857 100%); border: none;">
                    <i class="fas fa-phone-volume me-2"></i>Call Now
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Twilio Quick Call Function
function initiateTwilioQuickCall() {
    const agentPhone = document.getElementById('twilioAgentPhone').value.trim();
    const callBtn = document.getElementById('twilioCallBtn');
    const statusDiv = document.getElementById('twilioCallStatus');
    
    if (!agentPhone) {
        showTwilioStatus('error', 'Please enter your phone number');
        return;
    }
    
    // Validate UK phone format
    const phonePattern = /^(07[0-9]{9}|447[0-9]{9}|\+447[0-9]{9})$/;
    if (!phonePattern.test(agentPhone.replace(/\s/g, ''))) {
        showTwilioStatus('error', 'Please enter a valid UK mobile number');
        return;
    }
    
    const originalHTML = callBtn.innerHTML;
    callBtn.disabled = true;
    callBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Calling...';
    
    showTwilioStatus('info', '<i class="fas fa-phone-volume me-2"></i>Calling your phone...');
    
    // Get CSRF token
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    
    // Prepare form data
    const formData = new FormData();
    formData.append('donor_id', <?php echo $donor_id; ?>);
    formData.append('queue_id', 0);
    formData.append('agent_phone', agentPhone);
    formData.append('csrf_token', csrfToken);
    
    fetch('../call-center/api/twilio-initiate-call.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showTwilioStatus('success', '<i class="fas fa-check-circle me-2"></i>Call initiated! Answer your phone.');
            
            // Keep modal open and show success
            setTimeout(() => {
                showTwilioStatus('success', '<i class="fas fa-phone me-2"></i>Connected! Call in progress...');
                // Option: redirect to call status page for tracking
                // window.location.href = '../call-center/call-status.php?donor_id=<?php echo $donor_id; ?>&session_id=' + data.session_id;
            }, 2000);
            
            // Reset button after a moment
            setTimeout(() => {
                callBtn.disabled = false;
                callBtn.innerHTML = originalHTML;
            }, 5000);
        } else {
            throw new Error(data.error || 'Failed to initiate call');
        }
    })
    .catch(error => {
        console.error('Twilio call error:', error);
        showTwilioStatus('error', '<i class="fas fa-exclamation-triangle me-2"></i>' + error.message);
        callBtn.disabled = false;
        callBtn.innerHTML = originalHTML;
    });
}

function showTwilioStatus(type, message) {
    const statusDiv = document.getElementById('twilioCallStatus');
    const bgColor = type === 'error' ? '#fef2f2' : 
                    type === 'success' ? '#ecfdf5' : '#f0f9ff';
    const borderColor = type === 'error' ? '#fecaca' : 
                        type === 'success' ? '#a7f3d0' : '#bae6fd';
    const textColor = type === 'error' ? '#b91c1c' : 
                      type === 'success' ? '#065f46' : '#0369a1';
    
    statusDiv.innerHTML = `<div class="p-3 rounded" style="background: ${bgColor}; border: 1px solid ${borderColor}; color: ${textColor};">${message}</div>`;
    statusDiv.style.display = 'block';
}

// Load Donor Data - Use PHP data already on page
function loadDonorData(donorId) {
    // Data is already available from PHP, populate form directly
    document.getElementById('editDonorId').value = <?php echo $donor_id; ?>;
    document.getElementById('editDonorName').value = <?php echo json_encode($donor['name'] ?? ''); ?>;
    document.getElementById('editDonorBaptismName').value = <?php echo json_encode($donor['baptism_name'] ?? ''); ?>;
    document.getElementById('editDonorPhone').value = <?php echo json_encode($donor['phone'] ?? ''); ?>;
    document.getElementById('editDonorEmail').value = <?php echo json_encode($donor['email'] ?? ''); ?>;
    document.getElementById('editDonorCity').value = <?php echo json_encode($donor['city'] ?? ''); ?>;
    document.getElementById('editDonorLanguage').value = <?php echo json_encode($donor['preferred_language'] ?? 'en'); ?>;
    document.getElementById('editDonorPaymentMethod').value = <?php echo json_encode($donor['preferred_payment_method'] ?? 'bank_transfer'); ?>;
    document.getElementById('editDonorChurch').value = <?php echo json_encode($donor['church_id'] ?? ''); ?>;
}

// Load Pledge Data
function loadPledgeData(pledgeId, donorId) {
    fetch('get-pledge-data.php?id=' + pledgeId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('editPledgeId').value = data.pledge.id;
                document.getElementById('editPledgeDonorId').value = donorId;
                document.getElementById('editPledgeAmount').value = data.pledge.amount || '';
                document.getElementById('editPledgeStatus').value = data.pledge.status || 'pending';
                document.getElementById('editPledgeRegistrar').value = data.pledge.created_by_user_id || '';
                const date = data.pledge.created_at ? new Date(data.pledge.created_at).toISOString().slice(0, 16) : '';
                document.getElementById('editPledgeDate').value = date;
            }
        })
        .catch(error => console.error('Error loading pledge data:', error));
}

// Load Payment Data
function loadPaymentData(paymentId, donorId) {
    fetch('get-payment-data.php?id=' + paymentId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('editPaymentId').value = data.payment.id;
                document.getElementById('editPaymentDonorId').value = donorId;
                document.getElementById('editPaymentAmount').value = data.payment.amount || '';
                document.getElementById('editPaymentMethod').value = data.payment.method || 'cash';
                document.getElementById('editPaymentReference').value = data.payment.reference || '';
                document.getElementById('editPaymentStatus').value = data.payment.status || 'pending';
                document.getElementById('editPaymentRegistrar').value = data.payment.received_by_user_id || '';
                const date = data.payment.date ? new Date(data.payment.date).toISOString().slice(0, 16) : '';
                document.getElementById('editPaymentDate').value = date;
            }
        })
        .catch(error => console.error('Error loading payment data:', error));
}

// Load Payment Plan Data
function loadPaymentPlanData(planId, donorId) {
    fetch('get-payment-plan-data.php?id=' + planId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('editPlanId').value = data.plan.id;
                document.getElementById('editPlanDonorId').value = donorId;
                document.getElementById('editPlanTotalAmount').value = data.plan.total_amount || '';
                document.getElementById('editPlanMonthlyAmount').value = data.plan.monthly_amount || '';
                document.getElementById('editPlanTotalPayments').value = data.plan.total_payments || '';
                document.getElementById('editPlanStatus').value = data.plan.status || 'active';
                const date = data.plan.start_date ? new Date(data.plan.start_date).toISOString().slice(0, 10) : '';
                document.getElementById('editPlanStartDate').value = date;
            }
        })
        .catch(error => console.error('Error loading payment plan data:', error));
}

// Load Call Session Data
function loadCallSessionData(sessionId, donorId) {
    fetch('get-call-session-data.php?id=' + sessionId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('editCallSessionId').value = data.session.id;
                document.getElementById('editCallDonorId').value = donorId;
                document.getElementById('editCallAgentId').value = data.session.agent_id || '';
                const minutes = data.session.duration_seconds ? Math.floor(data.session.duration_seconds / 60) : '';
                document.getElementById('editCallDuration').value = minutes;
                document.getElementById('editCallOutcome').value = data.session.outcome || 'no_answer';
                document.getElementById('editCallNotes').value = data.session.notes || '';
            }
        })
        .catch(error => console.error('Error loading call session data:', error));
}

// Load Assignment Data
function loadAssignmentData(donorId) {
    document.getElementById('editAssignmentDonorId').value = donorId;
    
    // Set current church
    const currentChurchId = <?php echo json_encode($assignment['church_id'] ?? ''); ?>;
    if (currentChurchId) {
        document.getElementById('editAssignmentChurchId').value = currentChurchId;
        // Load representatives for this church
        loadRepresentatives(currentChurchId);
    }
    
    // Set current agent
    const currentAgentId = <?php echo json_encode($assignment['agent_id'] ?? ''); ?>;
    if (currentAgentId) {
        document.getElementById('editAssignmentAgentId').value = currentAgentId;
    }
    
    // Set current representative after a short delay to allow dropdown to populate
    setTimeout(() => {
        const currentRepId = <?php echo json_encode($assignment['representative_id'] ?? ''); ?>;
        if (currentRepId) {
            document.getElementById('editAssignmentRepId').value = currentRepId;
        }
    }, 500);
}

// Load representatives when church is selected
document.addEventListener('DOMContentLoaded', function() {
    const churchSelect = document.getElementById('editAssignmentChurchId');
    if (churchSelect) {
        churchSelect.addEventListener('change', function() {
            const churchId = this.value;
            if (churchId) {
                loadRepresentatives(churchId);
            } else {
                // Clear representatives if no church selected
                const repSelect = document.getElementById('editAssignmentRepId');
                repSelect.innerHTML = '<option value="">-- Keep Current / No Change --</option>';
            }
        });
    }
});

function loadRepresentatives(churchId) {
    const repSelect = document.getElementById('editAssignmentRepId');
    repSelect.innerHTML = '<option value="">-- Loading --</option>';
    
    if (!churchId) {
        repSelect.innerHTML = '<option value="">-- Keep Current / No Change --</option>';
        return;
    }
    
    fetch('../church-management/get-representatives.php?church_id=' + churchId)
        .then(response => response.json())
        .then(data => {
            repSelect.innerHTML = '<option value="">-- Keep Current / No Change --</option>';
            if (data.representatives && data.representatives.length > 0) {
                data.representatives.forEach(rep => {
                    const option = document.createElement('option');
                    option.value = rep.id;
                    option.textContent = rep.name + (rep.is_primary ? ' (Primary)' : '') + ' - ' + rep.role;
                    repSelect.appendChild(option);
                });
            } else {
                // No representatives found - still allow keeping current
                const noRepsOption = document.createElement('option');
                noRepsOption.disabled = true;
                noRepsOption.textContent = 'No representatives found for this church';
                repSelect.appendChild(noRepsOption);
            }
        })
        .catch(error => {
            console.error('Error loading representatives:', error);
            repSelect.innerHTML = '<option value="">-- Keep Current / No Change --</option><option disabled>Error loading representatives</option>';
        });
}

// Delete Pledge Payment (only for voided payments)
function deletePledgePayment(paymentId, donorId) {
    if (!confirm('⚠️ PERMANENT DELETION\n\nAre you sure you want to permanently delete this voided payment record?\n\nThis action cannot be undone!\n\nThe payment record will be completely removed from the database.')) {
        return;
    }
    
    // Second confirmation for safety
    if (!confirm('Final confirmation: Delete payment #' + paymentId + '?\n\nClick OK to proceed with deletion.')) {
        return;
    }
    
    fetch('../donations/delete-pledge-payment.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            payment_id: paymentId,
            donor_id: donorId
        })
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            alert('✅ Payment deleted successfully!');
            location.reload();
        } else {
            alert('❌ Error: ' + res.message);
        }
    })
    .catch(err => {
        console.error('Delete error:', err);
        alert('❌ Failed to delete payment. Please try again.');
    });
}

// Financial Summary Edit Functions
function setFinancialsMode(mode) {
    const updateModeInput = document.getElementById('financials_update_mode');
    const manualSection = document.getElementById('manual_edit_section');
    const recalcSection = document.getElementById('recalculate_section');
    const submitBtn = document.getElementById('financials_submit_btn');
    
    if (updateModeInput) updateModeInput.value = mode;
    
    if (mode === 'recalculate') {
        if (manualSection) manualSection.style.display = 'none';
        if (recalcSection) recalcSection.style.display = 'block';
        if (submitBtn) submitBtn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>Recalculate & Save';
    } else {
        if (manualSection) manualSection.style.display = 'block';
        if (recalcSection) recalcSection.style.display = 'none';
        if (submitBtn) submitBtn.innerHTML = '<i class="fas fa-save me-1"></i>Save Changes';
    }
}

function calculateBalance() {
    const pledgedInput = document.getElementById('edit_total_pledged');
    const paidInput = document.getElementById('edit_total_paid');
    const balanceInput = document.getElementById('edit_balance');
    const statusSelect = document.getElementById('edit_payment_status');
    const autoStatusCheckbox = document.getElementById('auto_status_checkbox');
    
    if (pledgedInput && paidInput && balanceInput) {
        const pledged = parseFloat(pledgedInput.value) || 0;
        const paid = parseFloat(paidInput.value) || 0;
        const balance = Math.max(0, pledged - paid);
        balanceInput.value = balance.toFixed(2);
        
        // Auto-determine status if checkbox is checked
        if (autoStatusCheckbox && autoStatusCheckbox.checked && statusSelect) {
            updatePaymentStatus(pledged, paid);
        }
    }
}

function updatePaymentStatus(pledged, paid) {
    const statusSelect = document.getElementById('edit_payment_status');
    if (!statusSelect) return;
    
    if (pledged === 0 && paid === 0) {
        statusSelect.value = 'no_pledge';
    } else if (paid === 0) {
        statusSelect.value = 'not_started';
    } else if (paid >= pledged && pledged > 0) {
        statusSelect.value = 'completed';
    } else if (paid > 0) {
        statusSelect.value = 'paying';
    }
}

// Reset financial modal when closed
document.addEventListener('DOMContentLoaded', function() {
    const financialsModal = document.getElementById('editFinancialsModal');
    if (financialsModal) {
        financialsModal.addEventListener('hidden.bs.modal', function() {
            // Reset to manual mode
            const manualRadio = document.getElementById('mode_manual');
            if (manualRadio) manualRadio.checked = true;
            setFinancialsMode('manual');
        });
    }
});

// Golden Profile Share Function - General Invitation (humble approach)
function shareGoldenProfile() {
    const donateUrl = 'https://donate.abuneteklehaymanot.org/';
    
    const shareTitle = '⛪ Help Build Our Church - Liverpool Abune Teklehaymanot EOTC';
    const shareText = '⛪ የበረከት ተካፋይ ይሁኑ! ⛪\n\n' +
                      '🙏 Liverpool Abune Teklehaymanot Ethiopian Orthodox Tewahedo Church needs your help!\n\n' +
                      '🏛️ We are on our way to buy a new church building and every contribution makes a difference.\n\n' +
                      '💡 You can help by:\n' +
                      '✅ Contributing what you can today\n' +
                      '✅ Or pledge now and pay later with a flexible payment plan\n\n' +
                      '📌 No pressure to pay everything at once! pledge today, pay in your own way.\n\n' +
                      '🤝 Be part of this historic blessing!\n\n' +
                      '👉 ' + donateUrl + '\n\n' +
                      '#AbuneTekelHaymanot #EOTC #Liverpool #BePartOfTheBlessing #EthiopianOrthodox';
    
    // Share text only
    if (navigator.share) {
        navigator.share({
            title: shareTitle,
            text: shareText,
            url: donateUrl
        }).then(() => {
            showShareToast('Invitation shared!');
        }).catch((error) => {
            if (error.name === 'AbortError') {
                console.log('Share cancelled by user');
            } else {
                console.log('Share failed, copying to clipboard:', error);
                copyProfileToClipboard(shareText);
            }
        });
    } else {
        // Fallback: copy to clipboard
        copyProfileToClipboard(shareText);
    }
}

function copyProfileToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text + '\n\n' + window.location.href)
            .then(() => {
                showShareToast('Profile info copied to clipboard!');
            })
            .catch(() => {
                fallbackCopyToClipboard(text);
            });
    } else {
        fallbackCopyToClipboard(text);
    }
}

function fallbackCopyToClipboard(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text + '\n\n' + window.location.href;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        showShareToast('Profile info copied to clipboard!');
    } catch (e) {
        showShareToast('Could not copy. Please copy manually.');
    }
    document.body.removeChild(textarea);
}

function showShareToast(message) {
    // Create toast notification
    const toast = document.createElement('div');
    toast.style.cssText = 'position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); ' +
                          'background: linear-gradient(135deg, #d4af37, #b8860b); color: #1a1a2e; ' +
                          'padding: 12px 24px; border-radius: 50px; font-weight: 600; z-index: 9999; ' +
                          'box-shadow: 0 4px 20px rgba(212, 175, 55, 0.5); animation: toastSlide 0.3s ease;';
    toast.innerHTML = '<i class="fas fa-check-circle me-2"></i>' + message;
    
    // Add animation style
    const style = document.createElement('style');
    style.textContent = '@keyframes toastSlide { from { transform: translateX(-50%) translateY(20px); opacity: 0; } to { transform: translateX(-50%) translateY(0); opacity: 1; } }';
    document.head.appendChild(style);
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'toastSlide 0.3s ease reverse';
        setTimeout(() => {
            toast.remove();
            style.remove();
        }, 300);
    }, 2500);
}
</script>
<script>
// Wait for Bootstrap to load, then initialize
(function() {
    function initBootstrap() {
        if (typeof bootstrap === 'undefined') {
            console.error('Bootstrap JS failed to load!');
            return;
        }
        
        // Bootstrap accordions work automatically with data-bs-toggle="collapse"
        // But let's ensure they're properly initialized
        const accordionButtons = document.querySelectorAll('.accordion-button[data-bs-toggle="collapse"]');
        accordionButtons.forEach(function(button) {
            const targetId = button.getAttribute('data-bs-target');
            if (targetId) {
                const target = document.querySelector(targetId);
                if (target) {
                    // Create Bootstrap Collapse instance if it doesn't exist
                    if (!target._collapse) {
                        try {
                            new bootstrap.Collapse(target, {
                                toggle: false
                            });
                        } catch(e) {
                            console.warn('Could not initialize collapse for', targetId, e);
                        }
                    }
                }
            }
        });
        
        console.log('Bootstrap initialized. Found', accordionButtons.length, 'accordion buttons');
    }
    
    // Try to initialize immediately
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBootstrap);
    } else {
        // DOM already loaded
        setTimeout(initBootstrap, 100);
    }
})();
</script>
<script src="../assets/admin.js"></script>
<script>
// SMS Modal Functionality
(function() {
    const smsMessage = document.getElementById('smsMessage');
    const smsCharCount = document.getElementById('smsCharCount');
    const smsPartCount = document.getElementById('smsPartCount');
    const smsCharBadge = document.getElementById('smsCharBadge');
    const smsParts = document.getElementById('smsParts');
    const smsInfoBox = document.getElementById('smsInfoBox');
    const smsInfoText = document.getElementById('smsInfoText');
    const donorName = <?php echo json_encode($donor['name']); ?>;
    
    function hasSpecialChars(text) {
        return /[^\x00-\x7F]|[€\[\]{}\\~\^|]/.test(text);
    }
    
    function updateSmsCounter() {
        if (!smsMessage) return;
        
        const text = smsMessage.value;
        const len = text.length;
        const isUnicode = hasSpecialChars(text);
        
        const singleLimit = isUnicode ? 70 : 160;
        const multiLimit = isUnicode ? 67 : 153;
        
        smsCharCount.textContent = len;
        smsCharBadge.innerHTML = '<span id="smsCharCount">' + len + '</span> / ' + singleLimit + ' characters';
        
        let parts = 1;
        if (len > singleLimit) {
            parts = Math.ceil(len / multiLimit);
        }
        smsPartCount.textContent = parts;
        
        // Update styling based on parts
        if (parts === 1) {
            smsParts.style.background = '#0ea5e9';
            smsInfoBox.style.background = '#ecfdf5';
            smsInfoBox.style.borderColor = '#a7f3d0';
            smsInfoBox.style.color = '#065f46';
            smsInfoText.innerHTML = '<i class="fas fa-check-circle me-1"></i>Message fits in 1 SMS';
        } else if (parts <= 2) {
            smsParts.style.background = '#f59e0b';
            smsInfoBox.style.background = '#fef3c7';
            smsInfoBox.style.borderColor = '#fcd34d';
            smsInfoBox.style.color = '#92400e';
            smsInfoText.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Message will be sent as ' + parts + ' SMS parts';
        } else {
            smsParts.style.background = '#ef4444';
            smsInfoBox.style.background = '#fee2e2';
            smsInfoBox.style.borderColor = '#fecaca';
            smsInfoBox.style.color = '#991b1b';
            smsInfoText.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i>Long message: ' + parts + ' SMS parts (consider shortening)';
        }
        
        if (isUnicode) {
            smsInfoText.innerHTML += '<br><small>Special characters detected - using Unicode encoding</small>';
        }
    }
    
    if (smsMessage) {
        smsMessage.addEventListener('input', updateSmsCounter);
        updateSmsCounter();
    }
    
    // Template buttons
    document.querySelectorAll('.sms-template-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const template = this.getAttribute('data-template');
            if (smsMessage && template) {
                smsMessage.value = template.replace('{name}', donorName);
                updateSmsCounter();
                smsMessage.focus();
            }
        });
    });
    
    // Form submission
    const sendSmsForm = document.getElementById('sendSmsForm');
    const sendSmsBtn = document.getElementById('sendSmsBtn');
    
    if (sendSmsForm) {
        sendSmsForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(sendSmsForm);
            sendSmsBtn.disabled = true;
            sendSmsBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Sending...';
            
            fetch('sms/send-ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success
                    sendSmsBtn.innerHTML = '<i class="fas fa-check me-2"></i>Sent!';
                    sendSmsBtn.classList.remove('btn-primary');
                    sendSmsBtn.classList.add('btn-success');
                    
                    setTimeout(function() {
                        const modal = bootstrap.Modal.getInstance(document.getElementById('sendSmsModal'));
                        if (modal) modal.hide();
                        
                        // Reset form
                        smsMessage.value = '';
                        updateSmsCounter();
                        sendSmsBtn.disabled = false;
                        sendSmsBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Send SMS';
                        sendSmsBtn.classList.remove('btn-success');
                        sendSmsBtn.classList.add('btn-primary');
                        
                        // Show notification
                        alert('SMS sent successfully!');
                    }, 1500);
                } else {
                    throw new Error(data.error || 'Failed to send SMS');
                }
            })
            .catch(error => {
                sendSmsBtn.disabled = false;
                sendSmsBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Send SMS';
                alert('Error: ' + error.message);
            });
        });
    }
})();
</script>
<script>
// Additional safety check for accordions
document.addEventListener('DOMContentLoaded', function() {
    // Ensure accordion buttons work even if Bootstrap didn't initialize properly
    const accordionButtons = document.querySelectorAll('.accordion-button');
    accordionButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            const targetId = this.getAttribute('data-bs-target');
            if (targetId) {
                const target = document.querySelector(targetId);
                if (target) {
                    // If Bootstrap didn't handle it, do it manually
                    setTimeout(function() {
                        const isCollapsed = button.classList.contains('collapsed');
                        if (isCollapsed && target.classList.contains('show')) {
                            // Fix inconsistent state
                            target.classList.remove('show');
                        } else if (!isCollapsed && !target.classList.contains('show')) {
                            // Fix inconsistent state
                            target.classList.add('show');
                        }
                    }, 50);
                }
            }
        });
    });
});
</script>

<!-- Certificate Functions -->
<script>
// Certificate scaling for donor view - Simple and Reliable
function scaleDonorCertificate() {
    const container = document.getElementById('cert-aspect-container');
    const certificate = document.getElementById('donor-certificate');
    
    if (!container || !certificate) return;
    
    // Get container width
    const containerWidth = container.offsetWidth;
    
    // Certificate is 1200px wide, calculate scale
    const scale = containerWidth / 1200;
    
    // Apply transform
    certificate.style.transform = `scale(${scale})`;
    certificate.style.transformOrigin = 'top left';
}

// Initialize certificate scaling
document.addEventListener('DOMContentLoaded', function() {
    // Scale when accordion opens
    const certAccordion = document.getElementById('collapseCertificate');
    if (certAccordion) {
        certAccordion.addEventListener('shown.bs.collapse', function() {
            // Small delay to ensure layout is complete
            requestAnimationFrame(() => {
                scaleDonorCertificate();
            });
        });
    }
    
    // Update on window resize (debounced)
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(scaleDonorCertificate, 100);
    });
    
    // Also scale on orientation change
    window.addEventListener('orientationchange', function() {
        setTimeout(scaleDonorCertificate, 200);
    });
});

// Download certificate as image
function downloadDonorCertificate() {
    const certificate = document.getElementById('donor-certificate');
    if (!certificate) {
        alert('Certificate not found');
        return;
    }
    
    // Load html2canvas if needed
    if (typeof html2canvas === 'undefined') {
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
        script.onload = () => captureDonorCert(certificate);
        document.head.appendChild(script);
    } else {
        captureDonorCert(certificate);
    }
}

function captureDonorCert(element) {
    // Show loading
    const btn = document.querySelector('button[onclick="downloadDonorCertificate()"]');
    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Generating...';
        btn.disabled = true;
    }
    
    // Get donor name for filename
    const donorName = document.querySelector('.cert-bank-val')?.textContent || 'certificate';
    const safeName = donorName.replace(/[^a-z0-9]/gi, '_').toLowerCase();
    
    // Temporarily reset transform for capture
    const originalTransform = element.style.transform;
    element.style.transform = 'none';
    
    html2canvas(element, {
        scale: 2,
        useCORS: true,
        allowTaint: true,
        backgroundColor: null,
        width: 1200,
        height: 870,
        onclone: function(clonedDoc) {
            const clonedCert = clonedDoc.getElementById('donor-certificate');
            if (clonedCert) {
                clonedCert.style.transform = 'none';
            }
        }
    }).then(canvas => {
        // Restore transform
        element.style.transform = originalTransform;
        
        const link = document.createElement('a');
        link.download = `certificate_${safeName}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
        
        if (btn) {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }).catch(err => {
        // Restore transform on error too
        element.style.transform = originalTransform;
        
        console.error('Error generating certificate:', err);
        alert('Error generating certificate. Please try the full view.');
        if (btn) {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    });
}

// Send certificate via WhatsApp - captures as PNG and sends directly via UltraMsg API
function sendCertificateWhatsApp() {
    const certificate = document.getElementById('donor-certificate');
    if (!certificate) {
        alert('Certificate not found');
        return;
    }

    const donorPhone = '<?php echo htmlspecialchars($donor['phone'] ?? ''); ?>';
    const donorName = '<?php echo htmlspecialchars(addslashes($donor['name'] ?? '')); ?>';
    const donorId = '<?php echo $donor_id; ?>';
    const sqmValue = '<?php echo $sqmValue; ?>';
    const totalPaid = '<?php echo $currency . number_format($allocationBase, 2); ?>';

    if (!donorPhone) {
        alert('Donor has no phone number. Cannot send WhatsApp.');
        return;
    }

    // Show loading state
    const btn = document.querySelector('button[onclick="sendCertificateWhatsApp()"]');
    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Sending...';
        btn.disabled = true;
    }

    // Load html2canvas if needed, then capture and send
    if (typeof html2canvas === 'undefined') {
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
        script.onload = () => captureAndSendCertificate(certificate, donorPhone, donorId, donorName, sqmValue, totalPaid, btn, originalText);
        script.onerror = () => {
            alert('Failed to load image library. Please try again.');
            if (btn) { btn.innerHTML = originalText; btn.disabled = false; }
        };
        document.head.appendChild(script);
    } else {
        captureAndSendCertificate(certificate, donorPhone, donorId, donorName, sqmValue, totalPaid, btn, originalText);
    }
}

// Capture certificate as PNG and send directly via server API to WhatsApp
async function captureAndSendCertificate(element, phone, donorId, donorName, sqmValue, totalPaid, btn, originalText) {
    try {
        // Step 1: Capture certificate as PNG
        const originalTransform = element.style.transform;
        element.style.transform = 'none';

        const canvas = await html2canvas(element, {
            scale: 2,
            useCORS: true,
            allowTaint: true,
            backgroundColor: null,
            width: 1200,
            height: 870
        });

        element.style.transform = originalTransform;

        // Convert canvas to PNG blob
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        if (!blob) {
            throw new Error('Failed to generate certificate image');
        }

        // Step 2: Update button to show sending state
        if (btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Sending to WhatsApp...';
        }

        // Step 3: Send to server API which sends via UltraMsg
        const formData = new FormData();
        formData.append('certificate', blob, `certificate_${donorName.replace(/[^a-z0-9]/gi, '_')}.png`);
        formData.append('phone', phone);
        formData.append('donor_id', donorId);
        formData.append('donor_name', donorName);
        formData.append('sqm_value', sqmValue);
        formData.append('total_paid', totalPaid);

        // Get CSRF token
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
        formData.append('csrf_token', csrfToken);

        const response = await fetch('api/send-certificate-whatsapp.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            // Show success state on button
            if (btn) {
                btn.innerHTML = '<i class="fas fa-check me-1"></i> Sent!';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-success');

                // Restore button after 3 seconds
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-primary');
                }, 3000);
            }

            // Show success toast if available, otherwise simple alert
            if (typeof showToast === 'function') {
                showToast('Certificate sent to ' + donorName + ' via WhatsApp!', 'success');
            }
        } else {
            throw new Error(result.error || 'Failed to send certificate');
        }

    } catch (err) {
        console.error('Error sending certificate via WhatsApp:', err);

        // Show error
        alert('Failed to send certificate: ' + err.message);

        // Restore button
        if (btn) {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }
}
</script>
</body>
</html>
