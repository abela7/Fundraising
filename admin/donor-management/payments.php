<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_login();

// Allow both admin and registrar access
$user = current_user();
if (!in_array($user['role'] ?? '', ['admin', 'registrar'])) {
    header('Location: ' . url_for('index.php'));
    exit;
}

// Resiliently load settings and check for DB errors
require_once __DIR__ . '/../includes/resilient_db_loader.php';

$page_title = 'Payment Management';
$current_user = current_user();
$db = db();
$is_admin = ($current_user['role'] ?? '') === 'admin';

// Handle UPDATE payment request (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_payment' && $is_admin) {
    verify_csrf();
    $payment_id = (int)($_POST['payment_id'] ?? 0);
    
    if ($payment_id > 0) {
        try {
            $db->begin_transaction();
            
            // Get payment details before update for audit
            $stmt = $db->prepare("
                SELECT pp.*, d.name as donor_name, d.phone as donor_phone
                FROM pledge_payments pp
                LEFT JOIN donors d ON pp.donor_id = d.id
                WHERE pp.id = ?
            ");
            $stmt->bind_param('i', $payment_id);
            $stmt->execute();
            $payment = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$payment) {
                throw new Exception("Payment not found");
            }
            
            $beforeData = [
                'payment_date' => $payment['payment_date'],
                'approved_by_user_id' => $payment['approved_by_user_id'],
                'notes' => $payment['notes'],
                'payment_proof' => $payment['payment_proof']
            ];
            
            $updates = [];
            $types = '';
            $values = [];
            
            // Update payment date
            if (isset($_POST['payment_date']) && !empty($_POST['payment_date'])) {
                $payment_date = trim($_POST['payment_date']);
                if (!strtotime($payment_date)) {
                    throw new Exception("Invalid payment date format");
                }
                $updates[] = "payment_date = ?";
                $types .= 's';
                $values[] = $payment_date;
            }
            
            // Update approved by
            if (isset($_POST['approved_by_user_id'])) {
                $approved_by = !empty($_POST['approved_by_user_id']) ? (int)$_POST['approved_by_user_id'] : null;
                $updates[] = "approved_by_user_id = ?";
                $types .= 'i';
                $values[] = $approved_by;
            }
            
            // Update notes
            if (isset($_POST['notes'])) {
                $notes = trim($_POST['notes']);
                $updates[] = "notes = ?";
                $types .= 's';
                $values[] = $notes;
            }
            
            // Handle payment proof upload
            if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/../../uploads/payment_proofs/';
                if (!is_dir($upload_dir)) {
                    @mkdir($upload_dir, 0755, true);
                }
                
                $file_ext = strtolower(pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION));
                $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];
                if (!in_array($file_ext, $allowed_exts)) {
                    throw new Exception("Invalid file type. Allowed: " . implode(', ', $allowed_exts));
                }
                
                $new_filename = 'proof_' . $payment_id . '_' . time() . '.' . $file_ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['payment_proof']['tmp_name'], $upload_path)) {
                    // Delete old proof if exists
                    if (!empty($payment['payment_proof'])) {
                        $old_path = __DIR__ . '/../../' . $payment['payment_proof'];
                        if (file_exists($old_path)) {
                            @unlink($old_path);
                        }
                    }
                    
                    $proof_path = 'uploads/payment_proofs/' . $new_filename;
                    $updates[] = "payment_proof = ?";
                    $types .= 's';
                    $values[] = $proof_path;
                } else {
                    throw new Exception("Failed to upload payment proof");
                }
            }
            
            if (!empty($updates)) {
                $updates[] = "updated_at = NOW()";
                $sql = "UPDATE pledge_payments SET " . implode(', ', $updates) . " WHERE id = ?";
                $types .= 'i';
                $values[] = $payment_id;
                
                $update_stmt = $db->prepare($sql);
                $update_stmt->bind_param($types, ...$values);
                $update_stmt->execute();
                $update_stmt->close();
                
                // Get after data for audit
                $after_stmt = $db->prepare("SELECT payment_date, approved_by_user_id, notes, payment_proof FROM pledge_payments WHERE id = ?");
                $after_stmt->bind_param('i', $payment_id);
                $after_stmt->execute();
                $after_data = $after_stmt->get_result()->fetch_assoc();
                $after_stmt->close();
                
                // Audit log
                log_audit(
                    $db,
                    'update',
                    'pledge_payment',
                    $payment_id,
                    $beforeData,
                    [
                        'payment_date' => $after_data['payment_date'],
                        'approved_by_user_id' => $after_data['approved_by_user_id'],
                        'notes' => $after_data['notes'],
                        'payment_proof' => $after_data['payment_proof']
                    ],
                    'admin_portal',
                    (int)$current_user['id']
                );
            }
            
            $db->commit();
            
            header('Location: payments.php?success=' . urlencode('Payment #' . $payment_id . ' updated successfully'));
            exit;
            
        } catch (Exception $e) {
            $db->rollback();
            header('Location: payments.php?error=' . urlencode($e->getMessage()));
            exit;
        }
    }
}

// Handle DELETE payment request (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_payment' && $is_admin) {
    verify_csrf();
    $payment_id = (int)($_POST['payment_id'] ?? 0);
    
    if ($payment_id > 0) {
        try {
            $db->begin_transaction();
            
            // Get payment details before deletion for audit
            $stmt = $db->prepare("
                SELECT pp.*, d.name as donor_name, d.phone as donor_phone, pl.notes as pledge_notes
                FROM pledge_payments pp
                LEFT JOIN donors d ON pp.donor_id = d.id
                LEFT JOIN pledges pl ON pp.pledge_id = pl.id
                WHERE pp.id = ?
            ");
            $stmt->bind_param('i', $payment_id);
            $stmt->execute();
            $payment = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$payment) {
                throw new Exception("Payment not found");
            }
            
            // Only allow deletion of voided payments for safety
            if ($payment['status'] !== 'voided') {
                throw new Exception("Only voided payments can be deleted. Please void the payment first.");
            }
            
            // Delete the payment
            $delete_stmt = $db->prepare("DELETE FROM pledge_payments WHERE id = ?");
            $delete_stmt->bind_param('i', $payment_id);
            $delete_stmt->execute();
            $delete_stmt->close();
            
            // Audit log the deletion
            log_audit(
                $db,
                'delete',
                'pledge_payment',
                $payment_id,
                [
                    'id' => $payment['id'],
                    'donor_id' => $payment['donor_id'],
                    'donor_name' => $payment['donor_name'],
                    'donor_phone' => $payment['donor_phone'],
                    'pledge_id' => $payment['pledge_id'],
                    'amount' => $payment['amount'],
                    'payment_method' => $payment['payment_method'],
                    'payment_date' => $payment['payment_date'],
                    'reference_number' => $payment['reference_number'],
                    'status' => $payment['status'],
                    'notes' => $payment['notes']
                ],
                ['deleted' => true, 'reason' => 'Voided payment removed by admin'],
                'admin_portal',
                (int)$current_user['id']
            );
            
            $db->commit();
            
            // Redirect with success message
            header('Location: payments.php?success=' . urlencode('Payment #' . $payment_id . ' deleted successfully'));
            exit;
            
        } catch (Exception $e) {
            $db->rollback();
            header('Location: payments.php?error=' . urlencode($e->getMessage()));
            exit;
        }
    }
}

$success_message = '';
$error_message = '';

// Check if pledge_payments table exists
$table_check = $db->query("SHOW TABLES LIKE 'pledge_payments'");
if ($table_check->num_rows === 0) {
    $error_message = "The pledge_payments table does not exist. Please ensure the database is properly set up.";
}

// Check if payment_plan_id column exists
$has_plan_col = false;
if (empty($error_message)) {
    $has_plan_col = $db->query("SHOW COLUMNS FROM pledge_payments LIKE 'payment_plan_id'")->num_rows > 0;
}

// Get filter parameters
$filter_status = $_GET['status'] ?? 'all';
$filter_method = $_GET['method'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Check if any filters are active (excluding status filter for pills)
$has_active_filters = !empty($filter_method) || !empty($filter_date_from) || !empty($filter_date_to) || !empty($search);

// Build query
$payments = [];
// Overall stats (for filter pills - always show total counts)
$overall_stats = [
    'total' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'voided' => 0,
    'total_amount' => 0,
    'confirmed_amount' => 0,
    'pending_amount' => 0
];
// Filtered stats (for cards - changes based on filters)
$filtered_stats = [
    'total' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'voided' => 0,
    'total_amount' => 0,
    'confirmed_amount' => 0,
    'pending_amount' => 0
];

if (empty($error_message)) {
    try {
        // Get OVERALL statistics (for filter pills)
        $overall_stats_query = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN status = 'voided' THEN 1 ELSE 0 END) as voided,
                COALESCE(SUM(amount), 0) as total_amount,
                COALESCE(SUM(CASE WHEN status = 'confirmed' THEN amount ELSE 0 END), 0) as confirmed_amount,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_amount
            FROM pledge_payments
        ";
        $overall_stats_result = $db->query($overall_stats_query);
        if ($overall_stats_result) {
            $overall_stats = $overall_stats_result->fetch_assoc();
        }
        
        // Build filter conditions
        $where_conditions = [];
        $params = [];
        $types = '';
        
        if ($filter_status !== 'all') {
            $where_conditions[] = "pp.status = ?";
            $params[] = $filter_status;
            $types .= 's';
        }
        
        if (!empty($filter_method)) {
            $where_conditions[] = "pp.payment_method = ?";
            $params[] = $filter_method;
            $types .= 's';
        }
        
        if (!empty($filter_date_from)) {
            $where_conditions[] = "DATE(pp.payment_date) >= ?";
            $params[] = $filter_date_from;
            $types .= 's';
        }
        
        if (!empty($filter_date_to)) {
            $where_conditions[] = "DATE(pp.payment_date) <= ?";
            $params[] = $filter_date_to;
            $types .= 's';
        }
        
        if (!empty($search)) {
            // Search in donor name, phone, payment reference, and pledge notes (for 4-digit reference)
            $where_conditions[] = "(d.name LIKE ? OR d.phone LIKE ? OR pp.reference_number LIKE ? OR pl.notes LIKE ?)";
            $search_param = "%{$search}%";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $types .= 'ssss';
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        // Get FILTERED statistics (for stat cards)
        $filtered_stats_sql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN pp.status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN pp.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN pp.status = 'voided' THEN 1 ELSE 0 END) as voided,
                COALESCE(SUM(pp.amount), 0) as total_amount,
                COALESCE(SUM(CASE WHEN pp.status = 'confirmed' THEN pp.amount ELSE 0 END), 0) as confirmed_amount,
                COALESCE(SUM(CASE WHEN pp.status = 'pending' THEN pp.amount ELSE 0 END), 0) as pending_amount
            FROM pledge_payments pp
            LEFT JOIN donors d ON pp.donor_id = d.id
            LEFT JOIN pledges pl ON pp.pledge_id = pl.id
            {$where_clause}
        ";
        
        if (!empty($params)) {
            $filtered_stats_stmt = $db->prepare($filtered_stats_sql);
            $filtered_stats_stmt->bind_param($types, ...$params);
            $filtered_stats_stmt->execute();
            $filtered_stats_result = $filtered_stats_stmt->get_result();
        } else {
            $filtered_stats_result = $db->query($filtered_stats_sql);
        }
        
        if ($filtered_stats_result) {
            $filtered_stats = $filtered_stats_result->fetch_assoc();
        }
        
        $sql = "
            SELECT 
                pp.id,
                pp.pledge_id,
                pp.donor_id,
                pp.amount,
                pp.payment_method,
                pp.payment_date,
                pp.reference_number,
                pp.payment_proof,
                pp.notes,
                pp.status,
                pp.approved_at,
                pp.approved_by_user_id,
                pp.voided_at,
                pp.voided_by_user_id,
                pp.created_at,
                " . ($has_plan_col ? "pp.payment_plan_id," : "") . "
                d.id as donor_id,
                d.name as donor_name,
                d.phone as donor_phone,
                d.total_pledged,
                d.total_paid,
                d.balance,
                d.payment_status as donor_payment_status,
                d.has_active_plan,
                pl.amount as pledge_amount,
                pl.notes as pledge_notes,
                approver.name as approved_by_name,
                voider.name as voided_by_name
                " . ($has_plan_col ? ",
                pplan.id as plan_id,
                pplan.monthly_amount as plan_monthly_amount,
                pplan.payments_made as plan_payments_made,
                pplan.total_payments as plan_total_payments,
                pplan.status as plan_status
                " : "") . "
            FROM pledge_payments pp
            LEFT JOIN donors d ON pp.donor_id = d.id
            LEFT JOIN pledges pl ON pp.pledge_id = pl.id
            LEFT JOIN users approver ON pp.approved_by_user_id = approver.id
            LEFT JOIN users voider ON pp.voided_by_user_id = voider.id
            " . ($has_plan_col ? "LEFT JOIN donor_payment_plans pplan ON pp.payment_plan_id = pplan.id" : "") . "
            {$where_clause}
            ORDER BY pp.created_at DESC
            LIMIT 500
        ";
        
        if (!empty($params)) {
            $stmt = $db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $db->query($sql);
        }
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $payments[] = $row;
            }
        }
        
    } catch (Exception $e) {
        $error_message = "Error loading payments: " . $e->getMessage();
    }
}

// Get list of users (for approved_by dropdown in edit modal)
$users_list = [];
if ($is_admin && empty($error_message)) {
    $users_query = $db->query("SELECT id, name, role FROM users WHERE role IN ('admin', 'registrar') ORDER BY name ASC");
    if ($users_query) {
        while ($user_row = $users_query->fetch_assoc()) {
            $users_list[] = $user_row;
        }
    }
}

// Get unique donors who have paid (filtered)
$paying_donors_count = 0;
if (empty($error_message)) {
    // Build filtered unique donors query
    $unique_donors_sql = "
        SELECT COUNT(DISTINCT pp.donor_id) as cnt 
        FROM pledge_payments pp
        LEFT JOIN donors d ON pp.donor_id = d.id
        LEFT JOIN pledges pl ON pp.pledge_id = pl.id
        {$where_clause}
    ";
    
    // Add confirmed status filter if not already filtering by status
    if ($filter_status === 'all') {
        $unique_donors_sql = "
            SELECT COUNT(DISTINCT pp.donor_id) as cnt 
            FROM pledge_payments pp
            LEFT JOIN donors d ON pp.donor_id = d.id
            LEFT JOIN pledges pl ON pp.pledge_id = pl.id
            " . (!empty($where_clause) ? $where_clause . " AND pp.status = 'confirmed'" : "WHERE pp.status = 'confirmed'");
    }
    
    if (!empty($params)) {
        $unique_donors_stmt = $db->prepare($unique_donors_sql);
        $unique_donors_stmt->bind_param($types, ...$params);
        $unique_donors_stmt->execute();
        $unique_donors_result = $unique_donors_stmt->get_result();
        if ($unique_donors_result) {
            $paying_donors_count = (int)$unique_donors_result->fetch_assoc()['cnt'];
        }
    } else {
        $unique_donors = $db->query($unique_donors_sql);
        if ($unique_donors) {
            $paying_donors_count = (int)$unique_donors->fetch_assoc()['cnt'];
        }
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
        /* Enhanced Payment Card Styles */
        .payment-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            margin-bottom: 12px;
            overflow: hidden;
            transition: all 0.2s ease;
            border: 1px solid #e5e7eb;
        }
        .payment-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .payment-card.status-confirmed {
            border-left: 4px solid #10b981;
        }
        .payment-card.status-pending {
            border-left: 4px solid #f59e0b;
        }
        .payment-card.status-voided {
            border-left: 4px solid #ef4444;
        }
        
        .payment-card-header {
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            border-bottom: 1px solid #f3f4f6;
        }
        .payment-card-body {
            padding: 12px 16px;
        }
        .payment-card-footer {
            padding: 10px 16px;
            background: #f9fafb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }
        
        .donor-info {
            flex: 1;
            min-width: 0;
        }
        .donor-name {
            font-weight: 600;
            color: #1f2937;
            font-size: 0.95rem;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .donor-phone {
            font-size: 0.8rem;
            color: #6b7280;
        }
        
        .amount-display {
            text-align: right;
        }
        .amount-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #10b981;
        }
        .amount-pledge {
            font-size: 0.7rem;
            color: #9ca3af;
        }
        
        .payment-meta {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        .meta-item {
            display: flex;
            flex-direction: column;
        }
        .meta-label {
            font-size: 0.7rem;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        .meta-value {
            font-size: 0.85rem;
            color: #374151;
            font-weight: 500;
        }
        
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .status-pill.confirmed {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }
        .status-pill.pending {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }
        .status-pill.voided {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }
        
        .method-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: #f3f4f6;
            border-radius: 6px;
            font-size: 0.75rem;
            color: #4b5563;
        }
        
        .plan-indicator {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border-radius: 6px;
            font-size: 0.7rem;
            color: #1e40af;
            font-weight: 600;
        }
        
        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e5e7eb;
            background: #fff;
            color: #6b7280;
            transition: all 0.2s;
            cursor: pointer;
        }
        .action-btn:hover {
            background: #f3f4f6;
            color: #1f2937;
            border-color: #d1d5db;
        }
        .action-btn.proof { color: #8b5cf6; }
        .action-btn.proof:hover { background: #f5f3ff; border-color: #c4b5fd; }
        .action-btn.view { color: #3b82f6; }
        .action-btn.view:hover { background: #eff6ff; border-color: #93c5fd; }
        .action-btn.pending-action { color: #f59e0b; }
        .action-btn.pending-action:hover { background: #fffbeb; border-color: #fcd34d; }
        
        /* Filter Pills */
        .filter-pills {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .filter-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 24px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        .filter-pill.all {
            background: #f3f4f6;
            color: #4b5563;
        }
        .filter-pill.all:hover, .filter-pill.all.active {
            background: #1f2937;
            color: #fff;
        }
        .filter-pill.confirmed {
            background: #d1fae5;
            color: #065f46;
        }
        .filter-pill.confirmed:hover, .filter-pill.confirmed.active {
            background: #10b981;
            color: #fff;
        }
        .filter-pill.pending {
            background: #fef3c7;
            color: #92400e;
        }
        .filter-pill.pending:hover, .filter-pill.pending.active {
            background: #f59e0b;
            color: #fff;
        }
        .filter-pill.voided {
            background: #fee2e2;
            color: #991b1b;
        }
        .filter-pill.voided:hover, .filter-pill.voided.active {
            background: #ef4444;
            color: #fff;
        }
        .filter-pill .count {
            background: rgba(255,255,255,0.3);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
        }
        .filter-pill.active .count {
            background: rgba(255,255,255,0.25);
        }
        
        /* Search & Filter Bar */
        .search-filter-bar {
            background: #f9fafb;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .search-input {
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            padding: 10px 14px;
            font-size: 0.9rem;
        }
        .search-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        /* Stats Mini Cards */
        .stats-mini {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .stat-mini-card {
            background: #fff;
            border-radius: 10px;
            padding: 14px;
            text-align: center;
            border: 1px solid #e5e7eb;
        }
        .stat-mini-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .stat-mini-label {
            font-size: 0.7rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-mini-sub {
            font-size: 0.75rem;
            color: #9ca3af;
            margin-top: 2px;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        .empty-state-icon {
            width: 80px;
            height: 80px;
            background: #f3f4f6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2rem;
            color: #9ca3af;
        }
        
        /* Approved by text */
        .approved-by {
            font-size: 0.7rem;
            color: #6b7280;
            margin-top: 2px;
        }
        
        /* Desktop Table Styles - Hidden on mobile */
        .desktop-table {
            display: none;
        }
        
        /* Mobile cards - shown by default */
        .mobile-cards {
            display: block;
        }
        
        /* Desktop view */
        @media (min-width: 992px) {
            .desktop-table {
                display: block;
            }
            .mobile-cards {
                display: none;
            }
            .stats-mini {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        /* Tablet adjustments */
        @media (max-width: 991px) and (min-width: 768px) {
            .stats-mini {
                grid-template-columns: repeat(4, 1fr);
            }
            .payment-meta {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        /* Mobile adjustments */
        @media (max-width: 767px) {
            .stats-mini {
                grid-template-columns: repeat(2, 1fr);
            }
            .stat-mini-value {
                font-size: 1.25rem;
            }
            .filter-pills {
                justify-content: center;
            }
            .filter-pill {
                padding: 6px 12px;
                font-size: 0.8rem;
            }
            .page-header-actions {
                width: 100%;
                justify-content: center;
            }
            .amount-value {
                font-size: 1.1rem;
            }
        }
        
        /* Desktop Table Enhancements */
        .payments-table {
            width: 100%;
        }
        .payments-table th {
            background: #f9fafb;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            padding: 12px 16px;
            border-bottom: 2px solid #e5e7eb;
        }
        .payments-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .payments-table tbody tr {
            transition: background 0.15s;
            cursor: pointer;
        }
        .payments-table tbody tr:hover {
            background: #f9fafb;
        }
        .payments-table .donor-cell {
            min-width: 180px;
        }
        .payments-table .amount-cell {
            font-weight: 600;
            color: #10b981;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid px-3 px-lg-4">
                <?php include '../includes/db_error_banner.php'; ?>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_GET['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($_GET['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($_GET['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Page Header -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <div>
                        <h4 class="mb-0 fw-bold">
                            <i class="fas fa-money-bill-wave me-2 text-success"></i>Payments
                        </h4>
                        <p class="text-muted mb-0 small d-none d-sm-block">Track all donor payments</p>
                    </div>
                    <div class="d-flex gap-2 page-header-actions">
                        <?php if ($is_admin): ?>
                        <a href="../donations/record-pledge-payment.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-1"></i><span class="d-none d-sm-inline">Record</span>
                        </a>
                        <?php endif; ?>
                                <a href="../donations/review-pledge-payments.php" class="btn btn-warning btn-sm">
                            <i class="fas fa-clock me-1"></i>Pending <span class="badge bg-dark ms-1"><?php echo (int)$overall_stats['pending']; ?></span>
                        </a>
                    </div>
                </div>
                
                <!-- Stats Mini Cards (filtered) -->
                <div class="stats-mini">
                    <div class="stat-mini-card">
                        <div class="stat-mini-value text-primary"><?php echo number_format($paying_donors_count); ?></div>
                        <div class="stat-mini-label">Donors</div>
                        <div class="stat-mini-sub"><?php echo ($has_active_filters || $filter_status !== 'all') ? 'In results' : 'Have paid'; ?></div>
                    </div>
                    <div class="stat-mini-card">
                        <div class="stat-mini-value text-success"><?php echo number_format((int)$filtered_stats['confirmed']); ?></div>
                        <div class="stat-mini-label">Confirmed</div>
                        <div class="stat-mini-sub">£<?php echo number_format((float)$filtered_stats['confirmed_amount'], 0); ?></div>
                    </div>
                    <div class="stat-mini-card">
                        <div class="stat-mini-value text-warning"><?php echo number_format((int)$filtered_stats['pending']); ?></div>
                        <div class="stat-mini-label">Pending</div>
                        <div class="stat-mini-sub">£<?php echo number_format((float)$filtered_stats['pending_amount'], 0); ?></div>
                    </div>
                    <div class="stat-mini-card">
                        <div class="stat-mini-value text-secondary"><?php echo number_format((int)$filtered_stats['total']); ?></div>
                        <div class="stat-mini-label">Total</div>
                        <div class="stat-mini-sub">£<?php echo number_format((float)$filtered_stats['total_amount'], 0); ?></div>
                    </div>
                </div>
                
                <?php if ($has_active_filters || $filter_status !== 'all'): ?>
                <div class="alert alert-info py-2 mb-3">
                    <i class="fas fa-filter me-2"></i>
                    <strong>Filtered Results:</strong> 
                    Showing <?php echo number_format((int)$filtered_stats['total']); ?> payment(s) 
                    totaling £<?php echo number_format((float)$filtered_stats['total_amount'], 2); ?>
                    <?php if (!empty($filter_date_from) || !empty($filter_date_to)): ?>
                        <span class="ms-2">
                            <i class="fas fa-calendar me-1"></i>
                            <?php 
                            if ($filter_date_from && $filter_date_to) {
                                echo date('d M Y', strtotime($filter_date_from)) . ' - ' . date('d M Y', strtotime($filter_date_to));
                            } elseif ($filter_date_from) {
                                echo 'From ' . date('d M Y', strtotime($filter_date_from));
                            } elseif ($filter_date_to) {
                                echo 'Until ' . date('d M Y', strtotime($filter_date_to));
                            }
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Filter Pills (show overall counts) -->
                <div class="filter-pills">
                    <a href="?status=all<?php echo !empty($filter_method) ? '&method=' . urlencode($filter_method) : ''; ?><?php echo !empty($filter_date_from) ? '&date_from=' . urlencode($filter_date_from) : ''; ?><?php echo !empty($filter_date_to) ? '&date_to=' . urlencode($filter_date_to) : ''; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-pill all <?php echo $filter_status === 'all' ? 'active' : ''; ?>">
                        All <span class="count"><?php echo number_format((int)$overall_stats['total']); ?></span>
                    </a>
                    <a href="?status=confirmed<?php echo !empty($filter_method) ? '&method=' . urlencode($filter_method) : ''; ?><?php echo !empty($filter_date_from) ? '&date_from=' . urlencode($filter_date_from) : ''; ?><?php echo !empty($filter_date_to) ? '&date_to=' . urlencode($filter_date_to) : ''; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-pill confirmed <?php echo $filter_status === 'confirmed' ? 'active' : ''; ?>">
                        <i class="fas fa-check-circle"></i> Confirmed <span class="count"><?php echo number_format((int)$overall_stats['confirmed']); ?></span>
                    </a>
                    <a href="?status=pending<?php echo !empty($filter_method) ? '&method=' . urlencode($filter_method) : ''; ?><?php echo !empty($filter_date_from) ? '&date_from=' . urlencode($filter_date_from) : ''; ?><?php echo !empty($filter_date_to) ? '&date_to=' . urlencode($filter_date_to) : ''; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-pill pending <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">
                        <i class="fas fa-clock"></i> Pending <span class="count"><?php echo number_format((int)$overall_stats['pending']); ?></span>
                    </a>
                    <a href="?status=voided<?php echo !empty($filter_method) ? '&method=' . urlencode($filter_method) : ''; ?><?php echo !empty($filter_date_from) ? '&date_from=' . urlencode($filter_date_from) : ''; ?><?php echo !empty($filter_date_to) ? '&date_to=' . urlencode($filter_date_to) : ''; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="filter-pill voided <?php echo $filter_status === 'voided' ? 'active' : ''; ?>">
                        <i class="fas fa-ban"></i> Voided <span class="count"><?php echo number_format((int)$overall_stats['voided']); ?></span>
                    </a>
                </div>
                
                <!-- Search & Advanced Filters -->
                <div class="search-filter-bar">
                    <form method="GET" class="row g-2 align-items-end">
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                        
                        <div class="col-12 col-md-4">
                            <input type="text" class="form-control search-input" name="search" 
                                   placeholder="Search name, phone, reference..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="col-6 col-md-2">
                            <select class="form-select search-input" name="method">
                                <option value="">All Methods</option>
                                <option value="bank_transfer" <?php echo $filter_method === 'bank_transfer' ? 'selected' : ''; ?>>Bank</option>
                                <option value="cash" <?php echo $filter_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="card" <?php echo $filter_method === 'card' ? 'selected' : ''; ?>>Card</option>
                            </select>
                        </div>
                        
                        <div class="col-6 col-md-2">
                            <input type="date" class="form-control search-input" name="date_from" 
                                   placeholder="From"
                                   value="<?php echo htmlspecialchars($filter_date_from); ?>">
                        </div>
                        
                        <div class="col-6 col-md-2">
                            <input type="date" class="form-control search-input" name="date_to" 
                                   placeholder="To"
                                   value="<?php echo htmlspecialchars($filter_date_to); ?>">
                        </div>
                        
                        <div class="col-6 col-md-2">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-grow-1">
                                    <i class="fas fa-search"></i>
                                </button>
                                <a href="payments.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
                
                <?php if (empty($payments)): ?>
                    <!-- Empty State -->
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <h5 class="text-muted mb-2">No payments found</h5>
                        <p class="text-muted small mb-3">Try adjusting your filters or search terms</p>
                        <a href="payments.php" class="btn btn-outline-primary btn-sm">Clear Filters</a>
                    </div>
                <?php else: ?>
                    
                    <!-- Mobile Card View -->
                    <div class="mobile-cards">
                        <?php foreach ($payments as $payment): ?>
                            <?php
                            $status_class = $payment['status'];
                            $method_icon = match($payment['payment_method']) {
                                'bank_transfer' => 'fa-university',
                                'cash' => 'fa-money-bill',
                                'card' => 'fa-credit-card',
                                default => 'fa-wallet'
                            };
                            $date = $payment['payment_date'] ?? $payment['created_at'];
                            ?>
                            <div class="payment-card status-<?php echo $status_class; ?>" 
                                 data-payment='<?php echo htmlspecialchars(json_encode($payment), ENT_QUOTES); ?>'
                                 onclick="showPaymentDetail(this)">
                                
                                <div class="payment-card-header">
                                    <div class="donor-info">
                                        <div class="donor-name"><?php echo htmlspecialchars($payment['donor_name'] ?? 'Unknown'); ?></div>
                                        <div class="donor-phone">
                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($payment['donor_phone'] ?? '-'); ?>
                                        </div>
                                    </div>
                                    <div class="amount-display">
                                        <div class="amount-value">£<?php echo number_format((float)$payment['amount'], 2); ?></div>
                                        <?php if (!empty($payment['pledge_amount'])): ?>
                                            <div class="amount-pledge">of £<?php echo number_format((float)$payment['pledge_amount'], 0); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="payment-card-body">
                                    <div class="payment-meta">
                                        <div class="meta-item">
                                            <span class="meta-label">Method</span>
                                            <span class="method-badge">
                                                <i class="fas <?php echo $method_icon; ?>"></i>
                                                <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'] ?? 'Unknown')); ?>
                                            </span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Date</span>
                                            <span class="meta-value">
                                                <?php echo $date ? date('d M Y', strtotime($date)) : '-'; ?>
                                                <?php if (!empty($payment['created_at'])): ?>
                                                    <span class="text-muted small"><?php echo date('H:i', strtotime($payment['created_at'])); ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Status</span>
                                            <span class="status-pill <?php echo $status_class; ?>">
                                                <?php if ($status_class === 'confirmed'): ?><i class="fas fa-check"></i><?php endif; ?>
                                                <?php if ($status_class === 'pending'): ?><i class="fas fa-clock"></i><?php endif; ?>
                                                <?php if ($status_class === 'voided'): ?><i class="fas fa-ban"></i><?php endif; ?>
                                                <?php echo ucfirst($payment['status']); ?>
                                            </span>
                                            <?php if ($payment['status'] === 'confirmed' && !empty($payment['approved_by_name'])): ?>
                                                <div class="approved-by">by <?php echo htmlspecialchars($payment['approved_by_name']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Plan</span>
                                            <?php if ($has_plan_col && !empty($payment['plan_id'])): ?>
                                                <span class="plan-indicator">
                                                    <i class="fas fa-calendar-check"></i>
                                                    <?php echo (int)$payment['plan_payments_made']; ?>/<?php echo (int)$payment['plan_total_payments']; ?>
                                                </span>
                                            <?php elseif (!empty($payment['has_active_plan'])): ?>
                                                <span class="plan-indicator">
                                                    <i class="fas fa-calendar"></i> Active
                                                </span>
                                            <?php else: ?>
                                                <span class="meta-value text-muted">-</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="payment-card-footer">
                                    <div class="d-flex gap-2 ms-auto">
                                        <?php if (!empty($payment['payment_proof'])): ?>
                                            <button type="button" class="action-btn proof" 
                                                    onclick="event.stopPropagation(); viewProof('../../<?php echo htmlspecialchars($payment['payment_proof']); ?>')"
                                                    title="View Proof">
                                                <i class="fas fa-image"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($payment['status'] === 'pending' && $is_admin): ?>
                                            <a href="../donations/review-pledge-payments.php?filter=pending" 
                                               class="action-btn pending-action"
                                               onclick="event.stopPropagation();"
                                               title="Review">
                                                <i class="fas fa-clock"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="view-donor.php?id=<?php echo (int)$payment['donor_id']; ?>" 
                                           class="action-btn view"
                                           onclick="event.stopPropagation();"
                                           title="View Donor">
                                            <i class="fas fa-user"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Desktop Table View -->
                    <div class="desktop-table">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="payments-table">
                                        <thead>
                                            <tr>
                                                <th>Donor</th>
                                                <th>Amount</th>
                                                <th>Method</th>
                                                <th>Date</th>
                                                <th>Status</th>
                                                <th>Plan</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($payments as $payment): ?>
                                                <?php
                                                $status_class = $payment['status'];
                                                $method_icon = match($payment['payment_method']) {
                                                    'bank_transfer' => 'fa-university',
                                                    'cash' => 'fa-money-bill',
                                                    'card' => 'fa-credit-card',
                                                    default => 'fa-wallet'
                                                };
                                                $date = $payment['payment_date'] ?? $payment['created_at'];
                                                ?>
                                                <tr data-payment='<?php echo htmlspecialchars(json_encode($payment), ENT_QUOTES); ?>'
                                                    onclick="showPaymentDetail(this)">
                                                    <td class="donor-cell">
                                                        <div class="donor-name"><?php echo htmlspecialchars($payment['donor_name'] ?? 'Unknown'); ?></div>
                                                        <div class="donor-phone small">
                                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($payment['donor_phone'] ?? '-'); ?>
                                                        </div>
                                                    </td>
                                                    <td class="amount-cell">
                                                        £<?php echo number_format((float)$payment['amount'], 2); ?>
                                                        <?php if (!empty($payment['pledge_amount'])): ?>
                                                            <div class="small text-muted">of £<?php echo number_format((float)$payment['pledge_amount'], 0); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="method-badge">
                                                            <i class="fas <?php echo $method_icon; ?>"></i>
                                                            <?php echo ucwords(str_replace('_', ' ', $payment['payment_method'] ?? '')); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo $date ? date('d M Y', strtotime($date)) : '-'; ?>
                                                        <div class="small text-muted"><?php echo !empty($payment['created_at']) ? date('H:i', strtotime($payment['created_at'])) : ''; ?></div>
                                                    </td>
                                                    <td>
                                                        <span class="status-pill <?php echo $status_class; ?>">
                                                            <?php echo ucfirst($payment['status']); ?>
                                                        </span>
                                                        <?php if ($payment['status'] === 'confirmed' && !empty($payment['approved_by_name'])): ?>
                                                            <div class="approved-by">by <?php echo htmlspecialchars($payment['approved_by_name']); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($has_plan_col && !empty($payment['plan_id'])): ?>
                                                            <span class="plan-indicator">
                                                                <i class="fas fa-calendar-check"></i>
                                                                <?php echo (int)$payment['plan_payments_made']; ?>/<?php echo (int)$payment['plan_total_payments']; ?>
                                                            </span>
                                                        <?php elseif (!empty($payment['has_active_plan'])): ?>
                                                            <span class="plan-indicator">Has Plan</span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <div class="d-flex gap-1 justify-content-end">
                                                            <?php if (!empty($payment['payment_proof'])): ?>
                                                                <button type="button" class="action-btn proof" 
                                                                        onclick="event.stopPropagation(); viewProof('../../<?php echo htmlspecialchars($payment['payment_proof']); ?>')"
                                                                        title="View Proof">
                                                                    <i class="fas fa-image"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            <?php if ($payment['status'] === 'pending' && $is_admin): ?>
                                                                <a href="../donations/review-pledge-payments.php?filter=pending" 
                                                                   class="action-btn pending-action"
                                                                   onclick="event.stopPropagation();"
                                                                   title="Review">
                                                                    <i class="fas fa-clock"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                            <a href="view-donor.php?id=<?php echo (int)$payment['donor_id']; ?>" 
                                                               class="action-btn view"
                                                               onclick="event.stopPropagation();"
                                                               title="View Donor">
                                                                <i class="fas fa-user"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Results Count -->
                    <div class="text-center text-muted small mt-3 mb-4">
                        Showing <?php echo count($payments); ?> payment<?php echo count($payments) !== 1 ? 's' : ''; ?>
                        • Tap a payment for details
                    </div>
                    
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Payment Detail Modal -->
<div class="modal fade" id="paymentDetailModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header bg-success text-white py-3">
                <div>
                    <h6 class="modal-title mb-0">
                        <i class="fas fa-receipt me-2"></i>Payment Details
                    </h6>
                    <small class="text-white-50">Payment #<span id="modal_payment_id">-</span></small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Amount Highlight -->
                <div class="text-center mb-4 py-3" style="background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-radius: 12px;">
                    <div class="text-success small text-uppercase fw-bold mb-1">Payment Amount</div>
                    <h2 class="text-success mb-0" id="modal_amount">£0.00</h2>
                </div>
                
                <div class="row g-3">
                    <!-- Status & Method -->
                    <div class="col-6">
                        <div class="p-3 rounded" style="background: #f9fafb;">
                            <small class="text-muted d-block mb-1">Status</small>
                            <span id="modal_status" class="status-pill">-</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded" style="background: #f9fafb;">
                            <small class="text-muted d-block mb-1">Method</small>
                            <strong id="modal_method">-</strong>
                        </div>
                    </div>
                    
                    <!-- Date & Reference -->
                    <div class="col-6">
                        <div class="p-3 rounded" style="background: #f9fafb;">
                            <small class="text-muted d-block mb-1">Date</small>
                            <strong id="modal_date">-</strong>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded" style="background: #f9fafb;">
                            <small class="text-muted d-block mb-1">Reference</small>
                            <code id="modal_reference" class="small">-</code>
                        </div>
                    </div>
                    
                    <!-- Donor Info Section -->
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-primary bg-opacity-10 py-2">
                                <h6 class="mb-0 small text-primary"><i class="fas fa-user me-2"></i>Donor</h6>
                            </div>
                            <div class="card-body py-3">
                                <div class="row g-2">
                                    <div class="col-12">
                                        <strong id="modal_donor_name" class="d-block">-</strong>
                                        <small id="modal_donor_phone" class="text-muted">-</small>
                                    </div>
                                    <div class="col-4 text-center">
                                        <small class="text-muted d-block">Pledge</small>
                                        <strong id="modal_pledge_amount">-</strong>
                                    </div>
                                    <div class="col-4 text-center">
                                        <small class="text-muted d-block">Paid</small>
                                        <strong class="text-success" id="modal_total_paid">£0</strong>
                                    </div>
                                    <div class="col-4 text-center">
                                        <small class="text-muted d-block">Balance</small>
                                        <strong class="text-danger" id="modal_balance">£0</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Plan Info (if applicable) -->
                    <div class="col-12" id="modal_plan_section" style="display: none;">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-info bg-opacity-10 py-2">
                                <h6 class="mb-0 small text-info"><i class="fas fa-calendar-alt me-2"></i>Payment Plan</h6>
                            </div>
                            <div class="card-body py-3">
                                <div class="row g-2 text-center">
                                    <div class="col-4">
                                        <small class="text-muted d-block">Progress</small>
                                        <strong id="modal_plan_progress">-</strong>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-muted d-block">Amount</small>
                                        <strong id="modal_plan_amount">-</strong>
                                    </div>
                                    <div class="col-4">
                                        <small class="text-muted d-block">Status</small>
                                        <span id="modal_plan_status" class="badge">-</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Proof Image -->
                    <div class="col-12" id="modal_proof_section" style="display: none;">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-secondary bg-opacity-10 py-2">
                                <h6 class="mb-0 small text-secondary"><i class="fas fa-image me-2"></i>Payment Proof</h6>
                            </div>
                            <div class="card-body text-center py-3">
                                <img id="modal_proof_image" src="" alt="Proof" class="img-fluid rounded" style="max-height: 200px; cursor: pointer;" onclick="viewProof(this.src)">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Notes -->
                    <div class="col-12" id="modal_notes_section" style="display: none;">
                        <div class="p-3 rounded" style="background: #fef3c7;">
                            <small class="text-muted d-block mb-1"><i class="fas fa-sticky-note me-1"></i>Notes</small>
                            <p id="modal_notes" class="mb-0 small">-</p>
                        </div>
                    </div>
                    
                    <!-- Edit Form (hidden by default) -->
                    <?php if ($is_admin): ?>
                    <div class="col-12" id="modal_edit_section" style="display: none;">
                        <div class="card border-warning">
                            <div class="card-header bg-warning bg-opacity-10 py-2">
                                <h6 class="mb-0 small text-warning"><i class="fas fa-edit me-2"></i>Edit Payment Details</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="editPaymentForm" enctype="multipart/form-data">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="update_payment">
                                    <input type="hidden" name="payment_id" id="edit_payment_id" value="">
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Payment Date</label>
                                            <input type="date" class="form-control" name="payment_date" id="edit_payment_date" required>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Approved By</label>
                                            <select class="form-select" name="approved_by_user_id" id="edit_approved_by">
                                                <option value="">-- Select Registrar --</option>
                                                <?php foreach ($users_list as $user): ?>
                                                    <option value="<?php echo (int)$user['id']; ?>">
                                                        <?php echo htmlspecialchars($user['name']); ?> (<?php echo htmlspecialchars($user['role']); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-12">
                                            <label class="form-label small fw-bold">Notes</label>
                                            <textarea class="form-control" name="notes" id="edit_notes" rows="3" placeholder="Add notes about this payment..."></textarea>
                                        </div>
                                        
                                        <div class="col-12">
                                            <label class="form-label small fw-bold">Payment Proof</label>
                                            <input type="file" class="form-control" name="payment_proof" id="edit_payment_proof" accept="image/*,.pdf">
                                            <small class="text-muted">Allowed: JPG, PNG, PDF. Current proof will be replaced.</small>
                                            <div id="current_proof_info" class="mt-2 small text-muted" style="display: none;">
                                                <i class="fas fa-info-circle"></i> Current proof: <span id="current_proof_name"></span>
                                            </div>
                                        </div>
                                        
                                        <div class="col-12">
                                            <div class="d-flex gap-2">
                                                <button type="submit" class="btn btn-success">
                                                    <i class="fas fa-save me-1"></i>Save Changes
                                                </button>
                                                <button type="button" class="btn btn-secondary" onclick="toggleEditMode()">
                                                    <i class="fas fa-times me-1"></i>Cancel
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <?php if ($is_admin): ?>
                <form method="POST" id="deletePaymentForm" class="d-inline" onsubmit="return confirmDeletePayment()">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="delete_payment">
                    <input type="hidden" name="payment_id" id="delete_payment_id" value="">
                    <button type="submit" class="btn btn-danger" id="modal_delete_btn" style="display: none;">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                </form>
                <button type="button" class="btn btn-warning" id="modal_edit_btn" onclick="toggleEditMode()">
                    <i class="fas fa-edit me-1"></i>Edit
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="modal_close_btn">Close</button>
                <a href="#" id="modal_view_donor_btn" class="btn btn-primary">
                    <i class="fas fa-user me-1"></i>View Donor
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Proof Image Modal -->
<div class="modal fade" id="proofModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="fas fa-image me-2"></i>Payment Proof</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-0">
                <img id="proofImage" src="" alt="Payment Proof" class="img-fluid">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>

<script>
// View proof image
function viewProof(url) {
    document.getElementById('proofImage').src = url;
    new bootstrap.Modal(document.getElementById('proofModal')).show();
}

// Show payment detail modal
function showPaymentDetail(element) {
    const payment = JSON.parse(element.dataset.payment);
    
    // Basic info
    document.getElementById('modal_payment_id').textContent = payment.id;
    document.getElementById('modal_amount').textContent = '£' + parseFloat(payment.amount).toFixed(2);
    
    // Status
    const statusEl = document.getElementById('modal_status');
    statusEl.className = 'status-pill ' + payment.status;
    statusEl.textContent = payment.status.charAt(0).toUpperCase() + payment.status.slice(1);
    
    // Method
    const methodText = (payment.payment_method || 'Unknown').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    document.getElementById('modal_method').textContent = methodText;
    
    // Date & Time
    const date = payment.payment_date || payment.created_at;
    const createdAt = payment.created_at;
    let dateDisplay = '-';
    if (date) {
        dateDisplay = new Date(date).toLocaleDateString('en-GB');
        if (createdAt) {
            const time = new Date(createdAt);
            dateDisplay += ' ' + time.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
        }
    }
    document.getElementById('modal_date').textContent = dateDisplay;
    
    // Reference - show pledge notes (4-digit ref) if available, otherwise payment reference
    const reference = payment.pledge_notes || payment.reference_number || '-';
    document.getElementById('modal_reference').textContent = reference;
    
    // Notes
    if (payment.notes && payment.notes.trim()) {
        document.getElementById('modal_notes').textContent = payment.notes;
        document.getElementById('modal_notes_section').style.display = 'block';
    } else {
        document.getElementById('modal_notes_section').style.display = 'none';
    }
    
    // Donor info
    document.getElementById('modal_donor_name').textContent = payment.donor_name || 'Unknown';
    document.getElementById('modal_donor_phone').textContent = payment.donor_phone || '-';
    document.getElementById('modal_pledge_amount').textContent = payment.pledge_amount ? '£' + parseFloat(payment.pledge_amount).toFixed(0) : '-';
    document.getElementById('modal_total_paid').textContent = '£' + parseFloat(payment.total_paid || 0).toFixed(0);
    document.getElementById('modal_balance').textContent = '£' + parseFloat(payment.balance || 0).toFixed(0);
    
    // Payment plan
    if (payment.plan_id) {
        document.getElementById('modal_plan_progress').textContent = (payment.plan_payments_made || 0) + ' / ' + (payment.plan_total_payments || 0);
        document.getElementById('modal_plan_amount').textContent = '£' + parseFloat(payment.plan_monthly_amount || 0).toFixed(2);
        
        const planStatusEl = document.getElementById('modal_plan_status');
        const planStatusClass = {
            'active': 'bg-success',
            'completed': 'bg-primary',
            'paused': 'bg-warning'
        }[payment.plan_status] || 'bg-secondary';
        planStatusEl.className = 'badge ' + planStatusClass;
        planStatusEl.textContent = (payment.plan_status || 'Unknown').charAt(0).toUpperCase() + (payment.plan_status || '').slice(1);
        
        document.getElementById('modal_plan_section').style.display = 'block';
    } else {
        document.getElementById('modal_plan_section').style.display = 'none';
    }
    
    // Proof image
    if (payment.payment_proof) {
        document.getElementById('modal_proof_image').src = '../../' + payment.payment_proof;
        document.getElementById('modal_proof_section').style.display = 'block';
    } else {
        document.getElementById('modal_proof_section').style.display = 'none';
    }
    
    // View donor button
    document.getElementById('modal_view_donor_btn').href = 'view-donor.php?id=' + payment.donor_id;
    
    // Delete button - only show for voided payments (admin only)
    const deleteBtn = document.getElementById('modal_delete_btn');
    const deletePaymentId = document.getElementById('delete_payment_id');
    if (deleteBtn && deletePaymentId) {
        if (payment.status === 'voided') {
            deleteBtn.style.display = 'inline-block';
            deletePaymentId.value = payment.id;
        } else {
            deleteBtn.style.display = 'none';
            deletePaymentId.value = '';
        }
    }
    
    // Populate edit form
    const editPaymentId = document.getElementById('edit_payment_id');
    const editPaymentDate = document.getElementById('edit_payment_date');
    const editApprovedBy = document.getElementById('edit_approved_by');
    const editNotes = document.getElementById('edit_notes');
    const currentProofInfo = document.getElementById('current_proof_info');
    const currentProofName = document.getElementById('current_proof_name');
    
    if (editPaymentId) {
        editPaymentId.value = payment.id;
        
        // Set payment date (format YYYY-MM-DD)
        if (editPaymentDate && payment.payment_date) {
            const date = new Date(payment.payment_date);
            editPaymentDate.value = date.toISOString().split('T')[0];
        }
        
        // Set approved by
        if (editApprovedBy) {
            editApprovedBy.value = payment.approved_by_user_id || '';
        }
        
        // Set notes
        if (editNotes) {
            editNotes.value = payment.notes || '';
        }
        
        // Show current proof info
        if (payment.payment_proof) {
            const proofPath = payment.payment_proof;
            const proofFileName = proofPath.split('/').pop();
            if (currentProofInfo && currentProofName) {
                currentProofName.textContent = proofFileName;
                currentProofInfo.style.display = 'block';
            }
        } else if (currentProofInfo) {
            currentProofInfo.style.display = 'none';
        }
    }
    
    // Hide edit section initially
    const editSection = document.getElementById('modal_edit_section');
    if (editSection) {
        editSection.style.display = 'none';
    }
    
    // Show modal
    new bootstrap.Modal(document.getElementById('paymentDetailModal')).show();
}

// Toggle edit mode
function toggleEditMode() {
    const editSection = document.getElementById('modal_edit_section');
    const editBtn = document.getElementById('modal_edit_btn');
    const closeBtn = document.getElementById('modal_close_btn');
    const viewDonorBtn = document.getElementById('modal_view_donor_btn');
    
    if (editSection && editBtn) {
        if (editSection.style.display === 'none') {
            editSection.style.display = 'block';
            editBtn.innerHTML = '<i class="fas fa-times me-1"></i>Cancel Edit';
            editBtn.classList.remove('btn-warning');
            editBtn.classList.add('btn-secondary');
            if (closeBtn) closeBtn.style.display = 'none';
            if (viewDonorBtn) viewDonorBtn.style.display = 'none';
        } else {
            editSection.style.display = 'none';
            editBtn.innerHTML = '<i class="fas fa-edit me-1"></i>Edit';
            editBtn.classList.remove('btn-secondary');
            editBtn.classList.add('btn-warning');
            if (closeBtn) closeBtn.style.display = 'inline-block';
            if (viewDonorBtn) viewDonorBtn.style.display = 'inline-block';
        }
    }
}

// Confirm delete payment
function confirmDeletePayment() {
    const paymentId = document.getElementById('delete_payment_id').value;
    const donorName = document.getElementById('modal_donor_name').textContent;
    const amount = document.getElementById('modal_amount').textContent;
    
    return confirm(
        'Are you sure you want to permanently delete this payment?\n\n' +
        'Payment #' + paymentId + '\n' +
        'Donor: ' + donorName + '\n' +
        'Amount: ' + amount + '\n\n' +
        'This action cannot be undone, but will be recorded in the audit log.'
    );
}

// Reset edit mode when modal is closed
document.addEventListener('DOMContentLoaded', function() {
    const paymentModal = document.getElementById('paymentDetailModal');
    if (paymentModal) {
        paymentModal.addEventListener('hidden.bs.modal', function() {
            const editSection = document.getElementById('modal_edit_section');
            const editBtn = document.getElementById('modal_edit_btn');
            const closeBtn = document.getElementById('modal_close_btn');
            const viewDonorBtn = document.getElementById('modal_view_donor_btn');
            
            if (editSection) editSection.style.display = 'none';
            if (editBtn) {
                editBtn.innerHTML = '<i class="fas fa-edit me-1"></i>Edit';
                editBtn.classList.remove('btn-secondary');
                editBtn.classList.add('btn-warning');
            }
            if (closeBtn) closeBtn.style.display = 'inline-block';
            if (viewDonorBtn) viewDonorBtn.style.display = 'inline-block';
        });
    }
});

// Fallback for sidebar toggle
if (typeof window.toggleSidebar !== 'function') {
    window.toggleSidebar = function() {
        var body = document.body;
        var sidebar = document.getElementById('sidebar');
        var overlay = document.querySelector('.sidebar-overlay');
        if (window.innerWidth <= 991.98) {
            if (sidebar) sidebar.classList.toggle('active');
            if (overlay) overlay.classList.toggle('active');
            body.style.overflow = (sidebar && sidebar.classList.contains('active')) ? 'hidden' : '';
        } else {
            body.classList.toggle('sidebar-collapsed');
        }
    };
}
</script>
</body>
</html>
