<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

// Error logging function
function log_edit_error(string $section, array $data, string $error, array $context = []): void {
    $log_dir = __DIR__ . '/../../logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    
    $log_file = $log_dir . '/edit_errors.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = sprintf(
        "[%s] EDIT ERROR - Section: %s\nData: %s\nError: %s\nContext: %s\nStack Trace: %s\n\n",
        $timestamp,
        $section,
        json_encode($data, JSON_PRETTY_PRINT),
        $error,
        json_encode($context, JSON_PRETTY_PRINT),
        (new Exception())->getTraceAsString()
    );
    
    @file_put_contents($log_file, $log_entry, FILE_APPEND);
}

// Set error handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        log_edit_error('FATAL', [], $error['message'], [
            'file' => $error['file'],
            'line' => $error['line']
        ]);
    }
});

$db = db();
$donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;
$action = $_POST['action'] ?? 'update';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: view-donor.php?id=' . $donor_id);
    exit;
}

if (!$donor_id) {
    header('Location: donors.php?error=' . urlencode('Invalid donor ID'));
    exit;
}

try {
    // Fetch current donor data
    $check_stmt = $db->prepare("SELECT id, name, phone FROM donors WHERE id = ?");
    $check_stmt->bind_param('i', $donor_id);
    $check_stmt->execute();
    $current_donor = $check_stmt->get_result()->fetch_assoc();
    
    if (!$current_donor) {
        throw new Exception("Donor not found");
    }
    
    // Start transaction
    $db->begin_transaction();
    
    // Prepare update fields
    $updates = [];
    $types = '';
    $values = [];
    
    // Personal Information fields
    $editable_fields = [
        'name' => 'string',
        'baptism_name' => 'string',
        'phone' => 'string',
        'email' => 'string',
        'city' => 'string',
        'preferred_language' => 'string',
        'preferred_payment_method' => 'string',
        'church_id' => 'int'
    ];
    
    foreach ($editable_fields as $field => $type) {
        if (isset($_POST[$field])) {
            $value = trim($_POST[$field]);
            
            // Validation
            if ($field === 'phone' && !empty($value)) {
                // UK mobile format validation
                if (!preg_match('/^07\d{9}$/', $value)) {
                    throw new Exception("Invalid UK mobile phone format. Must be 07xxxxxxxxx");
                }
                
                // Check for duplicate phone (excluding current donor)
                $dup_check = $db->prepare("SELECT id FROM donors WHERE phone = ? AND id != ?");
                $dup_check->bind_param('si', $value, $donor_id);
                $dup_check->execute();
                if ($dup_check->get_result()->num_rows > 0) {
                    throw new Exception("Phone number already exists for another donor");
                }
            }
            
            if ($field === 'email' && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format");
            }
            
            if ($type === 'int') {
                $value = (int)$value;
                if ($value <= 0 && $field === 'church_id') {
                    $value = null; // Allow NULL for church_id
                }
            }
            
            $updates[] = "`{$field}` = ?";
            $types .= $type === 'int' ? 'i' : 's';
            $values[] = $value === '' ? null : $value;
        }
    }
    
    if (empty($updates)) {
        throw new Exception("No fields to update");
    }
    
    // Add updated_at
    $updates[] = "`updated_at` = NOW()";
    
    // Build and execute UPDATE query
    $sql = "UPDATE donors SET " . implode(', ', $updates) . " WHERE id = ?";
    $types .= 'i';
    $values[] = $donor_id;
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }
    
    $stmt->bind_param($types, ...$values);
    
    if (!$stmt->execute()) {
        throw new Exception("Update failed: " . $stmt->error);
    }
    
    // Commit transaction
    $db->commit();
    
    // Log successful update
    log_edit_error('SUCCESS', [
        'donor_id' => $donor_id,
        'updated_fields' => array_keys($editable_fields),
        'action' => $action
    ], 'Donor updated successfully', [
        'user_id' => $_SESSION['user']['id'] ?? 'unknown',
        'user_name' => $_SESSION['user']['name'] ?? 'unknown'
    ]);
    
    // Redirect with success message
    header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode('Donor information updated successfully'));
    exit;
    
} catch (mysqli_sql_exception $e) {
    $db->rollback();
    log_edit_error('DATABASE', $_POST, $e->getMessage(), [
        'error_code' => $e->getCode(),
        'sql_state' => $e->getSqlState() ?? 'N/A'
    ]);
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Database error: ' . $e->getMessage()));
    exit;
    
} catch (Exception $e) {
    $db->rollback();
    log_edit_error('GENERAL', $_POST, $e->getMessage(), [
        'donor_id' => $donor_id,
        'action' => $action
    ]);
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode($e->getMessage()));
    exit;
}

